<?php
declare(strict_types=1);

$phead = 'Loan Disbursement';
require "nav-head.php";
require "services_check.php";
require_once __DIR__ . "/loan_audit.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !$conn instanceof mysqli) {
    die("Database connection not established.");
}

// Assumption: role = 5 is "Accountant" - the only role that can disburse funds.
$currentRole   = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$isAccountant  = ($currentRole === 5);

if (!$isAccountant) {
    die("Not authorized. This page is for accountants only.");
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return 'UGX ' . number_format((float)($value ?? 0));
}

function methodLabel(?string $method): string
{
    return match ($method) {
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'mobile_money'  => 'Mobile Money',
        default         => $method !== null && $method !== '' ? ucfirst($method) : 'N/A',
    };
}

// --------------------------------------------------------------
// Loans approved by a reviewer, awaiting disbursement
// --------------------------------------------------------------
$pendingSql = "
    SELECT
        l.*,
        COALESCE(NULLIF(u.fname, ''), '') AS client_fname,
        COALESCE(NULLIF(u.lname, ''), '') AS client_lname,
        u.email AS client_email
    FROM loan l
    LEFT JOIN user u ON u.id = l.client_id
    WHERE l.status = 'APPROVED'
    ORDER BY l.id DESC
";
$pendingResult = $conn->query($pendingSql);
$pendingLoans = [];
if ($pendingResult) {
    while ($row = $pendingResult->fetch_assoc()) {
        $pendingLoans[] = $row;
    }
}

$auditTrailByLoan = getLoanAuditTrailBulk($conn, array_column($pendingLoans, 'id'));

// --------------------------------------------------------------
// Top-ups approved by a reviewer, awaiting disbursement
// --------------------------------------------------------------
$pendingTopupSql = "
    SELECT
        t.*,
        l.amount AS current_amount,
        l.loan_balance AS current_balance,
        l.due_date AS current_due_date,
        COALESCE(NULLIF(u.fname, ''), '') AS client_fname,
        COALESCE(NULLIF(u.lname, ''), '') AS client_lname,
        u.email AS client_email
    FROM loan_topup_requests t
    INNER JOIN loan l ON l.id = t.loan_id
    LEFT JOIN user u ON u.id = t.client_id
    WHERE t.status = 'APPROVED'
    ORDER BY t.id DESC
";
$pendingTopupResult = $conn->query($pendingTopupSql);
$pendingTopups = [];
if ($pendingTopupResult) {
    while ($row = $pendingTopupResult->fetch_assoc()) {
        $pendingTopups[] = $row;
    }
}

$auditTrailByTopup = getTopupAuditTrailBulk($conn, array_column($pendingTopups, 'id'));

// --------------------------------------------------------------
// Recently disbursed loans (for voucher lookup / reference)
// --------------------------------------------------------------
$recentSql = "
    SELECT
        l.*,
        COALESCE(NULLIF(u.fname, ''), '') AS client_fname,
        COALESCE(NULLIF(u.lname, ''), '') AS client_lname
    FROM loan l
    LEFT JOIN user u ON u.id = l.client_id
    WHERE l.status = 'OUTSTANDING' AND l.disbursed_at IS NOT NULL
    ORDER BY l.disbursed_at DESC
    LIMIT 25
";
$recentResult = $conn->query($recentSql);
$recentLoans = [];
if ($recentResult) {
    while ($row = $recentResult->fetch_assoc()) {
        $recentLoans[] = $row;
    }
}

// --------------------------------------------------------------
// Recently disbursed top-ups (for voucher lookup / reference)
// --------------------------------------------------------------
$recentTopupSql = "
    SELECT
        t.*,
        COALESCE(NULLIF(u.fname, ''), '') AS client_fname,
        COALESCE(NULLIF(u.lname, ''), '') AS client_lname
    FROM loan_topup_requests t
    LEFT JOIN user u ON u.id = t.client_id
    WHERE t.status = 'DISBURSED' AND t.disbursed_at IS NOT NULL
    ORDER BY t.disbursed_at DESC
    LIMIT 25
";
$recentTopupResult = $conn->query($recentTopupSql);
$recentTopups = [];
if ($recentTopupResult) {
    while ($row = $recentTopupResult->fetch_assoc()) {
        $recentTopups[] = $row;
    }
}

$stats = ['count' => 0, 'amount' => 0];
foreach ($pendingLoans as $loan) {
    $stats['count']++;
    $stats['amount'] += (float)($loan['amount'] ?? 0);
}

$topupStats = ['count' => 0, 'amount' => 0];
foreach ($pendingTopups as $topup) {
    $topupStats['count']++;
    $topupStats['amount'] += (float)($topup['amount'] ?? 0);
}
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    border: 0;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 6px 20px rgba(15,23,42,.06);
    overflow: hidden;
    position: relative;
    padding: 1rem;
}

.stat-card::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    width: 5px;
    height: 100%;
}

.border-teal-band::before { background: #0d9488; }
.border-primary-band::before { background: #0d6efd; }

.stat-flex { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
}

.stat-icon.bg-teal { background: #0d9488; }
.stat-icon.bg-primary { background: #0d6efd; }

.stat-label {
    font-size: .72rem;
    font-weight: 700;
    color: #6b7280;
    margin-bottom: .35rem;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.stat-value { font-size: 1.15rem; font-weight: 800; color: #111827; margin: 0; }

.disb-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-left: 5px solid #0d9488;
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: .9rem;
    box-shadow: 0 6px 18px rgba(15,23,42,.05);
}

.disb-card.topup-card {
    border-left-color: #7e22ce;
}

.disb-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.cell-av {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #0d9488;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    flex-shrink: 0;
}

.disb-meta { display: flex; align-items: center; gap: 2rem; flex-wrap: wrap; }

.fs-sm { font-size: .85rem; }
.fs-xs { font-size: .72rem; }
.text-muted { color: #64748b !important; }

.disb-panel {
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #fbfcfd;
    padding: 1rem;
    margin-top: .75rem;
}

.disb-panel.d-none { display: none; }

.table-recent th, .table-recent td { vertical-align: middle; font-size: .85rem; }

.badge-teal { background: #ccfbf1; color: #0d9488; }

@media (max-width: 768px) {
    .stats-grid { grid-template-columns: 1fr; }
}
</style>

<div class="page-header mb-4">
    <div class="title">Loan Disbursement</div>
    <div class="sub">
        <?= number_format($stats['count']) ?> new loan<?= $stats['count'] === 1 ? '' : 's' ?>
        and <?= number_format($topupStats['count']) ?> top-up<?= $topupStats['count'] === 1 ? '' : 's' ?> awaiting disbursement
    </div>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
    <div class="stat-card border-teal-band">
        <div class="stat-flex">
            <div>
                <div class="stat-label">New Loans Awaiting</div>
                <div class="stat-value"><?= number_format($stats['count']) ?></div>
            </div>
            <div class="stat-icon bg-teal"><i class="fas fa-hand-holding-dollar"></i></div>
        </div>
    </div>

    <div class="stat-card border-primary-band">
        <div class="stat-flex">
            <div>
                <div class="stat-label">New Loan Amount</div>
                <div class="stat-value"><?= money($stats['amount']) ?></div>
            </div>
            <div class="stat-icon bg-primary"><i class="fas fa-coins"></i></div>
        </div>
    </div>

    <div class="stat-card" style="border:0;border-radius:16px;background:#fff;box-shadow:0 6px 20px rgba(15,23,42,.06);position:relative;overflow:hidden;">
        <div style="content:'';position:absolute;left:0;top:0;width:5px;height:100%;background:#7e22ce;"></div>
        <div class="stat-flex">
            <div>
                <div class="stat-label">Top-Ups Awaiting</div>
                <div class="stat-value"><?= number_format($topupStats['count']) ?></div>
            </div>
            <div class="stat-icon" style="background:#7e22ce;"><i class="fas fa-layer-group"></i></div>
        </div>
    </div>

    <div class="stat-card" style="border:0;border-radius:16px;background:#fff;box-shadow:0 6px 20px rgba(15,23,42,.06);position:relative;overflow:hidden;">
        <div style="content:'';position:absolute;left:0;top:0;width:5px;height:100%;background:#7e22ce;"></div>
        <div class="stat-flex">
            <div>
                <div class="stat-label">Top-Up Amount</div>
                <div class="stat-value"><?= money($topupStats['amount']) ?></div>
            </div>
            <div class="stat-icon" style="background:#7e22ce;"><i class="fas fa-coins"></i></div>
        </div>
    </div>
</div>

<h5 class="mb-3">New Loans &mdash; Ready to Disburse</h5>

<?php if (empty($pendingLoans)): ?>

    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-check-circle" style="color:var(--secondary);opacity:.5"></i>
                <h3>All caught up!</h3>
                <p>No approved loans are waiting on disbursement.</p>
            </div>
        </div>
    </div>

<?php else: ?>

    <?php foreach ($pendingLoans as $loan): ?>
        <?php
        $loanId = (int)$loan['id'];
        $clientName = trim((string)($loan['client_fname'] ?? '') . ' ' . (string)($loan['client_lname'] ?? ''));
        $clientName = $clientName !== '' ? $clientName : ('Client #' . (int)$loan['client_id']);
        $initials = strtoupper(substr($clientName, 0, 1));
        $appliedDate = !empty($loan['datetime']) ? date('M j, Y', strtotime((string)$loan['datetime'])) : 'N/A';
        $trail = $auditTrailByLoan[$loanId] ?? [];

        $approvedEntry = null;
        foreach ($trail as $entry) {
            if ($entry['action'] === 'approved') {
                $approvedEntry = $entry;
                break;
            }
        }
        ?>

        <div class="disb-card">
            <div class="disb-card-top">
                <div class="d-flex align-center gap-3">
                    <div class="cell-av"><?= h($initials ?: 'U') ?></div>
                    <div>
                        <div style="font-weight:700"><?= h($clientName) ?></div>
                        <div class="fs-sm text-muted">Loan #<?= $loanId ?> &middot; Applied <?= h($appliedDate) ?></div>
                        <div class="mt-1">
                            <span class="badge badge-teal">APPROVED</span>
                            <span class="badge badge-secondary">Interest: <?= h((string)($loan['interest_rate'] ?? '')) ?>%</span>
                        </div>
                    </div>
                </div>

                <div class="disb-meta">
                    <div>
                        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.06em">Amount</div>
                        <div style="font-weight:800;color:var(--primary)"><?= money($loan['amount'] ?? 0) ?></div>
                    </div>
                    <div>
                        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.06em">Loan Period</div>
                        <div class="fs-sm text-muted"><?= (int)($loan['loan_period'] ?? 0) ?> month(s)</div>
                    </div>
                </div>
            </div>

            <?php if ($approvedEntry): ?>
                <div class="fs-xs text-muted mt-2">
                    Approved by <?= h(trim(($approvedEntry['fname'] ?? '') . ' ' . ($approvedEntry['lname'] ?? '')) ?: 'reviewer') ?>
                    on <?= h(date('M j, Y g:i A', strtotime((string)$approvedEntry['created_at']))) ?>
                    <?php if (!empty($approvedEntry['comments'])): ?>
                        &mdash; "<?= h($approvedEntry['comments']) ?>"
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="d-flex gap-2 flex-wrap mt-3">
                <a href="view_loan.php?id=<?= $loanId ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-eye"></i> View
                </a>
                <button type="button" class="btn btn-teal btn-sm disb-toggle-btn"
                        style="background:#0d9488;color:#fff;"
                        data-panel="disbPanel-<?= $loanId ?>" aria-expanded="false">
                    <i class="fas fa-hand-holding-dollar"></i> Disburse Funds
                </button>
            </div>

            <div id="disbPanel-<?= $loanId ?>" class="disb-panel d-none">
                <form method="POST" action="disbursement_action.php">
                    <input type="hidden" name="id" value="<?= $loanId ?>">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fs-sm">Disbursement method <span class="text-danger">*</span></label>
                            <select name="method" class="form-select" required>
                                <option value="">Select...</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="cash">Cash</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fs-sm">Reference # (optional)</label>
                            <input type="text" name="reference" class="form-control"
                                   placeholder="Auto-generated if left blank">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fs-sm">Notes (optional)</label>
                            <input type="text" name="notes" class="form-control" placeholder="Any context for the audit trail">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-sm text-white" style="background:#0d9488;"
                                onclick="return confirm('Confirm you are disbursing UGX <?= (int)($loan['amount'] ?? 0) ?> for loan #<?= $loanId ?>?')">
                            Confirm Disbursement
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <?php endforeach; ?>

<?php endif; ?>

<h5 class="mt-4 mb-3">Top-Ups &mdash; Ready to Disburse</h5>

<?php if (empty($pendingTopups)): ?>

    <div class="card mb-4">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-check-circle" style="color:var(--secondary);opacity:.5"></i>
                <h3>No top-ups waiting</h3>
                <p>Approved top-up requests will appear here.</p>
            </div>
        </div>
    </div>

<?php else: ?>

    <?php foreach ($pendingTopups as $topup): ?>
        <?php
        $topupId = (int)$topup['id'];
        $loanId = (int)$topup['loan_id'];
        $clientName = trim((string)($topup['client_fname'] ?? '') . ' ' . (string)($topup['client_lname'] ?? ''));
        $clientName = $clientName !== '' ? $clientName : ('Client #' . (int)$topup['client_id']);
        $initials = strtoupper(substr($clientName, 0, 1));
        $requestedDate = !empty($topup['created_at']) ? date('M j, Y', strtotime((string)$topup['created_at'])) : 'N/A';
        $trail = $auditTrailByTopup[$topupId] ?? [];

        $approvedEntry = null;
        foreach ($trail as $entry) {
            if ($entry['action'] === 'topup_approved') {
                $approvedEntry = $entry;
                break;
            }
        }
        ?>

        <div class="disb-card topup-card">
            <div class="disb-card-top">
                <div class="d-flex align-center gap-3">
                    <div class="cell-av" style="background:#7e22ce;"><?= h($initials ?: 'U') ?></div>
                    <div>
                        <div style="font-weight:700"><?= h($clientName) ?></div>
                        <div class="fs-sm text-muted">Top-up on Loan #<?= $loanId ?> &middot; Requested <?= h($requestedDate) ?></div>
                        <div class="mt-1">
                            <span class="badge badge-teal" style="background:#f3e8ff;color:#7e22ce;">APPROVED TOP-UP</span>
                        </div>
                    </div>
                </div>

                <div class="disb-meta">
                    <div>
                        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.06em">Top-Up Amount</div>
                        <div style="font-weight:800;color:var(--primary)"><?= money($topup['amount'] ?? 0) ?></div>
                    </div>
                    <div>
                        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.06em">Extra Period</div>
                        <div class="fs-sm text-muted"><?= (int)($topup['loan_period'] ?? 0) ?> month(s)</div>
                    </div>
                    <div>
                        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.06em">Current Balance</div>
                        <div class="fs-sm text-muted"><?= money($topup['current_balance'] ?? 0) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($approvedEntry): ?>
                <div class="fs-xs text-muted mt-2">
                    Approved by <?= h(trim(($approvedEntry['fname'] ?? '') . ' ' . ($approvedEntry['lname'] ?? '')) ?: 'reviewer') ?>
                    on <?= h(date('M j, Y g:i A', strtotime((string)$approvedEntry['created_at']))) ?>
                    <?php if (!empty($approvedEntry['comments'])): ?>
                        &mdash; "<?= h($approvedEntry['comments']) ?>"
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="d-flex gap-2 flex-wrap mt-3">
                <a href="view_loan.php?id=<?= $loanId ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-eye"></i> View Loan
                </a>
                <button type="button" class="btn btn-sm disb-toggle-btn"
                        style="background:#7e22ce;color:#fff;"
                        data-panel="topupDisbPanel-<?= $topupId ?>" aria-expanded="false">
                    <i class="fas fa-hand-holding-dollar"></i> Disburse Top-Up
                </button>
            </div>

            <div id="topupDisbPanel-<?= $topupId ?>" class="disb-panel d-none">
                <form method="POST" action="disbursement_topup_action.php">
                    <input type="hidden" name="topup_id" value="<?= $topupId ?>">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fs-sm">Disbursement method <span class="text-danger">*</span></label>
                            <select name="method" class="form-select" required>
                                <option value="">Select...</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="cash">Cash</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fs-sm">Reference # (optional)</label>
                            <input type="text" name="reference" class="form-control" placeholder="Auto-generated if left blank">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fs-sm">Notes (optional)</label>
                            <input type="text" name="notes" class="form-control" placeholder="Any context for the audit trail">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-sm text-white" style="background:#7e22ce;"
                                onclick="return confirm('Confirm you are disbursing a UGX <?= (int)($topup['amount'] ?? 0) ?> top-up for loan #<?= $loanId ?>?')">
                            Confirm Disbursement
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <?php endforeach; ?>

<?php endif; ?>

<h5 class="mt-4 mb-3">New Loans Recently Disbursed</h5>

<?php if (empty($recentLoans)): ?>
    <p class="text-muted fs-sm">No disbursements recorded yet.</p>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-recent mb-0">
                <thead>
                    <tr>
                        <th>Loan #</th>
                        <th>Client</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Disbursed</th>
                        <th>Voucher</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLoans as $loan): ?>
                        <?php
                        $clientName = trim((string)($loan['client_fname'] ?? '') . ' ' . (string)($loan['client_lname'] ?? ''));
                        $clientName = $clientName !== '' ? $clientName : ('Client #' . (int)$loan['client_id']);
                        $disbursedWhen = !empty($loan['disbursed_at']) ? date('M j, Y g:i A', strtotime((string)$loan['disbursed_at'])) : 'N/A';
                        ?>
                        <tr>
                            <td>#<?= (int)$loan['id'] ?></td>
                            <td><?= h($clientName) ?></td>
                            <td><?= money($loan['amount'] ?? 0) ?></td>
                            <td><?= h(methodLabel($loan['disbursement_method'] ?? null)) ?></td>
                            <td><?= h($loan['disbursement_reference'] ?? '') ?></td>
                            <td><?= h($disbursedWhen) ?></td>
                            <td>
                                <a href="disbursement_pdf.php?id=<?= (int)$loan['id'] ?>" target="_blank"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-file-pdf"></i> PDF
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<h5 class="mt-4 mb-3">Top-Ups Recently Disbursed</h5>

<?php if (empty($recentTopups)): ?>
    <p class="text-muted fs-sm">No top-up disbursements recorded yet.</p>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-recent mb-0">
                <thead>
                    <tr>
                        <th>Loan #</th>
                        <th>Client</th>
                        <th>Top-Up Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Disbursed</th>
                        <th>Voucher</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentTopups as $topup): ?>
                        <?php
                        $clientName = trim((string)($topup['client_fname'] ?? '') . ' ' . (string)($topup['client_lname'] ?? ''));
                        $clientName = $clientName !== '' ? $clientName : ('Client #' . (int)$topup['client_id']);
                        $disbursedWhen = !empty($topup['disbursed_at']) ? date('M j, Y g:i A', strtotime((string)$topup['disbursed_at'])) : 'N/A';
                        ?>
                        <tr>
                            <td>#<?= (int)$topup['loan_id'] ?></td>
                            <td><?= h($clientName) ?></td>
                            <td><?= money($topup['amount'] ?? 0) ?></td>
                            <td><?= h(methodLabel($topup['disbursement_method'] ?? null)) ?></td>
                            <td><?= h($topup['disbursement_reference'] ?? '') ?></td>
                            <td><?= h($disbursedWhen) ?></td>
                            <td>
                                <a href="topup_disbursement_pdf.php?id=<?= (int)$topup['id'] ?>" target="_blank"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-file-pdf"></i> PDF
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('click', function (e) {
    var toggleBtn = e.target.closest('.disb-toggle-btn');
    if (!toggleBtn) {
        return;
    }
    var panel = document.getElementById(toggleBtn.getAttribute('data-panel'));
    if (panel) {
        var nowHidden = panel.classList.toggle('d-none');
        toggleBtn.setAttribute('aria-expanded', nowHidden ? 'false' : 'true');
    }
});
</script>

<?php require "footer.php"; ?>