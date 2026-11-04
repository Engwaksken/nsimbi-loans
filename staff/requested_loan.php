<?php
session_start();
require 'db.php';
require_once __DIR__ . '/../php/mail-function.php';

date_default_timezone_set('Africa/Nairobi');

$client_id = $_SESSION['logged_in'] ?? '';
$fname = $_SESSION['fname'] ?? '';
$lname = $_SESSION['lname'] ?? '';
$userName = trim("$fname $lname");
$userEmail = $_SESSION['email'] ?? '';

$amount = floatval($_POST['amount'] ?? 0);
$loan_period = intval($_POST['loan_period'] ?? 0);
$loan_method = $_POST['loan_method'] ?? '';
$phone = $_POST['phone'] ?? '';
$bank_name = $_POST['bank_name'] ?? '';
$bank_account = $_POST['bank_account'] ?? '';

function redirect_with_message($message, $type = 'error', $location = 'requesting_loan.php') {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    header("Location: $location");
    exit;
}

if (!$client_id || $amount <= 0 || !$loan_period || !$loan_method) {
    redirect_with_message("Invalid input. Please try again.");
}

$get_client = $conn->query("SELECT * FROM members WHERE user_id='$client_id'");
if ($get_client->num_rows === 0) {
    redirect_with_message("Client not found.");
}
$client = $get_client->fetch_assoc();

if ($amount > $client['loan_limit']) {
    redirect_with_message("FAILED. Loan limit exceeded.");
}

$get_loan = $conn->query("SELECT * FROM loan WHERE client_id='$client_id' ORDER BY id DESC LIMIT 1");
if ($get_loan->num_rows > 0) {
    $latest_loan = $get_loan->fetch_assoc();
    if ($latest_loan['status'] !== "CLEARED") {
        redirect_with_message("FAILED. You have an uncleared loan.");
    }
}

// Calculate interest and due date
$interest_rate = 10;
$interest = $amount * ($interest_rate / 100);
$loan_balance = $amount + $interest;
$status = "PENDING";

$due_date = (new DateTime())->modify("+$loan_period months")->format("Y-m-d H:i:s");

// Insert loan
$stmt = $conn->prepare("INSERT INTO loan (amount, interest_rate, client_id, status, interest, due_date, loan_balance, loan_period, loan_method, phone, bank_name, bank_account)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("dddsssdsssss", $amount, $interest_rate, $client_id, $status, $interest, $due_date, $loan_balance, $loan_period, $loan_method, $phone, $bank_name, $bank_account);

if ($stmt->execute()) {
    // Log message
    $msg_stmt = $conn->prepare("INSERT INTO loan_messages (user_id, sender_name, message_type, is_read, created_at)
    VALUES (?, ?, 'loan_request', 0, NOW())");
    $msg_stmt->bind_param("is", $client_id, $userName);
    $msg_stmt->execute();

    // Send notification email
    sendAdminLoanRequest($userName, $userEmail, $conn);

    redirect_with_message("LOAN SUCCESSFULLY REQUESTED", 'success', 'pending_loans.php');
} else {
    redirect_with_message("Loan request failed. Please try again.");
}
?>
