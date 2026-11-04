<?php
declare(strict_types=1);

session_start();

require "db.php";
require_once __DIR__ . "/loan_audit.php";
require_once __DIR__ . '/../php/mail-function.php';

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Assumption: role = 5 is "Accountant" — the only role allowed to disburse funds.
$role    = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
$actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

$loan_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$loan_id) {
    $loan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
}

$method    = trim((string)($_POST['method'] ?? ''));
$reference = trim((string)($_POST['reference'] ?? ''));
$notes     = trim((string)($_POST['notes'] ?? ''));

$allowedMethods = ['cash', 'bank_transfer', 'mobile_money'];

function redirectWithAlert(string $message, string $url = 'disbursement.php'): void
{
    $safeMsg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

    echo "<script>
        alert('{$safeMsg}');
        window.location.replace('{$safeUrl}');
    </script>";
    exit;
}

if (!$loan_id || $loan_id <= 0) {
    redirectWithAlert("Invalid Loan ID");
}

if ($role !== 5) {
    redirectWithAlert("Not Authorized for this Action");
}

if ($actorId <= 0) {
    redirectWithAlert("Your session is missing a user id. Please log in again.");
}

if (!in_array($method, $allowedMethods, true)) {
    redirectWithAlert("Please select a valid disbursement method");
}

$stmt = $conn->prepare("SELECT * FROM loan WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$result = $stmt->get_result();
$fetch_loan = $result->fetch_assoc();
$stmt->close();

if (!$fetch_loan) {
    redirectWithAlert("Loan not found");
}

if (($fetch_loan['status'] ?? '') !== 'APPROVED') {
    redirectWithAlert("This loan is not awaiting disbursement");
}

$client_id  = (int)$fetch_loan['client_id'];
$loanPeriod = max(1, (int)$fetch_loan['loan_period']);
$amountRaw  = (float)$fetch_loan['amount'];
$loanAmount = number_format($amountRaw, 2);

$stmtClient = $conn->prepare("SELECT fname, lname, email FROM user WHERE id = ? LIMIT 1");
$stmtClient->bind_param("i", $client_id);
$stmtClient->execute();
$client = $stmtClient->get_result()->fetch_assoc();
$stmtClient->close();

$clientName  = $client ? trim($client['fname'] . ' ' . $client['lname']) : 'Client';
$clientEmail = $client['email'] ?? '';

// The loan term starts now — funds are actually moving today.
$disbursedAt = new DateTime('now');
$dueDate = (clone $disbursedAt)->add(new DateInterval('P' . $loanPeriod . 'M'))->format('Y-m-d H:i:s');
$disbursedAtStr = $disbursedAt->format('Y-m-d H:i:s');

if ($reference === '') {
    $reference = 'DISB-' . $loan_id . '-' . $disbursedAt->format('YmdHis');
}

$newStatus = 'OUTSTANDING';

$stmtUpdate = $conn->prepare("
    UPDATE loan
    SET status = ?,
        due_date = ?,
        disbursed_at = ?,
        disbursed_by = ?,
        disbursement_method = ?,
        disbursement_reference = ?,
        disbursement_notes = ?
    WHERE id = ? AND status = 'APPROVED'
");
$stmtUpdate->bind_param(
    "sssisssi",
    $newStatus,
    $dueDate,
    $disbursedAtStr,
    $actorId,
    $method,
    $reference,
    $notes,
    $loan_id
);

if (!$stmtUpdate->execute()) {
    $stmtUpdate->close();
    redirectWithAlert("Error recording disbursement");
}
$stmtUpdate->close();

logLoanAudit(
    $conn,
    $loan_id,
    $actorId,
    $role,
    'disbursed',
    $notes !== '' ? $notes : null,
    null,
    'APPROVED',
    $newStatus
);

if (!empty($clientEmail)) {
    $methodLabel = match ($method) {
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'mobile_money'  => 'Mobile Money',
        default         => ucfirst($method),
    };

    $subject = "Your Loan Has Been Disbursed - Nsimbi Impact";
    $body = "
        <h3>Dear " . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . ",</h3>
        <p>Your loan of <strong>UGX {$loanAmount}</strong> has been <strong>DISBURSED</strong>.</p>
        <p><strong>Method:</strong> {$methodLabel}</p>
        <p><strong>Reference:</strong> " . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . "</p>
        <p><strong>Due Date:</strong> {$dueDate}</p>
        <p>Please log in to your account for your disbursement voucher and repayment schedule.</p>
        <p>Best regards,<br>Nsimbi Impact Team</p>
    ";

    sendEmail($clientEmail, $subject, $body, strip_tags($body));
}

redirectWithAlert("Loan #{$loan_id} disbursed successfully", "disbursement.php");