<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
require 'db.php';
require_once __DIR__ . '/loan_audit.php';
require_once __DIR__ . '/../php/mail-function.php';
require_once __DIR__ . '/../includes/loan_offers.php';
require_once __DIR__ . '/../includes/loan_products.php';
require_once __DIR__ . '/../includes/loan_notifications.php';

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

// Approve = send the client an offer. The reviewer picks the applicant's
// requested amount, the system-calculated amount or a custom amount. Bulk
// approval offers each applicant the amount they asked for.
$offerChoice = $bulk ? 'requested' : postText('offer_choice', 20);
$customAmount = (float)preg_replace('/[^0-9.]/', '', postText('custom_amount', 30));
$ninVerified = ($_POST['nin_verified'] ?? '') === '1';
$ninRef = postText('nin_reference', 100);
if ($action === 'approve') {
    if (!in_array($offerChoice, ['requested', 'system', 'custom'], true)) finishReview('Choose the amount to offer.');
    if ($offerChoice === 'custom' && $customAmount < 10000) finishReview('Enter the amount to offer (at least UGX 10,000).');
    loan_offers_ensure_schema($conn);
    loan_products_ensure_schema($conn);
}
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
        $offerEvent = null;
        $staffAmount = (float)($loan['amount'] ?? 0);
        if ($action === 'approve' && $clientType === 'customer') {
            // Mobile/web customers: send an offer the client accepts or rejects.
            $requested = (float)($loan['requested_amount'] ?? 0) ?: (float)($loan['amount'] ?? 0);
            $system = (float)($loan['system_amount'] ?? 0);
            if ($system <= 0) {
                $s = $conn->prepare('SELECT loan_limit FROM customers WHERE user_id = ? LIMIT 1');
                $s->bind_param('i', $clientId); $s->execute();
                $system = (float)($s->get_result()->fetch_assoc()['loan_limit'] ?? 0); $s->close();
            }
            $offer = match ($offerChoice) {
                'system' => $system,
                'custom' => $customAmount,
                default  => $requested,
            };
            if ($offer < 10000) {
                throw new RuntimeException("offer amount {$offer} too small ({$offerChoice})");
            }

            // Offers must stay within the loan product's minimum and maximum.
            $product = loan_product_get($conn, (int)($loan['loan_product_id'] ?? 0));
            if ($product) {
                $limitError = loan_product_amount_error($product, $offer);
                if ($limitError !== '') {
                    $conn->rollback(); $skipped++;
                    if (count($errors) < 5) $errors[] = '#'.$id.' ('.$limitError.')';
                    continue;
                }
            }

            // NIN must be checked (Laboremus STREAMLINE Flex) once per client.
            $s = $conn->prepare('SELECT nin, nin_verified_at FROM customers WHERE user_id = ? LIMIT 1');
            $s->bind_param('i', $clientId); $s->execute();
            $cust = $s->get_result()->fetch_assoc() ?: []; $s->close();
            if (empty($cust['nin_verified_at'])) {
                if (!$ninVerified) {
                    $conn->rollback(); $skipped++;
                    if (count($errors) < 5) $errors[] = '#'.$id.' (NIN not verified)';
                    continue;
                }
                $s = $conn->prepare('UPDATE customers SET nin_verified_at = NOW(), nin_verified_by = ?, nin_verification_ref = ? WHERE user_id = ?');
                $s->bind_param('isi', $actorId, $ninRef, $clientId); $s->execute(); $s->close();
            }

            $period = max(1, (int)($loan['loan_period'] ?? 1));
            $unit = loan_term_unit((string)($loan['loan_period_unit'] ?? 'month'));
            $quote = loan_quote_for_loan($conn, $loan, $offer);
            $rate = $quote['interest_rate'];
            $rateText = rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%'
                . (($quote['interest_rate_basis'] ?? '') === 'per_month' ? ' per month' : '') . ' interest';
            $installments = (int)$quote['installments'];
            $installmentAmount = (float)$quote['installment_amount'];
            $interest = $quote['interest'];
            $total = $quote['total_repayment'];
            $fee = min($quote['processing_fee'], $offer);
            $dueDate = loan_term_due_date($period, $unit);
            $newStatus = 'OFFERED';
            $staffAmount = $offer;
            $s = $conn->prepare("
                UPDATE loan
                SET status = ?, amount = ?, offer_amount = ?, offer_choice = ?, offered_by = ?, offered_at = NOW(),
                    requested_amount = COALESCE(requested_amount, ?), system_amount = COALESCE(system_amount, ?),
                    principal_balance = ?, interest = ?, interest_balance = ?, interest_rate = ?,
                    outstanding_balance = ?, loan_balance = ?, processing_fee = ?, due_date = ?,
                    installment_count = ?, installment_amount = ?, updated_at = NOW()
                WHERE id = ? AND UPPER(TRIM(status)) IN ('PENDING','INFO_PROVIDED')
            ");
            $s->bind_param('sddsidddddddddsidi',
                $newStatus, $offer, $offer, $offerChoice, $actorId,
                $requested, $system,
                $offer, $interest, $interest, $rate,
                $total, $total, $fee, $dueDate, $installments, $installmentAmount, $id);
            $auditAction = 'approved';
            $auditReason = 'Offer ' . loan_money($offer) . ' (' . $offerChoice . '; requested ' . loan_money($requested)
                . ', system ' . loan_money($system) . ')' . ($ninRef !== '' ? '; NIN ref ' . $ninRef : '');
            $offerEvent = [
                'title' => 'Your loan offer is ready - Nsimbi Impact',
                'body'  => "Dear {$clientName}, we can offer you a loan of " . loan_money($offer)
                    . ' for ' . $quote['term_label'] . '. You will repay ' . loan_money($total)
                    . ' (including ' . $rateText . ')'
                    . ($installments > 1 ? ', in ' . $installments . ' ' . ($quote['repayment_frequency'] ?? 'monthly') . ' installments of about ' . loan_money($installmentAmount) : '') . '.'
                    . (($quote['penalty_rate'] ?? 0) > 0 ? ' Late payment: ' . rtrim(rtrim(number_format((float)$quote['penalty_rate'], 2), '0'), '.') . '% of the unpaid balance for every 30 days overdue.' : '')
                    . ' A processing fee of ' . loan_money($fee) . ' is taken from the payout, so you will receive '
                    . loan_money($offer - $fee) . '. Open the Nsimbi app to accept or reject this offer.'
                    . ($comments !== '' ? "\n\n" . $comments : ''),
            ];
        } elseif ($action === 'approve') {
            // Members and startups keep the previous direct approval.
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
            $officerName = trim((string)$officer['fname'].' '.(string)$officer['lname']) ?: 'Your loan officer';
            $officerEmail = (string)($officer['email'] ?? '');
            $notification = ['More Information Needed for Your Loan Request - Nsimbi Impact',
                '<h3>Dear '.htmlValue($clientName).',</h3>'
                .'<p>Thank you for your loan request of UGX '.htmlValue($amount).'. Before we can make a decision, we need some more information from you:</p>'
                .'<p style="background:#f6f7fb;border-left:3px solid #5e72e4;padding:10px 12px">'.nl2br(htmlValue($infoDetails)).'</p>'
                .'<p>Your loan officer, <strong>'.htmlValue($officerName).'</strong>, will contact you to collect it.'
                .($officerEmail !== '' ? ' You can also reach them at <a href="mailto:'.htmlValue($officerEmail).'">'.htmlValue($officerEmail).'</a>.' : '')
                .'</p><p>Nsimbi Impact</p>'];
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
        if ($offerEvent) {
            loan_notify_client($conn, $clientId, $id, 'loan_offer', $offerEvent['title'], $offerEvent['body']);
        }
        if ($notification) {
            safeMail($clientEmail, $notification[0], $notification[1]);
        }
        // Staff emails: officer (+ accountants on approval). Sent after the response.
        if ($action !== 'delete') {
            loan_notify_decision($conn, $id, $action, [
                'comments'     => $comments,
                'reason'       => $reason,
                'info_details' => $infoDetails,
                'offer_amount' => $staffAmount,
                'new_status'   => $newStatus,
            ]);
        }
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Loan action '.$action.' on #'.$id.': '.$e->getMessage());
        $failed++;
        if (count($errors) < 5) $errors[] = '#'.$id;
    }
}
finishReview("{$success} loan(s) processed. {$skipped} skipped. {$failed} failed."
    . ($errors ? ' Not processed: '.implode(', ', $errors).'.' : '')
    . ($failed ? ' Check server logs.' : '')
    . ($skipped > count(array_filter($errors, static fn($e) => str_contains($e, '('))) ? ' Loans that are no longer pending are skipped.' : ''));
