<?php
$phead = "Payment History";

require "nav-head.php";
require_once __DIR__ . '/../includes/loan_workflow.php';

/*
 * Client payment history.
 *
 * Payments are matched through their loan (payments.loan_id -> loan.client_id)
 * because not every payment path fills payments.user_id (staff-recorded
 * payments don't). Styling uses loanpro.css; Bootstrap is not loaded here.
 */

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return 'UGX ' . number_format((float)$amount, 2);
}

function fmt_date($value, string $format = 'd M Y, H:i'): string
{
    $ts = $value ? strtotime((string)$value) : false;
    return $ts ? date($format, $ts) : '-';
}

// Payment statuses that mean the money did NOT land.
const UNSETTLED_PAYMENT_STATUSES = ['PENDING', 'FAILED', 'REJECTED', 'CANCELLED', 'REVERSED', 'DECLINED'];

$payments = [];
$open_loans = ['count' => 0, 'balance' => 0.0];

try {
    // Optional payment columns differ between installs.
    $method_col = loan_table_has_column($conn, 'payments', 'payment_method') ? 'p.payment_method'
        : (loan_table_has_column($conn, 'payments', 'method') ? 'p.method' : "''");
    $ref_col = loan_table_has_column($conn, 'payments', 'transaction_reference') ? 'p.transaction_reference'
        : (loan_table_has_column($conn, 'payments', 'reference') ? 'p.reference' : "''");
    $status_col = loan_table_has_column($conn, 'payments', 'status') ? 'p.status' : "''";
    $date_col = loan_table_has_column($conn, 'payments', 'datetime') ? 'p.datetime'
        : (loan_table_has_column($conn, 'payments', 'created_at') ? 'p.created_at' : 'NULL');

    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.loan_id,
            p.amount,
            {$method_col} AS method,
            {$ref_col}    AS reference,
            {$status_col} AS status,
            {$date_col}   AS paid_at,
            l.amount       AS loan_amount,
            l.loan_balance AS loan_balance,
            l.status       AS loan_status
        FROM payments p
        INNER JOIN loan l ON l.id = p.loan_id
        WHERE l.client_id = ?
        ORDER BY paid_at DESC, p.id DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS loans, COALESCE(SUM(loan_balance), 0) AS balance
        FROM loan
        WHERE client_id = ?
          AND UPPER(status) IN ('OUTSTANDING', 'OVERDUE')
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $open_loans = ['count' => (int)$row['loans'], 'balance' => (float)$row['balance']];
} catch (mysqli_sql_exception $e) {
    error_log('loan_payments load failed: ' . $e->getMessage());
    echo '<div class="alert alert-danger">We could not load your payment history right now. Please try again later.</div>';
    require "footer.php";
    exit;
}

/* ---------- Statistics ---------- */
$total_paid      = 0.0;
$settled_count   = 0;
$unsettled_count = 0;
$this_month_paid = 0.0;
$last_payment    = null;
$loans_paid      = [];
$month_key       = date('Y-m');

foreach ($payments as &$payment) {
    $status = strtoupper(trim((string)$payment['status']));
    $payment['settled'] = !in_array($status, UNSETTLED_PAYMENT_STATUSES, true);

    if (!$payment['settled']) {
        $unsettled_count++;
        continue;
    }

    $amount = (float)$payment['amount'];
    $total_paid += $amount;
    $settled_count++;
    $loans_paid[(int)$payment['loan_id']] = true;

    if ($payment['paid_at'] && date('Y-m', strtotime((string)$payment['paid_at'])) === $month_key) {
        $this_month_paid += $amount;
    }

    // Rows are newest first, so the first settled one is the latest.
    if ($last_payment === null) {
        $last_payment = $payment;
    }
}
unset($payment);

function payment_badge(array $payment): string
{
    $status = strtoupper(trim((string)$payment['status']));
    if ($status === '') {
        $status = 'RECEIVED';
    }

    $class = $payment['settled'] ? 'badge-success' : ($status === 'PENDING' ? 'badge-warning' : 'badge-danger');
    return '<span class="badge ' . $class . '">' . esc($status) . '</span>';
}

function loan_badge(string $status): string
{
    $status = strtoupper(trim($status)) ?: 'UNKNOWN';
    $class = [
        'CLEARED'     => 'badge-success',
        'OUTSTANDING' => 'badge-info',
        'OVERDUE'     => 'badge-danger',
        'PENDING'     => 'badge-warning',
    ][$status] ?? 'badge-muted';

    return '<span class="badge ' . $class . '">' . esc($status) . '</span>';
}
?>

<div class="page-header">
    <div>
        <div class="title">Payment History</div>
        <div class="sub">Every repayment made towards your loans.</div>
    </div>
    <a href="my_loans" class="btn btn-primary btn-sm">
        <i class="fas fa-money-bill-wave"></i> Make a Payment
    </a>
</div>

<div class="stats-grid">
    <div class="stat-card c-green">
        <div class="stat-head">
            <div>
                <div class="stat-label">Total Paid</div>
                <div class="stat-value sm"><?= money($total_paid); ?></div>
            </div>
            <div class="stat-ico c-green"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="stat-note">Across <?= count($loans_paid); ?> <?= count($loans_paid) === 1 ? 'loan' : 'loans'; ?></div>
    </div>

    <div class="stat-card c-blue">
        <div class="stat-head">
            <div>
                <div class="stat-label">Payments Made</div>
                <div class="stat-value"><?= number_format($settled_count); ?></div>
            </div>
            <div class="stat-ico c-blue"><i class="fas fa-receipt"></i></div>
        </div>
        <div class="stat-note">
            <?= $unsettled_count > 0
                ? number_format($unsettled_count) . ' pending or unsuccessful'
                : 'All payments received'; ?>
        </div>
    </div>

    <div class="stat-card c-orange">
        <div class="stat-head">
            <div>
                <div class="stat-label">Outstanding Balance</div>
                <div class="stat-value sm"><?= money($open_loans['balance']); ?></div>
            </div>
            <div class="stat-ico c-orange"><i class="fas fa-balance-scale"></i></div>
        </div>
        <div class="stat-note">
            <?= $open_loans['count'] > 0
                ? number_format($open_loans['count']) . ' active ' . ($open_loans['count'] === 1 ? 'loan' : 'loans')
                : 'No active loans'; ?>
        </div>
    </div>

    <div class="stat-card c-purple">
        <div class="stat-head">
            <div>
                <div class="stat-label">Last Payment</div>
                <div class="stat-value sm"><?= $last_payment ? money($last_payment['amount']) : '-'; ?></div>
            </div>
            <div class="stat-ico c-purple"><i class="fas fa-calendar-check"></i></div>
        </div>
        <div class="stat-note">
            <?= $last_payment ? esc(fmt_date($last_payment['paid_at'], 'd M Y')) : 'No payments yet'; ?>
            | <?= money($this_month_paid); ?> this month
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title"><span class="card-dot"></span> Payments</div>
            <div class="card-sub">Newest first</div>
        </div>
        <?php if ($payments): ?>
            <button type="button" class="btn btn-sm btn-outline" onclick="download_table_as_csv('paymentsTable', 'loan-payments.csv')">
                <i class="fas fa-download"></i> Export CSV
            </button>
        <?php endif; ?>
    </div>

    <div class="card-body no-pad">
        <?php if (!$payments): ?>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <h3>No payments yet</h3>
                <p>Payments you make towards your loans will appear here.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table" id="paymentsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Loan</th>
                            <th>Amount Paid</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Payment</th>
                            <th>Loan Balance</th>
                            <th>Loan Status</th>
                            <th class="text-center">View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $i => $payment): ?>
                            <tr>
                                <td><?= $i + 1; ?></td>
                                <td><?= esc(fmt_date($payment['paid_at'])); ?></td>
                                <td>
                                    <div class="fw-600">#<?= (int)$payment['loan_id']; ?></div>
                                    <div class="fs-xs text-muted"><?= money($payment['loan_amount']); ?></div>
                                </td>
                                <td class="fw-700 text-success"><?= money($payment['amount']); ?></td>
                                <td><?= esc($payment['method'] ?: '-'); ?></td>
                                <td><?= esc($payment['reference'] ?: '-'); ?></td>
                                <td><?= payment_badge($payment); ?></td>
                                <td><?= money($payment['loan_balance']); ?></td>
                                <td><?= loan_badge((string)$payment['loan_status']); ?></td>
                                <td class="text-center">
                                    <a href="view_loan.php?id=<?= (int)$payment['loan_id']; ?>&amp;user_id=<?= $user_id; ?>" class="btn btn-info btn-icon btn-sm" title="View loan">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require "footer.php"; ?>
