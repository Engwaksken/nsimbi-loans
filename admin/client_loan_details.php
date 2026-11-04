<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Africa/Nairobi');

/* =========================================================
   RESOLVE LOAN ID (accept multiple parameter names)
========================================================= */
$loan_id = 0;

if (isset($_GET['loan_id']) && is_numeric($_GET['loan_id'])) {
    $loan_id = (int) $_GET['loan_id'];
} elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $loan_id = (int) $_GET['id'];
} elseif (isset($_POST['loan_id']) && is_numeric($_POST['loan_id'])) {
    $loan_id = (int) $_POST['loan_id'];
} elseif (isset($_POST['id']) && is_numeric($_POST['id'])) {
    $loan_id = (int) $_POST['id'];
}

/**
 * A PDF export must send raw binary output with its own Content-Type
 * header, so it can't go through nav-head.php (which already prints
 * page chrome/HTML). We only pull in the DB connection for that branch
 * and skip the site layout entirely.
 */
$wantsPdf = isset($_GET['export']) && $_GET['export'] === 'pdf';

require $wantsPdf ? "db.php" : "nav-head.php";

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($amount): string {
    return 'UGX ' . number_format((float)$amount);
}

function fmt_date(?string $value): string {
    if (!$value) return '—';
    $ts = strtotime($value);
    return $ts ? date('d M Y', $ts) : h($value);
}

function fmt_datetime(?string $value): string {
    if (!$value) return '—';
    $ts = strtotime($value);
    return $ts ? date('d M Y, H:i') : h($value);
}

/**
 * Statuses counted as a successful payment, matching the logic already
 * used elsewhere in this app (view_client.php / client_profile_pdf.php).
 */
function is_counted_payment(?string $status): bool {
    $status = strtoupper(trim((string)$status));
    return in_array($status, ['PAID', 'SUCCESS', 'COMPLETED', 'APPROVED', ''], true);
}

function render_invalid(string $message, bool $wantsPdf): void {
    if ($wantsPdf) {
        http_response_code(400);
        echo $message;
        exit;
    }

    ?>
    <div class="container-fluid py-4">
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?= h($message) ?>
        </div>
        <a href="clients.php" class="btn btn-primary">
            <i class="fas fa-arrow-left me-2"></i>
            Back to Clients
        </a>
    </div>
    <?php
    require "footer.php";
    exit;
}

/* =========================================================
   FINAL VALIDATION
========================================================= */
if ($loan_id <= 0) {
    render_invalid('Loan ID missing or invalid.', $wantsPdf);
}

/* =========================================================
   FETCH LOAN
========================================================= */
$stmt = $conn->prepare("
    SELECT *
    FROM loan
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    $stmt->close();
    render_invalid('Loan not found.', $wantsPdf);
}

$loan = $result->fetch_assoc();
$stmt->close();

$client_id = (int) $loan['client_id'];

/* =========================================================
   FETCH CLIENT
========================================================= */
$clientStmt = $conn->prepare("
    SELECT
        u.id AS user_id,
        u.fname,
        u.lname,
        u.email,
        u.image_url,

        c.phone,
        c.business_name,
        c.business_type,
        c.district,
        c.location,
        c.loan_limit
    FROM user u
    LEFT JOIN customers c ON c.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");
$clientStmt->bind_param("i", $client_id);
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();
$clientStmt->close();

$clientName = $client
    ? trim(($client['fname'] ?? '') . ' ' . ($client['lname'] ?? ''))
    : 'Unknown Client';

/* =========================================================
   FETCH PAYMENT HISTORY FOR THIS LOAN
========================================================= */
$payments = [];
$totalPaid = 0.0;

$payStmt = $conn->prepare("
    SELECT *
    FROM payments
    WHERE loan_id = ?
    ORDER BY datetime DESC, id DESC
");
$payStmt->bind_param("i", $loan_id);
$payStmt->execute();
$payResult = $payStmt->get_result();

while ($p = $payResult->fetch_assoc()) {
    $counted = is_counted_payment($p['status'] ?? null);
    $p['_counted'] = $counted;

    if ($counted) {
        $totalPaid += (float)($p['amount'] ?? 0);
    }

    $payments[] = $p;
}

$payStmt->close();

/* =========================================================
   COMPUTE LOAN TOTALS
========================================================= */
$amount   = (float)($loan['amount'] ?? 0);
$interest = (float)($loan['interest'] ?? 0);
$total    = $amount + $interest;

$balance = isset($loan['loan_balance'])
    ? (float)$loan['loan_balance']
    : (isset($loan['outstanding_balance']) ? (float)$loan['outstanding_balance'] : max(0, $total - $totalPaid));

$status      = $loan['status'] ?? 'N/A';
$issuedDate  = $loan['datetime'] ?? null;
$dueDate     = $loan['due_date'] ?? null;

/* =========================================================
   PDF EXPORT BRANCH
========================================================= */
if ($wantsPdf) {

    $generatedAt = date('d M Y, H:i');

    $paymentRows = '';
    if (!empty($payments)) {
        foreach ($payments as $i => $p) {
            $paymentRows .= '
                <tr>
                    <td>' . ($i + 1) . '</td>
                    <td>' . h($p['reference'] ?? $p['transaction_id'] ?? ('#' . $p['id'])) . '</td>
                    <td>' . money($p['amount'] ?? 0) . '</td>
                    <td>' . h($p['method'] ?? '—') . '</td>
                    <td>' . h($p['status'] ?? '—') . '</td>
                    <td>' . fmt_datetime($p['datetime'] ?? null) . '</td>
                </tr>
            ';
        }
    } else {
        $paymentRows = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;">No payments recorded</td></tr>';
    }

    $html = '
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
            h1 { font-size: 18px; color: #123299; margin-bottom: 2px; }
            .sub { color: #6b7280; font-size: 11px; margin-bottom: 14px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
            th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; font-size: 11px; }
            th { background: #f8f9fc; text-transform: uppercase; font-size: 9.5px; color: #6b7280; }
            .section-title { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #123299; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; margin: 16px 0 8px; }
            .grid td { border: none; padding: 3px 8px 3px 0; }
            .grid .lbl { color: #9ca3af; font-size: 9.5px; text-transform: uppercase; }
            .grid .val { font-weight: bold; }
        </style>
    </head>
    <body>
        <h1>Loan Statement — #' . h($loan_id) . '</h1>
        <div class="sub">Generated ' . h($generatedAt) . '</div>

        <div class="section-title">Client</div>
        <table class="grid">
            <tr>
                <td class="lbl">Name</td><td class="val">' . h($clientName) . '</td>
                <td class="lbl">Phone</td><td class="val">' . h($client['phone'] ?? '—') . '</td>
            </tr>
            <tr>
                <td class="lbl">Business</td><td class="val">' . h($client['business_name'] ?? '—') . '</td>
                <td class="lbl">District</td><td class="val">' . h($client['district'] ?? '—') . '</td>
            </tr>
        </table>

        <div class="section-title">Loan Summary</div>
        <table class="grid">
            <tr>
                <td class="lbl">Principal</td><td class="val">' . money($amount) . '</td>
                <td class="lbl">Interest</td><td class="val">' . money($interest) . '</td>
            </tr>
            <tr>
                <td class="lbl">Total Payable</td><td class="val">' . money($total) . '</td>
                <td class="lbl">Total Paid</td><td class="val">' . money($totalPaid) . '</td>
            </tr>
            <tr>
                <td class="lbl">Balance</td><td class="val">' . money($balance) . '</td>
                <td class="lbl">Status</td><td class="val">' . h($status) . '</td>
            </tr>
            <tr>
                <td class="lbl">Issued</td><td class="val">' . fmt_datetime($issuedDate) . '</td>
                <td class="lbl">Due Date</td><td class="val">' . fmt_date($dueDate) . '</td>
            </tr>
        </table>

        <div class="section-title">Payment History</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Reference</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>' . $paymentRows . '</tbody>
        </table>
    </body>
    </html>
    ';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'Loan_' . $loan_id . '_Statement.pdf';
    $dompdf->stream($filename, ['Attachment' => false]);
    exit;
}

/* =========================================================
   NORMAL PAGE VIEW
========================================================= */
$pdfUrl = 'client_loan_details.php?loan_id=' . (int)$loan_id . '&export=pdf';
?>

<div class="page-header">
    <div>
        <div class="title">Loan #<?= h($loan_id) ?></div>
        <div class="sub">Issued <?= fmt_datetime($issuedDate) ?></div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="view_client.php?user_id=<?= (int)$client_id ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to Client
        </a>

        <a href="<?= h($pdfUrl) ?>" target="_blank" rel="noopener" class="btn btn-primary">
            <i class="fas fa-file-pdf"></i> Download Statement
        </a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex align-items-center gap-3 flex-wrap">
        <div class="client-avatar-fallback">
            <?= h(strtoupper(substr($client['fname'] ?? 'C', 0, 1) . substr($client['lname'] ?? '', 0, 1))) ?>
        </div>
        <div>
            <div class="cell-name fs-lg"><?= h($clientName) ?></div>
            <div class="cell-sub"><?= h($client['business_name'] ?? '—') ?> &nbsp;•&nbsp; <?= h($client['phone'] ?? '—') ?></div>
        </div>
        <div class="ms-auto">
            <a href="view_client.php?user_id=<?= (int)$client_id ?>" class="btn btn-sm btn-outline">
                <i class="fas fa-user"></i> View Client Profile
            </a>
        </div>
    </div>
</div>

<div class="stats-grid clients-stats-grid mb-3">
    <div class="stat-card c-blue">
        <div class="stat-value"><?= money($amount) ?></div>
        <div class="stat-label">Principal</div>
    </div>

    <div class="stat-card c-indigo">
        <div class="stat-value"><?= money($interest) ?></div>
        <div class="stat-label">Interest</div>
    </div>

    <div class="stat-card c-green">
        <div class="stat-value"><?= money($totalPaid) ?></div>
        <div class="stat-label">Total Paid</div>
    </div>

    <div class="stat-card c-orange">
        <div class="stat-value"><?= money($balance) ?></div>
        <div class="stat-label">Balance</div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>Loan Details</strong>
        <span class="badge badge-primary"><?= h($status) ?></span>
    </div>

    <div class="card-body">
        <div class="detail-grid">
            <div class="detail-item">
                <div class="lbl">Total Payable</div>
                <div class="val"><?= money($total) ?></div>
            </div>
            <div class="detail-item">
                <div class="lbl">Status</div>
                <div class="val"><?= h($status) ?></div>
            </div>
            <div class="detail-item">
                <div class="lbl">Issued</div>
                <div class="val"><?= fmt_datetime($issuedDate) ?></div>
            </div>
            <div class="detail-item">
                <div class="lbl">Due Date</div>
                <div class="val"><?= fmt_date($dueDate) ?></div>
            </div>
            <div class="detail-item">
                <div class="lbl">Client Loan Limit</div>
                <div class="val"><?= money($client['loan_limit'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <strong>Payment History</strong>
    </div>

    <div class="card-body no-pad">
        <div class="table-responsive client-table-scroll">
            <table class="table data-table clients-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($payments)): ?>
                        <?php foreach ($payments as $i => $p): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= h($p['reference'] ?? $p['transaction_id'] ?? ('#' . $p['id'])) ?></td>
                                <td class="<?= $p['_counted'] ? 'text-success fw-700' : '' ?>"><?= money($p['amount'] ?? 0) ?></td>
                                <td><?= h($p['method'] ?? '—') ?></td>
                                <td>
                                    <span class="badge <?= $p['_counted'] ? 'badge-success' : 'badge-muted' ?>">
                                        <?= h($p['status'] ?: 'N/A') ?>
                                    </span>
                                </td>
                                <td><?= fmt_datetime($p['datetime'] ?? null) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fas fa-receipt"></i>
                                    <h3>No payments recorded</h3>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require "footer.php"; ?>