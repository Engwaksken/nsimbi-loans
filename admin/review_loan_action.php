<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
require 'db.php';
require_once __DIR__ . '/loan_audit.php';
require_once __DIR__ . '/../php/mail-function.php';

function finishReview(string $message): never {
    $_SESSION['loan_action_feedback'] = $message;
    header('Location: pending_loans.php', true, 303);
    exit;
}
function postText(string $key, int $max = 4000): string {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) return '';
    return mb_substr(trim($value), 0, $max, 'UTF-8');
}
function safeMail(string $to, string $subject, string $body): void {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    try { sendEmail($to, $subject, $body, strip_tags($body)); }
    catch (Throwable $e) { error_log('Loan notification email failed: ' . $e->getMessage()); }
}
function htmlValue(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') finishReview('Only POST requests are accepted.');
if (!($conn instanceof mysqli)) finishReview('Database connection is unavailable.');
$role = (int)($_SESSION['role'] ?? 0);
$actorId = (int)($_SESSION['user_id'] ?? 0);
if ($role !== 1 || $actorId <= 0) finishReview('Not authorized to review loans.');
$csrf = postText('csrf_token', 128);
if (empty($_SESSION['loan_review_csrf']) || !hash_equals((string)$_SESSION['loan_review_csrf'], $csrf)) {
    finishReview('Security token invalid or expired. Reload the pending loans page and try again.');
}
$action = postText('action', 30);
if (!in_array($action, ['approve','reject','request_info','delete'], true)) finishReview('Invalid action.');
$bulk = ($_POST['bulk'] ?? '') === '1';
$rawIds = $bulk ? ($_POST['ids'] ?? []) : [$_POST['id'] ?? null];
if (!is_array($rawIds)) finishReview('Invalid loan selection.');
$ids = [];
foreach ($rawIds as $raw) {
    if (!is_scalar($raw) || !preg_match('/^[1-9][0-9]*$/', (string)$raw)) continue;
    $id = (int)$raw;
    if ($id > 0) $ids[$id] = $id;
}
$ids = array_values($ids);
if (!$ids || count($ids) > 100) finishReview('Select 1 to 100 valid loans.');
$comments = postText('comments');
$reason = postText('reason');
$infoDetails = postText('info_details');
$deleteReason = postText('delete_reason');
$officerId = filter_var($_POST['officer_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
if ($action === 'reject' && $reason === '') finishReview('A rejection reason is required.');
if ($action === 'delete' && $deleteReason === '') finishReview('A deletion reason is required.');
if ($action === 'request_info' && ($infoDetails === '' || $officerId <= 0)) {
    finishReview('Specify the information needed and select a loan officer.');
}
$officer = null;
if ($action === 'request_info') {
    $s = $conn->prepare('SELECT id, fname, lname, email FROM user WHERE id = ? AND role = 2 LIMIT 1');
    $s->bind_param('i', $officerId);
    $s->execute(); $officer = $s->get_result()->fetch_assoc(); $s->close();
    if (!$officer) finishReview('The selected officer is not valid.');
}

// loan.client_id stores startups.startup_id (the startup's user id).
$startupIdColumn = 'startup_id';
try {
    $startupIdCheck = $conn->query("SHOW COLUMNS FROM startups LIKE 'startup_id'");
    if ($startupIdCheck instanceof mysqli_result) {
        if ($startupIdCheck->num_rows === 0) {
            $startupIdColumn = 'id';
        }
        $startupIdCheck->free();
    }
} catch (Throwable $e) {
    $startupIdColumn = 'startup_id';
}

$success = 0;
$skipped = 0;
$failed = 0;
$errors = [];
foreach ($ids as $id) {
    try {
        $conn->begin_transaction();
        $s = $conn->prepare('SELECT * FROM loan WHERE id = ? FOR UPDATE');
        $s->bind_param('i', $id);
        $s->execute(); $loan = $s->get_result()->fetch_assoc(); $s->close();
        if (!$loan || !in_array(strtoupper(trim((string)$loan['status'])), ['PENDING','INFO_PROVIDED'], true)) {
            $conn->rollback(); $skipped++; continue;
        }
        $oldStatus = (string)$loan['status'];
        $clientId = (int)($loan['client_id'] ?? 0);
        $amount = number_format((float)($loan['amount'] ?? 0), 2);
        $clientType = strtolower(trim((string)($loan['client_type'] ?? '')));
        // Only match user IDs to a client in tables known to use user IDs.
        // Startup IDs belong to the startups table, and must never be treated as user IDs.
        $client = null;
        if ($clientType === 'customer' || $clientType === 'member') {
            $s = $conn->prepare('SELECT fname, lname, email FROM user WHERE id = ? LIMIT 1');
            $s->bind_param('i', $clientId); $s->execute();
            $client = $s->get_result()->fetch_assoc(); $s->close();
        } elseif ($clientType === 'startup') {
            $s = $conn->prepare("SELECT ceo_name AS fname, company_name AS lname, email FROM startups WHERE `{$startupIdColumn}` = ? LIMIT 1");
            $s->bind_param('i', $clientId); $s->execute();
            $client = $s->get_result()->fetch_assoc(); $s->close();
        }
        // Missing client_type: never guess across overlapping identifiers.
        $clientName = trim((string)($client['fname'] ?? '') . ' ' . (string)($client['lname'] ?? '')) ?: 'Applicant';
        $clientEmail = (string)($client['email'] ?? '');
        $notification = null;
        $officerNotification = null;
        if ($action === 'approve') {
            $period = max(1, min(120, (int)($loan['loan_period'] ?? 1)));
            $rate = $period === 1 ? 10.0 : 6.6;
            $dueDate = (new DateTimeImmutable('now'))->add(new DateInterval('P' . $period . 'M'))->format('Y-m-d H:i:s');
            $newStatus = 'OUTSTANDING';
            $s = $conn->prepare("UPDATE loan SET status = ?, interest_rate = ?, due_date = ? WHERE id = ? AND UPPER(TRIM(status)) IN ('PENDING','INFO_PROVIDED')");
            $s->bind_param('sdsi', $newStatus, $rate, $dueDate, $id);
            $auditAction = 'approved'; $auditReason = null;
            $notification = ['Your Loan Has Been Approved - Nsimbi Impact',
                '<h3>Dear '.htmlValue($clientName).',</h3><p>Your loan of UGX '.htmlValue($amount).' has been approved.</p><p>Interest rate: '.htmlValue((string)$rate).'%</p><p>Due date: '.htmlValue($dueDate).'</p><p>'.nl2br(htmlValue($comments)).'</p>'];
        } elseif ($action === 'reject') {
            $newStatus = 'REJECTED';
            $s = $conn->prepare("UPDATE loan SET status = ?, rejection_reason = ? WHERE id = ? AND UPPER(TRIM(status)) IN ('PENDING','INFO_PROVIDED')");
            $s->bind_param('ssi', $newStatus, $reason, $id);
            $auditAction = 'rejected'; $auditReason = $reason;
            $notification = ['Your Loan Request Was Rejected - Nsimbi Impact',
                '<h3>Dear '.htmlValue($clientName).',</h3><p>Your request of UGX '.htmlValue($amount).' was rejected.</p><p>Reason: '.nl2br(htmlValue($reason)).'</p>'];
            $assigned = (int)($loan['assigned_officer_id'] ?? 0);
            if ($assigned > 0) $officerNotification = [$assigned, 'loan_rejected', "Loan #{$id} rejected. Reason: {$reason}"];
        } elseif ($action === 'request_info') {
            $newStatus = 'INFO_REQUESTED';
            $s = $conn->prepare("UPDATE loan SET status = ?, assigned_officer_id = ?, info_request_details = ?, officer_response = NULL WHERE id = ? AND UPPER(TRIM(status)) IN ('PENDING','INFO_PROVIDED')");
            $s->bind_param('sisi', $newStatus, $officerId, $infoDetails, $id);
            $auditAction = 'info_requested'; $auditReason = null;
            $officerNotification = [$officerId, 'loan_info_requested', "Please gather information for loan #{$id} ({$clientName}): {$infoDetails}"];
            $officerName = trim((string)$officer['fname'].' '.(string)$officer['lname']);
            $notification = ['Action Needed: Loan #'.$id.' Requires More Information',
                '<h3>Dear '.htmlValue($officerName).',</h3><p>Loan #'.$id.' ('.htmlValue($clientName).') needs additional information.</p><p>'.nl2br(htmlValue($infoDetails)).'</p>'];
        } else {
            // Soft delete, preserving the loan and audit trail for accountability.
            // The loan.status column must accept the value DELETED (VARCHAR or suitable ENUM).
            $newStatus = 'DELETED';
            $s = $conn->prepare("UPDATE loan SET status = ? WHERE id = ? AND UPPER(TRIM(status)) IN ('PENDING','INFO_PROVIDED')");
            $s->bind_param('si', $newStatus, $id);
            $auditAction = 'deleted'; $auditReason = $deleteReason;
        }
        $s->execute(); $changed = $s->affected_rows; $s->close();
        if ($changed !== 1) { $conn->rollback(); $skipped++; continue; }
        logLoanAudit($conn, $id, $actorId, $role, $auditAction,
            $comments !== '' ? $comments : null, $auditReason, $oldStatus, $newStatus);
        if ($action === 'approve' && in_array($clientType, ['customer','member'], true)) {
            $s = $conn->prepare("UPDATE loan_messages SET message_type = 'loan_approved', is_read = 1 WHERE user_id = ? AND message_type = 'loan_request'");
            if ($s) { $s->bind_param('i', $clientId); $s->execute(); $s->close(); }
        }
        if ($officerNotification) {
            notifyUser($conn, $officerNotification[0], $id, $officerNotification[1], $officerNotification[2]);
        }
        $conn->commit();
        $success++;
        if ($notification) {
            $destination = $action === 'request_info' ? (string)($officer['email'] ?? '') : $clientEmail;
            safeMail($destination, $notification[0], $notification[1]);
        }
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Loan action '.$action.' on #'.$id.': '.$e->getMessage());
        $failed++;
        if (count($errors) < 5) $errors[] = '#'.$id;
    }
}
finishReview("{$success} loan(s) processed. {$skipped} skipped (not pending or not found). {$failed} failed." . ($errors ? ' Failed IDs: '.implode(', ', $errors).'. Check server logs.' : ''));
