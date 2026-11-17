<?php
/**
 * Client supporting documents and loan-limit review requests.
 *
 * Files are stored in /storage/client_documents/{user_id}/ (web access denied
 * by .htaccess) and are only served through clients/document.php (owner) and
 * admin/client_document.php (staff). Uploads start as "pending" until staff
 * review them on admin/view_client.php.
 *
 * Tables are created on first use; see admin/client-documents-and-mail.sql.
 */

const CLIENT_DOC_MAX_BYTES = 5 * 1024 * 1024;
const CLIENT_DOC_MIME = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];

/**
 * Document types. "legacy" is the customers column staff fill in at
 * registration; a file there counts as already on file.
 */
function client_doc_types(): array
{
    return [
        'id_front' => [
            'label' => 'National ID (front)', 'icon' => 'fa-id-card', 'legacy' => 'id_front', 'required' => true,
            'hint'  => 'A clear photo of the front of your National ID.',
        ],
        'id_back' => [
            'label' => 'National ID (back)', 'icon' => 'fa-id-card-alt', 'legacy' => 'id_back', 'required' => true,
            'hint'  => 'A clear photo of the back of your National ID.',
        ],
        'business_photo' => [
            'label' => 'Business photo', 'icon' => 'fa-store', 'legacy' => 'stock', 'required' => true,
            'hint'  => 'Your shop, stall or stock, showing the business in operation.',
        ],
        'trading_license' => [
            'label' => 'Trading licence', 'icon' => 'fa-certificate', 'legacy' => 'license', 'required' => false,
            'hint'  => 'Trading licence or business registration certificate.',
        ],
        'other' => [
            'label' => 'Other document', 'icon' => 'fa-file-alt', 'legacy' => null, 'required' => false,
            'hint'  => 'Bank or Mobile Money statements, receipts, land titles, etc.',
        ],
    ];
}

function client_docs_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS client_documents (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            doc_type VARCHAR(40) NOT NULL,
            title VARCHAR(150) NOT NULL DEFAULT '',
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL DEFAULT '',
            mime_type VARCHAR(100) NOT NULL DEFAULT '',
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            review_note VARCHAR(255) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_client_documents_user (user_id),
            KEY idx_client_documents_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS limit_review_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            current_limit DECIMAL(15,2) NOT NULL DEFAULT 0,
            requested_limit DECIMAL(15,2) NOT NULL DEFAULT 0,
            approved_limit DECIMAL(15,2) NULL,
            reason TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            staff_note VARCHAR(255) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_limit_review_user (user_id),
            KEY idx_limit_review_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $done = true;
}

function client_docs_storage_root(): string
{
    return dirname(__DIR__) . '/storage/client_documents';
}

/** Create the storage folder and lock it against direct web access. */
function client_docs_ensure_storage(int $userId): string
{
    $root = client_docs_storage_root();
    $dir = $root . '/' . $userId;

    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create document storage folder.');
    }

    $guards = [
        dirname($root) . '/.htaccess' => "Require all denied\nDeny from all\n",
        dirname($root) . '/index.html' => '',
        $root . '/index.html' => '',
        $dir . '/index.html' => '',
    ];
    foreach ($guards as $path => $content) {
        if (!is_file($path)) {
            @file_put_contents($path, $content);
        }
    }

    return $dir;
}

function client_docs_for_user(mysqli $conn, int $userId): array
{
    client_docs_ensure_schema($conn);

    $stmt = $conn->prepare("SELECT * FROM client_documents WHERE user_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * Per required/standard type: the newest upload (if any) and whether a file
 * from registration is already on the customer record.
 */
function client_docs_summary(array $documents, array $customer): array
{
    $summary = [];

    foreach (client_doc_types() as $type => $meta) {
        if ($type === 'other') {
            continue;
        }

        $latest = null;
        foreach ($documents as $doc) {
            if ($doc['doc_type'] === $type) {
                $latest = $doc;
                break;
            }
        }

        $legacy = $meta['legacy'] ? trim((string)($customer[$meta['legacy']] ?? '')) : '';

        if ($latest && $latest['status'] === 'approved') {
            $state = 'approved';
        } elseif ($latest && $latest['status'] === 'pending') {
            $state = 'pending';
        } elseif ($legacy !== '') {
            $state = 'on_file';
        } elseif ($latest && $latest['status'] === 'rejected') {
            $state = 'rejected';
        } else {
            $state = 'missing';
        }

        $summary[$type] = $meta + ['state' => $state, 'latest' => $latest, 'has_legacy' => $legacy !== ''];
    }

    return $summary;
}

/** Required documents that are neither on file nor uploaded (rejected counts as missing). */
function client_docs_missing_required(array $summary): array
{
    $missing = [];
    foreach ($summary as $type => $item) {
        if ($item['required'] && in_array($item['state'], ['missing', 'rejected'], true)) {
            $missing[$type] = $item['label'];
        }
    }
    return $missing;
}

/**
 * Validate and store an uploaded file. Throws DomainException with a message
 * that is safe to show the client.
 */
function client_docs_store_upload(mysqli $conn, int $userId, string $type, string $title, array $file): int
{
    client_docs_ensure_schema($conn);

    $types = client_doc_types();
    if (!isset($types[$type])) {
        throw new DomainException('Please choose what kind of document this is.');
    }

    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
        throw new DomainException('Please choose a file to upload.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int)$file['size'] > CLIENT_DOC_MAX_BYTES) {
        throw new DomainException('The file is too large. The maximum size is 5 MB.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new DomainException('The upload did not complete. Please try again.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $ext = CLIENT_DOC_MIME[$mime] ?? '';
    if ($ext === '') {
        throw new DomainException('Only JPG, PNG, WebP images or PDF files are accepted.');
    }

    $title = trim(preg_replace('/\s+/', ' ', $title));
    if ($type === 'other' && $title === '') {
        throw new DomainException('Please give this document a short title, e.g. "Bank statement March".');
    }
    if ($title === '') {
        $title = $types[$type]['label'];
    }
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 150) : substr($title, 0, 150);

    $dir = client_docs_ensure_storage($userId);
    $filename = $type . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        throw new RuntimeException('move_uploaded_file failed for ' . $filename);
    }

    $relative = $userId . '/' . $filename;
    $original = basename((string)($file['name'] ?? $filename));
    $original = function_exists('mb_substr') ? mb_substr($original, 0, 255) : substr($original, 0, 255);
    $size = (int)$file['size'];

    try {
        $stmt = $conn->prepare("
            INSERT INTO client_documents (user_id, doc_type, title, file_path, original_name, mime_type, file_size, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param('isssssi', $userId, $type, $title, $relative, $original, $mime, $size);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
    } catch (Throwable $e) {
        @unlink($dir . '/' . $filename);
        throw $e;
    }

    return $id;
}

function client_docs_find(mysqli $conn, int $docId): ?array
{
    client_docs_ensure_schema($conn);

    $stmt = $conn->prepare("SELECT * FROM client_documents WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $docId);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $doc ?: null;
}

/** Clients may only remove their own uploads that are still pending. */
function client_docs_delete_pending(mysqli $conn, int $userId, int $docId): void
{
    $doc = client_docs_find($conn, $docId);
    if (!$doc || (int)$doc['user_id'] !== $userId) {
        throw new DomainException('Document not found.');
    }
    if ($doc['status'] !== 'pending') {
        throw new DomainException('Only documents that are still pending review can be removed.');
    }

    $stmt = $conn->prepare("DELETE FROM client_documents WHERE id = ? AND user_id = ? AND status = 'pending'");
    $stmt->bind_param('ii', $docId, $userId);
    $stmt->execute();
    $stmt->close();

    $path = client_docs_file_path($doc);
    if ($path !== null) {
        @unlink($path);
    }
}

/** Absolute path to a stored file, or null if it is missing or outside storage. */
function client_docs_file_path(array $doc): ?string
{
    $relative = (string)$doc['file_path'];
    if (!preg_match('#^\d+/[a-z_]+_[a-f0-9]{24}\.(jpg|png|webp|pdf)$#', $relative)) {
        return null;
    }
    $path = client_docs_storage_root() . '/' . $relative;
    return is_file($path) ? $path : null;
}

/** Send a stored document to the browser and stop. */
function client_docs_stream(array $doc, bool $download = false): void
{
    $path = client_docs_file_path($doc);
    if ($path === null) {
        http_response_code(404);
        exit('File not found.');
    }

    $mime = array_key_exists($doc['mime_type'], CLIENT_DOC_MIME) ? $doc['mime_type'] : 'application/octet-stream';
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($doc['original_name'] ?: basename($path)));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; plugin-types application/pdf");
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

/* ---------------------------------------------------------------------
 * Loan limit review requests
 * ------------------------------------------------------------------- */

function client_limit_requests(mysqli $conn, int $userId): array
{
    client_docs_ensure_schema($conn);

    $stmt = $conn->prepare("SELECT * FROM limit_review_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function client_limit_pending(array $requests): ?array
{
    foreach ($requests as $request) {
        if ($request['status'] === 'pending') {
            return $request;
        }
    }
    return null;
}

function client_limit_submit(mysqli $conn, int $userId, float $currentLimit, float $requested, string $reason): int
{
    client_docs_ensure_schema($conn);

    $stmt = $conn->prepare("
        INSERT INTO limit_review_requests (user_id, current_limit, requested_limit, reason, status)
        VALUES (?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param('idds', $userId, $currentLimit, $requested, $reason);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    return $id;
}

/** Loan officer assigned to the client's latest loan, else 0. */
function client_assigned_officer(mysqli $conn, int $userId): int
{
    if (!function_exists('loan_table_has_column') || !loan_table_has_column($conn, 'loan', 'assigned_officer_id')) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT assigned_officer_id FROM loan
        WHERE client_id = ? AND assigned_officer_id IS NOT NULL AND assigned_officer_id > 0
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['assigned_officer_id'] ?? 0);
}

/**
 * Email the client's officer (or every admin/officer when none is assigned)
 * that something needs review. Never throws.
 */
function client_docs_notify_staff(mysqli $conn, int $userId, string $subject, string $html): void
{
    try {
        if (!function_exists('sendEmail')) {
            require_once dirname(__DIR__) . '/php/mail-function.php';
        }

        $officer = client_assigned_officer($conn, $userId);
        if ($officer > 0) {
            $stmt = $conn->prepare("SELECT email FROM user WHERE id = ? AND email <> '' LIMIT 1");
            $stmt->bind_param('i', $officer);
        } else {
            $stmt = $conn->prepare("SELECT email FROM user WHERE role IN (1, 2) AND email <> '' LIMIT 20");
        }
        $stmt->execute();
        $emails = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'email');
        $stmt->close();

        foreach (array_unique($emails) as $email) {
            sendEmail($email, $subject, $html, strip_tags($html));
        }
    } catch (Throwable $e) {
        error_log('client_docs_notify_staff failed: ' . $e->getMessage());
    }
}

/** Email the client about a review decision. Never throws. */
function client_docs_notify_client(mysqli $conn, int $userId, string $subject, string $html): void
{
    try {
        if (!function_exists('sendEmail')) {
            require_once dirname(__DIR__) . '/php/mail-function.php';
        }

        $stmt = $conn->prepare("SELECT email FROM user WHERE id = ? AND email <> '' LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $email = (string)($stmt->get_result()->fetch_assoc()['email'] ?? '');
        $stmt->close();

        if ($email !== '') {
            sendEmail($email, $subject, $html, strip_tags($html));
        }
    } catch (Throwable $e) {
        error_log('client_docs_notify_client failed: ' . $e->getMessage());
    }
}

/** Base URL of the app (handles installs in a sub-folder such as /loans). */
function app_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    // Scripts live one folder below the app root (clients/, admin/, staff/).
    $root = rtrim(dirname(dirname($script)), '/');

    return ($https ? 'https' : 'http') . '://' . $host . ($root === '' || $root === '.' ? '' : $root);
}
