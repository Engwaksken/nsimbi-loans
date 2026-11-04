<?php
declare(strict_types=1);

session_start();

require "db.php";
require_once __DIR__ . '/../php/mail-function.php';

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;

$loan_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$loan_id) {
    $loan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
}

$action = strtolower(trim($_POST['action'] ?? $_GET['action'] ?? 'approve'));

function redirectWithAlert(string $message, string $url = 'pending_loans.php'): void
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

if ($role !== 1) {
    redirectWithAlert("Not Authorized for this Action");
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

if (($fetch_loan['status'] ?? '') !== 'PENDING') {
    redirectWithAlert("Invalid action or loan status");
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

$redirect = "pending_loans.php";

if ($action === 'approve') {
    $approvalDate = new DateTime('now');
    $approvalDate->add(new DateInterval('P' . $loanPeriod . 'M'));
    $dueDate = $approvalDate->format('Y-m-d H:i:s');

    $interestRate = ($loanPeriod === 1) ? 10.0 : 6.6;

    $stmtUpdate = $conn->prepare("
        UPDATE loan
        SET status = 'OUTSTANDING',
            interest_rate = ?,
            due_date = ?
        WHERE id = ? AND status = 'PENDING'
    ");
    $stmtUpdate->bind_param("dsi", $interestRate, $dueDate, $loan_id);

    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        redirectWithAlert("Error approving loan");
    }

    $stmtUpdate->close();

    $stmtMsg = $conn->prepare("
        UPDATE loan_messages
        SET message_type = 'loan_approved',
            is_read = 1
        WHERE user_id = ? AND message_type = 'loan_request'
    ");
    if ($stmtMsg) {
        $stmtMsg->bind_param("i", $client_id);
        $stmtMsg->execute();
        $stmtMsg->close();
    }

    if (!empty($clientEmail)) {
        $subject = "Your Loan Has Been Approved - Nsimbi Impact";
        $body = "
            <h3>Dear " . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . ",</h3>
            <p>Your loan request of <strong>UGX {$loanAmount}</strong> has been <strong>APPROVED</strong>.</p>
            <p><strong>Interest Rate:</strong> {$interestRate}%</p>
            <p><strong>Due Date:</strong> {$dueDate}</p>
            <p>Please log in to your account for more details.</p>
            <p>Best regards,<br>Nsimbi Impact Team</p>
        ";

        sendEmail($clientEmail, $subject, $body, strip_tags($body));
    }

    redirectWithAlert("Loan approved successfully", "loans.php");
}

if ($action === 'reject') {
    $stmtUpdate = $conn->prepare("
        UPDATE loan
        SET status = 'REJECTED'
        WHERE id = ? AND status = 'PENDING'
    ");
    $stmtUpdate->bind_param("i", $loan_id);

    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        redirectWithAlert("Error rejecting loan");
    }

    $stmtUpdate->close();

    if (!empty($clientEmail)) {
        $subject = "Your Loan Request Was Rejected - Nsimbi Impact";
        $body = "
            <h3>Dear " . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . ",</h3>
            <p>We regret to inform you that your loan request of <strong>UGX {$loanAmount}</strong> has been <strong>REJECTED</strong>.</p>
            <p>Please contact support for more information.</p>
            <p>Best regards,<br>Nsimbi Impact Team</p>
        ";

        sendEmail($clientEmail, $subject, $body, strip_tags($body));
    }

    redirectWithAlert("Loan rejected successfully");
}

redirectWithAlert("Invalid action");
?>