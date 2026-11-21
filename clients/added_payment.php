<?php
/**
 * Client-submitted payment (from view_loan.php).
 *
 * Records a PENDING payment with the transaction number and the payment slip
 * for a loan officer to check on admin/payment_slips.php. The loan balance is
 * NOT reduced here; approving the slip does that. The client is always the
 * logged-in user, never a form field.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require "db.php";
require_once __DIR__ . '/../includes/payment_slips.php';

date_default_timezone_set('Africa/Nairobi');

$loan_id = (int)($_POST['loan'] ?? 0);

function back(string $message, string $type = 'error', int $loan_id = 0): void
{
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    header('Location: ' . ($loan_id > 0 ? 'view_loan.php?id=' . $loan_id : 'my_loans.php'));
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
$user_role = (int)($_SESSION['role'] ?? 0);

if ($user_id <= 0 || $user_role !== 3) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('Please use the payment form.', 'error', $loan_id);
}

$raw_amount = preg_replace('/[^\d.]/', '', (string)($_POST['amount'] ?? ''));
$amount     = is_numeric($raw_amount) ? round((float)$raw_amount, 2) : 0.0;
$reference  = trim((string)($_POST['rec'] ?? ''));

if ($loan_id <= 0) {
    back('Loan not specified.');
}
if ($reference === '' || strlen($reference) > 100) {
    back('Please enter the transaction number from your payment.', 'error', $loan_id);
}

$slip = $_FILES['slip'] ?? null;
if (!is_array($slip) || (int)($slip['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    back('Please attach your payment slip.', 'error', $loan_id);
}
if ((int)$slip['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$slip['tmp_name'])) {
    back('The payment slip did not upload. Please try again (max 5 MB).', 'error', $loan_id);
}

try {
    payment_slip_submit(
        $conn,
        $user_id,
        $loan_id,
        $amount,
        (string)($_POST['payment_method'] ?? 'mobile_money'),
        $reference,
        (string)file_get_contents((string)$slip['tmp_name']),
        (string)($slip['name'] ?? ''),
        'web'
    );
} catch (DomainException $e) {
    back($e->getMessage(), 'error', $loan_id);
} catch (Throwable $e) {
    error_log('Client payment submit failed: ' . $e->getMessage());
    back('Payment could not be submitted. Please try again.', 'error', $loan_id);
}

back('Payment submitted. A loan officer will check your slip and update your balance.', 'success', $loan_id);
