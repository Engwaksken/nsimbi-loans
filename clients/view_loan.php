<?php
$phead = "Loan Details";

require "nav-head.php";
require_once __DIR__ . '/../includes/loan_workflow.php';

/*
 * The client is always the logged-in user. Any user_id in the URL is ignored
 * (old links still pass one), so a client can only open their own loans.
 */
$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
$loan_id = (int)($_GET['id'] ?? 0);

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return 'UGX ' . number_format((float)$amount, 2);
}

function fmt_date($value, string $format = 'd M Y'): string
{
    $ts = $value ? strtotime((string)$value) : false;
    return $ts ? date($format, $ts) : '-';
}

function not_found(string $message): void
{
    echo '<div class="empty-state"><i class="fas fa-search"></i><h3>' . esc($message) . '</h3>'
       . '<p><a href="my_loans" class="btn btn-primary btn-sm mt-2">Back to My Loans</a></p></div>';
    require "footer.php";
    exit;
}

if ($loan_id <= 0) {
    not_found('Loan not specified.');
}

$loan = null;
$payments = [];

try {
    $stmt = $conn->prepare("SELECT * FROM loan WHERE id = ? AND client_id = ? LIMIT 1");
    $stmt->bind_param("ii", $loan_id, $user_id);
    $stmt->execute();
    $loan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($loan) {
        $ref_col = loan_table_has_column($conn, 'payments', 'transaction_reference') ? 'transaction_reference'
            : (loan_table_has_column($conn, 'payments', 'reference') ? 'reference' : "''");
        $status_col = loan_table_has_column($conn, 'payments', 'status') ? 'status' : "''";
        $date_col = loan_table_has_column($conn, 'payments', 'datetime') ? 'datetime'
            : (loan_table_has_column($conn, 'payments', 'created_at') ? 'created_at' : 'NULL');

        $stmt = $conn->prepare("
            SELECT id, amount, {$ref_col} AS reference, {$status_col} AS status, {$date_col} AS paid_at
            FROM payments
            WHERE loan_id = ?
            ORDER BY paid_at DESC, id DESC
        ");
        $stmt->bind_param("i", $loan_id);
        $stmt->execute();
        $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    error_log('view_loan load failed: ' . $e->getMessage());
    not_found('We could not load this loan right now.');
}

if (!$loan) {
    not_found('Loan not found.');
}

$status   = strtoupper((string)$loan['status']);
$can_pay  = in_array($status, ['OUTSTANDING', 'OVERDUE'], true);
$repayable = (float)$loan['amount'] + (float)$loan['interest'];
$paid = 0.0;
$pending_claims = 0;
foreach ($payments as $p) {
    $ps = strtoupper(trim((string)$p['status']));
    if ($ps === 'PENDING') {
        $pending_claims++;
    } elseif (!in_array($ps, ['FAILED', 'REJECTED', 'CANCELLED', 'REVERSED', 'DECLINED'], true)) {
        $paid += (float)$p['amount'];
    }
}
$progress = $repayable > 0 ? min(100, round(($repayable - (float)$loan['loan_balance']) / $repayable * 100)) : 0;

$badge = [
    'CLEARED' => 'badge-success', 'OUTSTANDING' => 'badge-info',
    'OVERDUE' => 'badge-danger', 'PENDING' => 'badge-warning',
][$status] ?? 'badge-muted';

$flash_message = $_SESSION['message'] ?? '';
$flash_type    = $_SESSION['message_type'] ?? 'info';
unset($_SESSION['message'], $_SESSION['message_type']);
?>

<?php if ($flash_message !== ''): ?>
    <div id="_flash" data-type="<?= esc($flash_type); ?>" style="display:none"><?= esc($flash_message); ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <div class="title">Loan #<?= (int)$loan['id']; ?> <span class="badge <?= $badge; ?>"><?= esc($status); ?></span></div>
        <div class="sub">Requested <?= esc(fmt_date($loan['datetime'] ?? null)); ?> &middot; Due <?= esc(fmt_date($loan['due_date'] ?? null)); ?></div>
    </div>
    <a href="my_loans" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> My Loans</a>
</div>

<div class="stats-grid">
    <div class="stat-card c-blue">
        <div class="stat-label">Loan Amount</div>
        <div class="stat-value sm"><?= money($loan['amount']); ?></div>
        <div class="stat-note"><?= (int)$loan['loan_period']; ?> month(s) &middot; <?= esc($loan['loan_method'] ?: '-'); ?></div>
    </div>
    <div class="stat-card c-purple">
        <div class="stat-label">Total Repayable</div>
        <div class="stat-value sm"><?= money($repayable); ?></div>
        <div class="stat-note">Interest <?= money($loan['interest']); ?></div>
    </div>
    <div class="stat-card c-green">
        <div class="stat-label">Paid</div>
        <div class="stat-value sm"><?= money($paid); ?></div>
        <div class="stat-note"><?= $progress; ?>% repaid<?= $pending_claims ? ' &middot; ' . $pending_claims . ' awaiting confirmation' : ''; ?></div>
    </div>
    <div class="stat-card c-orange">
        <div class="stat-label">Balance</div>
        <div class="stat-value sm"><?= money($loan['loan_balance']); ?></div>
        <div class="stat-note">Due <?= esc(fmt_date($loan['due_date'] ?? null)); ?></div>
    </div>
</div>

<div class="grid-2 align-start">
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="card-dot"></span> Payments on this loan</div>
        </div>
        <div class="card-body no-pad">
            <?php if (!$payments): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <h3>No payments yet</h3>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Amount</th><th>Reference</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <?php
                                $ps = strtoupper(trim((string)$p['status'])) ?: 'RECEIVED';
                                $pc = $ps === 'PENDING' ? 'badge-warning'
                                    : (in_array($ps, ['FAILED', 'REJECTED', 'CANCELLED', 'REVERSED', 'DECLINED'], true) ? 'badge-danger' : 'badge-success');
                                ?>
                                <tr>
                                    <td><?= esc(fmt_date($p['paid_at'], 'd M Y, H:i')); ?></td>
                                    <td class="fw-600"><?= money($p['amount']); ?></td>
                                    <td><?= esc($p['reference'] ?: '-'); ?></td>
                                    <td><span class="badge <?= $pc; ?>"><?= esc($ps); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="card-dot"></span> Record a Payment</div>
        </div>
        <div class="card-body">
            <?php if ($status === 'CLEARED'): ?>
                <div class="alert alert-success mb-0"><i class="fas fa-check-circle"></i> This loan is fully cleared.</div>
            <?php elseif (!$can_pay): ?>
                <div class="alert alert-info mb-0"><i class="fas fa-info-circle"></i> Payments can be made once the loan is disbursed.</div>
            <?php else: ?>
                <p class="fs-sm text-muted mb-3">
                    After paying via Mobile Money or bank, enter the amount and transaction number.
                    It will show as <strong>PENDING</strong> until our team confirms it.
                </p>
                <form method="post" action="added_payment.php">
                    <input type="hidden" name="loan" value="<?= (int)$loan['id']; ?>">
                    <div class="form-group">
                        <label class="form-label" for="pay_amount">Amount Paid</label>
                        <input type="text" class="form-control" id="pay_amount" name="amount" inputmode="decimal" placeholder="e.g. 50,000" required>
                        <div class="form-help">Balance: <?= money($loan['loan_balance']); ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="pay_ref">Transaction Number</label>
                        <input type="text" class="form-control" id="pay_ref" name="rec" maxlength="100" placeholder="e.g. MP240312.1234.A56789" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-paper-plane"></i> Submit Payment</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require "footer.php"; ?>
