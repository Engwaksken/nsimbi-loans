<?php
/**
 * Client uploads a credit document (Credit score tab on profile.php).
 * POST: csrf, doc_type (mobile_money|utility_bill|business_records), file.
 * Responds with JSON. The AI reads the document straight away; staff then
 * approve or reject it before the credit score and loan limit change.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require "db.php";
require_once __DIR__ . '/../includes/credit_scoring.php';

header('Content-Type: application/json; charset=utf-8');

function credit_json(bool $ok, string $message, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['success' => $ok, 'message' => $message] + $extra);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
if ($user_id <= 0 || (int)($_SESSION['role'] ?? 0) !== 3) {
    credit_json(false, 'Please log in again.', [], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    credit_json(false, 'Invalid request.', [], 405);
}
if (empty($_SESSION['docs_csrf']) || !hash_equals((string)$_SESSION['docs_csrf'], (string)($_POST['csrf'] ?? ''))) {
    credit_json(false, 'Your session expired. Refresh the page and try again.', [], 419);
}

$docType = (string)($_POST['doc_type'] ?? '');
$file = $_FILES['file'] ?? null;
if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    credit_json(false, 'Please choose a file to upload.', [], 422);
}
$err = (int)$file['error'];
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    credit_json(false, 'The file is too large. The maximum size is 10 MB.', [], 422);
}
if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
    credit_json(false, 'The upload did not complete. Please try again.', [], 422);
}

ignore_user_abort(true);
try {
    $row = credit_evidence_submit(
        $conn,
        $user_id,
        $docType,
        (string)file_get_contents((string)$file['tmp_name']),
        (string)($file['name'] ?? ''),
        'web'
    );
} catch (DomainException $e) {
    credit_json(false, $e->getMessage(), [], 422);
} catch (Throwable $e) {
    error_log('clients/credit_evidence_action: ' . $e->getMessage());
    credit_json(false, 'The document could not be uploaded. Please try again.', [], 500);
}

$status = (string)($row['status'] ?? '');
credit_json(true, $status === 'failed'
    ? 'Your document was uploaded, but it could not be read automatically. Our team will look at it.'
    : 'Thank you. Your document has been read and sent to our team for review. Your credit score will update once it is approved.',
    ['document' => credit_evidence_public($row)]
);
