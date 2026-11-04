<?php
declare(strict_types=1);

session_start();

require "db.php";

header("Content-Type: application/json");

function json_response(bool $success, string $message): void
{
    echo json_encode([
        "success" => $success,
        "message" => $message
    ]);
    exit;
}

if (empty($_SESSION['logged_in'])) {
    json_response(false, "Unauthorized access.");
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    json_response(false, "Database connection not found.");
}

$conn->set_charset("utf8mb4");

$client_id = (int)$_SESSION['logged_in'];
$loan_id = (int)($_POST['loan_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$loan_period = (int)($_POST['loan_period'] ?? 0);

if ($loan_id <= 0) {
    json_response(false, "Invalid loan selected.");
}

if ($amount <= 0) {
    json_response(false, "Loan amount is required.");
}

if ($loan_period <= 0) {
    json_response(false, "Loan period is required.");
}

$stmt = $conn->prepare("
    SELECT id
    FROM loan
    WHERE id = ?
    AND client_id = ?
    AND UPPER(status) = 'PENDING'
    LIMIT 1
");
$stmt->bind_param("ii", $loan_id, $client_id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    json_response(false, "Loan not found or cannot be edited.");
}

$interest_rate = 10.0;
$interest = $amount * ($interest_rate / 100);
$loan_balance = $amount + $interest;

$now = date("Y-m-d H:i:s");
$due_date = (new DateTime())->modify("+{$loan_period} months")->format("Y-m-d H:i:s");

$stmt = $conn->prepare("
    UPDATE loan
    SET 
        amount = ?,
        interest_rate = ?,
        interest = ?,
        loan_period = ?,
        loan_balance = ?,
        principal_balance = ?,
        interest_balance = ?,
        outstanding_balance = ?,
        datetime = ?,
        updated_at = ?,
        due_date = ?
    WHERE id = ?
    AND client_id = ?
    AND UPPER(status) = 'PENDING'
");
$stmt->bind_param(
    "dddiddddsssii",
    $amount,
    $interest_rate,
    $interest,
    $loan_period,
    $loan_balance,
    $amount,
    $interest,
    $loan_balance,
    $now,
    $now,
    $due_date,
    $loan_id,
    $client_id
);

if (!$stmt->execute()) {
    json_response(false, "Failed to update loan.");
}

$stmt->close();

json_response(true, "Loan updated successfully.");