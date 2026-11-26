<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/alert_bridge.php';

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

/**
 * Approve one top-up (a loan_topup_requests row): set APPROVED, log it and
 * email the client. Returns false when the request was no longer awaiting
 * review (or the update failed), so nothing else is done for it.
 */
function approveTopupRequest(mysqli $conn, array $topup, int $actorId, int $role, string $comments): bool
{
    $topupId = (int)$topup['id'];
    $loanId = (int)$topup['loan_id'];
    $oldStatus = (string)($topup['status'] ?? '');
    $newStatus = 'APPROVED';

    try {
        $stmt = $conn->prepare("
            UPDATE loan_topup_requests
            SET status = ?, updated_at = NOW()
            WHERE id = ? AND status IN ('PENDING', 'INFO_PROVIDED')
        ");
        $stmt->bind_param("si", $newStatus, $topupId);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
    } catch (Throwable $e) {
        error_log("approveTopupRequest #{$topupId}: " . $e->getMessage());
        return false;
    }
    if (!$changed) {
        return false;
    }

    logTopupAudit($conn, $loanId, $topupId, $actorId, $role, 'topup_approved',
        $comments !== '' ? $comments : null, null, $oldStatus, $newStatus);

    try {
        $clientId = (int)$topup['client_id'];
        $stmt = $conn->prepare("SELECT fname, lname, email FROM user WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $clientId);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $clientName = $client ? trim($client['fname'] . ' ' . $client['lname']) : 'Client';
        $clientEmail = $client['email'] ?? '';
        $topupAmount = number_format((float)$topup['amount'], 2);

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
    } catch (Throwable $e) {
        // The approval stands even if the email can't be sent.
        error_log("approveTopupRequest #{$topupId} email: " . $e->getMessage());
    }

    return true;
}

/**
 * Reject one top-up: set REJECTED with the reason, log it, tell the
 * assigned officer and email the client. Returns false when the request was
 * no longer awaiting review (or the update failed).
 */
function rejectTopupRequest(mysqli $conn, array $topup, int $actorId, int $role, string $reason, string $comments): bool
{
    $topupId = (int)$topup['id'];
    $loanId = (int)$topup['loan_id'];
    $oldStatus = (string)($topup['status'] ?? '');
    $newStatus = 'REJECTED';

    try {
        $stmt = $conn->prepare("
            UPDATE loan_topup_requests
            SET status = ?, rejection_reason = ?, updated_at = NOW()
            WHERE id = ? AND status IN ('PENDING', 'INFO_PROVIDED')
        ");
        $stmt->bind_param("ssi", $newStatus, $reason, $topupId);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
    } catch (Throwable $e) {
        error_log("rejectTopupRequest #{$topupId}: " . $e->getMessage());
        return false;
    }
    if (!$changed) {
        return false;
    }

    logTopupAudit($conn, $loanId, $topupId, $actorId, $role, 'topup_rejected',
        $comments !== '' ? $comments : null, $reason, $oldStatus, $newStatus);

    try {
        $assignedOfficer = (int)($topup['assigned_officer_id'] ?? 0);
        if ($assignedOfficer > 0) {
            notifyUser($conn, $assignedOfficer, $loanId, 'topup_rejected',
                "The top-up request you were assisting with (loan #{$loanId}) was rejected. Reason: {$reason}");
        }

        $clientId = (int)$topup['client_id'];
        $stmt = $conn->prepare("SELECT fname, lname, email FROM user WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $clientId);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $clientName = $client ? trim($client['fname'] . ' ' . $client['lname']) : 'Client';
        $clientEmail = $client['email'] ?? '';
        $topupAmount = number_format((float)$topup['amount'], 2);

        if (!empty($clientEmail)) {
            $subject = "Your Loan Top-Up Request Was Rejected - Nsimbi Impact";
            $body = "
                <h3>Dear " . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . ",</h3>
                <p>We regret to inform you that your request to top up loan #{$loanId} by <strong>UGX {$topupAmount}</strong> has been <strong>REJECTED</strong>.</p>
                <p><strong>Reason:</strong> " . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "</p>
                <p>Please contact support for more information.</p>
                <p>Best regards,<br>Nsimbi Impact Team</p>
            ";
            sendEmail($clientEmail, $subject, $body, strip_tags($body));
        }
    } catch (Throwable $e) {
        // The rejection stands even if a notification can't be sent.
        error_log("rejectTopupRequest #{$topupId} notify: " . $e->getMessage());
    }

    return true;
}

// Bulk approve / reject: ids[] from the checkboxes on Pending Top-Ups.
if ($action === 'bulk_approve' || $action === 'bulk_reject') {
    $rejecting = $action === 'bulk_reject';
    if ($role !== 1) {
        redirectWithAlert("Not Authorized for this Action");
    }
    if ($actorId <= 0) {
        redirectWithAlert("Your session is missing a user id. Please log in again.");
    }
    $ids = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['ids'] ?? [])),
        static fn(int $id): bool => $id > 0
    )));
    if ($ids === []) {
        redirectWithAlert("Select at least one top-up request");
    }
    if ($rejecting && $rejectionReason === '') {
        redirectWithAlert("A reason for rejection is required");
    }

    $done = 0;
    $skipped = 0;
    $stmt = $conn->prepare("SELECT * FROM loan_topup_requests WHERE id = ? LIMIT 1");
    foreach ($ids as $id) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $ok = $row && in_array((string)$row['status'], ['PENDING', 'INFO_PROVIDED'], true)
            && ($rejecting
                ? rejectTopupRequest($conn, $row, $actorId, $role, $rejectionReason, $comments)
                : approveTopupRequest($conn, $row, $actorId, $role, $comments));
        $ok ? $done++ : $skipped++;
    }
    $stmt->close();

    $message = "{$done} top-up request" . ($done === 1 ? '' : 's')
        . ($rejecting ? ' rejected' : ' approved and sent to accounts for disbursement');
    if ($skipped > 0) {
        $message .= ". {$skipped} skipped (no longer awaiting review)";
    }
    redirectWithAlert($message);
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
    if (!approveTopupRequest($conn, $topup, $actorId, $role, $comments)) {
        redirectWithAlert("Error approving top-up request");
    }
    redirectWithAlert("Top-up request approved and sent to accounts for disbursement", "pending_topups.php");
}


if ($action === 'reject') {
    if ($rejectionReason === '') {
        redirectWithAlert("A reason for rejection is required");
    }

    if (!rejectTopupRequest($conn, $topup, $actorId, $role, $rejectionReason, $comments)) {
        redirectWithAlert("Error rejecting top-up request");
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