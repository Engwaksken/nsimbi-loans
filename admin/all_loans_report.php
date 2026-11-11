<?php
declare(strict_types=1);

require 'db.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Africa/Nairobi');

/*
 * Different installations use either startups.id or startups.startup_id.
 * Detect the available primary key so the report cannot fail with
 * "Unknown column 'st.id'" and return HTTP 500.
 */
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

/* ============================================================
   SAFE HELPERS
============================================================ */
function e($v): string
{
    if ($v === null) return '';
    if (is_object($v) || is_array($v)) return '';
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mfmt($v): string
{
    return 'UGX ' . number_format((float)$v);
}

function date_ok(string $d): string
{
    if ($d === '') return '';
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return ($x && $x->format('Y-m-d') === $d) ? $d : '';
}

function loan_dt(array $r): string
{
    return (string)($r['datetime'] ?? '');
}

function due_dt(array $r): string
{
    return (string)($r['due_date'] ??  '');
}

function qlink(array $ov = []): string
{
    $q = array_merge($_GET, $ov);
    return e(http_build_query($q));
}

function runQuery(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_result
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if (!empty($params)) {
        if (strlen($types) !== count($params)) {
            throw new RuntimeException(
                'Bind mismatch. Types: ' . strlen($types) . ' Params: ' . count($params)
            );
        }

        if (!$stmt->bind_param($types, ...$params)) {
            throw new RuntimeException('Bind failed: ' . $stmt->error);
        }
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Execute failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();

    if (!$result) {
        throw new RuntimeException('Get result failed: ' . $stmt->error);
    }

    return $result;
}

function statusBadge(string $st): string
{
    return match ($st) {
        'CLEARED' => 'badge-success',
        'ACTIVE' => 'badge-info',
        'APPROVED' => 'badge-green',
        'PENDING' => 'badge-warning',
        'DEFAULTED', 'OVERDUE', 'OUTSTANDING' => 'badge-danger',
        default => 'badge-muted',
    };
}

function typeBadge(string $t): string
{
    return match ($t) {
        'customer' => 'badge-primary',
        'member', 'staff' => 'badge-purple',
        'startup' => 'badge-warning',
        default => 'badge-muted',
    };
}

/* ============================================================
   INPUTS
============================================================ */
$status = trim($_GET['status'] ?? 'all');
$type   = trim($_GET['type'] ?? 'all');
$q      = trim($_GET['q'] ?? '');
$from   = date_ok(trim($_GET['date_from'] ?? ''));
$to     = date_ok(trim($_GET['date_to'] ?? ''));
$export = trim($_GET['export'] ?? '');

$allowedStatuses = [
    'all',
    'ACTIVE',
    'PENDING',
    'CLEARED',
    'DEFAULTED',
    'OVERDUE',
    'REJECTED',
    'APPROVED',
    'OUTSTANDING'
];

/*
 * Staff members are represented by the `members` table elsewhere in the
 * application, so the report uses the same "member" type. Keep accepting the
 * old "staff" query value for bookmarked links.
 */
if ($type === 'staff') $type = 'member';

$allowedTypes = ['all', 'customer', 'member', 'startup'];

if (!in_array($status, $allowedStatuses, true)) $status = 'all';
if (!in_array($type, $allowedTypes, true)) $type = 'all';

$limit  = $export === 'pdf' ? 1000000 : 10;
$page   = $export === 'pdf' ? 1 : max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

/* ============================================================
   WHERE BUILDER
============================================================ */
function buildWhere(string $source, string $status, string $from, string $to, string $q): array
{
    $where = [];
    $params = [];
    $types = '';

    if ($status !== 'all') {
        $where[] = 'l.status = ?';
        $params[] = $status;
        $types .= 's';
    }

    if ($from !== '') {
        $where[] = 'DATE(COALESCE(l.datetime)) >= ?';
        $params[] = $from;
        $types .= 's';
    }

    if ($to !== '') {
        $where[] = 'DATE(COALESCE(l.datetime)) <= ?';
        $params[] = $to;
        $types .= 's';
    }

    if ($q !== '') {
        $like = '%' . $q . '%';

        if ($source === 'customer') {
            $where[] = "(
                u.fname LIKE ? OR 
                u.lname LIKE ? OR 
                u.email LIKE ? OR 
                c.phone LIKE ? OR 
                c.nin LIKE ? OR 
                c.business_name LIKE ? OR 
                CAST(l.id AS CHAR) LIKE ? OR 
                l.status LIKE ?
            )";

            for ($i = 0; $i < 8; $i++) {
                $params[] = $like;
                $types .= 's';
            }
        }

        if ($source === 'staff') {
            $where[] = "(
                u.fname LIKE ? OR 
                u.lname LIKE ? OR 
                u.email LIKE ? OR 
                m.phone LIKE ? OR 
                m.nin LIKE ? OR 
                m.employer LIKE ? OR 
                CAST(l.id AS CHAR) LIKE ? OR 
                l.status LIKE ?
            )";

            for ($i = 0; $i < 8; $i++) {
                $params[] = $like;
                $types .= 's';
            }
        }

        if ($source === 'startup') {
            $where[] = "(
                st.company_name LIKE ? OR 
                st.ceo_name LIKE ? OR 
                st.email LIKE ? OR 
                st.phone LIKE ? OR 
                st.company_location LIKE ? OR 
                CAST(l.id AS CHAR) LIKE ? OR 
                l.status LIKE ?
            )";

            for ($i = 0; $i < 7; $i++) {
                $params[] = $like;
                $types .= 's';
            }
        }
    }

    return [
        'sql' => $where ? 'WHERE ' . implode(' AND ', $where) : '',
        'params' => $params,
        'types' => $types
    ];
}

$cw = buildWhere('customer', $status, $from, $to, $q);
$mw = buildWhere('staff', $status, $from, $to, $q);
$sw = buildWhere('startup', $status, $from, $to, $q);


/* ============================================================
   SELECTS
============================================================ */
$paymentSubquery = "
    SELECT 
        loan_id, 
        COALESCE(SUM(amount), 0) AS report_total_paid,
        COUNT(*) AS report_payment_count
    FROM payments
    GROUP BY loan_id
";

$selCustomer = "
    SELECT 
        l.*,
        'customer' AS report_client_type,
        u.fname,
        u.lname,
        u.email AS user_email,
        c.phone AS client_phone,
        c.nin,
        c.business_name,
        c.location,
        c.district,
        COALESCE(c.credit_score, 0) AS credit_score,
        COALESCE(c.loan_limit, 0) AS client_loan_limit,
        COALESCE(p.report_total_paid, 0) AS report_total_paid,
        COALESCE(p.report_payment_count, 0) AS report_payment_count
    FROM loan l
    INNER JOIN customers c ON l.client_id = c.user_id
    INNER JOIN user u ON c.user_id = u.id
    LEFT JOIN ($paymentSubquery) p ON p.loan_id = l.id
    {$cw['sql']}
";

$selStaff = "
    SELECT 
        l.*,
        'member' AS report_client_type,
        u.fname,
        u.lname,
        u.email AS user_email,
        m.phone AS client_phone,
        m.nin,
        m.employer AS business_name,
        m.department AS location,
        m.home_address AS district,
        0 AS credit_score,
        COALESCE(m.loan_limit, 0) AS client_loan_limit,
        COALESCE(p.report_total_paid, 0) AS report_total_paid,
        COALESCE(p.report_payment_count, 0) AS report_payment_count
    FROM loan l
    INNER JOIN members m ON l.client_id = m.user_id
    INNER JOIN user u ON m.user_id = u.id
    LEFT JOIN ($paymentSubquery) p ON p.loan_id = l.id
    {$mw['sql']}
";

$selStartup = "
    SELECT 
        l.*,
        'startup' AS report_client_type,
        st.ceo_name AS fname,
        '' AS lname,
        st.email AS user_email,
        st.phone AS client_phone,
        '' AS nin,
        st.company_name AS business_name,
        st.company_location AS location,
        '' AS district,
        0 AS credit_score,
        0 AS client_loan_limit,
        COALESCE(p.report_total_paid, 0) AS report_total_paid,
        COALESCE(p.report_payment_count, 0) AS report_payment_count
    FROM loan l
    INNER JOIN startups st ON l.client_id = st.{$startupIdColumn}
    LEFT JOIN ($paymentSubquery) p ON p.loan_id = l.id
    {$sw['sql']}
";

/* ============================================================
   COMBINED SQL
============================================================ */
switch ($type) {
    case 'customer':
        $baseSql = $selCustomer;
        $bindP = $cw['params'];
        $bindT = $cw['types'];
        break;

    case 'member':
        $baseSql = $selStaff;
        $bindP = $mw['params'];
        $bindT = $mw['types'];
        break;

    case 'startup':
        $baseSql = $selStartup;
        $bindP = $sw['params'];
        $bindT = $sw['types'];
        break;

    default:
        $baseSql = "($selCustomer) UNION ALL ($selStaff) UNION ALL ($selStartup)";
        $bindP = array_merge($cw['params'], $mw['params'], $sw['params']);
        $bindT = $cw['types'] . $mw['types'] . $sw['types'];
        break;
}

/* ============================================================
   QUERY DATA
============================================================ */
try {
    $statsRes = runQuery($conn, "
        SELECT
            COUNT(*) AS total_loans,
            COALESCE(SUM(amount), 0) AS total_disbursed,
            COALESCE(SUM(interest), 0) AS total_interest,
            COALESCE(SUM(report_total_paid), 0) AS total_collected,
            COALESCE(SUM(loan_balance), 0) AS total_outstanding
        FROM ($baseSql) t
    ", $bindT, $bindP);

    $stats = $statsRes->fetch_assoc() ?: [];

    $totalLoans       = (int)($stats['total_loans'] ?? 0);
    $totalDisbursed   = (float)($stats['total_disbursed'] ?? 0);
    $totalInterest    = (float)($stats['total_interest'] ?? 0);
    $totalCollected   = (float)($stats['total_collected'] ?? 0);
    $totalOutstanding = (float)($stats['total_outstanding'] ?? 0);
    $avgLoan          = $totalLoans > 0 ? $totalDisbursed / $totalLoans : 0;

    $typeStatsRes = runQuery($conn, "
        SELECT
            report_client_type,
            COUNT(*) AS loan_count,
            COALESCE(SUM(amount), 0) AS disbursed,
            COALESCE(SUM(report_total_paid), 0) AS collected,
            COALESCE(SUM(loan_balance), 0) AS outstanding,
            COALESCE(SUM(interest), 0) AS interest_earned
        FROM ($baseSql) t
        GROUP BY report_client_type
    ", $bindT, $bindP);

    $byType = [
        'customer' => [],
        'member' => [],
        'startup' => []
    ];

    while ($row = $typeStatsRes->fetch_assoc()) {
        $byType[$row['report_client_type']] = $row;
    }

    $statusRes = runQuery($conn, "
        SELECT 
            status, 
            COUNT(*) AS cnt
        FROM ($baseSql) t
        GROUP BY status
        ORDER BY cnt DESC
    ", $bindT, $bindP);

    $byStatus = [];

    while ($row = $statusRes->fetch_assoc()) {
        $byStatus[strtoupper((string)($row['status'] ?? ''))] = (int)$row['cnt'];
    }

    $countRes = runQuery($conn, "
        SELECT COUNT(*) AS n 
        FROM ($baseSql) t
    ", $bindT, $bindP);

    $totalRows = (int)($countRes->fetch_assoc()['n'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $limit));

    $dataRes = runQuery($conn, "
        SELECT *
        FROM ($baseSql) t
        ORDER BY COALESCE(datetime) DESC, id DESC
        LIMIT ? OFFSET ?
    ", $bindT . 'ii', array_merge($bindP, [$limit, $offset]));

    $loans = [];

    while ($r = $dataRes->fetch_assoc()) {
        $r['full_name'] = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''));
        $loans[] = $r;
    }

} catch (Throwable $e) {
    die('
        <div style="padding:20px;margin:20px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-family:Arial;border-radius:8px">
            <h3 style="margin-top:0">All Loans Report Error</h3>
            <p>' . e($e->getMessage()) . '</p>
        </div>
    ');
}

/* ============================================================
   PDF EXPORT
============================================================ */
if ($export === 'pdf') {
    // Only the PDF export needs Composer, so a missing/misconfigured vendor
    // directory can no longer break the report page itself.
    require_once __DIR__ . '/vendor/autoload.php';

    $generatedAt = date('F d, Y H:i:s');

    ob_start();
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 18mm 12mm; }
            body {
                font-family: DejaVu Sans, Arial, sans-serif;
                font-size: 9px;
                color: #1f2937;
            }
            h1 {
                color: #123299;
                margin: 0 0 8px;
                font-size: 18px;
            }
            .meta {
                color: #64748b;
                margin-bottom: 10px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
            }
            .summary td {
                border: 1px solid #e5e7eb;
                background: #f8fafc;
                padding: 8px;
                width: 25%;
            }
            .label {
                color: #64748b;
                font-size: 7px;
                text-transform: uppercase;
            }
            .value {
                font-size: 11px;
                font-weight: bold;
                color: #111827;
            }
            .rpt th {
                background: #123299;
                color: #fff;
                padding: 5px;
                text-align: left;
                font-size: 7.5px;
            }
            .rpt td {
                border-bottom: 1px solid #e5e7eb;
                padding: 4px;
                vertical-align: top;
            }
            .rpt tr:nth-child(even) td {
                background: #f8fafc;
            }
        </style>
    </head>
    <body>

    <h1>All Loans History Report</h1>
    <div class="meta">Generated: <?= e($generatedAt) ?></div>

    <table class="summary">
        <tr>
            <td>
                <div class="label">Total Loans</div>
                <div class="value"><?= number_format($totalLoans) ?></div>
            </td>
            <td>
                <div class="label">Total Disbursed</div>
                <div class="value"><?= mfmt($totalDisbursed) ?></div>
            </td>
            <td>
                <div class="label">Total Collected</div>
                <div class="value"><?= mfmt($totalCollected) ?></div>
            </td>
            <td>
                <div class="label">Outstanding</div>
                <div class="value"><?= mfmt($totalOutstanding) ?></div>
            </td>
        </tr>
    </table>

    <br>

    <table class="rpt">
        <thead>
        <tr>
            <th>#</th>
            <th>Client</th>
            <th>Type</th>
            <th>Phone</th>
            <th>Amount</th>
            <th>Interest</th>
            <th>Paid</th>
            <th>Balance</th>
            <th>Status</th>
            <th>Issued</th>
            <th>Due</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($loans): ?>
            <?php foreach ($loans as $loan): ?>
                <?php
                $dt = loan_dt($loan);
                $due = due_dt($loan);
                ?>
                <tr>
                    <td><?= e($loan['id'] ?? '') ?></td>
                    <td><?= e($loan['full_name'] ?: ($loan['business_name'] ?? '-')) ?></td>
                    <td><?= e(ucfirst((string)($loan['report_client_type'] ?? ''))) ?></td>
                    <td><?= e($loan['client_phone'] ?? '-') ?></td>
                    <td><?= mfmt($loan['amount'] ?? 0) ?></td>
                    <td><?= mfmt($loan['interest'] ?? 0) ?></td>
                    <td><?= mfmt($loan['report_total_paid'] ?? 0) ?></td>
                    <td><?= mfmt($loan['loan_balance'] ?? 0) ?></td>
                    <td><?= e($loan['status'] ?? '-') ?></td>
                    <td><?= e($dt ? date('d/m/Y', strtotime($dt)) : '-') ?></td>
                    <td><?= e($due ? date('d/m/Y', strtotime($due)) : '-') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="11" style="text-align:center;padding:15px">No loans found.</td>
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
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('All_Loans_Report_' . date('Ymd_His') . '.pdf', ['Attachment' => false]);
    exit;
}

/* ============================================================
   HTML PAGE
============================================================ */
require 'nav-head.php';
?>

<style>
    .report-page {
        padding: 1rem;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .title {
        font-size: 1.35rem;
        font-weight: 800;
        color: #111827;
    }

    .sub {
        color: #64748b;
        font-size: .9rem;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
    }

    .stat-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 1rem;
        box-shadow: 0 6px 18px rgba(15, 23, 42, .04);
        border-left: 5px solid #2563eb;
    }

    .stat-card.c-indigo { border-left-color: #4f46e5; }
    .stat-card.c-green { border-left-color: #16a34a; }
    .stat-card.c-red { border-left-color: #dc2626; }

    .stat-value {
        font-size: 1.1rem;
        font-weight: 800;
        color: #111827;
        word-break: break-word;
    }

    .stat-label {
        margin-top: .35rem;
        color: #64748b;
        font-size: .8rem;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        box-shadow: 0 6px 18px rgba(15, 23, 42, .04);
    }

    .card-header {
        padding: 1rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .card-title {
        font-weight: 800;
        color: #111827;
    }

    .card-body {
        padding: 1rem;
    }

    .card-body.no-pad {
        padding: 0;
    }

    .grid-3 {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem;
    }

    .form-label {
        font-weight: 700;
        color: #374151;
        font-size: .85rem;
        margin-bottom: .35rem;
        display: block;
    }

    .form-control,
    .form-select {
        width: 100%;
        border: 1px solid #d1d5db;
        border-radius: 10px;
        padding: .65rem .75rem;
        outline: none;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        border: 0;
        border-radius: 10px;
        padding: .6rem .85rem;
        text-decoration: none;
        cursor: pointer;
        font-weight: 700;
    }

    .btn-sm {
        padding: .45rem .65rem;
        font-size: .82rem;
    }

    .btn-primary {
        background: #2563eb;
        color: #fff;
    }

    .btn-danger {
        background: #dc2626;
        color: #fff;
    }

    .btn-info {
        background: #0ea5e9;
        color: #fff;
    }

    .btn-ghost {
        background: #f3f4f6;
        color: #374151;
    }

    .btn-icon-sm {
        width: 34px;
        height: 34px;
        justify-content: center;
        padding: 0;
    }

    .table-responsive {
        width: 100%;
        overflow-x: auto;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1150px;
    }

    .table th {
        background: #f8fafc;
        color: #475569;
        font-size: .75rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        padding: .8rem;
        border-bottom: 1px solid #e5e7eb;
        white-space: nowrap;
        text-align: left;
    }

    .table td {
        padding: .85rem;
        border-bottom: 1px solid #e5e7eb;
        vertical-align: middle;
        white-space: nowrap;
    }

    .td-dark {
        font-weight: 800;
        color: #111827;
    }

    .cell-sub {
        color: #64748b;
        font-size: .78rem;
        margin-top: .2rem;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: .28rem .55rem;
        font-size: .72rem;
        font-weight: 800;
    }

    .badge-primary { background: #dbeafe; color: #1d4ed8; }
    .badge-purple { background: #f3e8ff; color: #7e22ce; }
    .badge-warning { background: #fef3c7; color: #92400e; }
    .badge-success { background: #dcfce7; color: #166534; }
    .badge-info { background: #e0f2fe; color: #0369a1; }
    .badge-green { background: #dcfce7; color: #15803d; }
    .badge-danger { background: #fee2e2; color: #b91c1c; }
    .badge-muted { background: #f1f5f9; color: #475569; }

    .text-success { color: #16a34a; }
    .text-danger { color: #dc2626; }
    .text-end { text-align: right; }
    .fw-700 { font-weight: 700; }

    .pag-wrap {
        padding: 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
        border-top: 1px solid #e5e7eb;
    }

    .pag-info {
        color: #64748b;
        font-size: .85rem;
    }

    .pagination {
        display: flex;
        gap: .35rem;
        flex-wrap: wrap;
    }

    .pg-btn {
        min-width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        color: #334155;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 700;
    }

    .pg-btn.act {
        background: #2563eb;
        color: #fff;
        border-color: #2563eb;
    }

    .pg-btn.dis {
        opacity: .45;
        pointer-events: none;
    }

    .empty-state {
        padding: 2rem;
        text-align: center;
        color: #64748b;
    }

    @media (max-width: 992px) {
        .stats-grid,
        .grid-3 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 576px) {
        .stats-grid,
        .grid-3 {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="report-page">

    <div class="page-header mb-4">
        <div>
            <div class="title">All Loans History Report</div>
            <div class="sub"><?= number_format($totalRows) ?> loan records found</div>
        </div>

        <div>
            <a href="?<?= qlink(['export' => 'pdf', 'page' => 1]) ?>" target="_blank" class="btn btn-danger btn-sm">
                <i class="fas fa-file-pdf"></i> Export PDF
            </a>
        </div>
    </div>

    <div class="stats-grid mb-3">
        <div class="stat-card c-blue">
            <div class="stat-value"><?= number_format($totalLoans) ?></div>
            <div class="stat-label">Total Loans</div>
        </div>

        <div class="stat-card c-indigo">
            <div class="stat-value"><?= mfmt($totalDisbursed) ?></div>
            <div class="stat-label">Total Disbursed</div>
        </div>

        <div class="stat-card c-green">
            <div class="stat-value"><?= mfmt($totalCollected) ?></div>
            <div class="stat-label">Total Collected</div>
        </div>

        <div class="stat-card c-red">
            <div class="stat-value"><?= mfmt($totalOutstanding) ?></div>
            <div class="stat-label">Outstanding Balance</div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <span class="card-title">
                <i class="fas fa-filter"></i> Filter & Search
            </span>
        </div>

        <div class="card-body">
            <form method="GET">
                <div class="grid-3 mb-3">
                    <div>
                        <label class="form-label">Search</label>
                        <input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Name, phone, NIN, loan ID...">
                    </div>

                    <div>
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach ($allowedStatuses as $s): ?>
                                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
                                    <?= $s === 'all' ? 'All Statuses' : e($s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">Client Type</label>
                        <select name="type" class="form-select">
                            <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="customer" <?= $type === 'customer' ? 'selected' : '' ?>>Customers</option>
                            <option value="member" <?= $type === 'member' ? 'selected' : '' ?>>Members / Staff</option>
                            <option value="startup" <?= $type === 'startup' ? 'selected' : '' ?>>Startups</option>
                        </select>
                    </div>
                </div>

                <div class="grid-3">
                    <div>
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" value="<?= e($from) ?>" class="form-control">
                    </div>

                    <div>
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" value="<?= e($to) ?>" class="form-control">
                    </div>

                    <div style="display:flex;align-items:end;gap:.5rem;flex-wrap:wrap">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>

                        <a href="all_loans_report.php" class="btn btn-ghost">
                            <i class="fas fa-rotate-left"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">
                <i class="fas fa-table"></i> Loan Records
            </span>
            <span class="badge badge-muted"><?= number_format($totalRows) ?> records</span>
        </div>

        <div class="card-body no-pad">
            <div class="table-responsive">
                <table class="table" id="tbl-loans-report">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Client</th>
                        <th>Type</th>
                        <th>Contact</th>
                        <th>Principal</th>
                        <th>Interest</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Payments</th>
                        <th>Status</th>
                        <th>Issued</th>
                        <th>Due</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if ($loans): ?>
                        <?php $counter = $offset + 1; ?>
                        <?php foreach ($loans as $loan): ?>
                            <?php
                            $dt = loan_dt($loan);
                            $due = due_dt($loan);
                            $st = strtoupper((string)($loan['status'] ?? ''));
                            $displayName = $loan['full_name'] ?: ($loan['business_name'] ?? 'Unnamed');
                            ?>
                            <tr>
                                <td><?= $counter++ ?></td>

                                <td>
                                    <div class="td-dark"><?= e($displayName) ?></div>
                                    <div class="cell-sub"><?= e($loan['business_name'] ?? '') ?></div>
                                </td>

                                <td>
                                    <span class="badge <?= typeBadge((string)$loan['report_client_type']) ?>">
                                        <?= e(ucfirst((string)$loan['report_client_type'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="td-dark"><?= e($loan['client_phone'] ?? '-') ?></div>
                                    <div class="cell-sub"><?= e($loan['nin'] ?? '') ?></div>
                                </td>

                                <td class="td-dark"><?= mfmt($loan['amount'] ?? 0) ?></td>
                                <td><?= mfmt($loan['interest'] ?? 0) ?></td>
                                <td class="text-success fw-700"><?= mfmt($loan['report_total_paid'] ?? 0) ?></td>
                                <td class="text-danger fw-700"><?= mfmt($loan['loan_balance'] ?? 0) ?></td>

                                <td>
                                    <span class="badge badge-muted">
                                        <?= number_format((int)($loan['report_payment_count'] ?? 0)) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="badge <?= statusBadge($st) ?>">
                                        <?= e($st ?: '-') ?>
                                    </span>
                                </td>

                                <td><?= e($dt ? date('d M Y', strtotime($dt)) : '-') ?></td>
                                <td><?= e($due ? date('d M Y', strtotime($due)) : '-') ?></td>

                                <td class="text-end">
                                    <a href="view_loan.php?id=<?= e($loan['id'] ?? '') ?>" class="btn btn-info btn-icon-sm" title="View Loan">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="13">
                                <div class="empty-state">
                                    <i class="fas fa-inbox fa-2x"></i>
                                    <h3>No loans found</h3>
                                    <p>No records match the current filters.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <?php
            $base = $_GET;
            unset($base['page'], $base['export']);
            $qs = $base ? '&' . http_build_query($base) : '';
            $win = 2;
            $start = max(1, $page - $win);
            $end = min($totalPages, $page + $win);
            ?>

            <div class="pag-wrap">
                <div class="pag-info">
                    Showing <?= number_format($totalRows === 0 ? 0 : $offset + 1) ?> -
                    <?= number_format(min($offset + $limit, $totalRows)) ?>
                    of <?= number_format($totalRows) ?> records
                </div>

                <div class="pagination">
                    <a class="pg-btn <?= $page <= 1 ? 'dis' : '' ?>" href="?page=<?= max(1, $page - 1) . $qs ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>

                    <?php for ($p = $start; $p <= $end; $p++): ?>
                        <a class="pg-btn <?= $p === $page ? 'act' : '' ?>" href="?page=<?= $p . $qs ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>

                    <a class="pg-btn <?= $page >= $totalPages ? 'dis' : '' ?>" href="?page=<?= min($totalPages, $page + 1) . $qs ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require 'footer.php'; ?>