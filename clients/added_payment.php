<?php
/**
 * Client-submitted payment (from view_loan.php).
 *
 * Records a PENDING payment with the client's transaction number for staff
 * to confirm. The loan balance is NOT reduced here; staff confirmation does
 * that. The client is always the logged-in user, never a form field.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require "db.php";
require_once __DIR__ . '/../includes/loan_workflow.php';

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
if ($amount <= 0) {
    back('Please enter a valid amount.', 'error', $loan_id);
}
if ($reference === '' || strlen($reference) > 100) {
    back('Please enter the transaction number from your payment.', 'error', $loan_id);
}

try {
    $stmt = $conn->prepare("SELECT id, status, loan_balance FROM loan WHERE id = ? AND client_id = ? LIMIT 1");
    $stmt->bind_param("ii", $loan_id, $user_id);
    $stmt->execute();
    $loan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$loan) {
        back('Loan not found.');
    }
    if (!in_array(strtoupper((string)$loan['status']), ['OUTSTANDING', 'OVERDUE'], true)) {
        back('Payments can only be made on outstanding or overdue loans.', 'error', $loan_id);
    }
    if ($amount > (float)$loan['loan_balance']) {
        back('Amount is more than your balance of UGX ' . number_format((float)$loan['loan_balance'], 2) . '.', 'error', $loan_id);
    }

    $ref_col = loan_table_has_column($conn, 'payments', 'transaction_reference') ? 'transaction_reference'
        : (loan_table_has_column($conn, 'payments', 'reference') ? 'reference' : null);

    // Reject the same transaction number being submitted twice.
    if ($ref_col !== null) {
        $stmt = $conn->prepare("SELECT 1 FROM payments WHERE loan_id = ? AND {$ref_col} = ? LIMIT 1");
        $stmt->bind_param("is", $loan_id, $reference);
        $stmt->execute();
        $duplicate = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($duplicate) {
            back('This transaction number has already been submitted.', 'error', $loan_id);
        }
    }

    $status  = 'PENDING';
    $now     = date('Y-m-d H:i:s');
    $columns = ['loan_id', 'amount'];
    $types   = 'id';
    $values  = [$loan_id, $amount];

    $optional = [
        'user_id'   => ['i', $user_id],
        'client_id' => ['i', $user_id],
        'status'    => ['s', $status],
        'datetime'  => ['s', $now],
    ];
    foreach ($optional as $column => [$type, $value]) {
        if (loan_table_has_column($conn, 'payments', $column)) {
            $columns[] = $column;
            $types    .= $type;
            $values[]  = $value;
        }
    }
    if ($ref_col !== null) {
        $columns[] = $ref_col;
        $types    .= 's';
        $values[]  = $reference;
    }

    $stmt = $conn->prepare(
        "INSERT INTO payments (" . implode(', ', $columns) . ") VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")"
    );
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log('Client payment submit failed: ' . $e->getMessage());
    back('Payment could not be submitted. Please try again.', 'error', $loan_id);
}

back('Payment submitted. It will reflect once our team confirms it.', 'success', $loan_id);
