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

/** Insert into notifications using only the columns this database has. */
function topup_notify(mysqli $conn, int $recipientId, int $clientId, int $loanId, string $title, string $message): void
{
    $now = date('Y-m-d H:i:s');
    $data = [
        'user_id'    => [$recipientId, 'i'],
        'client_id'  => [$clientId, 'i'],
        'loan_id'    => [$loanId, 'i'],
        'title'      => [$title, 's'],
        'message'    => [$message, 's'],
        'type'       => ['topup_request', 's'],
        'status'     => ['unread', 's'],
        'created_at' => [$now, 's'],
        'updated_at' => [$now, 's'],
    ];

    $cols = [];
    $types = '';
    $params = [];
    foreach ($data as $col => [$val, $type]) {
        if (loan_table_has_column($conn, 'notifications', $col)) {
            $cols[] = $col;
            $types .= $type;
            $params[] = $val;
        }
    }
    if (!in_array('message', $cols, true)) {
        return;
    }

    $sql = 'INSERT INTO notifications (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }
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

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("
        INSERT INTO loan_topup_requests
            (loan_id, client_id, client_type, amount, loan_period,
             proposed_interest_rate, proposed_interest_amount, request_notes,
             status, requested_by, assigned_officer_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?, ?)
    ");
    $stmt->bind_param(
        'iisdiddsii',
        $loanId,
        $clientId,
        $clientType,
        $amount,
        $period,
        $rate,
        $proposedInterest,
        $reason,
        $clientId,
        $officerParam
    );
    $stmt->execute();
    $topupId = (int)$stmt->insert_id;
    $stmt->close();

    logTopupAudit($conn, $loanId, $topupId, $clientId, 4, 'topup_requested', $reason, null, null, 'PENDING');

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Staff top-up request failed: ' . $e->getMessage());
    topup_back('Your top-up request could not be submitted. Please try again or contact the loans office.');
}

// Tell the officer. Failures here are logged, not shown: the request is saved.
$staffName = trim((string)($_SESSION['fname'] ?? '') . ' ' . (string)($_SESSION['lname'] ?? '')) ?: 'A staff member';
$title = 'Loan Top-Up Request';
$message = "{$staffName} has requested a top-up of " . topup_money($amount)
    . " on LOAN-{$loanId} (current balance " . topup_money($currentBalance) . "). Reason: {$reason}";

if ($officerId > 0) {
    try {
        topup_notify($conn, $officerId, $clientId, $loanId, $title, $message);
    } catch (Throwable $e) {
        error_log('Staff top-up notification failed: ' . $e->getMessage());
    }

    $stmt = $conn->prepare('SELECT fname, email FROM user WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $officerId);
    $stmt->execute();
    $officer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $officerEmail = trim((string)($officer['email'] ?? ''));
    if (filter_var($officerEmail, FILTER_VALIDATE_EMAIL)) {
        $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $link = 'https://nsimbiimpact.com/loans/admin/pending_topups.php';
        $body = '<p>Hello ' . $esc($officer['fname'] ?? '') . ',</p>'
            . '<p>A staff member has requested a loan top-up on the <strong>Nsimbi Impact Loan System</strong>.</p>'
            . '<p><strong>Staff member:</strong> ' . $esc($staffName) . '<br>'
            . '<strong>Loan:</strong> LOAN-' . $loanId . '<br>'
            . '<strong>Current balance:</strong> ' . $esc(topup_money($currentBalance)) . '<br>'
            . '<strong>Top-up requested:</strong> ' . $esc(topup_money($amount)) . '<br>'
            . '<strong>Period:</strong> ' . $period . ' months<br>'
            . '<strong>Reason:</strong> ' . nl2br($esc($reason)) . '</p>'
            . "<p><a href='{$link}' style='padding:10px 20px;background-color:#007BFF;color:white;text-decoration:none;'>Review top-up requests</a></p>";
        try {
            sendEmail($officerEmail, 'Loan Top-Up Request - Nsimbi Impact', $body, strip_tags(str_replace('<br>', "\n", $body)));
        } catch (Throwable $e) {
            error_log('Staff top-up email failed: ' . $e->getMessage());
        }
    } else {
        error_log("Staff top-up: officer #{$officerId} has no valid email address.");
    }
} else {
    error_log('Staff top-up #' . $topupId . ' saved with no loan officer to notify.');
}

topup_back('Your top-up request of ' . topup_money($amount) . ' has been sent to the loan officer for review.', 'success');
