<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require 'db.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Africa/Nairobi');

/* ============================================================
   HELPERS
============================================================ */
function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return 'UGX ' . number_format((float)$amount);
}

function safeDate($date): string
{
    if (empty($date)) return 'N/A';

    $ts = strtotime((string)$date);

    return $ts ? date('d M Y', $ts) : h($date);
}

function fetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if (!empty($params)) {
        if (strlen($types) !== count($params)) {
            throw new RuntimeException('Bind mismatch.');
        }

        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Execute failed: ' . $stmt->error);
    }

    $res = $stmt->get_result();

    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }

    return null;
}

function fetchAllRows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if (!empty($params)) {
        if (strlen($types) !== count($params)) {
            throw new RuntimeException('Bind mismatch.');
        }

        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Execute failed: ' . $stmt->error);
    }

    $res = $stmt->get_result();
    $rows = [];

    while ($res && $row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function pickLoanDate(array $loan): string
{
    return (string)($loan['datetime'] ?? $loan['created_at'] ?? $loan['date_created'] ?? '');
}

function pickDueDate(array $loan): string
{
    return (string)($loan['due_date'] ?? $loan['return_date'] ?? $loan['maturity_date'] ?? '');
}

/* ============================================================
   VALIDATE LOAN
============================================================ */
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid loan selected.');
}

$loan_id = (int)$_GET['id'];

try {
    $loan = fetchOne($conn, "SELECT * FROM loan WHERE id = ? LIMIT 1", 'i', [$loan_id]);

    if (!$loan) {
        die('Loan not found.');
    }

    $client_id = (int)($loan['client_id'] ?? 0);

    $clientType = 'Unknown';
    $clientSource = 'unknown';
    $client = [];

    /* CUSTOMER: loan.client_id = customers.user_id */
    $customer = fetchOne($conn, "
        SELECT 
            'customer' AS client_source,
            u.id AS user_id,
            u.fname,
            u.lname,
            u.email,
            c.phone,
            c.nin,
            c.business_name,
            c.location,
            c.district,
            c.credit_score,
            c.loan_limit
        FROM customers c
        INNER JOIN user u ON c.user_id = u.id
        WHERE c.user_id = ?
        LIMIT 1
    ", 'i', [$client_id]);

    if ($customer) {
        $clientType = 'Customer';
        $clientSource = 'customer';
        $client = $customer;
    }

    /* STAFF / MEMBER: loan.client_id = members.user_id */
    if (!$client) {
        $member = fetchOne($conn, "
            SELECT 
                'member' AS client_source,
                u.id AS user_id,
                u.fname,
                u.lname,
                u.email,
                m.phone,
                m.nin,
                m.employer,
                m.department,
                m.job_title,
                m.home_address,
                m.loan_limit
            FROM members m
            INNER JOIN user u ON m.user_id = u.id
            WHERE m.user_id = ?
            LIMIT 1
        ", 'i', [$client_id]);

        if ($member) {
            $clientType = 'Staff Member';
            $clientSource = 'member';
            $client = $member;
        }
    }

   
    if (!$client) {
        $startup = fetchOne($conn, "
            SELECT
                'startup' AS client_source,
                st.id AS startup_id,
                st.company_name,
                st.ceo_name,
                st.email,
                st.phone,
                st.company_location,
             
                st.status AS startup_status
            FROM startups st
            WHERE st.id = ?
            LIMIT 1
        ", 'i', [$client_id]);

        if ($startup) {
            $clientType = 'Startup';
            $clientSource = 'startup';
            $client = $startup;
        }
    }

    $payments = fetchAllRows($conn, "
        SELECT 
            id,
            amount,
            status,
            datetime
        FROM payments
        WHERE loan_id = ?
        ORDER BY datetime ASC, id ASC
    ", 'i', [$loan_id]);

} catch (Throwable $e) {
    die('
        <div style="padding:20px;margin:20px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:8px;font-family:Arial">
            <strong>Error:</strong> ' . h($e->getMessage()) . '
        </div>
    ');
}

/* ============================================================
   DERIVED VALUES
============================================================ */
$total_paid = 0;

foreach ($payments as $payment) {
    $total_paid += (float)($payment['amount'] ?? 0);
}

$principal = (float)($loan['amount'] ?? 0);
$interest = (float)($loan['interest'] ?? 0);
$total_due = $principal + $interest;

$loan_balance = isset($loan['loan_balance'])
    ? (float)$loan['loan_balance']
    : max($total_due - $total_paid, 0);

$status = strtoupper((string)($loan['status'] ?? 'PENDING'));

$statusClass = match ($status) {
    'CLEARED' => 'success',
    'ACTIVE' => 'primary',
    'APPROVED' => 'success',
    'PENDING' => 'warning',
    'DEFAULTED', 'OVERDUE', 'OUTSTANDING' => 'danger',
    default => 'secondary',
};

if ($clientSource === 'startup') {
    $fullName = $client['ceo_name'] ?? 'N/A';
    $displayTitle = $client['company_name'] ?? $fullName;
} else {
    $fullName = trim(($client['fname'] ?? '') . ' ' . ($client['lname'] ?? ''));
    $fullName = $fullName !== '' ? $fullName : 'N/A';
    $displayTitle = $fullName;
}

$issueDate = pickLoanDate($loan);
$dueDate = pickDueDate($loan);

/* ============================================================
   PDF EXPORT
============================================================ */
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    ob_start();
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 18mm 14mm; }

            body {
                font-family: DejaVu Sans, Arial, sans-serif;
                font-size: 11px;
                color: #1f2937;
            }

            .header {
                text-align: center;
                border-bottom: 3px solid #2563eb;
                padding-bottom: 12px;
                margin-bottom: 18px;
            }

            .header h1 {
                margin: 0;
                color: #1e3a8a;
                font-size: 22px;
            }

            .header p {
                margin: 4px 0 0;
                color: #64748b;
            }

            .section {
                background: #2563eb;
                color: #fff;
                padding: 8px 10px;
                font-weight: bold;
                margin: 16px 0 8px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            td, th {
                border-bottom: 1px solid #e5e7eb;
                padding: 7px;
                text-align: left;
                vertical-align: top;
            }

            td:first-child {
                font-weight: bold;
                background: #f8fafc;
                width: 32%;
            }

            th {
                background: #eff6ff;
                color: #1e3a8a;
            }
        </style>
    </head>
    <body>

    <div class="header">
        <h1>Loan Report</h1>
        <p>Loan #<?= h($loan_id) ?> | <?= h($displayTitle) ?> | <?= h($clientType) ?></p>
    </div>

    <div class="section">Client Information</div>
    <table>
        <tr><td>Client Type</td><td><?= h($clientType) ?></td></tr>

        <?php if ($clientSource === 'startup'): ?>
            <tr><td>Company Name</td><td><?= h($client['company_name'] ?? 'N/A') ?></td></tr>
            <tr><td>CEO Name</td><td><?= h($client['ceo_name'] ?? 'N/A') ?></td></tr>
            <tr><td>Email</td><td><?= h($client['email'] ?? 'N/A') ?></td></tr>
            <tr><td>Phone</td><td><?= h($client['phone'] ?? 'N/A') ?></td></tr>
            <tr><td>Location</td><td><?= h($client['company_location'] ?? 'N/A') ?></td></tr>
            <tr><td>Business Type</td><td><?= h($client['business_type'] ?? 'N/A') ?></td></tr>
            <tr><td>Stage</td><td><?= h($client['stage'] ?? 'N/A') ?></td></tr>
            <tr><td>Startup Status</td><td><?= h($client['startup_status'] ?? 'N/A') ?></td></tr>
        <?php elseif ($clientSource === 'member'): ?>
            <tr><td>Full Name</td><td><?= h($fullName) ?></td></tr>
            <tr><td>Email</td><td><?= h($client['email'] ?? 'N/A') ?></td></tr>
            <tr><td>Phone</td><td><?= h($client['phone'] ?? 'N/A') ?></td></tr>
            <tr><td>NIN</td><td><?= h($client['nin'] ?? 'N/A') ?></td></tr>
            <tr><td>Employer</td><td><?= h($client['employer'] ?? 'N/A') ?></td></tr>
            <tr><td>Department</td><td><?= h($client['department'] ?? 'N/A') ?></td></tr>
            <tr><td>Job Title</td><td><?= h($client['job_title'] ?? 'N/A') ?></td></tr>
            <tr><td>Home Address</td><td><?= h($client['home_address'] ?? 'N/A') ?></td></tr>
        <?php elseif ($clientSource === 'customer'): ?>
            <tr><td>Full Name</td><td><?= h($fullName) ?></td></tr>
            <tr><td>Email</td><td><?= h($client['email'] ?? 'N/A') ?></td></tr>
            <tr><td>Phone</td><td><?= h($client['phone'] ?? 'N/A') ?></td></tr>
            <tr><td>NIN</td><td><?= h($client['nin'] ?? 'N/A') ?></td></tr>
            <tr><td>Business Name</td><td><?= h($client['business_name'] ?? 'N/A') ?></td></tr>
            <tr><td>Location</td><td><?= h($client['location'] ?? 'N/A') ?></td></tr>
            <tr><td>District</td><td><?= h($client['district'] ?? 'N/A') ?></td></tr>
            <tr><td>Credit Score</td><td><?= h($client['credit_score'] ?? 'N/A') ?></td></tr>
        <?php else: ?>
            <tr><td>Client</td><td>No matching customer, member, or startup record found.</td></tr>
        <?php endif; ?>
    </table>

    <div class="section">Loan Details</div>
    <table>
        <tr><td>Principal</td><td><?= money($principal) ?></td></tr>
        <tr><td>Interest</td><td><?= money($interest) ?></td></tr>
        <tr><td>Total Due</td><td><?= money($total_due) ?></td></tr>
        <tr><td>Total Paid</td><td><?= money($total_paid) ?></td></tr>
        <tr><td>Balance</td><td><?= money($loan_balance) ?></td></tr>
        <tr><td>Status</td><td><?= h($status) ?></td></tr>
        <tr><td>Issued Date</td><td><?= h(safeDate($issueDate)) ?></td></tr>
        <tr><td>Due Date</td><td><?= h(safeDate($dueDate)) ?></td></tr>
    </table>

    <div class="section">Payment History</div>
    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>Date</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Running Balance</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($payments): ?>
            <?php $running = $total_due; ?>
            <?php foreach ($payments as $i => $payment): ?>
                <?php
                $running -= (float)($payment['amount'] ?? 0);
                $runningBalance = max($running, 0);
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= h(safeDate($payment['datetime'] ?? '')) ?></td>
                    <td><?= money($payment['amount'] ?? 0) ?></td>
                    <td><?= h($payment['status'] ?? 'N/A') ?></td>
                    <td><?= money($runningBalance) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="5">No payments recorded.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    </body>
    </html>
    <?php

    $html = ob_get_clean();

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('Loan_Report_' . $loan_id . '.pdf', ['Attachment' => false]);
    exit;
}

/* ============================================================
   HTML PAGE
============================================================ */
require 'nav-head.php';
?>

<style>
.loan-view-page {
    padding: 1rem;
}

.loan-hero {
    background: linear-gradient(135deg, #123299, #2563eb);
    color: #fff;
    padding: 1.5rem;
    border-radius: 18px;
    margin-bottom: 1rem;
}

.loan-hero-title {
    margin: 0;
    font-weight: 800;
}

.loan-hero-subtitle {
    margin: .35rem 0 0;
    opacity: .9;
}

.loan-status-pill {
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.25);
    padding: .45rem .8rem;
    border-radius: 999px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
}

.loan-tabs-wrapper {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: .7rem;
    margin-bottom: 1rem;
    overflow-x: auto;
}

.loan-tabs {
    display: flex;
    flex-wrap: nowrap;
    gap: .5rem;
}

.loan-tabs .nav-link {
    border: 0;
    border-radius: 10px;
    color: #475569;
    background: #f8fafc;
    font-weight: 700;
    white-space: nowrap;
    padding: .65rem .9rem;
}

.loan-tabs .nav-link.active {
    background: #2563eb;
    color: #fff;
}

.loan-view-page .tab-pane {
    display: none;
}

.loan-view-page .tab-pane.show.active,
.loan-view-page .tab-pane.active {
    display: block;
}

.loan-tab-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 1.2rem;
    box-shadow: 0 8px 24px rgba(15,23,42,.05);
}

.loan-tab-header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    border-bottom: 1px solid #e5e7eb;
    padding-bottom: 1rem;
    margin-bottom: 1rem;
}

.loan-tab-header h5 {
    margin: 0;
    font-weight: 800;
}

.loan-tab-header p {
    margin: .25rem 0 0;
    color: #64748b;
}

.loan-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 1rem;
}

.loan-kpi {
    border: 1px solid #e5e7eb;
    border-left: 5px solid #2563eb;
    border-radius: 14px;
    padding: 1rem;
    background: #f8fafc;
}

.loan-kpi.warning {
    border-left-color: #f59e0b;
}

.loan-kpi.success {
    border-left-color: #16a34a;
}

.loan-kpi.danger {
    border-left-color: #dc2626;
}

.loan-kpi-label {
    margin: 0;
    color: #64748b;
    font-size: .8rem;
    text-transform: uppercase;
    font-weight: 800;
}

.loan-kpi-value {
    margin: .4rem 0 0;
    font-weight: 900;
    color: #111827;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0,1fr));
    gap: 1rem;
}

.info-item {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: .9rem;
}

.info-label {
    display: block;
    color: #64748b;
    font-size: .75rem;
    text-transform: uppercase;
    font-weight: 800;
    margin-bottom: .25rem;
}

.info-value {
    display: block;
    color: #111827;
    font-weight: 800;
}

.loan-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 760px;
}

.loan-table th {
    background: #f8fafc;
    color: #475569;
    font-size: .78rem;
    text-transform: uppercase;
    padding: .8rem;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
}

.loan-table td {
    padding: .85rem;
    border-bottom: 1px solid #e5e7eb;
}

.badge-soft-primary {
    background:#dbeafe;
    color:#1d4ed8;
}

.badge-soft-success {
    background:#dcfce7;
    color:#166534;
}

.badge-soft-warning {
    background:#fef3c7;
    color:#92400e;
}

.badge-soft-danger {
    background:#fee2e2;
    color:#b91c1c;
}

.badge-soft-secondary {
    background:#f1f5f9;
    color:#475569;
}

.payment-form-box {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 1rem;
}

.payment-input {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    padding: .75rem;
}

.lp-form-error {
    color: #b91c1c;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: .6rem .8rem;
}

.loan-empty-state {
    padding: 2rem;
    text-align: center;
    color: #64748b;
}

.text-success {
    color: #16a34a;
}

.text-end {
    text-align: right;
}

@media (max-width: 992px) {
    .loan-kpi-grid,
    .info-grid {
        grid-template-columns: repeat(2, minmax(0,1fr));
    }
}

@media (max-width: 576px) {
    .loan-kpi-grid,
    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container-fluid loan-view-page">

    <div class="loan-hero">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h4 class="loan-hero-title">Loan #<?= h($loan_id) ?></h4>
                <p class="loan-hero-subtitle">
                    <?= h($displayTitle) ?> &middot; <?= h($clientType) ?> &middot; <?= h($status) ?>
                </p>
            </div>

            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                <span class="loan-status-pill me-2">
                    <i class="fas fa-circle" style="font-size:.5rem"></i>
                    <?= h($status) ?>
                </span>

                <a id="btn-export-pdf"
                   href="?id=<?= h($loan_id) ?>&export=pdf"
                   target="_blank"
                   class="btn btn-secondary btn-sm">
                    <i class="fas fa-file-pdf me-2"></i>Export PDF
                </a>
            </div>
        </div>
    </div>

    <div class="loan-tabs-wrapper">
        <ul class="nav nav-pills loan-tabs" id="loanTabs" role="tablist">

            <li class="nav-item" role="presentation">
                <button class="nav-link active"
                        id="loan-overview-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#overview"
                        type="button"
                        role="tab"
                        aria-controls="overview"
                        aria-selected="true">
                    <i class="fas fa-chart-line me-2"></i>Overview
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button class="nav-link"
                        id="client-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#client"
                        type="button"
                        role="tab"
                        aria-controls="client"
                        aria-selected="false">
                    <i class="fas fa-user me-2"></i>Client
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button class="nav-link"
                        id="details-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#details"
                        type="button"
                        role="tab"
                        aria-controls="details"
                        aria-selected="false">
                    <i class="fas fa-file-invoice-dollar me-2"></i>Loan Details
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button class="nav-link"
                        id="payment-history-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#payments"
                        type="button"
                        role="tab"
                        aria-controls="payments"
                        aria-selected="false">
                    <i class="fas fa-receipt me-2"></i>Payment History
                    <span class="badge badge-soft-secondary ms-1"><?= count($payments) ?></span>
                </button>
            </li>

            <?php if ($status !== 'CLEARED'): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link"
                            id="add-payment-tab"
                            data-bs-toggle="pill"
                            data-bs-target="#add-payment"
                            type="button"
                            role="tab"
                            aria-controls="add-payment"
                            aria-selected="false">
                        <i class="fas fa-plus-circle me-2"></i>Add Payment
                    </button>
                </li>
            <?php endif; ?>

        </ul>
    </div>

    <div class="tab-content" id="loanTabsContent">

        <div class="tab-pane fade show active"
             id="overview"
             role="tabpanel"
             aria-labelledby="loan-overview-tab">
            <div class="loan-tab-card">
                <div class="loan-tab-header">
                    <div>
                        <h5><i class="fas fa-chart-line text-primary me-2"></i>Loan Overview</h5>
                        <p>Summary of principal, interest, repayments, and balance.</p>
                    </div>

                    <span class="badge badge-soft-<?= h($statusClass) ?> px-3 py-2">
                        <?= h($status) ?>
                    </span>
                </div>

                <div class="loan-kpi-grid">
                    <div class="loan-kpi">
                        <p class="loan-kpi-label">Principal</p>
                        <h5 class="loan-kpi-value"><?= money($principal) ?></h5>
                    </div>

                    <div class="loan-kpi warning">
                        <p class="loan-kpi-label">Interest</p>
                        <h5 class="loan-kpi-value"><?= money($interest) ?></h5>
                    </div>

                    <div class="loan-kpi success">
                        <p class="loan-kpi-label">Total Paid</p>
                        <h5 class="loan-kpi-value"><?= money($total_paid) ?></h5>
                    </div>

                    <div class="loan-kpi danger">
                        <p class="loan-kpi-label">Balance</p>
                        <h5 class="loan-kpi-value"><?= money($loan_balance) ?></h5>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade"
             id="client"
             role="tabpanel"
             aria-labelledby="client-tab">
            <div class="loan-tab-card">
                <div class="loan-tab-header">
                    <div>
                        <h5><i class="fas fa-user text-primary me-2"></i>Client Information</h5>
                        <p>Details fetched from <?= h(strtolower($clientType)) ?> record.</p>
                    </div>

                    <span class="badge badge-soft-primary px-3 py-2">
                        <?= h($clientType) ?>
                    </span>
                </div>

                <div class="info-grid">
                    <?php if ($clientSource === 'startup'): ?>

                        <div class="info-item">
                            <span class="info-label">Company Name</span>
                            <span class="info-value"><?= h($client['company_name'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">CEO Name</span>
                            <span class="info-value"><?= h($client['ceo_name'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Email</span>
                            <span class="info-value"><?= h($client['email'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Phone</span>
                            <span class="info-value"><?= h($client['phone'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Location</span>
                            <span class="info-value"><?= h($client['company_location'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Business Type</span>
                            <span class="info-value"><?= h($client['business_type'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Stage</span>
                            <span class="info-value"><?= h($client['stage'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Startup Status</span>
                            <span class="info-value"><?= h($client['startup_status'] ?? 'N/A') ?></span>
                        </div>

                    <?php elseif ($clientSource === 'member'): ?>

                        <div class="info-item">
                            <span class="info-label">Full Name</span>
                            <span class="info-value"><?= h($fullName) ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Email</span>
                            <span class="info-value"><?= h($client['email'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Phone</span>
                            <span class="info-value"><?= h($client['phone'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">NIN</span>
                            <span class="info-value"><?= h($client['nin'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Employer</span>
                            <span class="info-value"><?= h($client['employer'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Department</span>
                            <span class="info-value"><?= h($client['department'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Job Title</span>
                            <span class="info-value"><?= h($client['job_title'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Home Address</span>
                            <span class="info-value"><?= h($client['home_address'] ?? 'N/A') ?></span>
                        </div>

                    <?php elseif ($clientSource === 'customer'): ?>

                        <div class="info-item">
                            <span class="info-label">Full Name</span>
                            <span class="info-value"><?= h($fullName) ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Email</span>
                            <span class="info-value"><?= h($client['email'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Phone</span>
                            <span class="info-value"><?= h($client['phone'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">NIN</span>
                            <span class="info-value"><?= h($client['nin'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Business Name</span>
                            <span class="info-value"><?= h($client['business_name'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Location</span>
                            <span class="info-value"><?= h($client['location'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">District</span>
                            <span class="info-value"><?= h($client['district'] ?? 'N/A') ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Credit Score</span>
                            <span class="info-value"><?= h($client['credit_score'] ?? 'N/A') ?></span>
                        </div>

                    <?php else: ?>

                        <div class="info-item">
                            <span class="info-label">Client</span>
                            <span class="info-value">No matching customer, member, or startup record found.</span>
                        </div>

                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="tab-pane fade"
             id="details"
             role="tabpanel"
             aria-labelledby="details-tab">
            <div class="loan-tab-card">
                <div class="loan-tab-header">
                    <div>
                        <h5><i class="fas fa-file-invoice-dollar text-primary me-2"></i>Loan Details</h5>
                        <p>Loan dates, financial values, and current status.</p>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Loan ID</span>
                        <span class="info-value">#<?= h($loan_id) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="info-value"><?= h($status) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Principal</span>
                        <span class="info-value"><?= money($principal) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Interest</span>
                        <span class="info-value"><?= money($interest) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Total Due</span>
                        <span class="info-value"><?= money($total_due) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Total Paid</span>
                        <span class="info-value"><?= money($total_paid) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Balance</span>
                        <span class="info-value"><?= money($loan_balance) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Payments Made</span>
                        <span class="info-value"><?= count($payments) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Issued Date</span>
                        <span class="info-value"><?= h(safeDate($issueDate)) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Due Date</span>
                        <span class="info-value"><?= h(safeDate($dueDate)) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade"
             id="payments"
             role="tabpanel"
             aria-labelledby="payment-history-tab">
            <div class="loan-tab-card">
                <div class="loan-tab-header">
                    <div>
                        <h5><i class="fas fa-receipt text-primary me-2"></i>Payment History</h5>
                        <p>All payments recorded for this loan.</p>
                    </div>
                </div>

                <?php if ($payments): ?>
                    <div class="table-responsive">
                        <table class="loan-table">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th class="text-end">Running Balance</th>
                            </tr>
                            </thead>

                            <tbody>
                            <?php $running = $total_due; ?>
                            <?php foreach ($payments as $i => $payment): ?>
                                <?php
                                $running -= (float)($payment['amount'] ?? 0);
                                $runningBalance = max($running, 0);
                                ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= h(safeDate($payment['datetime'] ?? '')) ?></td>
                                    <td>
                                        <strong class="text-success">
                                            <?= money($payment['amount'] ?? 0) ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-soft-secondary px-3 py-2">
                                            <?= h($payment['status'] ?? 'N/A') ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <strong data-balance="<?= h($runningBalance) ?>">
                                            <?= money($runningBalance) ?>
                                        </strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="loan-empty-state">
                        <i class="fas fa-receipt fa-2x"></i>
                        <p>No payments have been recorded yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($status !== 'CLEARED'): ?>
            <div class="tab-pane fade"
                 id="add-payment"
                 role="tabpanel"
                 aria-labelledby="add-payment-tab">
                <div class="loan-tab-card">
                    <div class="loan-tab-header">
                        <div>
                            <h5><i class="fas fa-plus-circle text-primary me-2"></i>Add Payment</h5>
                            <p>Record a new repayment against this loan.</p>
                        </div>
                    </div>

                    <div class="payment-form-box">
                        <form id="form-add-payment-inline"
                              method="post"
                              action="added_payment.php"
                              data-balance="<?= h($loan_balance) ?>">

                            <div class="row">
                                <div class="col-lg-5 col-md-7">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold" for="payment-amount">
                                            Payment Amount
                                        </label>

                                        <input type="number"
                                               id="payment-amount"
                                               name="amount"
                                               class="payment-input"
                                               step="0.01"
                                               min="1"
                                               max="<?= h($loan_balance) ?>"
                                               placeholder="Enter payment amount"
                                               required>

                                        <small class="text-muted d-block mt-1">
                                            Outstanding balance:
                                            <strong><?= money($loan_balance) ?></strong>
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <p id="payment-feedback" class="lp-form-error" style="display:none"></p>

                            <input type="hidden" name="loan" value="<?= h($loan_id) ?>">
                            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">

                            <button type="submit" name="add" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Payment
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const buttons = document.querySelectorAll('#loanTabs .nav-link');
    const panes = document.querySelectorAll('#loanTabsContent .tab-pane');

    function openLoanTab(targetId) {
        buttons.forEach(btn => {
            btn.classList.remove('active');
            btn.setAttribute('aria-selected', 'false');
        });

        panes.forEach(pane => {
            pane.classList.remove('show', 'active');
            pane.style.display = 'none';
        });

        const btn = document.querySelector('#loanTabs .nav-link[data-bs-target="' + targetId + '"]');
        const pane = document.querySelector(targetId);

        if (btn) {
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');
        }

        if (pane) {
            pane.classList.add('show', 'active');
            pane.style.display = 'block';
        }
    }

    buttons.forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const target = this.getAttribute('data-bs-target');
            openLoanTab(target);
        });
    });

    openLoanTab('#overview');
});
</script>
<?php require 'footer.php'; ?>