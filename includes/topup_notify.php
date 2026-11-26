<?php
declare(strict_types=1);

/*
 * Notifications for loan top-up requests: an in-app notification (bell in
 * the admin header) plus an email. Failures are logged, never thrown, so a
 * mail or notification problem can't undo the request itself.
 *
 * Needs loan_table_has_column() (includes/loan_workflow.php) and sendEmail()
 * (php/mail-function.php).
 */

const TOPUP_ADMIN_URL = 'https://nsimbiimpact.com/loans/admin/';

/** In-app notification, writing only the columns this database has. */
function topup_notify_bell(mysqli $conn, int $recipientId, int $loanId, string $title, string $message, int $clientId = 0): void
{
    try {
        $now = date('Y-m-d H:i:s');
        $data = [
            'user_id'    => [$recipientId, 'i'],
            'client_id'  => [$clientId, 'i'],
            'loan_id'    => [$loanId, 'i'],
            'title'      => [$title, 's'],
            'message'    => [$message, 's'],
            'type'       => ['topup_request', 's'],
            'status'     => ['unread', 's'],
            'created_at' => [$now, 's'],
            'updated_at' => [$now, 's'],
        ];
        $cols = [];
        $types = '';
        $params = [];
        foreach ($data as $col => [$val, $type]) {
            if (loan_table_has_column($conn, 'notifications', $col)) {
                $cols[] = $col;
                $types .= $type;
                $params[] = $val;
            }
        }
        if (!in_array('message', $cols, true)) {
            return;
        }
        $stmt = $conn->prepare('INSERT INTO notifications (' . implode(',', $cols) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Top-up notification failed: ' . $e->getMessage());
    }
}

/**
 * Email one user. $lines are label => value rows; $page is the admin page
 * the button opens.
 */
function topup_notify_email(mysqli $conn, int $recipientId, string $subject, string $intro, array $lines, string $page, string $button): void
{
    try {
        $stmt = $conn->prepare('SELECT fname, email FROM user WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $recipientId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $email = trim((string)($user['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log("Top-up email: user #{$recipientId} has no valid email address.");
            return;
        }

        $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($lines as $label => $value) {
            $rows .= '<strong>' . $esc($label) . ':</strong> ' . nl2br($esc($value)) . '<br>';
        }
        $link = TOPUP_ADMIN_URL . $page;
        $body = '<p>Hello ' . $esc($user['fname'] ?? '') . ',</p>'
            . '<p>' . $esc($intro) . '</p>'
            . '<p>' . $rows . '</p>'
            . "<p><a href='{$esc($link)}' style='padding:10px 20px;background-color:#007BFF;color:white;text-decoration:none;'>" . $esc($button) . '</a></p>';

        sendEmail($email, $subject, $body, strip_tags(str_replace('<br>', "\n", $body)));
    } catch (Throwable $e) {
        error_log('Top-up email failed: ' . $e->getMessage());
    }
}

/** Active administrators (role 1). */
function topup_admin_ids(mysqli $conn): array
{
    $statusSql = loan_table_has_column($conn, 'user', 'status') ? ' AND COALESCE(status, 1) = 1' : '';
    $ids = [];
    $result = $conn->query('SELECT id FROM user WHERE role = 1' . $statusSql);
    while ($result && ($row = $result->fetch_assoc())) {
        $ids[] = (int)$row['id'];
    }
    return $ids;
}
