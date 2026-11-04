<?php
declare(strict_types=1);

$phead = 'Dashboard';
require "nav-head.php";
require "services_check.php";

if (!isset($conn)) {
    die("Database connection not established.");
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return 'UGX ' . number_format((float)$value);
}

function scalarValue(mysqli $conn, string $sql): float
{
    $res = $conn->query($sql);

    if (!$res) {
        return 0;
    }

    $row = $res->fetch_assoc();

    return (float)($row['v'] ?? 0);
}

function sb($status): string
{
    $status = strtoupper((string)($status ?? ''));

    $map = [
        'CLEARED'     => 'badge-success',
        'OUTSTANDING' => 'badge-warning',
        'OVERDUE'     => 'badge-danger',
        'ACTIVE'      => 'badge-primary',
        'PENDING'     => 'badge-muted',
        'SUCCESSFUL'  => 'badge-success',
        'FAILED'      => 'badge-danger',
        'REJECTED'    => 'badge-danger',
        'APPROVED'    => 'badge-success',
    ];

    return '<span class="badge ' . ($map[$status] ?? 'badge-muted') . '">' . h($status ?: 'N/A') . '</span>';
}

function typeBadge(string $type): string
{
    return match ($type) {
        'customer' => 'badge-primary',
        'member'   => 'badge-purple',
        'startup'  => 'badge-warning',
        default    => 'badge-muted',
    };
}

/*
|--------------------------------------------------------------------------
| MAIN DASHBOARD STATS
|--------------------------------------------------------------------------
*/
$total_loans = scalarValue($conn, "
    SELECT COALESCE(SUM(amount), 0) AS v 
    FROM loan 
    WHERE status != 'PENDING'
");

$total_interest = scalarValue($conn, "
    SELECT COALESCE(SUM(interest), 0) AS v 
    FROM loan 
    WHERE status != 'PENDING'
");

$outstanding = scalarValue($conn, "
    SELECT COALESCE(SUM(loan_balance), 0) AS v 
    FROM loan 
    WHERE status = 'OUTSTANDING'
");

$cleared_amt = scalarValue($conn, "
    SELECT COALESCE(SUM(amount), 0) AS v 
    FROM loan 
    WHERE status = 'CLEARED'
");

$overdue_amt = scalarValue($conn, "
    SELECT COALESCE(SUM(amount), 0) AS v 
    FROM loan 
    WHERE status = 'OVERDUE'
");

$total_paid = scalarValue($conn, "
    SELECT COALESCE(SUM(amount), 0) AS v 
    FROM payments 
    WHERE status = 'SUCCESSFUL'
");

$pending_count = scalarValue($conn, "
    SELECT COUNT(*) AS v 
    FROM loan 
    WHERE status = 'PENDING'
");

$overdue_count = scalarValue($conn, "
    SELECT COUNT(*) AS v 
    FROM loan 
    WHERE status = 'OVERDUE'
");

$cleared_count = scalarValue($conn, "
    SELECT COUNT(*) AS v 
    FROM loan 
    WHERE status = 'CLEARED'
");

$today_loans = scalarValue($conn, "
    SELECT COUNT(*) AS v 
    FROM loan 
    WHERE DATE(datetime) = CURDATE()
");

$today_payments = scalarValue($conn, "
    SELECT COALESCE(SUM(amount), 0) AS v 
    FROM payments 
    WHERE DATE(datetime) = CURDATE() 
    AND status = 'SUCCESSFUL'
");

$total_customers = scalarValue($conn, "
    SELECT COUNT(*) AS v 
    FROM customers
");

$active_customers = scalarValue($conn, "
    SELECT COUNT(*) AS v 
    FROM customers 
    WHERE active = '1'
");

$total_members = scalarValue($conn, "
    SELECT COUNT(*) AS v 
    FROM members
");

$total_startups = scalarValue($conn, "
    SELECT COUNT(*) AS v 
    FROM startups
");

/*
|--------------------------------------------------------------------------
| NEW LOAN ELIGIBLE CLIENT STATS
| Excludes clients with open / pending loan records.
|--------------------------------------------------------------------------
*/
$eligible_customer_count = scalarValue($conn, "
    SELECT COUNT(*) AS v
    FROM customers c
    WHERE NOT EXISTS (
        SELECT 1 
        FROM loan l
        WHERE l.client_id = c.user_id
        AND l.status IN ('PENDING', 'OUTSTANDING', 'OVERDUE', 'ACTIVE', 'APPROVED')
    )
");

$eligible_member_count = scalarValue($conn, "
    SELECT COUNT(*) AS v
    FROM members m
    WHERE NOT EXISTS (
        SELECT 1 
        FROM loan l
        WHERE l.client_id = m.user_id
        AND l.status IN ('PENDING', 'OUTSTANDING', 'OVERDUE', 'ACTIVE', 'APPROVED')
    )
");

$eligible_startup_count = scalarValue($conn, "
    SELECT COUNT(*) AS v
    FROM startups s
    WHERE NOT EXISTS (
        SELECT 1 
        FROM loan l
        WHERE l.client_id = s.id
        AND l.status IN ('PENDING', 'OUTSTANDING', 'OVERDUE', 'ACTIVE', 'APPROVED')
    )
");

$total_eligible_new_loan = $eligible_customer_count + $eligible_member_count + $eligible_startup_count;

/*
|--------------------------------------------------------------------------
| RECENT LOANS - CUSTOMERS / MEMBERS / STARTUPS
|--------------------------------------------------------------------------
*/
$recentLoansSql = "
    (
        SELECT 
            l.*,
            'customer' AS client_type,
            u.fname,
            u.lname,
            COALESCE(c.email, u.email) AS client_email,
            c.phone AS client_phone,
            c.business_name AS organization_name
        FROM loan l
        INNER JOIN customers c ON l.client_id = c.user_id
        INNER JOIN user u ON c.user_id = u.id
        WHERE l.status != 'PENDING'
    )

    UNION ALL

    (
        SELECT 
            l.*,
            'member' AS client_type,
            u.fname,
            u.lname,
            COALESCE(m.work_email, u.email) AS client_email,
            m.phone AS client_phone,
            m.employer AS organization_name
        FROM loan l
        INNER JOIN members m ON l.client_id = m.user_id
        INNER JOIN user u ON m.user_id = u.id
        WHERE l.status != 'PENDING'
    )

    UNION ALL

    (
        SELECT 
            l.*,
            'startup' AS client_type,
            st.ceo_name AS fname,
            '' AS lname,
            st.email AS client_email,
            st.phone AS client_phone,
            st.company_name AS organization_name
        FROM loan l
        INNER JOIN startups st ON l.client_id = st.id
        WHERE l.status != 'PENDING'
    )

    ORDER BY id DESC
    LIMIT 8
";

$recent_loans = [];
$rl = $conn->query($recentLoansSql);

if ($rl) {
    while ($row = $rl->fetch_assoc()) {
        $recent_loans[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| RECENT PAYMENTS - CUSTOMERS / MEMBERS / STARTUPS
|--------------------------------------------------------------------------
*/
$recentPaymentsSql = "
    (
        SELECT 
            p.*,
            l.id AS loan_id,
            'customer' AS client_type,
            u.fname,
            u.lname,
            c.business_name AS organization_name
        FROM payments p
        INNER JOIN loan l ON p.loan_id = l.id
        INNER JOIN customers c ON l.client_id = c.user_id
        INNER JOIN user u ON c.user_id = u.id
    )

    UNION ALL

    (
        SELECT 
            p.*,
            l.id AS loan_id,
            'member' AS client_type,
            u.fname,
            u.lname,
            m.employer AS organization_name
        FROM payments p
        INNER JOIN loan l ON p.loan_id = l.id
        INNER JOIN members m ON l.client_id = m.user_id
        INNER JOIN user u ON m.user_id = u.id
    )

    UNION ALL

    (
        SELECT 
            p.*,
            l.id AS loan_id,
            'startup' AS client_type,
            st.ceo_name AS fname,
            '' AS lname,
            st.company_name AS organization_name
        FROM payments p
        INNER JOIN loan l ON p.loan_id = l.id
        INNER JOIN startups st ON l.client_id = st.id
    )

    ORDER BY id DESC
    LIMIT 6
";

$recent_pays = [];
$rp = $conn->query($recentPaymentsSql);

if ($rp) {
    while ($row = $rp->fetch_assoc()) {
        $recent_pays[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| ELIGIBLE LISTS FOR NEW LOAN MODAL / JS
|--------------------------------------------------------------------------
| This can be used by ajax/add_loan_form.php or by a frontend select.
|--------------------------------------------------------------------------
*/
$eligibleCustomers = [];
$eligibleMembers = [];
$eligibleStartups = [];

$ec = $conn->query("
    SELECT 
        'customer' AS client_type,
        c.user_id AS client_id,
        CONCAT(u.fname, ' ', u.lname) AS client_name,
        c.phone AS client_phone,
        COALESCE(c.email, u.email) AS client_email,
        c.business_name AS organization_name
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    WHERE NOT EXISTS (
        SELECT 1 FROM loan l
        WHERE l.client_id = c.user_id
        AND l.status IN ('PENDING', 'OUTSTANDING', 'OVERDUE', 'ACTIVE', 'APPROVED')
    )
    ORDER BY u.fname ASC, u.lname ASC
    LIMIT 100
");

if ($ec) {
    while ($row = $ec->fetch_assoc()) {
        $eligibleCustomers[] = $row;
    }
}

$em = $conn->query("
    SELECT 
        'member' AS client_type,
        m.user_id AS client_id,
        CONCAT(u.fname, ' ', u.lname) AS client_name,
        m.phone AS client_phone,
        COALESCE(m.work_email, u.email) AS client_email,
        m.employer AS organization_name
    FROM members m
    INNER JOIN user u ON m.user_id = u.id
    WHERE NOT EXISTS (
        SELECT 1 FROM loan l
        WHERE l.client_id = m.user_id
        AND l.status IN ('PENDING', 'OUTSTANDING', 'OVERDUE', 'ACTIVE', 'APPROVED')
    )
    ORDER BY u.fname ASC, u.lname ASC
    LIMIT 100
");

if ($em) {
    while ($row = $em->fetch_assoc()) {
        $eligibleMembers[] = $row;
    }
}

$es = $conn->query("
    SELECT 
        'startup' AS client_type,
        s.id AS client_id,
        s.company_name AS client_name,
        s.phone AS client_phone,
        s.email AS client_email,
        s.ceo_name AS organization_name
    FROM startups s
    WHERE NOT EXISTS (
        SELECT 1 FROM loan l
        WHERE l.client_id = s.id
        AND l.status IN ('PENDING', 'OUTSTANDING', 'OVERDUE', 'ACTIVE', 'APPROVED')
    )
    ORDER BY s.company_name ASC
    LIMIT 100
");

if ($es) {
    while ($row = $es->fetch_assoc()) {
        $eligibleStartups[] = $row;
    }
}
?>

<style>
.dashboard-main-grid {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 1.15rem;
    align-items: start;
}

.badge-purple {
    background: #f3e8ff;
    color: #7e22ce;
}

.new-loan-client-preview {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .8rem;
    margin-bottom: 1.1rem;
}

.client-source-card {
    background: #fff;
    border: 1px solid var(--border-light, #e5e7eb);
    border-radius: 16px;
    padding: 1rem;
    box-shadow: 0 6px 18px rgba(15,23,42,.04);
}

.client-source-card h6 {
    margin: 0;
    font-size: .85rem;
    color: var(--text-dark, #111827);
    font-weight: 800;
}

.client-source-card p {
    margin: .25rem 0 0;
    color: var(--text-muted, #64748b);
    font-size: .78rem;
}

@media (max-width: 1100px) {
    .dashboard-main-grid {
        grid-template-columns: 1fr;
    }

    .new-loan-client-preview {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- ══ MAIN STATS ══════════════════════════════════════ -->
<div class="stats-grid">

    <a href="loans.php" class="stat-card c-blue">
        <div class="stat-head">
            <div class="stat-ico c-blue"><i class="fas fa-coins"></i></div>
            <span class="stat-badge flat"><i class="fas fa-database"></i> Total</span>
        </div>
        <div class="stat-value"><?= money($total_loans) ?></div>
        <div class="stat-label">Loans Disbursed</div>
        <div class="stat-foot">
            <i class="fas fa-calendar-day"></i>
            <?= number_format($today_loans) ?> loan(s) issued today
        </div>
    </a>

    <a href="cleared.php" class="stat-card c-green">
        <div class="stat-head">
            <div class="stat-ico c-green"><i class="fas fa-check-double"></i></div>
            <span class="stat-badge up"><i class="fas fa-check"></i> <?= number_format($cleared_count) ?></span>
        </div>
        <div class="stat-value"><?= money($cleared_amt) ?></div>
        <div class="stat-label">Cleared Loans</div>
        <div class="stat-foot">
            <i class="fas fa-money-bill-wave"></i>
            <?= money($total_paid) ?> collected
        </div>
    </a>

    <a href="outstanding.php" class="stat-card c-orange">
        <div class="stat-head">
            <div class="stat-ico c-orange"><i class="fas fa-chart-line"></i></div>
            <span class="stat-badge flat">Active</span>
        </div>
        <div class="stat-value"><?= money($outstanding) ?></div>
        <div class="stat-label">Outstanding Balance</div>
        <div class="stat-foot">
            <i class="fas fa-percentage"></i>
            <?= money($total_interest) ?> in interest
        </div>
    </a>

    <a href="overdue.php" class="stat-card c-red">
        <div class="stat-head">
            <div class="stat-ico c-red"><i class="fas fa-exclamation-triangle"></i></div>
            <?php if ($overdue_count > 0): ?>
                <span class="stat-badge down">
                    <i class="fas fa-arrow-up"></i>
                    <?= number_format($overdue_count) ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="stat-value"><?= money($overdue_amt) ?></div>
        <div class="stat-label">Overdue Amount</div>
        <div class="stat-foot">
            <i class="fas fa-clock"></i>
            <?= number_format($overdue_count) ?> overdue loan(s)
        </div>
    </a>

    <a href="pending_loans.php" class="stat-card c-purple">
        <div class="stat-head">
            <div class="stat-ico c-purple"><i class="fas fa-hourglass-half"></i></div>
            <?php if ($pending_count > 0): ?>
                <span class="stat-badge down">Needs review</span>
            <?php endif; ?>
        </div>
        <div class="stat-value sm"><?= number_format($pending_count) ?></div>
        <div class="stat-label">Pending Applications</div>
        <div class="stat-foot"><i class="fas fa-eye"></i> Awaiting approval</div>
    </a>

    <a href="loan_payments.php" class="stat-card c-teal">
        <div class="stat-head">
            <div class="stat-ico c-teal"><i class="fas fa-hand-holding-usd"></i></div>
            <span class="stat-badge up"><i class="fas fa-arrow-up"></i> Today</span>
        </div>
        <div class="stat-value"><?= money($today_payments) ?></div>
        <div class="stat-label">Today's Collections</div>
        <div class="stat-foot">
            <i class="fas fa-calendar"></i>
            <?= money($total_paid) ?> lifetime
        </div>
    </a>

    <a href="clients.php" class="stat-card c-indigo">
        <div class="stat-head">
            <div class="stat-ico c-indigo"><i class="fas fa-users"></i></div>
            <span class="stat-badge up">Active</span>
        </div>
        <div class="stat-value sm"><?= number_format($active_customers) ?></div>
        <div class="stat-label">Active Customers</div>
        <div class="stat-foot">
            <i class="fas fa-user-plus"></i>
            <?= number_format($total_customers) ?> total registered
        </div>
    </a>

    <a href="loans.php" class="stat-card c-pink">
        <div class="stat-head">
            <div class="stat-ico c-pink"><i class="fas fa-percentage"></i></div>
            <span class="stat-badge flat">Revenue</span>
        </div>
        <div class="stat-value"><?= money($total_interest) ?></div>
        <div class="stat-label">Total Interest Earned</div>
        <div class="stat-foot">
            <i class="fas fa-chart-pie"></i>
            From all loan products
        </div>
    </a>

</div>

<!-- ══ NEW LOAN ELIGIBILITY STATS ═════════════════════ -->
<div class="stats-grid g3">
    <a href="add_loan.php?type=customer" class="stat-card c-blue">
        <div class="stat-head">
            <div class="stat-ico c-blue"><i class="fas fa-users"></i></div>
            <span class="stat-badge flat">Eligible</span>
        </div>
        <div class="stat-value sm"><?= number_format($eligible_customer_count) ?></div>
        <div class="stat-label">Customers For New Loan</div>
        <div class="stat-foot">No open or pending loan</div>
    </a>

    <a href="add_loan.php?type=member" class="stat-card c-purple">
        <div class="stat-head">
            <div class="stat-ico c-purple"><i class="fas fa-user-tie"></i></div>
            <span class="stat-badge flat">Eligible</span>
        </div>
        <div class="stat-value sm"><?= number_format($eligible_member_count) ?></div>
        <div class="stat-label">Staff / Members For New Loan</div>
        <div class="stat-foot">No open or pending loan</div>
    </a>

    <a href="add_loan.php?type=startup" class="stat-card c-orange">
        <div class="stat-head">
            <div class="stat-ico c-orange"><i class="fas fa-rocket"></i></div>
            <span class="stat-badge flat">Eligible</span>
        </div>
        <div class="stat-value sm"><?= number_format($eligible_startup_count) ?></div>
        <div class="stat-label">Startups For New Loan</div>
        <div class="stat-foot">No open or pending loan</div>
    </a>
</div>

<!-- ══ MAIN CONTENT ROW ═══════════════════════════════ -->
<div class="dashboard-main-grid">

    <!-- Recent Loans Table -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title"><span class="card-dot"></span>Recent Loans</div>
                <div class="card-sub">Latest approved loan disbursements</div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary btn-sm" onclick="addLoanModal()">
                    <i class="fas fa-plus"></i> New Loan
                </button>
                <a href="loans.php" class="btn btn-ghost btn-sm">View All</a>
            </div>
        </div>

        <div class="card-body no-pad">
            <table class="table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Client</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th></th>
                </tr>
                </thead>

                <tbody>
                <?php foreach ($recent_loans as $loan): ?>
                    <?php
                    $clientType = (string)($loan['client_type'] ?? '');
                    $clientName = trim(($loan['fname'] ?? '') . ' ' . ($loan['lname'] ?? ''));

                    if ($clientType === 'startup') {
                        $clientName = $loan['organization_name'] ?: ($loan['fname'] ?? 'Startup');
                    }

                    if ($clientName === '') {
                        $clientName = 'N/A';
                    }

                    $initials = strtoupper(substr($clientName, 0, 1));
                    ?>
                    <tr>
                        <td class="td-dark"><?= (int)$loan['id'] ?></td>

                        <td>
                            <div class="cell-user">
                                <div class="cell-av"><?= h($initials ?: 'U') ?></div>
                                <div>
                                    <span class="cell-name"><?= h($clientName) ?></span>
                                    <div class="fs-xs text-muted"><?= h($loan['organization_name'] ?? '') ?></div>
                                </div>
                            </div>
                        </td>

                        <td>
                            <span class="badge <?= typeBadge($clientType) ?>">
                                <?= h(ucfirst($clientType)) ?>
                            </span>
                        </td>

                        <td class="td-dark"><?= money($loan['amount'] ?? 0) ?></td>

                        <td class="<?= ((float)($loan['loan_balance'] ?? 0) > 0) ? 'text-warning' : 'text-success' ?>">
                            <?= money($loan['loan_balance'] ?? 0) ?>
                        </td>

                        <td><?= sb($loan['status'] ?? '') ?></td>

                        <td class="text-muted fs-sm">
                            <?= !empty($loan['datetime']) ? date('M j, Y', strtotime((string)$loan['datetime'])) : 'N/A' ?>
                        </td>

                        <td>
                            <a href="view_loan.php?id=<?= (int)$loan['id'] ?>"
                               class="btn btn-ghost btn-icon-sm"
                               title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($recent_loans)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="fas fa-file-invoice-dollar"></i>
                                <h3>No loans found</h3>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Right Column -->
    <div style="display:flex;flex-direction:column;gap:1.1rem">

        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <span class="card-dot green"></span>Quick Actions
                </div>
            </div>

            <div class="card-body" style="display:flex;flex-direction:column;gap:.5rem;padding:1rem">
                <button onclick="addLoanModal()" class="action-btn">
                    <div class="a-ico"><i class="fas fa-plus-circle"></i></div>
                    Issue New Loan
                </button>

                <a href="add_client.php" class="action-btn">
                    <div class="a-ico"><i class="fas fa-user-plus"></i></div>
                    Add Customer
                </a>

                <a href="loan_payments.php" class="action-btn">
                    <div class="a-ico"><i class="fas fa-money-bill-wave"></i></div>
                    Record Payment
                </a>

                <a href="all_loans_report.php" class="action-btn">
                    <div class="a-ico"><i class="fas fa-file-export"></i></div>
                    Export Report
                </a>
            </div>
        </div>

        <!-- New Loan Eligible Preview -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">
                        <span class="card-dot"></span>Eligible For New Loan
                    </div>
                    <div class="card-sub"><?= number_format($total_eligible_new_loan) ?> total eligible records</div>
                </div>
            </div>

            <div class="card-body" style="display:flex;flex-direction:column;gap:.65rem">
                <div class="client-source-card">
                    <h6><i class="fas fa-users text-primary"></i> Customers</h6>
                    <p><?= number_format($eligible_customer_count) ?> without pending/open loan</p>
                </div>

                <div class="client-source-card">
                    <h6><i class="fas fa-user-tie text-purple"></i> Staff / Members</h6>
                    <p><?= number_format($eligible_member_count) ?> without pending/open loan</p>
                </div>

                <div class="client-source-card">
                    <h6><i class="fas fa-rocket text-warning"></i> Startups</h6>
                    <p><?= number_format($eligible_startup_count) ?> without pending/open loan</p>
                </div>
            </div>
        </div>

        <!-- Recent Payments -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <span class="card-dot green"></span>Recent Payments
                </div>
                <a href="loan_payments.php" class="btn btn-ghost btn-sm">View All</a>
            </div>

            <div class="card-body no-pad">
                <?php foreach ($recent_pays as $pay): ?>
                    <?php
                    $payType = (string)($pay['client_type'] ?? '');
                    $payName = trim(($pay['fname'] ?? '') . ' ' . ($pay['lname'] ?? ''));

                    if ($payType === 'startup') {
                        $payName = $pay['organization_name'] ?: ($pay['fname'] ?? 'Startup');
                    }

                    if ($payName === '') {
                        $payName = 'N/A';
                    }
                    ?>
                    <div style="padding:.75rem 1.1rem;border-bottom:1px solid var(--border-light);display:flex;align-items:center;justify-content:space-between">
                        <div>
                            <div style="font-size:.83rem;font-weight:600;color:var(--text-dark)">
                                <?= h($payName) ?>
                            </div>
                            <div class="fs-xs text-muted">
                                Loan #<?= (int)$pay['loan_id'] ?> . <?= h(ucfirst($payType)) ?>
                            </div>
                        </div>

                        <div style="text-align:right">
                            <div style="font-weight:700;color:var(--success);font-size:.88rem">
                                <?= money($pay['amount'] ?? 0) ?>
                            </div>
                            <div class="fs-xs text-muted">
                                <?= !empty($pay['datetime']) ? date('M j', strtotime((string)$pay['datetime'])) : 'N/A' ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($recent_pays)): ?>
                    <div class="empty-state" style="padding:1.5rem">
                        <p>No payments yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
window.NewLoanEligibleClients = {
    customers: <?= json_encode($eligibleCustomers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    members: <?= json_encode($eligibleMembers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    startups: <?= json_encode($eligibleStartups, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
};
</script>

<?php require "footer.php"; ?>