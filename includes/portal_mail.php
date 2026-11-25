<?php
/**
 * Portal mailbox: threaded messages between borrowers (clients role 3,
 * staff borrowers role 4) and the loan team (admins role 1, loan officers
 * role 2). Every new message is also emailed to the recipient.
 *
 * Used by clients/messages.php, staff/messages.php and admin/mailbox.php.
 * Tables are created on first use; see admin/client-documents-and-mail.sql.
 */

const PM_TEAM_ROLES     = [1, 2];
const PM_BORROWER_ROLES = [3, 4];
const PM_FOLDERS        = ['inbox' => 'Inbox', 'starred' => 'Starred', 'sent' => 'Sent', 'archive' => 'Archive'];
const PM_PAGE_SIZE      = 25;

function pm_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS mail_threads (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            subject VARCHAR(200) NOT NULL,
            created_by INT NOT NULL,
            last_message_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mail_threads_last (last_message_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS mail_messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            thread_id INT UNSIGNED NOT NULL,
            sender_id INT NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mail_messages_thread (thread_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS mail_participants (
            thread_id INT UNSIGNED NOT NULL,
            user_id INT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            is_starred TINYINT(1) NOT NULL DEFAULT 0,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            has_sent TINYINT(1) NOT NULL DEFAULT 0,
            has_received TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (thread_id, user_id),
            KEY idx_mail_participants_user (user_id, is_archived, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $done = true;
}

function pm_user(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT id, fname, lname, username, email, role FROM user WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

function pm_name(array $user): string
{
    $name = trim(($user['fname'] ?? '') . ' ' . ($user['lname'] ?? ''));
    return $name !== '' ? $name : (string)($user['username'] ?? 'User');
}

function pm_role_label(int $role): string
{
    return [1 => 'Admin', 2 => 'Loan Officer', 3 => 'Client', 4 => 'Staff'][$role] ?? 'User';
}

/** Borrowers write to the loan team and vice versa. */
function pm_can_message(int $fromRole, int $toRole): bool
{
    return (in_array($fromRole, PM_BORROWER_ROLES, true) && in_array($toRole, PM_TEAM_ROLES, true))
        || (in_array($fromRole, PM_TEAM_ROLES, true) && in_array($toRole, PM_BORROWER_ROLES, true));
}

/**
 * People this user can start a conversation with.
 * Borrowers: loan officers and admins, assigned officer first.
 * Team: clients and staff borrowers.
 */
function pm_recipients(mysqli $conn, array $me): array
{
    $myRole = (int)$me['role'];
    $roles = in_array($myRole, PM_BORROWER_ROLES, true) ? PM_TEAM_ROLES : PM_BORROWER_ROLES;
    $list = implode(',', array_map('intval', $roles));

    $rows = $conn->query("
        SELECT id, fname, lname, username, email, role
        FROM user
        WHERE role IN ({$list})" . pm_officer_borrower_sql($conn, $myRole, (int)$me['id']) . "
        ORDER BY role DESC, fname, lname
        LIMIT 2000
    ")->fetch_all(MYSQLI_ASSOC);

    $assigned = 0;
    if ($myRole === 3 && function_exists('client_assigned_officer')) {
        $assigned = client_assigned_officer($conn, (int)$me['id']);
    }

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id'       => (int)$row['id'],
            'name'     => pm_name($row),
            'email'    => (string)$row['email'],
            'role'     => (int)$row['role'],
            'label'    => pm_role_label((int)$row['role']),
            'assigned' => (int)$row['id'] === $assigned,
        ];
    }

    // Borrowers: assigned officer first, then officers, then admins.
    $rank = static fn(array $r): array => [$r['assigned'] ? 0 : 1, $r['role'] === 2 ? 0 : 1, strtolower($r['name'])];
    usort($out, static fn($a, $b) => $rank($a) <=> $rank($b));

    return $out;
}

function pm_unread_count(mysqli $conn, int $userId): int
{
    try {
        pm_ensure_schema($conn);
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM mail_participants WHERE user_id = ? AND is_read = 0 AND is_archived = 0 AND has_received = 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $count;
    } catch (Throwable $e) {
        error_log('pm_unread_count failed: ' . $e->getMessage());
        return 0;
    }
}

function pm_folder_counts(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT
            SUM(has_received = 1 AND is_archived = 0 AND is_read = 0) AS inbox,
            SUM(is_starred = 1) AS starred
        FROM mail_participants WHERE user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return ['inbox' => (int)($row['inbox'] ?? 0), 'starred' => (int)($row['starred'] ?? 0)];
}

/** Threads in a folder, newest first, with the other party and a snippet. */
function pm_list(mysqli $conn, int $userId, string $folder, string $search = '', int $page = 1): array
{
    $where = [
        'inbox'   => 'p.has_received = 1 AND p.is_archived = 0',
        'starred' => 'p.is_starred = 1',
        'sent'    => 'p.has_sent = 1',
        'archive' => 'p.is_archived = 1',
    ][$folder] ?? 'p.has_received = 1 AND p.is_archived = 0';

    $params = [$userId];
    $types = 'i';
    $searchSql = '';
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $searchSql = " AND (t.subject LIKE ? OR EXISTS (SELECT 1 FROM mail_messages sm WHERE sm.thread_id = t.id AND sm.body LIKE ?)
                       OR EXISTS (SELECT 1 FROM mail_participants sp JOIN user su ON su.id = sp.user_id
                                  WHERE sp.thread_id = t.id AND sp.user_id <> ? AND CONCAT_WS(' ', su.fname, su.lname, su.email) LIKE ?))";
        array_push($params, $like, $like, $userId, $like);
        $types .= 'ssis';
    }

    $offset = max(0, ($page - 1) * PM_PAGE_SIZE);
    $limit = PM_PAGE_SIZE + 1; // one extra to know if there is a next page

    $stmt = $conn->prepare("
        SELECT t.id, t.subject, t.last_message_at, p.is_read, p.is_starred, p.is_archived,
               (SELECT COUNT(*) FROM mail_messages c WHERE c.thread_id = t.id) AS message_count,
               (SELECT m.body FROM mail_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_body,
               (SELECT m.sender_id FROM mail_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_sender,
               (SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', u.fname, u.lname)) SEPARATOR ', ')
                  FROM mail_participants op JOIN user u ON u.id = op.user_id
                 WHERE op.thread_id = t.id AND op.user_id <> p.user_id) AS other_names
        FROM mail_participants p
        JOIN mail_threads t ON t.id = p.thread_id
        WHERE p.user_id = ? AND {$where} {$searchSql}
        ORDER BY t.last_message_at DESC, t.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $hasMore = count($rows) > PM_PAGE_SIZE;
    return ['rows' => array_slice($rows, 0, PM_PAGE_SIZE), 'has_more' => $hasMore];
}

/** A thread the user takes part in, with all messages; null if not allowed. */
function pm_thread(mysqli $conn, int $userId, int $threadId): ?array
{
    $stmt = $conn->prepare("
        SELECT t.*, p.is_read, p.is_starred, p.is_archived
        FROM mail_threads t JOIN mail_participants p ON p.thread_id = t.id AND p.user_id = ?
        WHERE t.id = ? LIMIT 1
    ");
    $stmt->bind_param('ii', $userId, $threadId);
    $stmt->execute();
    $thread = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$thread) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT m.id, m.sender_id, m.body, m.created_at, u.fname, u.lname, u.username, u.email, u.role
        FROM mail_messages m JOIN user u ON u.id = m.sender_id
        WHERE m.thread_id = ? ORDER BY m.id ASC
    ");
    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    $thread['messages'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT u.id, u.fname, u.lname, u.username, u.email, u.role
        FROM mail_participants p JOIN user u ON u.id = p.user_id
        WHERE p.thread_id = ? AND p.user_id <> ?
    ");
    $stmt->bind_param('ii', $threadId, $userId);
    $stmt->execute();
    $thread['others'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $thread;
}

function pm_set_flag(mysqli $conn, int $userId, int $threadId, string $flag, int $value): void
{
    if (!in_array($flag, ['is_read', 'is_starred', 'is_archived'], true)) {
        return;
    }
    $stmt = $conn->prepare("UPDATE mail_participants SET {$flag} = ? WHERE thread_id = ? AND user_id = ?");
    $stmt->bind_param('iii', $value, $threadId, $userId);
    $stmt->execute();
    $stmt->close();
}

function pm_clean_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace("/\n{4,}/", "\n\n\n", $body);
    $body = trim($body);
    return function_exists('mb_substr') ? mb_substr($body, 0, 10000) : substr($body, 0, 10000);
}

/** Start a new conversation. Returns the thread id. */
/**
 * For a loan officer: SQL limiting user rows to their own clients (customers
 * and staff borrowers with assigned_officer_id = them). '' for everyone else.
 */
function pm_officer_borrower_sql(mysqli $conn, int $role, int $userId): string
{
    if ($role !== 2 || $userId <= 0) {
        return '';
    }
    $parts = [];
    foreach (['customers', 'members'] as $table) {
        try {
            $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'assigned_officer_id'");
            if ($res && $res->num_rows > 0) {
                $parts[] = "id IN (SELECT user_id FROM `{$table}` WHERE assigned_officer_id = {$userId})";
            }
        } catch (Throwable $e) {
            // Table missing: nothing to add.
        }
    }
    // Officer with no assignment columns yet: show no borrowers rather than all.
    if (!$parts) {
        return ' AND 1 = 0';
    }
    return $parts ? ' AND (' . implode(' OR ', $parts) . ')' : '';
}

function pm_send_new(mysqli $conn, array $me, int $recipientId, string $subject, string $body): int
{
    $subject = trim(preg_replace('/\s+/', ' ', $subject));
    $subject = function_exists('mb_substr') ? mb_substr($subject, 0, 200) : substr($subject, 0, 200);
    $body = pm_clean_body($body);

    if ($subject === '') {
        throw new DomainException('Please add a subject.');
    }
    if ($body === '') {
        throw new DomainException('Please write a message.');
    }

    $recipient = pm_user($conn, $recipientId);
    if (!$recipient || !pm_can_message((int)$me['role'], (int)$recipient['role'])) {
        throw new DomainException('Please choose a valid recipient.');
    }
    // Loan officers may only start conversations with their own clients.
    $officerSql = pm_officer_borrower_sql($conn, (int)$me['role'], (int)$me['id']);
    if ($officerSql !== '') {
        $mine = $conn->query('SELECT 1 FROM user WHERE id = ' . (int)$recipientId . $officerSql . ' LIMIT 1');
        if (!$mine || $mine->num_rows === 0) {
            throw new DomainException('This client is assigned to another loan officer.');
        }
    }

    $meId = (int)$me['id'];
    $now = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO mail_threads (subject, created_by, last_message_at, created_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('siss', $subject, $meId, $now, $now);
        $stmt->execute();
        $threadId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO mail_messages (thread_id, sender_id, body, created_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iiss', $threadId, $meId, $body, $now);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO mail_participants (thread_id, user_id, is_read, has_sent, has_received)
            VALUES (?, ?, 1, 1, 0), (?, ?, 0, 0, 1)
        ");
        $stmt->bind_param('iiii', $threadId, $meId, $threadId, $recipientId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    pm_notify($conn, $me, [$recipient], $threadId, $subject, $body, true);

    return $threadId;
}

/** Reply in an existing conversation the user takes part in. */
function pm_reply(mysqli $conn, array $me, int $threadId, string $body): void
{
    $body = pm_clean_body($body);
    if ($body === '') {
        throw new DomainException('Please write a reply.');
    }

    $meId = (int)$me['id'];
    $thread = pm_thread($conn, $meId, $threadId);
    if (!$thread) {
        throw new DomainException('Conversation not found.');
    }

    $now = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO mail_messages (thread_id, sender_id, body, created_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iiss', $threadId, $meId, $body, $now);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE mail_threads SET last_message_at = ? WHERE id = ?");
        $stmt->bind_param('si', $now, $threadId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE mail_participants SET has_sent = 1, is_read = 1 WHERE thread_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $threadId, $meId);
        $stmt->execute();
        $stmt->close();

        // Like Gmail: a new reply brings the conversation back to the inbox.
        $stmt = $conn->prepare("UPDATE mail_participants SET has_received = 1, is_read = 0, is_archived = 0 WHERE thread_id = ? AND user_id <> ?");
        $stmt->bind_param('ii', $threadId, $meId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    pm_notify($conn, $me, $thread['others'], $threadId, (string)$thread['subject'], $body, false);
}

/** Portal page that shows a thread to a user of the given role. */
function pm_portal_path(int $role): string
{
    return [1 => '/admin/mailbox.php', 2 => '/admin/mailbox.php', 3 => '/clients/messages.php', 4 => '/staff/messages.php'][$role] ?? '/login.php';
}

/** Email each recipient a copy with a link back to the conversation. Never throws. */
function pm_notify(mysqli $conn, array $from, array $recipients, int $threadId, string $subject, string $body, bool $isNew): void
{
    try {
        if (!function_exists('sendEmail')) {
            require_once dirname(__DIR__) . '/php/mail-function.php';
        }
        if (!function_exists('app_base_url')) {
            require_once __DIR__ . '/client_documents.php';
        }

        $fromName = pm_name($from);
        $fromRole = pm_role_label((int)$from['role']);
        $preview = function_exists('mb_substr') ? mb_substr($body, 0, 1500) : substr($body, 0, 1500);

        foreach ($recipients as $to) {
            $email = trim((string)($to['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $link = app_base_url() . pm_portal_path((int)$to['role']) . '?thread=' . $threadId;
            $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937;max-width:600px">'
                . '<p style="color:#6b7280;margin:0 0 12px">' . ($isNew ? 'New message' : 'New reply') . ' from <strong style="color:#111827">'
                . htmlspecialchars($fromName) . '</strong> (' . htmlspecialchars($fromRole) . ')</p>'
                . '<h2 style="font-size:18px;margin:0 0 12px">' . htmlspecialchars($subject) . '</h2>'
                . '<div style="white-space:pre-wrap;border-left:3px solid #2563eb;padding:8px 14px;background:#f8fafc;border-radius:6px">'
                . nl2br(htmlspecialchars($preview)) . '</div>'
                . '<p style="margin:18px 0"><a href="' . htmlspecialchars($link) . '" style="background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">Reply in the portal</a></p>'
                . '<p style="color:#9ca3af;font-size:12px">Please reply through the portal so your loan team keeps a record of the conversation.</p>'
                . '</div>';

            sendEmail($email, ($isNew ? '' : 'Re: ') . $subject, $html, $preview . "\n\nReply in the portal: " . $link);
        }
    } catch (Throwable $e) {
        error_log('pm_notify failed for thread ' . $threadId . ': ' . $e->getMessage());
    }
}

/**
 * Handle a mailbox form post for the logged-in user and redirect back
 * (post/redirect/get). Call before any output.
 */
function pm_handle_post(mysqli $conn, array $me, string $selfUrl): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $folder = array_key_exists((string)($_POST['folder'] ?? ''), PM_FOLDERS) ? (string)$_POST['folder'] : 'inbox';
    $threadId = (int)($_POST['thread_id'] ?? 0);
    $back = function (string $message = '', string $type = 'success', int $thread = 0, array $old = []) use ($selfUrl, $folder): void {
        if ($message !== '') {
            $_SESSION['pm_flash'] = ['message' => $message, 'type' => $type];
        }
        if ($old) {
            $_SESSION['pm_old'] = $old;
        }
        $query = ['folder' => $folder];
        if ($thread > 0) {
            $query['thread'] = $thread;
        }
        header('Location: ' . $selfUrl . '?' . http_build_query($query));
        exit;
    };

    if (!hash_equals((string)($_SESSION['pm_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        $back('Your session expired. Please try again.', 'error', $threadId);
    }

    $action = (string)($_POST['action'] ?? '');
    $meId = (int)$me['id'];

    try {
        pm_ensure_schema($conn);

        switch ($action) {
            case 'send':
                $newId = pm_send_new($conn, $me, (int)($_POST['recipient_id'] ?? 0), (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''));
                $_SESSION['pm_flash'] = ['message' => 'Message sent.', 'type' => 'success'];
                header('Location: ' . $selfUrl . '?' . http_build_query(['folder' => 'sent', 'thread' => $newId]));
                exit;

            case 'reply':
                pm_reply($conn, $me, $threadId, (string)($_POST['body'] ?? ''));
                $back('Reply sent.', 'success', $threadId);
                // no break: $back exits

            case 'star':
            case 'unstar':
                if (pm_thread($conn, $meId, $threadId)) {
                    pm_set_flag($conn, $meId, $threadId, 'is_starred', $action === 'star' ? 1 : 0);
                }
                $back('', 'success', (int)($_POST['stay'] ?? 0) ? $threadId : 0);

            case 'archive':
            case 'unarchive':
                if (pm_thread($conn, $meId, $threadId)) {
                    pm_set_flag($conn, $meId, $threadId, 'is_archived', $action === 'archive' ? 1 : 0);
                }
                $back($action === 'archive' ? 'Conversation archived.' : 'Moved to inbox.');

            case 'unread':
                if (pm_thread($conn, $meId, $threadId)) {
                    pm_set_flag($conn, $meId, $threadId, 'is_read', 0);
                }
                $back('Marked as unread.');
        }

        $back('Unknown action.', 'error');
    } catch (DomainException $e) {
        $old = $action === 'send'
            ? ['recipient_id' => (int)($_POST['recipient_id'] ?? 0), 'subject' => (string)($_POST['subject'] ?? ''), 'body' => (string)($_POST['body'] ?? ''), 'compose' => 1]
            : ['body' => (string)($_POST['body'] ?? '')];
        $back($e->getMessage(), 'error', $action === 'send' ? 0 : $threadId, $old);
    } catch (Throwable $e) {
        error_log('Mailbox action "' . $action . '" failed for user ' . $meId . ': ' . $e->getMessage());
        $back('Something went wrong. Please try again.', 'error', $threadId);
    }
}

function pm_csrf(): string
{
    if (empty($_SESSION['pm_csrf'])) {
        $_SESSION['pm_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['pm_csrf'];
}

/** Gmail-style short date: time today, "12 Mar" this year, else dd/mm/yy. */
function pm_short_date(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return '';
    }
    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('H:i', $ts);
    }
    if (date('Y', $ts) === date('Y')) {
        return date('j M', $ts);
    }
    return date('d/m/y', $ts);
}
