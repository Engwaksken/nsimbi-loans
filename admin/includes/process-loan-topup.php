<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services_check.php';
require_once __DIR__ . '/../loan_audit.php';

date_default_timezone_set('Africa/Nairobi');

function flashRedirect(string $message, string $type = 'error', string $url = '../outstanding.php'): void
{
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    header("Location: {$url}");
    exit;
}

function cleanMoney($value): float
{
    $clean = str_replace([',', ' '], '', trim((string)$value));

    if ($clean === '' || !is_numeric($clean)) {
        throw new RuntimeException('Invalid amount entered.');
    }

    return (float)$clean;
}

// Kept identical to the original logic — used here only to show the
// reviewer a proposed rate/amount. The authoritative rate is recalculated
// at disbursement time, since rates could change between request and
// disbursement and the loan shouldn't be locked to a stale figure.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flashRedirect('Invalid request method.');
}

if (!isset($conn) || !$conn instanceof mysqli) {
    flashRedirect('Database connection not established.');
}

$actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

$loanId     = (int)($_POST['loan_id'] ?? 0);
$clientId   = (int)($_POST['user_id'] ?? 0);
$clientType = strtolower(trim((string)($_POST['client_type'] ?? '')));
$newPeriod  = (int)($_POST['loan_period'] ?? 0);
$notes      = trim((string)($_POST['notes'] ?? ''));

try {
    $newAmount = cleanMoney($_POST['amount'] ?? '');
} catch (Throwable $e) {
    flashRedirect($e->getMessage());
}

if ($loanId <= 0 || $clientId <= 0 || $newAmount <= 0 || $newPeriod <= 0) {
    flashRedirect('Invalid loan top-up request.');
}

if (!in_array($clientType, ['customer', 'member', 'startup'], true)) {
    flashRedirect('Invalid client type.');
}

try {
    $proposedRate = getInterestRate($clientType, $newPeriod);
    $proposedInterest = $newAmount * ($proposedRate / 100);
} catch (Throwable $e) {
    flashRedirect($e->getMessage());
}

// Confirm the loan is a real, currently-outstanding loan for this client,
// and make sure there isn't already an active top-up request in flight.
$loanStmt = $conn->prepare("
    SELECT id, loan_balance
    FROM loan
    WHERE id = ? AND client_id = ? AND status = 'OUTSTANDING'
    LIMIT 1
");
$loanStmt->bind_param('ii', $loanId, $clientId);
$loanStmt->execute();
$loan = $loanStmt->get_result()->fetch_assoc();
$loanStmt->close();

if (!$loan) {
    flashRedirect('No outstanding loan found for this client.');
}

$existingStmt = $conn->prepare("
    SELECT id FROM loan_topup_requests
    WHERE loan_id = ? AND status NOT IN ('REJECTED', 'DISBURSED')
    LIMIT 1
");
$existingStmt->bind_param('i', $loanId);
$existingStmt->execute();
$existing = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();

if ($existing) {
    flashRedirect('This loan already has a top-up request in progress. Please wait for it to be resolved.');
}

// Early loan-limit sanity check (re-checked again, authoritatively, at
// disbursement time in case the limit changes in the meantime).
if (in_array($clientType, ['customer', 'member'], true)) {
    $limitTable = $clientType === 'customer' ? 'customers' : 'members';
    $limitStmt = $conn->prepare("SELECT loan_limit FROM {$limitTable} WHERE user_id = ? LIMIT 1");
    $limitStmt->bind_param('i', $clientId);
    $limitStmt->execute();
    $limitRow = $limitStmt->get_result()->fetch_assoc();
    $limitStmt->close();

    $loanLimit = (float)($limitRow['loan_limit'] ?? 0);
    $currentBalance = (float)($loan['loan_balance'] ?? 0);

    if ($loanLimit > 0 && ($currentBalance + $newAmount) > $loanLimit) {
        flashRedirect('Top-up exceeds client loan limit.');
    }
}

$stmt = $conn->prepare("
    INSERT INTO loan_topup_requests
        (loan_id, client_id, client_type, amount, loan_period,
         proposed_interest_rate, proposed_interest_amount, request_notes,
         status, requested_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)
");
$stmt->bind_param(
    'iisdiidsi',
    $loanId,
    $clientId,
    $clientType,
    $newAmount,
    $newPeriod,
    $proposedRate,
    $proposedInterest,
    $notes,
    $actorId
);

if (!$stmt->execute()) {
    $stmt->close();
    flashRedirect('Could not submit top-up request: ' . $conn->error);
}

$topupId = $stmt->insert_id;
$stmt->close();

logTopupAudit(
    $conn,
    $loanId,
    $topupId,
    $actorId,
    isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0,
    'topup_requested',
    $notes !== '' ? $notes : null,
    null,
    null,
    'PENDING'
);

flashRedirect(
    'Top-up request submitted. A reviewer will approve or reject it before it is applied.',
    'success',
    '../outstanding.php'
);