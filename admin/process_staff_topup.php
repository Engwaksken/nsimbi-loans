<?php
require "db.php";
require "services_check.php";

date_default_timezone_set('Africa/Nairobi');


function clean_amount($value) {
    $value = trim((string)$value);
    if ($value === '') return false;
    // "1,200,000" or "1 200 000" -> "1200000"
    $clean = str_replace([',', ' '], '', $value);
    return is_numeric($clean) ? (float)$clean : false;
}


$staffId    = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$newAmount  = clean_amount($_POST['amount'] ?? '');
$newPeriod  = isset($_POST['loan_period']) ? (int)$_POST['loan_period'] : 0;


$newRate = clean_amount($_POST['interest'] ?? '');
if ($newRate === false) $newRate = 0.0;

$loanDate = !empty($_POST['loan_date']) ? $_POST['loan_date'] : date("Y-m-d H:i");

if ($staffId <= 0 || $newAmount === false || $newAmount <= 0 || $newPeriod <= 0) {
    echo "<script>alert('Invalid loan top-up request.'); window.location='staff_loans.php';</script>";
    exit;
}


$newInterest = $newAmount * ($newRate / 100);


$loanSql = "
    SELECT *
    FROM loan
    WHERE client_id = ? AND status = 'OUTSTANDING'
    ORDER BY id DESC
    LIMIT 1
";
$loanStmt = $conn->prepare($loanSql);
$loanStmt->bind_param("i", $staffId);
$loanStmt->execute();
$loan = $loanStmt->get_result()->fetch_assoc();
$loanStmt->close();

if (!$loan) {
    echo "<script>alert('No outstanding loan found for this staff.'); window.location='staff_loans.php';</script>";
    exit;
}

$loanId       = (int)$loan['id'];
$oldAmount    = (float)$loan['amount'];
$oldBalance   = (float)$loan['loan_balance'];
$oldInterest  = (float)$loan['interest'];
$oldDueDate   = $loan['due_date'];


$totalAmount   = $oldAmount + $newAmount;
$totalInterest = $oldInterest + $newInterest;


$newBalance    = $newAmount + $oldBalance + $newInterest;

$dueDateObj = new DateTime($oldDueDate);
$dueDateObj->modify("+{$newPeriod} months");
$newDueDate = $dueDateObj->format("Y-m-d H:i");


$clientSql  = "SELECT loan_limit FROM members WHERE user_id = ? LIMIT 1";
$clientStmt = $conn->prepare($clientSql);
$clientStmt->bind_param("i", $staffId);
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();
$clientStmt->close();

if (!$client) {
    echo "<script>alert('Client information not found.'); window.location='staff_loans.php';</script>";
    exit;
}

$loanLimit = (float)$client['loan_limit'];

if ($newAmount > $loanLimit) {
    echo "<script>alert('FAILED: Top-up exceeds staff loan limit.'); window.location='staff_loan_topup.php?id={$staffId}';</script>";
    exit;
}

$updateSql = "
    UPDATE loan SET
        amount = ?,
        interest_rate = ?,
        interest = ?,
        loan_period = loan_period + ?,
        due_date = ?,
        loan_balance = ?,
        datetime = ?,
        updated_at = NOW()
    WHERE id = ?
";

$updateStmt = $conn->prepare($updateSql);
$updateStmt->bind_param(
    "dddisdsi",
    $totalAmount,
    $newRate,
    $totalInterest,
    $newPeriod,
    $newDueDate,
    $newBalance,
    $loanDate,
    $loanId
);

if ($updateStmt->execute()) {
    echo "<script>alert('Loan top-up successfully applied.'); window.location='staff_loans.php';</script>";
} else {
    echo "<script>alert('Top-up failed. Please try again or contact support.'); window.location='staff_loan_topup.php?id={$staffId}';</script>";
}

$updateStmt->close();
$conn->close();
?>
