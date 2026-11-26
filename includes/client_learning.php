<?php
declare(strict_types=1);

/*
 * Financial / business literacy.
 *
 * Sign-up asks "Have you ever received Financial/Business training?"
 * (customers.business_training: 1 = yes, 0 = no, NULL = not asked).
 *
 * Admin > People > Learning Notes: admins and loan officers upload notes
 * (PDF, Word, PowerPoint or images) and send them to clients, e.g. everyone
 * who has had no training. Each send is a literacy_note_shares row; the
 * client is emailed and finds the notes under "Learning Notes" in their
 * portal. Files live in storage/learning_notes, which the web can't reach
 * directly, and are streamed by admin/ and clients/learning_note_file.php.
 */

const LEARNING_NOTE_MAX_BYTES = 15 * 1024 * 1024;
const LEARNING_NOTE_MIME = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

/** Who a note can be sent to. */
const LEARNING_AUDIENCES = [
    'no_training' => 'Clients who have had no financial/business training',
    'unanswered'  => 'Clients who have not answered the training question',
    'all'         => 'All clients',
];

function learning_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $res = $conn->query("SHOW COLUMNS FROM customers LIKE 'business_training'");
        if ($res instanceof mysqli_result && $res->num_rows === 0) {
            $conn->query('ALTER TABLE customers ADD COLUMN business_training TINYINT(1) NULL DEFAULT NULL');
        }
        $conn->query("
            CREATE TABLE IF NOT EXISTS literacy_notes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(190) NOT NULL,
                description TEXT NULL,
                file_path VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(120) NOT NULL,
                file_size INT UNSIGNED NOT NULL DEFAULT 0,
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $conn->query("
            CREATE TABLE IF NOT EXISTS literacy_note_shares (
                note_id INT UNSIGNED NOT NULL,
                user_id INT NOT NULL,
                shared_by INT NOT NULL,
                shared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                viewed_at DATETIME NULL,
                PRIMARY KEY (note_id, user_id),
                KEY idx_literacy_shares_user (user_id, shared_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('Learning schema setup failed: ' . $e->getMessage());
    }
}

/** The sign-up answer as 1, 0 or null (not answered / invalid). */
function learning_parse_training($value): ?int
{
    $value = trim((string)$value);
    return $value === '1' ? 1 : ($value === '0' ? 0 : null);
}

function learning_training_label($value): string
{
    if ($value === null || $value === '') {
        return 'Not answered';
    }
    return (int)$value === 1 ? 'Yes' : 'No';
}

function learning_storage_root(): string
{
    return dirname(__DIR__) . '/storage/learning_notes';
}

function learning_ensure_storage(): string
{
    $root = learning_storage_root();
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create the learning notes folder.');
    }
    $guards = [
        dirname($root) . '/.htaccess' => "Require all denied\nDeny from all\n",
        dirname($root) . '/index.html' => '',
        $root . '/index.html' => '',
    ];
    foreach ($guards as $path => $content) {
        if (!is_file($path)) {
            @file_put_contents($path, $content);
        }
    }
    return $root;
}

/** Save an uploaded note; returns its id. Throws DomainException with a message for the user. */
function learning_store_note(mysqli $conn, int $actorId, string $title, string $description, array $file): int
{
    learning_ensure_schema($conn);

    $title = mb_substr(trim(preg_replace('/\s+/', ' ', $title)), 0, 190, 'UTF-8');
    if ($title === '') {
        throw new DomainException('Give the note a title.');
    }
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
        throw new DomainException('Choose a file to upload.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int)$file['size'] > LEARNING_NOTE_MAX_BYTES) {
        throw new DomainException('The file is too large. The maximum size is 15 MB.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new DomainException('The upload did not complete. Please try again.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $ext = LEARNING_NOTE_MIME[$mime] ?? '';
    if ($ext === '') {
        throw new DomainException('Upload a PDF, Word, PowerPoint, JPG or PNG file.');
    }

    $dir = learning_ensure_storage();
    $filename = 'note_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        throw new RuntimeException('move_uploaded_file failed for ' . $filename);
    }

    $original = mb_substr(basename((string)($file['name'] ?? $filename)), 0, 255, 'UTF-8');
    $size = (int)$file['size'];
    $description = mb_substr(trim($description), 0, 2000, 'UTF-8');
    try {
        $stmt = $conn->prepare('INSERT INTO literacy_notes (title, description, file_path, original_name, mime_type, file_size, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssssii', $title, $description, $filename, $original, $mime, $size, $actorId);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
    } catch (Throwable $e) {
        @unlink($dir . '/' . $filename);
        throw $e;
    }
    return $id;
}

function learning_note_find(mysqli $conn, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    learning_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM literacy_notes WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/** All notes, newest first, with how many clients each was sent to. */
function learning_notes_all(mysqli $conn): array
{
    learning_ensure_schema($conn);
    $res = $conn->query("
        SELECT n.*, TRIM(CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, ''))) AS author,
               (SELECT COUNT(*) FROM literacy_note_shares s WHERE s.note_id = n.id) AS shares,
               (SELECT COUNT(*) FROM literacy_note_shares s WHERE s.note_id = n.id AND s.viewed_at IS NOT NULL) AS viewed
        FROM literacy_notes n
        LEFT JOIN user u ON u.id = n.created_by
        ORDER BY n.id DESC
    ");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** Notes sent to one client, newest first. */
function learning_notes_for_client(mysqli $conn, int $userId): array
{
    learning_ensure_schema($conn);
    $stmt = $conn->prepare('
        SELECT n.id, n.title, n.description, n.original_name, n.mime_type, n.file_size, s.shared_at, s.viewed_at
        FROM literacy_note_shares s
        JOIN literacy_notes n ON n.id = s.note_id
        WHERE s.user_id = ?
        ORDER BY s.shared_at DESC
    ');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Client user ids for an audience. $scopeSql limits it (e.g. a loan
 * officer's own clients, from officer_client_sql('c')).
 */
function learning_audience_ids(mysqli $conn, string $audience, string $scopeSql = ''): array
{
    learning_ensure_schema($conn);
    $where = match ($audience) {
        'no_training' => 'c.business_training = 0',
        'unanswered'  => 'c.business_training IS NULL',
        'all'         => '1 = 1',
        default       => '1 = 0',
    };
    $res = $conn->query("
        SELECT DISTINCT c.user_id
        FROM customers c
        JOIN user u ON u.id = c.user_id AND u.role = 3 AND COALESCE(u.status, 1) = 1
        WHERE {$where}{$scopeSql}
    ");
    $ids = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $ids[] = (int)$row['user_id'];
    }
    return $ids;
}

/** How many clients each audience covers (for the send form). */
function learning_audience_counts(mysqli $conn, string $scopeSql = ''): array
{
    $counts = [];
    foreach (array_keys(LEARNING_AUDIENCES) as $audience) {
        $counts[$audience] = count(learning_audience_ids($conn, $audience, $scopeSql));
    }
    return $counts;
}

/**
 * Send a note to clients: record each share (a client is never sent the same
 * note twice) and email the new recipients after the page has responded.
 * Returns how many clients were newly sent the note.
 */
function learning_share_note(mysqli $conn, array $note, array $userIds, int $actorId): int
{
    learning_ensure_schema($conn);
    $noteId = (int)$note['id'];
    $new = [];
    $stmt = $conn->prepare('INSERT IGNORE INTO literacy_note_shares (note_id, user_id, shared_by) VALUES (?, ?, ?)');
    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        if ($userId <= 0) {
            continue;
        }
        $stmt->bind_param('iii', $noteId, $userId, $actorId);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $new[] = $userId;
        }
    }
    $stmt->close();

    if ($new) {
        require_once __DIR__ . '/loan_offers.php';
        require_once __DIR__ . '/loan_notifications.php';
        $title = 'New learning notes: ' . $note['title'];
        $body = "We have shared new financial/business learning notes with you: \"{$note['title']}\".\n\n"
            . (trim((string)$note['description']) !== '' ? trim((string)$note['description']) . "\n\n" : '')
            . "Log in to your Nsimbi account and open Learning Notes to read them:\n"
            . rtrim(learning_base_url(), '/') . "/clients/learning_notes";
        // One job per client: the deferred runner allows ~10 seconds per job.
        foreach ($new as $userId) {
            loan_notify_defer(static function () use ($conn, $userId, $title, $body): void {
                loan_notify_client($conn, $userId, 0, 'learning_note', $title, $body);
            });
        }
    }
    return count($new);
}

function learning_base_url(): string
{
    if (function_exists('app_base_url')) {
        return app_base_url();
    }
    require_once __DIR__ . '/client_documents.php';
    return app_base_url();
}

/** Delete a note, its shares and its file. */
function learning_delete_note(mysqli $conn, int $noteId): void
{
    $note = learning_note_find($conn, $noteId);
    if (!$note) {
        return;
    }
    $stmt = $conn->prepare('DELETE FROM literacy_note_shares WHERE note_id = ?');
    $stmt->bind_param('i', $noteId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('DELETE FROM literacy_notes WHERE id = ?');
    $stmt->bind_param('i', $noteId);
    $stmt->execute();
    $stmt->close();
    $path = learning_note_path($note);
    if ($path !== null) {
        @unlink($path);
    }
}

function learning_note_path(array $note): ?string
{
    $file = (string)$note['file_path'];
    if (!preg_match('/^note_[a-f0-9]{24}\.(pdf|docx?|pptx?|jpg|png)$/', $file)) {
        return null;
    }
    $path = learning_storage_root() . '/' . $file;
    return is_file($path) ? $path : null;
}

/** Send a note's file to the browser and stop. */
function learning_note_stream(array $note, bool $download = false): void
{
    $path = learning_note_path($note);
    if ($path === null) {
        http_response_code(404);
        exit('File not found.');
    }
    $mime = array_key_exists($note['mime_type'], LEARNING_NOTE_MIME) ? $note['mime_type'] : 'application/octet-stream';
    // Only PDFs and images open in the browser; Office files download.
    $inline = !$download && ($mime === 'application/pdf' || str_starts_with($mime, 'image/'));
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($note['original_name'] ?: basename($path)));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; plugin-types application/pdf");
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

function learning_file_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}
