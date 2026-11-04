<?php
declare(strict_types=1);

session_start();

require "db.php";
require_once __DIR__ . "/loan_audit.php";
require_once __DIR__ . '/../php/mail-function.php';

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$role   = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
$actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

$loan_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$loan_id) {
    $loan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
}

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));

// Reviewer-supplied free text. Always optional except where noted per action.
$comments = trim((string)($_POST['comments'] ?? ''));
$rejectionReason = trim((string)($_POST['reason'] ?? ''));
$infoDetails = trim((string)($_POST['info_details'] ?? ''));
$assignedOfficerId = filter_input(INPUT_POST, 'officer_id', FILTER_VALIDATE_INT) ?: 0;

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

if ($actorId <= 0) {
    redirectWithAlert("Your session is missing a user id. Please log in again.");
}

$validActions = ['approve', 'reject', 'request_info'];
if (!in_array($action, $validActions, true)) {
    redirectWithAlert("Invalid action");
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

$currentStatus = (string)($fetch_loan['status'] ?? '');

// A reviewer may act on a loan that is freshly PENDING, or one an officer
// has just finished gathering information for (INFO_PROVIDED).
$reviewableStatuses = ['PENDING', 'INFO_PROVIDED'];
if (!in_array($currentStatus, $reviewableStatuses, true)) {
    redirectWithAlert("This loan is not currently awaiting reviewer action");
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

// ------------------------------------------------------------------
// APPROVE
// ------------------------------------------------------------------
if ($action === 'approve') {
    $approvalDate = new DateTime('now');
    $approvalDate->add(new DateInterval('P' . $loanPeriod . 'M'));
    $dueDate = $approvalDate->format('Y-m-d H:i:s');

    $interestRate = ($loanPeriod === 1) ? 10.0 : 6.6;
    $newStatus = 'OUTSTANDING';

    $stmtUpdate = $conn->prepare("
        UPDATE loan
        SET status = ?,
            interest_rate = ?,
            due_date = ?
        WHERE id = ? AND status IN ('PENDING', 'INFO_PROVIDED')
    ");
    $stmtUpdate->bind_param("sdsi", $newStatus, $interestRate, $dueDate, $loan_id);

    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        redirectWithAlert("Error approving loan");
    }
    $stmtUpdate->close();

    logLoanAudit(
        $conn,
        $loan_id,
        $actorId,
        $role,
        'approved',
        $comments !== '' ? $comments : null,
        null,
        $currentStatus,
        $newStatus
    );

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
            " . ($comments !== '' ? "<p><strong>Reviewer Notes:</strong> " . htmlspecialchars($comments, ENT_QUOTES, 'UTF-8') . "</p>" : "") . "
            <p>Please log in to your account for more details.</p>
            <p>Best regards,<br>Nsimbi Impact Team</p>
        ";

        sendEmail($clientEmail, $subject, $body, strip_tags($body));
    }

    redirectWithAlert("Loan approved successfully", "loans.php");
}

// ------------------------------------------------------------------
// REJECT (reason is mandatory)
// ------------------------------------------------------------------
if ($action === 'reject') {
    if ($rejectionReason === '') {
        redirectWithAlert("A reason for rejection is required");
    }

    $newStatus = 'REJECTED';

    $stmtUpdate = $conn->prepare("
        UPDATE loan
        SET status = ?,
            rejection_reason = ?
        WHERE id = ? AND status IN ('PENDING', 'INFO_PROVIDED')
    ");
    $stmtUpdate->bind_param("ssi", $newStatus, $rejectionReason, $loan_id);

    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        redirectWithAlert("Error rejecting loan");
    }
    $stmtUpdate->close();

    logLoanAudit(
        $conn,
        $loan_id,
        $actorId,
        $role,
        'rejected',
        $comments !== '' ? $comments : null,
        $rejectionReason,
        $currentStatus,
        $newStatus
    );

    // Make sure the assigned loan officer (if any) can see why, too.
    $assignedOfficer = (int)($fetch_loan['assigned_officer_id'] ?? 0);
    if ($assignedOfficer > 0) {
        notifyUser(
            $conn,
            $assignedOfficer,
            $loan_id,
            'loan_rejected',
            "Loan #{$loan_id} was rejected by the reviewer. Reason: {$rejectionReason}"
        );
    }

    if (!empty($clientEmail)) {
        $subject = "Your Loan Request Was Rejected - Nsimbi Impact";
        $body = "
            <h3>Dear " . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . ",</h3>
            <p>We regret to inform you that your loan request of <strong>UGX {$loanAmount}</strong> has been <strong>REJECTED</strong>.</p>
            <p><strong>Reason:</strong> " . htmlspecialchars($rejectionReason, ENT_QUOTES, 'UTF-8') . "</p>
            <p>Please contact support for more information.</p>
            <p>Best regards,<br>Nsimbi Impact Team</p>
        ";

        sendEmail($clientEmail, $subject, $body, strip_tags($body));
    }

    redirectWithAlert("Loan rejected successfully");
}

// ------------------------------------------------------------------
// REQUEST MORE INFORMATION (details + assigned officer are mandatory)
// ------------------------------------------------------------------
if ($action === 'request_info') {
    if ($infoDetails === '') {
        redirectWithAlert("Please describe what additional information is required");
    }

    if ($assignedOfficerId <= 0) {
        redirectWithAlert("Please assign this request to a loan officer");
    }

    $stmtOfficer = $conn->prepare("SELECT id, fname, lname, email FROM user WHERE id = ? AND role = 2 LIMIT 1");
    $stmtOfficer->bind_param("i", $assignedOfficerId);
    $stmtOfficer->execute();
    $officer = $stmtOfficer->get_result()->fetch_assoc();
    $stmtOfficer->close();

    if (!$officer) {
        redirectWithAlert("Selected loan officer was not found");
    }

    $newStatus = 'INFO_REQUESTED';

    $stmtUpdate = $conn->prepare("
        UPDATE loan
        SET status = ?,
            assigned_officer_id = ?,
            info_request_details = ?,
            officer_response = NULL
        WHERE id = ? AND status IN ('PENDING', 'INFO_PROVIDED')
    ");
    $stmtUpdate->bind_param("sisi", $newStatus, $assignedOfficerId, $infoDetails, $loan_id);

    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        redirectWithAlert("Error requesting more information");
    }
    $stmtUpdate->close();

    logLoanAudit(
        $conn,
        $loan_id,
        $actorId,
        $role,
        'info_requested',
        $comments !== '' ? $comments : null,
        null,
        $currentStatus,
        $newStatus
    );

    notifyUser(
        $conn,
        $assignedOfficerId,
        $loan_id,
        'loan_info_requested',
        "You've been asked to gather more information for loan #{$loan_id} ({$clientName}): {$infoDetails}"
    );

    if (!empty($officer['email'])) {
        $subject = "Action Needed: Loan #{$loan_id} Requires More Information";
        $body = "
            <h3>Dear " . htmlspecialchars(trim($officer['fname'] . ' ' . $officer['lname']), ENT_QUOTES, 'UTF-8') . ",</h3>
            <p>A reviewer has requested more information for a pending loan application.</p>
            <p><strong>Client:</strong> " . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</p>
            <p><strong>Loan Amount:</strong> UGX {$loanAmount}</p>
            <p><strong>Information Requested:</strong> " . htmlspecialchars($infoDetails, ENT_QUOTES, 'UTF-8') . "</p>
            " . ($comments !== '' ? "<p><strong>Reviewer Notes:</strong> " . htmlspecialchars($comments, ENT_QUOTES, 'UTF-8') . "</p>" : "") . "
            <p>Please log in to respond and mark this request as complete once resolved.</p>
        ";

        sendEmail($officer['email'], $subject, $body, strip_tags($body));
    }

    redirectWithAlert("Request for more information sent to the loan officer");
}

redirectWithAlert("Invalid action");