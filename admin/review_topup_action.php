<?php
declare(strict_types=1);

session_start();

require "db.php";
require_once __DIR__ . "/loan_audit.php";
require_once __DIR__ . '/../php/mail-function.php';

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$role    = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
$actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

$topupId = filter_input(INPUT_POST, 'topup_id', FILTER_VALIDATE_INT);
if (!$topupId) {
    $topupId = filter_input(INPUT_GET, 'topup_id', FILTER_VALIDATE_INT);
}

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));

$comments           = trim((string)($_POST['comments'] ?? ''));
$rejectionReason     = trim((string)($_POST['reason'] ?? ''));
$infoDetails         = trim((string)($_POST['info_details'] ?? ''));
$assignedOfficerId   = filter_input(INPUT_POST, 'officer_id', FILTER_VALIDATE_INT) ?: 0;

function redirectWithAlert(string $message, string $url = 'pending_topups.php'): void
{
    $safeMsg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

    echo "<script>
        alert('{$safeMsg}');
        window.location.replace('{$safeUrl}');
    </script>";
    exit;
}

if (!$topupId || $topupId <= 0) {
    redirectWithAlert("Invalid top-up request ID");
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

$stmt = $conn->prepare("SELECT * FROM loan_topup_requests WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $topupId);
$stmt->execute();
$topup = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$topup) {
    redirectWithAlert("Top-up request not found");
}

$loanId = (int)$topup['loan_id'];
$currentStatus = (string)($topup['status'] ?? '');

$reviewableStatuses = ['PENDING', 'INFO_PROVIDED'];
if (!in_array($currentStatus, $reviewableStatuses, true)) {
    redirectWithAlert("This top-up request is not currently awaiting reviewer action");
}

$clientId = (int)$topup['client_id'];

$stmtClient = $conn->prepare("SELECT fname, lname, email FROM user WHERE id = ? LIMIT 1");
$stmtClient->bind_param("i", $clientId);
$stmtClient->execute();
$client = $stmtClient->get_result()->fetch_assoc();
$stmtClient->close();

$clientName  = $client ? trim($client['fname'] . ' ' . $client['lname']) : 'Client';
$clientEmail = $client['email'] ?? '';
$topupAmount = number_format((float)$topup['amount'], 2);


if ($action === 'approve') {
    $newStatus = 'APPROVED';

    $stmtUpdate = $conn->prepare("
        UPDATE loan_topup_requests
        SET status = ?, updated_at = NOW()
        WHERE id = ? AND status IN ('PENDING', 'INFO_PROVIDED')
    ");
    $stmtUpdate->bind_param("si", $newStatus, $topupId);

    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        redirectWithAlert("Error approving top-up request");
    }
    $stmtUpdate->close();

    logTopupAudit(
        $conn,
        $loanId,
        $topupId,
        $actorId,
        $role,
        'topup_approved',
        $comments !== '' ? $comments : null,
        null,
        $currentStatus,
        $newStatus
    );

    if (!empty($clientEmail)) {
        $subject = "Your Loan Top-Up Has Been Approved - Nsimbi Impact";
        $body = "
            <h3>Dear " . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . ",</h3>
            <p>Your request to top up loan #{$loanId} by <strong>UGX {$topupAmount}</strong> has been <strong>APPROVED</strong>.</p>
            <p>Our accounts team will now process disbursement of the additional funds.</p>
            " . ($comments !== '' ? "<p><strong>Reviewer Notes:</strong> " . htmlspecialchars($comments, ENT_QUOTES, 'UTF-8') . "</p>" : "") . "
            <p>Best regards,<br>Nsimbi Impact Team</p>
        ";

        sendEmail($clientEmail, $subject, $body, strip_tags($body));
    }

    redirectWithAlert("Top-up request approved and sent to accounts for disbursement", "pending_topups.php");
}


if ($action === 'reject') {
    if ($rejectionReason === '') {
        redirectWithAlert("A reason for rejection is required");
    }

    $newStatus = 'REJECTED';

    $stmtUpdate = $conn->prepare("
        UPDATE loan_topup_requests
        SET status = ?, rejection_reason = ?, updated_at = NOW()
        WHERE id = ? AND status IN ('PENDING', 'INFO_PROVIDED')
    ");
    $stmtUpdate->bind_param("ssi", $newStatus, $rejectionReason, $topupId);

    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        redirectWithAlert("Error rejecting top-up request");
    }
    $stmtUpdate->close();

    logTopupAudit(
        $conn,
        $loanId,
        $topupId,
        $actorId,
        $role,
        'topup_rejected',
        $comments !== '' ? $comments : null,
        $rejectionReason,
        $currentStatus,
        $newStatus
    );

    $assignedOfficer = (int)($topup['assigned_officer_id'] ?? 0);
    if ($assignedOfficer > 0) {
        notifyUser(
            $conn,
            $assignedOfficer,
            $loanId,
            'topup_rejected',
            "The top-up request you were assisting with (loan #{$loanId}) was rejected. Reason: {$rejectionReason}"
        );
    }

    if (!empty($clientEmail)) {
        $subject = "Your Loan Top-Up Request Was Rejected - Nsimbi Impact";
        $body = "
            <h3>Dear " . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . ",</h3>
            <p>We regret to inform you that your request to top up loan #{$loanId} by <strong>UGX {$topupAmount}</strong> has been <strong>REJECTED</strong>.</p>
            <p><strong>Reason:</strong> " . htmlspecialchars($rejectionReason, ENT_QUOTES, 'UTF-8') . "</p>
            <p>Please contact support for more information.</p>
            <p>Best regards,<br>Nsimbi Impact Team</p>
        ";

        sendEmail($clientEmail, $subject, $body, strip_tags($body));
    }

    redirectWithAlert("Top-up request rejected");
}

// ------------------------------------------------------------------
// REQUEST MORE INFORMATION (details + assigned officer mandatory)
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
        UPDATE loan_topup_requests
        SET status = ?,
            assigned_officer_id = ?,
            info_request_details = ?,
            officer_response = NULL,
            updated_at = NOW()
        WHERE id = ? AND status IN ('PENDING', 'INFO_PROVIDED')
    ");
    $stmtUpdate->bind_param("sisi", $newStatus, $assignedOfficerId, $infoDetails, $topupId);

    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        redirectWithAlert("Error requesting more information");
    }
    $stmtUpdate->close();

    logTopupAudit(
        $conn,
        $loanId,
        $topupId,
        $actorId,
        $role,
        'topup_info_requested',
        $comments !== '' ? $comments : null,
        null,
        $currentStatus,
        $newStatus
    );

    notifyUser(
        $conn,
        $assignedOfficerId,
        $loanId,
        'topup_info_requested',
        "You've been asked to gather more information for a top-up on loan #{$loanId} ({$clientName}): {$infoDetails}"
    );

    if (!empty($officer['email'])) {
        $subject = "Action Needed: Loan #{$loanId} Top-Up Requires More Information";
        $body = "
            <h3>Dear " . htmlspecialchars(trim($officer['fname'] . ' ' . $officer['lname']), ENT_QUOTES, 'UTF-8') . ",</h3>
            <p>A reviewer has requested more information for a top-up request.</p>
            <p><strong>Client:</strong> " . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</p>
            <p><strong>Loan:</strong> #{$loanId}</p>
            <p><strong>Top-Up Amount:</strong> UGX {$topupAmount}</p>
            <p><strong>Information Requested:</strong> " . htmlspecialchars($infoDetails, ENT_QUOTES, 'UTF-8') . "</p>
            " . ($comments !== '' ? "<p><strong>Reviewer Notes:</strong> " . htmlspecialchars($comments, ENT_QUOTES, 'UTF-8') . "</p>" : "") . "
            <p>Please log in to respond and mark this request as complete once resolved.</p>
        ";

        sendEmail($officer['email'], $subject, $body, strip_tags($body));
    }

    redirectWithAlert("Request for more information sent to the loan officer");
}

redirectWithAlert("Invalid action");