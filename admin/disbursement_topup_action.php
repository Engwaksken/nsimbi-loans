<?php
declare(strict_types=1);

session_start();

require "db.php";
require_once __DIR__ . "/loan_audit.php";
require_once __DIR__ . '/../php/mail-function.php';

date_default_timezone_set('Africa/Nairobi');

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$role    = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
$actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

$topupId = filter_input(INPUT_POST, 'topup_id', FILTER_VALIDATE_INT);
if (!$topupId) {
    $topupId = filter_input(INPUT_GET, 'topup_id', FILTER_VALIDATE_INT);
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


function getInterestRate(string $clientType, int $period): float
{
    if ($clientType === 'member') {
        if ($period !== 3) {
            throw new RuntimeException('Staff/member top-up must be for 3 months.');
        }

        return 10.0;
    }

    if ($clientType === 'customer') {
        return $period === 1 ? 10.0 : 6.6;
    }

    if ($clientType === 'startup') {
        return 6.0;
    }

    throw new RuntimeException('Invalid client type.');
}

if (!$topupId || $topupId <= 0) {
    redirectWithAlert("Invalid top-up request ID");
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

$conn->begin_transaction();

try {
    $topupStmt = $conn->prepare("
        SELECT * FROM loan_topup_requests
        WHERE id = ? AND status = 'APPROVED'
        LIMIT 1
        FOR UPDATE
    ");
    $topupStmt->bind_param("i", $topupId);
    $topupStmt->execute();
    $topup = $topupStmt->get_result()->fetch_assoc();
    $topupStmt->close();

    if (!$topup) {
        throw new RuntimeException('This top-up is not awaiting disbursement.');
    }

    $loanId     = (int)$topup['loan_id'];
    $clientId   = (int)$topup['client_id'];
    $clientType = (string)$topup['client_type'];
    $newAmount  = (float)$topup['amount'];
    $newPeriod  = (int)$topup['loan_period'];

    $loanStmt = $conn->prepare("
        SELECT * FROM loan
        WHERE id = ? AND client_id = ? AND status = 'OUTSTANDING'
        LIMIT 1
        FOR UPDATE
    ");
    $loanStmt->bind_param('ii', $loanId, $clientId);
    $loanStmt->execute();
    $loan = $loanStmt->get_result()->fetch_assoc();
    $loanStmt->close();

    if (!$loan) {
        throw new RuntimeException('No outstanding loan found for this client.');
    }

    $newRate = getInterestRate($clientType, $newPeriod);
    $newInterest = $newAmount * ($newRate / 100);

    $oldAmount   = (float)($loan['amount'] ?? 0);
    $oldBalance  = (float)($loan['loan_balance'] ?? 0);
    $oldInterest = (float)($loan['interest'] ?? 0);
    $oldPeriod   = (int)($loan['loan_period'] ?? 0);

    // Authoritative loan-limit check, re-verified now in case it changed
    // since the request was first submitted.
    if ($clientType === 'customer') {
        $limitStmt = $conn->prepare("SELECT loan_limit FROM customers WHERE user_id = ? LIMIT 1");
    } elseif ($clientType === 'member') {
        $limitStmt = $conn->prepare("SELECT loan_limit FROM members WHERE user_id = ? LIMIT 1");
    } else {
        $limitStmt = null;
    }

    if ($limitStmt) {
        $limitStmt->bind_param('i', $clientId);
        $limitStmt->execute();
        $limitRow = $limitStmt->get_result()->fetch_assoc();
        $limitStmt->close();

        $loanLimit = (float)($limitRow['loan_limit'] ?? 0);

        if ($loanLimit > 0 && ($oldBalance + $newAmount) > $loanLimit) {
            throw new RuntimeException('Top-up exceeds client loan limit.');
        }
    }

    $totalAmount   = $oldAmount + $newAmount;
    $totalInterest = $oldInterest + $newInterest;
    $newBalance    = $oldBalance + $newAmount + $newInterest;
    $totalPeriod   = $oldPeriod + $newPeriod;

    $baseDueDate = !empty($loan['due_date'])
        ? new DateTime((string)$loan['due_date'])
        : new DateTime();

    $baseDueDate->modify("+{$newPeriod} months");
    $newDueDate = $baseDueDate->format('Y-m-d H:i:s');

    $now = date('Y-m-d H:i:s');

    if ($reference === '') {
        $reference = 'TOPUP-' . $topupId . '-' . date('YmdHis');
    }

    $updateLoanStmt = $conn->prepare("
        UPDATE loan
        SET
            amount = ?,
            interest_rate = ?,
            interest = ?,
            loan_period = ?,
            due_date = ?,
            loan_balance = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $updateLoanStmt->bind_param(
        'dddisdi',
        $totalAmount,
        $newRate,
        $totalInterest,
        $totalPeriod,
        $newDueDate,
        $newBalance,
        $loanId
    );

    if (!$updateLoanStmt->execute()) {
        throw new RuntimeException('Top-up merge failed: ' . $updateLoanStmt->error);
    }
    $updateLoanStmt->close();

    $newStatus = 'DISBURSED';

    $updateTopupStmt = $conn->prepare("
        UPDATE loan_topup_requests
        SET
            status = ?,
            disbursed_at = ?,
            disbursed_by = ?,
            disbursement_method = ?,
            disbursement_reference = ?,
            disbursement_notes = ?,
            updated_at = NOW()
        WHERE id = ? AND status = 'APPROVED'
    ");
    $updateTopupStmt->bind_param(
        'ssisssi',
        $newStatus,
        $now,
        $actorId,
        $method,
        $reference,
        $notes,
        $topupId
    );

    if (!$updateTopupStmt->execute()) {
        throw new RuntimeException('Could not record top-up disbursement: ' . $updateTopupStmt->error);
    }
    $updateTopupStmt->close();

    logTopupAudit(
        $conn,
        $loanId,
        $topupId,
        $actorId,
        $role,
        'topup_disbursed',
        $notes !== '' ? $notes : null,
        null,
        'APPROVED',
        $newStatus
    );

    $conn->commit();

    $stmtClient = $conn->prepare("SELECT fname, lname, email FROM user WHERE id = ? LIMIT 1");
    $stmtClient->bind_param("i", $clientId);
    $stmtClient->execute();
    $client = $stmtClient->get_result()->fetch_assoc();
    $stmtClient->close();

    $clientName  = $client ? trim($client['fname'] . ' ' . $client['lname']) : 'Client';
    $clientEmail = $client['email'] ?? '';

    if (!empty($clientEmail)) {
        $methodLabel = match ($method) {
            'cash'          => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'mobile_money'  => 'Mobile Money',
            default         => ucfirst($method),
        };

        $subject = "Your Loan Top-Up Has Been Disbursed - Nsimbi Impact";
        $body = "
            <h3>Dear " . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . ",</h3>
            <p>Your top-up of <strong>UGX " . number_format($newAmount, 2) . "</strong> on loan #{$loanId} has been <strong>DISBURSED</strong>.</p>
            <p><strong>Method:</strong> {$methodLabel}</p>
            <p><strong>Reference:</strong> " . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . "</p>
            <p><strong>New Outstanding Balance:</strong> UGX " . number_format($newBalance, 2) . "</p>
            <p><strong>New Due Date:</strong> {$newDueDate}</p>
            <p>Best regards,<br>Nsimbi Impact Team</p>
        ";

        sendEmail($clientEmail, $subject, $body, strip_tags($body));
    }

    redirectWithAlert("Top-up #{$topupId} disbursed and merged into loan #{$loanId}", "disbursement.php");

} catch (Throwable $e) {
    $conn->rollback();
    redirectWithAlert($e->getMessage(), "disbursement.php");
}