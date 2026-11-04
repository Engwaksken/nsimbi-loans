<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../php/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['logged_in'])) {
    header('Location: ../index.php');
    exit;
}

if ((int)($_SESSION['role'] ?? 0) !== 3) {
    header('Location: ../index.php');
    exit;
}

$client_id = (int)$_SESSION['logged_in'];

function fetch_one(mysqli $conn, string $sql, int $client_id): float
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result ? $result->fetch_row() : [0];
    $stmt->close();
    return (float)($row[0] ?? 0);
}

function ugx(float $amount): string
{
    return 'UGX ' . number_format($amount, 0);
}

$totalLoans      = fetch_one($conn, "SELECT COALESCE(SUM(amount),0) FROM loan WHERE client_id = ?", $client_id);
$totalLoanCount  = fetch_one($conn, "SELECT COUNT(*) FROM loan WHERE client_id = ?", $client_id);
$totalInterest   = fetch_one($conn, "SELECT COALESCE(SUM(interest),0) FROM loan WHERE client_id = ?", $client_id);
$clearedLoans    = fetch_one($conn, "SELECT COALESCE(SUM(amount),0) FROM loan WHERE client_id = ? AND status = 'CLEARED'", $client_id);
$outstandingLoans= fetch_one($conn, "SELECT COALESCE(SUM(loan_balance),0) FROM loan WHERE client_id = ? AND status IN ('OUTSTANDING','OVERDUE')", $client_id);
$totalPaid       = fetch_one($conn, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE user_id = ? AND status = 'SUCCESSFUL'", $client_id);
$pendingLoans    = fetch_one($conn, "SELECT COUNT(*) FROM loan WHERE client_id = ? AND status = 'PENDING'", $client_id);
$overdueLoans    = fetch_one($conn, "SELECT COUNT(*) FROM loan WHERE client_id = ? AND status = 'OVERDUE'", $client_id);

$recentStmt = $conn->prepare("
    SELECT id, client_id, amount, interest, loan_balance, status, datetime, due_date
    FROM loan
    WHERE client_id = ? AND status != 'PENDING'
    ORDER BY id DESC
    LIMIT 5
");
if (!$recentStmt) die('Failed to load recent loans.');
$recentStmt->bind_param('i', $client_id);
$recentStmt->execute();
$recentLoans = $recentStmt->get_result();

$fname = htmlspecialchars((string)($_SESSION['fname'] ?? 'Client'));

require_once __DIR__ . '/nav-head.php';
if (file_exists(__DIR__ . '/services_check.php')) require_once __DIR__ . '/services_check.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

<style>
/* -- Design tokens --------------------------------------- */
:root {
    --ink:        #0a0f1e;
    --ink-soft:   #4a5568;
    --ink-muted:  #94a3b8;
    --surface:    #f7f8fc;
    --card:       #ffffff;
    --border:     #e8ecf4;

    --blue:       #1a56db;
    --blue-light: #ebf2ff;
    --green:      #059669;
    --green-light:#ecfdf5;
    --amber:      #d97706;
    --amber-light:#fffbeb;
    --red:        #dc2626;
    --red-light:  #fef2f2;
    --slate:      #475569;
    --slate-light:#f1f5f9;

    --radius-sm:  10px;
    --radius:     16px;
    --radius-lg:  22px;
    --shadow-sm:  0 1px 3px rgba(10,15,30,.06), 0 4px 12px rgba(10,15,30,.05);
    --shadow:     0 4px 16px rgba(10,15,30,.08), 0 1px 4px rgba(10,15,30,.04);
    --shadow-lg:  0 8px 32px rgba(10,15,30,.10), 0 2px 8px rgba(10,15,30,.06);

    --font:       'Sora', sans-serif;
    --mono:       'DM Mono', monospace;
}

/* -- Base reset for this page ---------------------------- */
.db-wrap * { box-sizing: border-box; }
.db-wrap {
    font-family: var(--font);
    background: var(--surface);
    min-height: 100vh;
    padding: 32px 28px 48px;
    color: var(--ink);
}

/* -- Page header ----------------------------------------- */
.db-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 32px;
}

.db-header-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

.db-avatar {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--blue), #3b82f6);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.3rem;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(26,86,219,.35);
    letter-spacing: -.02em;
}

.db-greeting {
    font-size: .76rem;
    font-weight: 600;
    color: var(--ink-muted);
    text-transform: uppercase;
    letter-spacing: .08em;
    margin-bottom: 3px;
}

.db-name {
    font-size: 1.35rem;
    font-weight: 800;
    color: var(--ink);
    line-height: 1.1;
    letter-spacing: -.03em;
}

.db-date-chip {
    font-family: var(--mono);
    font-size: .72rem;
    color: var(--ink-soft);
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 30px;
    padding: 6px 14px;
    white-space: nowrap;
    box-shadow: var(--shadow-sm);
}

/* -- Section label --------------------------------------- */
.db-section-label {
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--ink-muted);
    margin-bottom: 14px;
    padding-left: 2px;
}

/* -- Hero banner ----------------------------------------- */
.db-hero {
    background: linear-gradient(135deg, #0f2669 0%, #1a56db 55%, #3b82f6 100%);
    border-radius: var(--radius-lg);
    padding: 30px 32px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 28px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(26,86,219,.30);
}

.db-hero::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 220px; height: 220px;
    border-radius: 50%;
    background: rgba(255,255,255,.06);
    pointer-events: none;
}

.db-hero::after {
    content: '';
    position: absolute;
    bottom: -60px; right: 60px;
    width: 160px; height: 160px;
    border-radius: 50%;
    background: rgba(255,255,255,.04);
    pointer-events: none;
}

.db-hero-label {
    font-size: .7rem;
    font-weight: 600;
    letter-spacing: .1em;
    text-transform: uppercase;
    opacity: .7;
    margin-bottom: 6px;
}

.db-hero-value {
    font-size: 2.2rem;
    font-weight: 800;
    letter-spacing: -.04em;
    line-height: 1;
    margin-bottom: 4px;
}

.db-hero-sub {
    font-size: .78rem;
    opacity: .65;
}

.db-hero-right {
    display: flex;
    gap: 28px;
    flex-wrap: wrap;
}

.db-hero-pill {
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 12px;
    padding: 14px 20px;
    text-align: center;
    min-width: 110px;
    backdrop-filter: blur(4px);
}

.db-hero-pill-val {
    font-size: 1.25rem;
    font-weight: 800;
    letter-spacing: -.03em;
    margin-bottom: 2px;
}

.db-hero-pill-lbl {
    font-size: .65rem;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
    opacity: .65;
}

/* -- Stat grid ------------------------------------------- */
.db-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 28px;
}

@media (max-width: 1199px) { .db-stats-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 575px)  { .db-stats-grid { grid-template-columns: 1fr; } }

.db-stat {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    text-decoration: none;
    color: inherit;
    transition: transform .18s, box-shadow .18s, border-color .18s;
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
}

.db-stat::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: var(--radius) var(--radius) 0 0;
    background: var(--stat-accent, var(--blue));
    opacity: 0;
    transition: opacity .18s;
}

.db-stat:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
    border-color: transparent;
    color: inherit;
}

.db-stat:hover::after { opacity: 1; }

.db-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .95rem;
    flex-shrink: 0;
}

.db-stat-body { min-width: 0; }

.db-stat-label {
    font-size: .67rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--ink-muted);
    margin-bottom: 4px;
    white-space: nowrap;
}

.db-stat-value {
    font-size: 1.02rem;
    font-weight: 800;
    color: var(--ink);
    letter-spacing: -.02em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Colour variants */
.db-stat.s-blue   { --stat-accent: var(--blue);  }
.db-stat.s-blue   .db-stat-icon { background: var(--blue-light);  color: var(--blue);  }
.db-stat.s-green  { --stat-accent: var(--green); }
.db-stat.s-green  .db-stat-icon { background: var(--green-light); color: var(--green); }
.db-stat.s-amber  { --stat-accent: var(--amber); }
.db-stat.s-amber  .db-stat-icon { background: var(--amber-light); color: var(--amber); }
.db-stat.s-red    { --stat-accent: var(--red);   }
.db-stat.s-red    .db-stat-icon { background: var(--red-light);   color: var(--red);   }
.db-stat.s-slate  { --stat-accent: var(--slate); }
.db-stat.s-slate  .db-stat-icon { background: var(--slate-light); color: var(--slate); }

/* -- Recent loans card ----------------------------------- */
.db-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.db-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 22px 24px 16px;
    border-bottom: 1px solid var(--border);
}

.db-card-title {
    font-size: .95rem;
    font-weight: 800;
    color: var(--ink);
    letter-spacing: -.02em;
    margin: 0;
}

.db-card-link {
    font-size: .72rem;
    font-weight: 700;
    color: var(--blue);
    text-decoration: none;
    letter-spacing: .03em;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: gap .15s;
}

.db-card-link:hover { gap: 8px; color: var(--blue); }

/* -- Table ----------------------------------------------- */
.db-table { width: 100%; border-collapse: collapse; }

.db-table thead tr {
    border-bottom: 1px solid var(--border);
}

.db-table thead th {
    font-family: var(--font);
    font-size: .65rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--ink-muted);
    padding: 12px 16px;
    background: #fafbfd;
    white-space: nowrap;
}

.db-table thead th:first-child { padding-left: 24px; }
.db-table thead th:last-child  { padding-right: 24px; text-align: center; }

.db-table tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background .12s;
}

.db-table tbody tr:last-child { border-bottom: 0; }
.db-table tbody tr:hover      { background: #fafbfd; }

.db-table tbody td {
    font-size: .83rem;
    color: var(--ink);
    padding: 13px 16px;
    vertical-align: middle;
}

.db-table tbody td:first-child { padding-left: 24px; }
.db-table tbody td:last-child  { padding-right: 24px; text-align: center; }

.db-table .amount {
    font-family: var(--mono);
    font-size: .8rem;
    font-weight: 500;
    color: var(--ink);
}

.db-table .balance-zero { color: var(--ink-muted); }

.db-table .dt-cell {
    font-family: var(--mono);
    font-size: .75rem;
    color: var(--ink-soft);
}

/* -- Status badges --------------------------------------- */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 30px;
    font-size: .65rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    white-space: nowrap;
}

.status-badge::before {
    content: '';
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}

.sb-cleared     { background: var(--green-light); color: var(--green); }
.sb-outstanding { background: var(--amber-light); color: var(--amber); }
.sb-overdue     { background: var(--red-light);   color: var(--red);   }
.sb-default     { background: var(--slate-light);  color: var(--slate); }

/* -- View link ------------------------------------------- */
.view-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: var(--blue-light);
    color: var(--blue);
    font-size: .75rem;
    text-decoration: none;
    transition: background .15s, transform .15s;
}

.view-link:hover {
    background: var(--blue);
    color: #fff;
    transform: scale(1.08);
}

/* -- Empty state ----------------------------------------- */
.db-empty {
    padding: 48px 24px;
    text-align: center;
    color: var(--ink-muted);
}

.db-empty i { font-size: 2rem; margin-bottom: 12px; opacity: .4; display: block; }
.db-empty p { font-size: .85rem; margin: 0; }

/* -- Divider row between stat groups --------------------- */
.db-divider {
    height: 1px;
    background: var(--border);
    margin: 6px 0 20px;
    border: none;
}

/* -- Staggered card-in animation ------------------------- */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}

.db-hero      { animation: fadeUp .35s ease both; }
.db-stats-grid .db-stat:nth-child(1) { animation: fadeUp .35s .06s ease both; }
.db-stats-grid .db-stat:nth-child(2) { animation: fadeUp .35s .10s ease both; }
.db-stats-grid .db-stat:nth-child(3) { animation: fadeUp .35s .14s ease both; }
.db-stats-grid .db-stat:nth-child(4) { animation: fadeUp .35s .18s ease both; }
.db-stats-grid .db-stat:nth-child(5) { animation: fadeUp .35s .22s ease both; }
.db-stats-grid .db-stat:nth-child(6) { animation: fadeUp .35s .26s ease both; }
.db-stats-grid .db-stat:nth-child(7) { animation: fadeUp .35s .30s ease both; }
.db-stats-grid .db-stat:nth-child(8) { animation: fadeUp .35s .34s ease both; }
.db-card        { animation: fadeUp .35s .38s ease both; }
</style>

<div class="db-wrap">

    <!-- -- Page header ------------------------------------ -->
    <div class="db-header">
        <div class="db-header-left">
            <div class="db-avatar"><?= mb_strtoupper(mb_substr($fname, 0, 1)); ?></div>
            <div>
                <div class="db-greeting">Welcome back</div>
                <div class="db-name"><?= $fname; ?></div>
            </div>
        </div>
        <div class="db-date-chip">
            <i class="fa fa-calendar-alt me-1"></i>
            <?= date('l, j F Y'); ?>
        </div>
    </div>

    <!-- -- Hero banner ------------------------------------ -->
    <div class="db-hero">
        <div>
            <div class="db-hero-label">Total Loan Portfolio</div>
            <div class="db-hero-value"><?= ugx($totalLoans); ?></div>
            <div class="db-hero-sub"><?= number_format($totalLoanCount, 0); ?> loan<?= $totalLoanCount != 1 ? 's' : ''; ?> requested all time</div>
        </div>

        <div class="db-hero-right">
            <div class="db-hero-pill">
                <div class="db-hero-pill-val"><?= ugx($totalPaid); ?></div>
                <div class="db-hero-pill-lbl">Total Paid</div>
            </div>
            <div class="db-hero-pill">
                <div class="db-hero-pill-val"><?= ugx($totalInterest); ?></div>
                <div class="db-hero-pill-lbl">Interest Accrued</div>
            </div>
        </div>
    </div>

    <!-- -- Stats row -------------------------------------- -->
    <div class="db-section-label">Account Overview</div>

    <div class="db-stats-grid">

        <a href="outstanding.php?check=2" class="db-stat s-amber">
            <div class="db-stat-icon"><i class="fa fa-chart-line"></i></div>
            <div class="db-stat-body">
                <div class="db-stat-label">Outstanding</div>
                <div class="db-stat-value"><?= ugx($outstandingLoans); ?></div>
            </div>
        </a>

        <a href="cleared.php?check=1" class="db-stat s-green">
            <div class="db-stat-icon"><i class="fa fa-check-circle"></i></div>
            <div class="db-stat-body">
                <div class="db-stat-label">Cleared Loans</div>
                <div class="db-stat-value"><?= ugx($clearedLoans); ?></div>
            </div>
        </a>

        <a href="?page=loan_payments" class="db-stat s-blue">
            <div class="db-stat-icon"><i class="fa fa-credit-card"></i></div>
            <div class="db-stat-body">
                <div class="db-stat-label">Paid Amount</div>
                <div class="db-stat-value"><?= ugx($totalPaid); ?></div>
            </div>
        </a>

        <a href="my_loans.php" class="db-stat s-slate">
            <div class="db-stat-icon"><i class="fa fa-list-ul"></i></div>
            <div class="db-stat-body">
                <div class="db-stat-label">Loans Requested</div>
                <div class="db-stat-value"><?= number_format($totalLoanCount, 0); ?></div>
            </div>
        </a>

        <a href="?page=pending_loans&check=0" class="db-stat s-amber">
            <div class="db-stat-icon"><i class="fa fa-clock"></i></div>
            <div class="db-stat-body">
                <div class="db-stat-label">Pending Loans</div>
                <div class="db-stat-value"><?= number_format($pendingLoans, 0); ?></div>
            </div>
        </a>

        <a href="overdue.php?check=3" class="db-stat s-red">
            <div class="db-stat-icon"><i class="fa fa-exclamation-triangle"></i></div>
            <div class="db-stat-body">
                <div class="db-stat-label">Overdue Loans</div>
                <div class="db-stat-value"><?= number_format($overdueLoans, 0); ?></div>
            </div>
        </a>

        <a href="my_loans.php" class="db-stat s-slate">
            <div class="db-stat-icon"><i class="fa fa-percentage"></i></div>
            <div class="db-stat-body">
                <div class="db-stat-label">Total Interest</div>
                <div class="db-stat-value"><?= ugx($totalInterest); ?></div>
            </div>
        </a>

        <a href="my_loans.php" class="db-stat s-blue">
            <div class="db-stat-icon"><i class="fa fa-money-bill-wave"></i></div>
            <div class="db-stat-body">
                <div class="db-stat-label">Total Borrowed</div>
                <div class="db-stat-value"><?= ugx($totalLoans); ?></div>
            </div>
        </a>

    </div>

    <!-- -- Recent loans ----------------------------------- -->
    <div class="db-section-label">Recent Activity</div>

    <div class="db-card">
        <div class="db-card-head">
            <h5 class="db-card-title">Recent Loans</h5>
            <a href="my_loans.php" class="db-card-link">
                View all <i class="fa fa-arrow-right" style="font-size:.6rem;"></i>
            </a>
        </div>

        <div class="table-responsive">
            <table class="db-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Amount</th>
                        <th>Interest</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Issue Date</th>
                        <th>Due Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($recentLoans && $recentLoans->num_rows > 0):
                    $counter = 1;
                    while ($loan = $recentLoans->fetch_assoc()):
                        $status   = strtoupper((string)$loan['status']);
                        $badgeCls = match ($status) {
                            'CLEARED'     => 'sb-cleared',
                            'OUTSTANDING' => 'sb-outstanding',
                            'OVERDUE'     => 'sb-overdue',
                            default       => 'sb-default',
                        };
                        $balance  = (float)$loan['loan_balance'];
                ?>
                    <tr>
                        <td style="color:var(--ink-muted);font-size:.78rem;font-weight:600;">
                            <?= $counter++; ?>
                        </td>

                        <td><span class="amount"><?= ugx((float)$loan['amount']); ?></span></td>
                        <td><span class="amount"><?= ugx((float)$loan['interest']); ?></span></td>

                        <td>
                            <span class="amount <?= $balance <= 0 ? 'balance-zero' : ''; ?>">
                                <?= $balance > 0 ? ugx($balance) : '-'; ?>
                            </span>
                        </td>

                        <td>
                            <span class="status-badge <?= $badgeCls; ?>">
                                <?= htmlspecialchars($status); ?>
                            </span>
                        </td>

                        <td class="dt-cell"><?= htmlspecialchars((string)$loan['datetime']); ?></td>
                        <td class="dt-cell"><?= htmlspecialchars((string)$loan['due_date']); ?></td>

                        <td>
                            <a href="view_loan.php?id=<?= (int)$loan['id']; ?>&user_id=<?= (int)$loan['client_id']; ?>"
                               class="view-link" title="View loan">
                                <i class="fa fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr>
                        <td colspan="8">
                            <div class="db-empty">
                                <i class="fa fa-inbox"></i>
                                <p>No recent loans found.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php
$recentStmt->close();
require_once __DIR__ . '/footer.php';
?>