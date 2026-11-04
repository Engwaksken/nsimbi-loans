<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require "db.php";

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($amount): string {
    return 'UGX ' . number_format((float)$amount);
}

function client_file_url(?string $path): string {
    if (!$path) return '';
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    return "/loans/clients/" . ltrim($path, '/');
}

function fmt_date(?string $value): string {
    if (!$value) return '-';
    $ts = strtotime($value);
    return $ts ? date('d M Y', $ts) : h($value);
}

function fmt_datetime(?string $value): string {
    if (!$value) return '-';
    $ts = strtotime($value);
    return $ts ? date('d M Y, H:i') : h($value);
}

$user_id = (int)($_GET['user_id'] ?? $_GET['client_id'] ?? $_GET['id'] ?? 0);

if ($user_id <= 0) {
    http_response_code(400);
    echo 'Invalid client selected.';
    exit;
}

/**
 * Fetch every client-detail column from both tables (excluding the two
 * password hash columns, which are never shown). This mirrors the full
 * field set captured at registration time - personal, contact, bank,
 * business, sales performance, credit score, next of kin, and documents.
 */
$clientSql = "
    SELECT 
        u.id AS user_id,
        u.fname,
        u.lname,
        u.username,
        u.email AS user_email,
        u.image_url,
        u.status AS user_status,
        u.role,
        u.datetime AS user_created_at,

        c.id AS customer_id,
        c.gender,
        c.dob,
        c.nin,
        c.phone,
        c.email AS customer_email,
        c.bank_name,
        c.bank_account,
        c.district,
        c.business_name,
        c.business_type,
        c.location,
        c.business_period,
        c.amount_last,
        c.amount_this,
        c.loan_limit,
        c.credit_score,
        c.nok_name,
        c.nok_tel,
        c.nok_rel,
        c.active,
        c.id_front,
        c.id_back,
        c.license,
        c.stock,
        c.applicant_signature
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    WHERE c.user_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($clientSql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    http_response_code(404);
    echo 'Client not found.';
    exit;
}

$loanStmt = $conn->prepare("
    SELECT 
        l.id,
        l.amount,
        l.interest,
        COALESCE((
            SELECT SUM(p.amount)
            FROM payments p
            WHERE p.loan_id = l.id
            AND p.user_id = l.client_id
            AND (
                p.status = 'PAID'
                OR p.status = 'SUCCESS'
                OR p.status = 'COMPLETED'
                OR p.status = 'APPROVED'
                OR p.status IS NULL
                OR p.status = ''
            )
        ), 0) AS amount_paid,
        l.loan_balance,
        l.outstanding_balance,
        l.status,
        l.datetime,
        l.due_date
    FROM loan l
    WHERE l.client_id = ?
    ORDER BY l.id DESC
");

$loans = [];
$totalLoans = 0;
$totalAmount = 0;
$totalPaid = 0;
$totalBalance = 0;

$loanStmt->bind_param("i", $user_id);
$loanStmt->execute();
$loanResult = $loanStmt->get_result();

while ($loan = $loanResult->fetch_assoc()) {
    $amount   = (float)($loan['amount'] ?? 0);
    $interest = (float)($loan['interest'] ?? 0);
    $paid     = (float)($loan['amount_paid'] ?? 0);
    $balance  = isset($loan['loan_balance'])
        ? (float)$loan['loan_balance']
        : (float)($loan['outstanding_balance'] ?? 0);

    $loans[] = $loan;
    $totalLoans++;
    $totalAmount  += ($amount + $interest);
    $totalPaid    += $paid;
    $totalBalance += $balance;
}

$loanStmt->close();

$fullName   = trim(($client['fname'] ?? '') . ' ' . ($client['lname'] ?? ''));
$isActive   = ((int)($client['active'] ?? 0) === 1 && (int)($client['user_status'] ?? 0) === 1);
$statusText = $isActive ? 'Active' : 'Blocked';

// Prefer the customer-record email if the user-record one is blank, and vice versa.
$email = ($client['customer_email'] ?? '') !== '' ? $client['customer_email'] : ($client['user_email'] ?? '');

$documents = [
    'Profile Image'             => $client['image_url'] ?? '',
    'National ID Front'         => $client['id_front'] ?? '',
    'National ID Back'          => $client['id_back'] ?? '',
    'License / Certificate'     => $client['license'] ?? '',
    'Business Photo / Stock'    => $client['stock'] ?? '',
    'Applicant Signature'       => $client['applicant_signature'] ?? '',
];

$generatedAt = date('d M Y, H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($fullName ?: 'Client') ?> - Profile</title>
<style>
    * { box-sizing: border-box; }

    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        color: #1f2937;
        margin: 0;
        padding: 32px 40px;
        font-size: 13px;
        line-height: 1.5;
    }

    .doc-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 3px solid #123299;
        padding-bottom: 16px;
        margin-bottom: 24px;
    }

    .doc-header h1 {
        font-size: 20px;
        margin: 0 0 4px;
        color: #123299;
    }

    .doc-header .sub {
        color: #6b7280;
        font-size: 12px;
    }

    .doc-header .generated {
        text-align: right;
        color: #6b7280;
        font-size: 11px;
    }

    .status-pill {
        display: inline-block;
        padding: 3px 12px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        margin-top: 6px;
    }
    .status-active  { background: #dcfce7; color: #15803d; }
    .status-blocked { background: #fee2e2; color: #b91c1c; }

    .section {
        margin-bottom: 22px;
    }

    .section h2 {
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #123299;
        border-bottom: 1px solid #e5e7eb;
        padding-bottom: 6px;
        margin: 0 0 12px;
    }

    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 10px 24px;
    }

    .info-item .lbl {
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: #9ca3af;
        margin-bottom: 2px;
    }

    .info-item .val {
        font-size: 13px;
        font-weight: 600;
        color: #111827;
    }

    .stats-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-bottom: 8px;
    }

    .stat-box {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 10px 12px;
    }

    .stat-box .num {
        font-size: 15px;
        font-weight: 700;
        color: #111827;
    }

    .stat-box .lbl {
        font-size: 10.5px;
        color: #6b7280;
        text-transform: uppercase;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }

    th, td {
        text-align: left;
        padding: 7px 8px;
        border-bottom: 1px solid #eef0f3;
    }

    th {
        background: #f8f9fc;
        font-size: 10.5px;
        text-transform: uppercase;
        color: #6b7280;
        letter-spacing: .03em;
    }

    .doc-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px 24px;
    }

    .doc-list li {
        display: flex;
        justify-content: space-between;
        border-bottom: 1px dotted #e5e7eb;
        padding-bottom: 6px;
        font-size: 12.5px;
    }

    .doc-list .uploaded { color: #15803d; font-weight: 600; }
    .doc-list .missing  { color: #9ca3af; }

    .toolbar {
        position: fixed;
        top: 16px;
        right: 16px;
    }

    .toolbar button {
        background: #123299;
        color: #fff;
        border: none;
        padding: 10px 18px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(18,50,153,.25);
    }

    .toolbar button:hover { background: #0e2678; }

    footer.doc-footer {
        margin-top: 30px;
        padding-top: 12px;
        border-top: 1px solid #e5e7eb;
        font-size: 10.5px;
        color: #9ca3af;
        text-align: center;
    }

    @media print {
        body { padding: 0 28px; }
        .toolbar { display: none !important; }
        .section { break-inside: avoid; }
        .info-grid { grid-template-columns: 1fr 1fr 1fr; }
    }

    @media (max-width: 700px) {
        .info-grid { grid-template-columns: 1fr 1fr; }
        .stats-row { grid-template-columns: 1fr 1fr; }
        .doc-list { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>

    <div class="toolbar no-print">
        <button onclick="window.print()">
            Print / Save as PDF
        </button>
    </div>

    <div class="doc-header">
        <div>
            <h1><?= h($fullName ?: 'Unknown Client') ?></h1>
            <div class="sub"><?= h($client['business_name'] ?: 'No business name on file') ?></div>
            <span class="status-pill <?= $isActive ? 'status-active' : 'status-blocked' ?>">
                <?= h($statusText) ?>
            </span>
        </div>
        <div class="generated">
            Client Profile Report<br>
            Client ID: <?= h($client['user_id']) ?> &nbsp;.&nbsp; Customer Ref: <?= h($client['customer_id']) ?><br>
            Generated <?= h($generatedAt) ?>
        </div>
    </div>

    <div class="section">
        <h2>Personal Details</h2>
        <div class="info-grid">
            <div class="info-item"><div class="lbl">Username</div><div class="val"><?= h($client['username'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">Email</div><div class="val"><?= h($email ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">Phone</div><div class="val"><?= h($client['phone'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">Gender</div><div class="val"><?= h($client['gender'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">Date of Birth</div><div class="val"><?= fmt_date($client['dob'] ?? null) ?></div></div>
            <div class="info-item"><div class="lbl">National ID (NIN)</div><div class="val"><?= h($client['nin'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">Client Since</div><div class="val"><?= fmt_datetime($client['user_created_at'] ?? null) ?></div></div>
        </div>
    </div>

    <div class="section">
        <h2>Bank Details</h2>
        <div class="info-grid">
            <div class="info-item"><div class="lbl">Bank Name</div><div class="val"><?= h($client['bank_name'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">Bank Account Number</div><div class="val"><?= h($client['bank_account'] ?: '-') ?></div></div>
        </div>
    </div>

    <div class="section">
        <h2>Business Details</h2>
        <div class="info-grid">
            <div class="info-item"><div class="lbl">Business Name</div><div class="val"><?= h($client['business_name'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">Business Type</div><div class="val"><?= h($client['business_type'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">Time in Business</div><div class="val"><?= h($client['business_period'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">Location</div><div class="val"><?= h($client['location'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">District</div><div class="val"><?= h($client['district'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">&nbsp;</div><div class="val">&nbsp;</div></div>
            <div class="info-item"><div class="lbl">Sales - Last Period</div><div class="val"><?= h($client['amount_last'] !== null && $client['amount_last'] !== '' ? money($client['amount_last']) : '-') ?></div></div>
            <div class="info-item"><div class="lbl">Sales - Prior Period</div><div class="val"><?= h($client['amount_this'] !== null && $client['amount_this'] !== '' ? money($client['amount_this']) : '-') ?></div></div>
            <div class="info-item"><div class="lbl">Credit Score</div><div class="val"><?= h($client['credit_score'] ?? '-') ?> / 10</div></div>
            <div class="info-item"><div class="lbl">Loan Limit</div><div class="val"><?= money($client['loan_limit'] ?? 0) ?></div></div>
        </div>
    </div>

    <div class="section">
        <h2>Next of Kin</h2>
        <div class="info-grid">
            <div class="info-item"><div class="lbl">Full Name</div><div class="val"><?= h($client['nok_name'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">Phone</div><div class="val"><?= h($client['nok_tel'] ?: '-') ?></div></div>
            <div class="info-item"><div class="lbl">Relationship</div><div class="val"><?= h($client['nok_rel'] ?: '-') ?></div></div>
        </div>
    </div>

    <div class="section">
        <h2>Documents on File</h2>
        <ul class="doc-list">
            <?php foreach ($documents as $label => $path): ?>
                <?php $has = client_file_url($path) !== ''; ?>
                <li>
                    <span><?= h($label) ?></span>
                    <span class="<?= $has ? 'uploaded' : 'missing' ?>">
                        <?= $has ? 'Uploaded' : 'Not uploaded' ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="section">
        <h2>Loan Summary</h2>
        <div class="stats-row">
            <div class="stat-box">
                <div class="num"><?= number_format($totalLoans) ?></div>
                <div class="lbl">Total Loans</div>
            </div>
            <div class="stat-box">
                <div class="num"><?= money($totalAmount) ?></div>
                <div class="lbl">Total Amount</div>
            </div>
            <div class="stat-box">
                <div class="num"><?= money($totalPaid) ?></div>
                <div class="lbl">Total Paid</div>
            </div>
            <div class="stat-box">
                <div class="num"><?= money($totalBalance) ?></div>
                <div class="lbl">Outstanding</div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Loan History</h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Loan ID</th>
                    <th>Amount</th>
                    <th>Interest</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Issued</th>
                    <th>Due Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($loans)): ?>
                    <?php foreach ($loans as $i => $loan): ?>
                        <?php
                        $loanBalance = isset($loan['loan_balance'])
                            ? $loan['loan_balance']
                            : ($loan['outstanding_balance'] ?? 0);
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>#<?= h($loan['id']) ?></td>
                            <td><?= money($loan['amount'] ?? 0) ?></td>
                            <td><?= money($loan['interest'] ?? 0) ?></td>
                            <td><?= money($loan['amount_paid'] ?? 0) ?></td>
                            <td><?= money($loanBalance) ?></td>
                            <td><?= h($loan['status'] ?? 'N/A') ?></td>
                            <td><?= fmt_datetime($loan['datetime'] ?? null) ?></td>
                            <td><?= fmt_date($loan['due_date'] ?? null) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9" style="text-align:center;color:#9ca3af;">No loan history found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <footer class="doc-footer">
        This document was generated automatically and reflects data at the time of generation.
    </footer>

    <script>
       
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>

</body>
</html>