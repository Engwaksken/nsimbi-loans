<?php
declare(strict_types=1);

/*
 * Staff (members, role 4) ask to top up one of their outstanding loans.
 *
 * This files a request in loan_topup_requests - the same queue the admin
 * Pending Top-Ups page reviews and Disbursement pays out - and sends it to
 * loan officer Esther Kayondo by email and in-app notification. Nothing
 * about the loan changes until an administrator approves and disburses it.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/officer_assignment.php';
require_once dirname(__DIR__) . '/admin/loan_audit.php';
require_once dirname(__DIR__) . '/php/mail-function.php';
require_once dirname(__DIR__) . '/includes/topup_notify.php';

date_default_timezone_set('Africa/Nairobi');

const TOPUP_OFFICER_NAME = 'Esther Kayondo';
const TOPUP_MEMBER_PERIOD = 3;      // staff top-ups are always 3 months
const TOPUP_MEMBER_RATE = 10.0;     // matches admin/includes/process-loan-topup.php

function topup_back(string $message, string $type = 'error'): never
{
    $_SESSION[$type === 'success' ? 'success' : 'error'] = $message;
    header('Location: loans.php');
    exit;
}

function topup_money(float $value): string
{
    return 'UGX ' . number_format($value);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    topup_back('Invalid request method.');
}

if (empty($_SESSION['logged_in']) || (string)($_SESSION['role'] ?? '') !== '4') {
    header('Location: ../index.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    topup_back('Database connection not found.');
}
$conn->set_charset('utf8mb4');

// Always the signed-in member; never trust a client_id from the form.
$clientId = (int)$_SESSION['logged_in'];
$loanId   = (int)($_POST['loan_id'] ?? 0);
$amount   = (float)str_replace([',', ' '], '', (string)($_POST['requested_amount'] ?? $_POST['amount'] ?? '0'));
$reason   = trim((string)($_POST['reason'] ?? ''));

if ($loanId <= 0) {
    topup_back('Invalid request. The loan is missing.');
}
if ($amount <= 0) {
    topup_back('Please enter the top-up amount you need.');
}
if ($reason === '') {
    topup_back('Please give a reason for the top-up.');
}

$stmt = $conn->prepare('SELECT * FROM loan WHERE id = ? AND client_id = ? LIMIT 1');
$stmt->bind_param('ii', $loanId, $clientId);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    topup_back('Loan not found.');
}
if (strtoupper(trim((string)$loan['status'])) !== 'OUTSTANDING') {
    topup_back('Only outstanding loans can be topped up. Please clear any overdue balance first.');
}

$stmt = $conn->prepare("
    SELECT id FROM loan_topup_requests
    WHERE loan_id = ? AND status NOT IN ('REJECTED', 'DISBURSED')
    LIMIT 1
");
$stmt->bind_param('i', $loanId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    topup_back('You already have a top-up request in progress for LOAN-' . $loanId . '. Please wait for it to be reviewed.');
}

// Same limit check the admin side uses: current balance + new money.
$stmt = $conn->prepare('SELECT loan_limit FROM members WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $clientId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

$loanLimit = (float)($member['loan_limit'] ?? 0);
$currentBalance = (float)($loan['loan_balance'] ?? 0);
if ($loanLimit > 0 && ($currentBalance + $amount) > $loanLimit) {
    topup_back('This top-up would take you over your loan limit of ' . topup_money($loanLimit)
        . '. The most you can request is ' . topup_money(max(0, $loanLimit - $currentBalance)) . '.');
}

// Esther Kayondo handles staff top-ups; fall back to the loan's own officer.
$officerId = officer_find_by_name($conn, TOPUP_OFFICER_NAME);
if ($officerId <= 0) {
    $officerId = (int)($loan['assigned_officer_id'] ?? 0);
    error_log('Staff top-up: ' . TOPUP_OFFICER_NAME . ' not found as an active loan officer; using loan officer #' . $officerId);
}

$proposedInterest = $amount * (TOPUP_MEMBER_RATE / 100);
$clientType = 'member';
$period = TOPUP_MEMBER_PERIOD;
$rate = TOPUP_MEMBER_RATE;
$officerParam = $officerId > 0 ? $officerId : null;

// With an officer, the request starts on the officer's Top-Up Reviews page
// (INFO_REQUESTED); she forwards it (INFO_PROVIDED) for an admin to approve.
// Without one it goes straight to the admins' Pending Top-Ups (PENDING).
$toOfficer = $officerId > 0 && loan_table_has_column($conn, 'loan_topup_requests', 'assigned_officer_id');
$initialStatus = $toOfficer ? 'INFO_REQUESTED' : 'PENDING';
$officerTask = 'Staff top-up request: check the request and the loan, then forward it to the administrator with your recommendation.';

// Only write the columns this database actually has (older installs differ).
$fields = [
    'info_request_details'     => [$toOfficer ? $officerTask : null, 's'],
    'loan_id'                  => [$loanId, 'i'],
    'client_id'                => [$clientId, 'i'],
    'client_type'              => [$clientType, 's'],
    'amount'                   => [$amount, 'd'],
    'loan_period'              => [$period, 'i'],
    'proposed_interest_rate'   => [$rate, 'd'],
    'proposed_interest_amount' => [$proposedInterest, 'd'],
    'request_notes'            => [$reason, 's'],
    'status'                   => [$initialStatus, 's'],
    'requested_by'             => [$clientId, 'i'],
    'assigned_officer_id'      => [$officerParam, 'i'],
    'created_at'               => [date('Y-m-d H:i:s'), 's'],
];

try {
    $cols = [];
    $types = '';
    $params = [];
    foreach ($fields as $col => [$val, $type]) {
        if (loan_table_has_column($conn, 'loan_topup_requests', $col)) {
            $cols[] = $col;
            $types .= $type;
            $params[] = $val;
        }
    }

    $stmt = $conn->prepare(
        'INSERT INTO loan_topup_requests (' . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')'
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $topupId = (int)$stmt->insert_id;
    $stmt->close();
} catch (Throwable $e) {
    error_log('Staff top-up request failed: ' . $e->getMessage());
    topup_back('Your top-up request could not be submitted (' . $e->getMessage() . '). Please contact the loans office.');
}

// The audit trail is a record, not a gate: never lose the request over it.
try {
    logTopupAudit($conn, $loanId, $topupId, $clientId, 4, 'topup_requested', $reason, null, null, $initialStatus);
} catch (Throwable $e) {
    error_log('Staff top-up audit failed: ' . $e->getMessage());
}


// Tell the officer (or the admins when no officer could be assigned).
// Failures here are logged, not shown: the request is already saved.
$staffName = trim((string)($_SESSION['fname'] ?? '') . ' ' . (string)($_SESSION['lname'] ?? '')) ?: 'A staff member';
$details = [
    'Staff member'     => $staffName,
    'Loan'             => 'LOAN-' . $loanId,
    'Current balance'  => topup_money($currentBalance),
    'Top-up requested' => topup_money($amount),
    'Period'           => $period . ' months',
    'Reason'           => $reason,
];
$message = "{$staffName} has requested a top-up of " . topup_money($amount)
    . " on LOAN-{$loanId} (current balance " . topup_money($currentBalance) . "). Reason: {$reason}";

if ($toOfficer) {
    topup_notify_bell($conn, $officerId, $loanId, 'Top-Up Needs Your Review', $message, $clientId);
    topup_notify_email(
        $conn,
        $officerId,
        'Loan Top-Up Request Needs Your Review - Nsimbi Impact',
        'A staff member has requested a loan top-up. Please review it and forward it to the administrator for approval.',
        $details,
        'officer_topup_requests.php',
        'Review top-up request'
    );
} else {
    error_log('Staff top-up #' . $topupId . ': no loan officer assigned; sent straight to administrators.');
    foreach (topup_admin_ids($conn) as $adminId) {
        topup_notify_bell($conn, $adminId, $loanId, 'Loan Top-Up Request', $message, $clientId);
        topup_notify_email($conn, $adminId, 'Loan Top-Up Request - Nsimbi Impact',
            'A staff member has requested a loan top-up.', $details, 'pending_topups.php', 'Review top-up requests');
    }
}

topup_back('Your top-up request of ' . topup_money($amount) . ' has been sent to the loan officer for review.', 'success');
