<?php
declare(strict_types=1);

/* Shows a client's payment slip to staff who review payments. */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/payment_slips.php';

if (!isset($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), PAYMENT_SLIP_REVIEW_ROLES, true)) {
    http_response_code(403);
    exit('Staff only.');
}

payment_slips_ensure_schema($conn);
require_once dirname(__DIR__) . '/includes/officer_assignment.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT loan_id, slip_path, slip_mime, slip_name FROM payments WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['slip_path'])) {
    http_response_code(404);
    exit('Slip not found.');
}
// Loan officers may only open slips for their own loans.
officer_require_loan($conn, (int)$row['loan_id']);

client_docs_stream([
    'file_path' => (string)$row['slip_path'],
    'mime_type' => (string)$row['slip_mime'],
    'original_name' => (string)($row['slip_name'] ?: 'payment-slip'),
], isset($_GET['download']));
