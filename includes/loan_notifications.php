<?php
declare(strict_types=1);

/**
 * Staff email notifications for loan applications.
 *
 *  - New request          -> every active administrator (role 1).
 *  - Approved             -> the assigned loan officer and every active accountant (role 5).
 *  - Rejected             -> the assigned loan officer.
 *  - More info requested  -> the assigned loan officer, with what is needed.
 *
 * Emails are queued and sent after the response has gone back to the browser
 * or app, so SMTP never slows a request down. Each job re-reads the loan
 * first, so a request whose transaction was rolled back sends nothing.
 * Failures are logged and never break the caller.
 */

require_once __DIR__ . '/loan_workflow.php';

const LOAN_NOTIFY_ROLE_ADMIN      = 1;
const LOAN_NOTIFY_ROLE_OFFICER    = 2;
const LOAN_NOTIFY_ROLE_ACCOUNTANT = 6; // role 5 is startups

/* ---------------------------------------------------------------------------
 | Public entry points
 * ------------------------------------------------------------------------- */

/** Email administrators about a new loan request (call once the loan exists). */
function loan_notify_new_request(mysqli $conn, int $loanId): void
{
    static $queued = [];
    if ($loanId <= 0 || isset($queued[$loanId])) {
        return;
    }
    $queued[$loanId] = true;

    // Whoever created the request (e.g. an admin keying it in) doesn't need an email about it.
    $actorId = (int)($_SESSION['user_id'] ?? 0);

    loan_notify_defer(static function () use ($conn, $loanId, $actorId): void {
        $loan = loan_notify_load($conn, $loanId);
        if (!$loan || strtoupper(trim((string)($loan['status'] ?? ''))) !== 'PENDING') {
            return; // rolled back, or not actually a pending request
        }

        $admins = array_filter(
            loan_notify_staff_by_role($conn, LOAN_NOTIFY_ROLE_ADMIN),
            static fn(array $u): bool => (int)$u['id'] !== $actorId
        );
        if (!$admins) {
            error_log("Loan #{$loanId}: no administrator email found for new-request alert.");
            return;
        }

        $officer = loan_notify_user($conn, (int)($loan['assigned_officer_id'] ?? 0));
        $rows = loan_notify_summary_rows($loan);
        $rows['Assigned officer'] = $officer ? $officer['name'] : 'Not yet assigned';

        $subject = "New loan request #{$loanId} - {$loan['_client_name']} (" . loan_notify_money(loan_notify_requested($loan)) . ')';
        $html = loan_notify_layout(
            'New loan request',
            '<p>A new loan application is waiting for review.</p>' . loan_notify_table($rows),
            'Review pending loans',
            loan_notify_admin_url('pending_loans.php')
        );

        foreach ($admins as $admin) {
            loan_notify_send($admin['email'], $subject, $html);
        }
    });
}

/**
 * Email staff about a review decision (call after the decision is committed).
 *
 * @param string $action approve|reject|request_info
 * @param array  $details comments, reason, info_details, offer_amount, new_status
 */
function loan_notify_decision(mysqli $conn, int $loanId, string $action, array $details = []): void
{
    if ($loanId <= 0 || !in_array($action, ['approve', 'reject', 'request_info'], true)) {
        return;
    }
    $reviewer = trim((string)($_SESSION['fname'] ?? '') . ' ' . (string)($_SESSION['lname'] ?? ''));
    $reviewerId = (int)($_SESSION['user_id'] ?? 0);

    loan_notify_defer(static function () use ($conn, $loanId, $action, $details, $reviewer, $reviewerId): void {
        $loan = loan_notify_load($conn, $loanId);
        if (!$loan) {
            return;
        }
        if ($reviewer === '' && $reviewerId > 0) {
            $reviewer = loan_notify_user($conn, $reviewerId)['name'] ?? '';
        }

        $officer = loan_notify_user($conn, (int)($loan['assigned_officer_id'] ?? 0));
        $client = $loan['_client_name'];
        $rows = loan_notify_summary_rows($loan);
        $rows['Assigned officer'] = $officer ? $officer['name'] : 'None';
        if ($reviewer !== '') {
            $rows['Reviewed by'] = $reviewer;
        }
        $comments = trim((string)($details['comments'] ?? ''));
        $url = loan_notify_admin_url('view_loan.php?id=' . $loanId);

        if ($action === 'approve') {
            $offer = (float)($details['offer_amount'] ?? 0);
            $isOffer = strtoupper((string)($details['new_status'] ?? $loan['status'] ?? '')) === 'OFFERED';
            if ($offer > 0) {
                $rows[$isOffer ? 'Amount offered' : 'Amount approved'] = loan_notify_money($offer);
            }
            $next = $isOffer
                ? 'An offer has been sent to the client. Once they accept it, the loan will be ready for disbursement.'
                : 'The loan is now ready for disbursement.';
            $subject = "Loan #{$loanId} approved - {$client}";
            $body = '<p>Loan #' . $loanId . ' for <strong>' . loan_notify_e($client) . '</strong> has been approved.</p>'
                . '<p>' . loan_notify_e($next) . '</p>'
                . loan_notify_table($rows)
                . loan_notify_note('Reviewer comments', $comments);

            $recipients = loan_notify_staff_by_role($conn, LOAN_NOTIFY_ROLE_ACCOUNTANT);
            if ($officer) {
                $recipients[] = $officer;
            } else {
                error_log("Loan #{$loanId} approved: no assigned officer to email.");
            }
            $html = loan_notify_layout('Loan approved', $body, 'Open the loan', $url);
        } elseif ($action === 'reject') {
            if (!$officer) {
                error_log("Loan #{$loanId} rejected: no assigned officer to email.");
                return;
            }
            $reason = trim((string)($details['reason'] ?? $loan['rejection_reason'] ?? ''));
            $subject = "Loan #{$loanId} rejected - {$client}";
            $body = '<p>Hi ' . loan_notify_e($officer['first']) . ',</p>'
                . '<p>Loan #' . $loanId . ' for <strong>' . loan_notify_e($client) . '</strong>, which you are handling, has been rejected.</p>'
                . loan_notify_note('Reason', $reason)
                . loan_notify_table($rows)
                . loan_notify_note('Reviewer comments', $comments);
            $recipients = [$officer];
            $html = loan_notify_layout('Loan rejected', $body, 'Open the loan', $url);
        } else {
            if (!$officer) {
                error_log("Loan #{$loanId} info requested: no assigned officer to email.");
                return;
            }
            $needed = trim((string)($details['info_details'] ?? $loan['info_request_details'] ?? ''));
            $contact = array_filter([
                'Client phone' => trim((string)($loan['phone'] ?? '')),
                'Client email' => $loan['_client_email'],
            ]);
            $subject = "Action needed: more information for loan #{$loanId} - {$client}";
            $body = '<p>Hi ' . loan_notify_e($officer['first']) . ',</p>'
                . '<p>Before loan #' . $loanId . ' for <strong>' . loan_notify_e($client) . '</strong> can be decided, '
                . 'please follow up with the client for the information below.</p>'
                . loan_notify_note('Information needed', $needed)
                . loan_notify_table($rows + $contact)
                . loan_notify_note('Reviewer comments', $comments);
            $recipients = [$officer];
            $html = loan_notify_layout('More information needed', $body, 'Open the loan', $url);
        }

        $sent = [];
        foreach ($recipients as $person) {
            $key = strtolower($person['email']);
            if (isset($sent[$key])) {
                continue;
            }
            $sent[$key] = true;
            loan_notify_send($person['email'], $subject, $html);
        }
    });
}

/* ---------------------------------------------------------------------------
 | Delivery
 * ------------------------------------------------------------------------- */

/** Run $job after the response has been sent. */
function loan_notify_defer(callable $job): void
{
    static $jobs = null;
    if ($jobs === null) {
        $jobs = new ArrayObject();
        register_shutdown_function(static function () use ($jobs): void {
            if (!count($jobs)) {
                return;
            }
            // Save the session (e.g. flash messages) before releasing the client.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            ignore_user_abort(true);
            @set_time_limit(max(60, 10 * count($jobs)));
            foreach ($jobs as $pending) {
                try {
                    $pending();
                } catch (Throwable $e) {
                    error_log('Loan notification failed: ' . $e->getMessage());
                }
            }
        });
    }
    $jobs->append($job);
}

function loan_notify_send(string $to, string $subject, string $html): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    try {
        if (!function_exists('sendEmail')) {
            require_once dirname(__DIR__) . '/php/mail-function.php';
        }
        $text = trim(html_entity_decode(strip_tags(str_replace(['<br />', '</h3>', '</p>', '</td>', '</tr>'], ["", "\n\n", "\n\n", ' ', "\n"], $html)), ENT_QUOTES, 'UTF-8'));
        if (sendEmail($to, $subject, $html, $text) === false) {
            error_log("Loan notification to {$to} was not sent ({$subject}).");
        }
    } catch (Throwable $e) {
        error_log("Loan notification to {$to} failed: " . $e->getMessage());
    }
}

/* ---------------------------------------------------------------------------
 | Lookups
 * ------------------------------------------------------------------------- */

/** The loan row plus _client_name / _client_email, or null when it doesn't exist. */
function loan_notify_load(mysqli $conn, int $loanId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM loan WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $loan = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$loan) {
        return null;
    }

    $clientId = (int)($loan['client_id'] ?? 0);
    $type = !empty($loan['organisation_id']) ? 'organisation' : strtolower(trim((string)($loan['client_type'] ?? '')));
    if ($type === 'organisation') {
        $clientId = (int)($loan['organisation_id'] ?? 0) ?: $clientId;
    }
    $loan['client_type'] = $type;
    $client = null;
    if ($clientId > 0 && $type === 'organisation') {
        require_once __DIR__ . '/organisations.php';
        organisations_ensure_table($conn);
        $stmt = $conn->prepare("SELECT company_name AS fname, '' AS lname, email FROM organisations WHERE id = ? LIMIT 1");
    } elseif ($clientId > 0 && $type === 'startup') {
        $column = loan_table_has_column($conn, 'startups', 'startup_id') ? 'startup_id' : 'id';
        $stmt = $conn->prepare("SELECT ceo_name AS fname, company_name AS lname, email FROM startups WHERE `{$column}` = ? LIMIT 1");
    } elseif ($clientId > 0 && in_array($type, ['customer', 'member', ''], true)) {
        $stmt = $conn->prepare('SELECT fname, lname, email FROM user WHERE id = ? LIMIT 1');
    } else {
        $stmt = false;
    }
    if ($stmt) {
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    $loan['_client_name'] = trim((string)($client['fname'] ?? '') . ' ' . (string)($client['lname'] ?? '')) ?: 'Client #' . $clientId;
    $loan['_client_email'] = trim((string)($client['email'] ?? ''));
    return $loan;
}

/** Active staff with a valid email for one role: [['id','name','first','email'], ...]. */
function loan_notify_staff_by_role(mysqli $conn, int $role): array
{
    $statusSql = loan_table_has_column($conn, 'user', 'status') ? ' AND COALESCE(status, 1) = 1' : '';
    $stmt = $conn->prepare("SELECT id, fname, lname, email FROM user WHERE role = ?{$statusSql} AND email LIKE '%@%'");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $people = [];
    while ($row = $result->fetch_assoc()) {
        if ($person = loan_notify_person($row)) {
            $people[] = $person;
        }
    }
    $stmt->close();
    return $people;
}

function loan_notify_user(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $stmt = $conn->prepare('SELECT id, fname, lname, email FROM user WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? loan_notify_person($row) : null;
}

function loan_notify_person(array $row): ?array
{
    $email = trim((string)($row['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $first = trim((string)($row['fname'] ?? ''));
    $name = trim($first . ' ' . trim((string)($row['lname'] ?? '')));
    return ['id' => (int)$row['id'], 'name' => $name ?: $email, 'first' => $first ?: ($name ?: 'there'), 'email' => $email];
}

/* ---------------------------------------------------------------------------
 | Formatting
 * ------------------------------------------------------------------------- */

function loan_notify_requested(array $loan): float
{
    return (float)($loan['requested_amount'] ?? 0) ?: (float)($loan['amount'] ?? 0);
}

function loan_notify_summary_rows(array $loan): array
{
    $period = (int)($loan['loan_period'] ?? 0);
    $unit = strtolower(trim((string)($loan['loan_period_unit'] ?? ''))) ?: 'month';
    $rows = [
        'Loan'             => '#' . (int)$loan['id'],
        'Client'           => $loan['_client_name'],
        'Client type'      => ucfirst(strtolower(trim((string)($loan['client_type'] ?? ''))) ?: 'Unknown'),
        'Amount requested' => loan_notify_money(loan_notify_requested($loan)),
    ];
    if ($period > 0) {
        $rows['Period'] = $period . ' ' . $unit . ($period === 1 ? '' : 's');
    }
    if (!empty($loan['datetime'])) {
        $rows['Submitted'] = (string)$loan['datetime'];
    }
    return $rows;
}

function loan_notify_money(float $amount): string
{
    return 'UGX ' . number_format($amount);
}

function loan_notify_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function loan_notify_admin_url(string $path): string
{
    $base = getenv('SITE_URL') ?: (defined('SITE_URL') ? (string)constant('SITE_URL') : 'https://fin.nsimbiimpact.com');
    return rtrim($base, '/') . '/admin/' . ltrim($path, '/');
}

function loan_notify_table(array $rows): string
{
    $html = '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:14px;margin:12px 0">';
    foreach ($rows as $label => $value) {
        $html .= '<tr><td style="color:#667;border-bottom:1px solid #eee"><strong>' . loan_notify_e((string)$label) . ':</strong></td>'
            . '<td style="border-bottom:1px solid #eee">' . loan_notify_e((string)$value) . '</td></tr>';
    }
    return $html . '</table>';
}

function loan_notify_note(string $label, string $text): string
{
    if ($text === '') {
        return '';
    }
    return '<p><strong>' . loan_notify_e($label) . ':</strong></p>'
        . '<p style="background:#f6f7fb;border-left:3px solid #5e72e4;padding:10px 12px;margin:0 0 12px">'
        . nl2br(loan_notify_e($text)) . '</p>';
}

function loan_notify_layout(string $heading, string $body, string $buttonLabel, string $url): string
{
    return '<div style="font-family:Arial,Helvetica,sans-serif;color:#1f2937;max-width:600px">'
        . '<h3 style="margin:0 0 12px">' . loan_notify_e($heading) . '</h3>'
        . $body
        . '<p style="margin:20px 0"><a href="' . loan_notify_e($url) . '" style="padding:10px 18px;background:#5e72e4;color:#fff;text-decoration:none;border-radius:4px">'
        . loan_notify_e($buttonLabel) . '</a></p>'
        . '<p style="color:#6b7280;font-size:12px">Nsimbi Impact loan system - automatic notification.</p>'
        . '</div>';
}
