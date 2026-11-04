<?php
declare(strict_types=1);

$phead = 'Loans';
require "nav-head.php";
require "services_check.php";

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($v): string
{
    return 'UGX ' . number_format((float)$v);
}

function lbadge($s): string
{
    $s = strtoupper((string)($s ?? ''));

    $m = [
        'CLEARED'     => 'badge-success',
        'OUTSTANDING' => 'badge-warning',
        'OVERDUE'     => 'badge-danger',
        'ACTIVE'      => 'badge-primary',
        'APPROVED'    => 'badge-success',
        'PENDING'     => 'badge-muted',
        'DEFAULTED'   => 'badge-danger',
        'REJECTED'    => 'badge-danger',
    ];

    return '<span class="badge ' . ($m[$s] ?? 'badge-muted') . '">' . e($s ?: 'N/A') . '</span>';
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

$filter = $_GET['filter'] ?? 'all';

$allowedFilters = [
    'all',
    'cleared',
    'outstanding',
    'overdue',
    'active',
    'customer',
    'member',
    'startup'
];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

/* ---- Search ---- */
$search = trim((string)($_GET['q'] ?? ''));
$likeSearch = '%' . $conn->real_escape_string($search) . '%';

/* ---- Pagination ---- */
$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$whereParts = ["l.status != 'PENDING'"];

if ($filter === 'cleared') {
    $whereParts[] = "l.status = 'CLEARED'";
} elseif ($filter === 'outstanding') {
    $whereParts[] = "l.status = 'OUTSTANDING'";
} elseif ($filter === 'overdue') {
    $whereParts[] = "l.status = 'OVERDUE'";
} elseif ($filter === 'active') {
    $whereParts[] = "l.status = 'ACTIVE'";
}

$wsql = implode(" AND ", $whereParts);

$customerSearch = $search !== ''
    ? "AND (u.fname LIKE '$likeSearch' OR u.lname LIKE '$likeSearch' OR c.phone LIKE '$likeSearch' OR COALESCE(c.email, u.email) LIKE '$likeSearch' OR c.business_name LIKE '$likeSearch')"
    : '';

$memberSearch = $search !== ''
    ? "AND (u.fname LIKE '$likeSearch' OR u.lname LIKE '$likeSearch' OR m.phone LIKE '$likeSearch' OR COALESCE(m.work_email, u.email) LIKE '$likeSearch' OR m.employer LIKE '$likeSearch')"
    : '';

$startupSearch = $search !== ''
    ? "AND (st.company_name LIKE '$likeSearch' OR st.ceo_name LIKE '$likeSearch' OR st.phone LIKE '$likeSearch' OR st.email LIKE '$likeSearch')"
    : '';

$customerSql = "
    SELECT 
        l.*,
        'customer' AS client_type,
        u.fname,
        u.lname,
        COALESCE(c.email, u.email) AS client_email,
        c.phone AS client_phone,
        c.nin AS client_nin,
        c.business_name AS organization_name,
        c.location AS client_location,
        c.district AS client_district
    FROM loan l
    INNER JOIN customers c ON l.client_id = c.user_id
    INNER JOIN user u ON c.user_id = u.id
    WHERE $wsql $customerSearch
";

$memberSql = "
    SELECT 
        l.*,
        'member' AS client_type,
        u.fname,
        u.lname,
        COALESCE(m.work_email, u.email) AS client_email,
        m.phone AS client_phone,
        m.nin AS client_nin,
        m.employer AS organization_name,
        m.department AS client_location,
        m.home_address AS client_district
    FROM loan l
    INNER JOIN members m ON l.client_id = m.user_id
    INNER JOIN user u ON m.user_id = u.id
    WHERE $wsql $memberSearch
";

/*
 * loan.client_id stores startups.startup_id (confirmed), which is
 * itself a foreign key into user.id — same pattern as
 * customers.user_id / members.user_id.
 */
$startupSql = "
    SELECT 
        l.*,
        'startup' AS client_type,
        st.ceo_name AS fname,
        '' AS lname,
        st.email AS client_email,
        st.phone AS client_phone,
        '' AS client_nin,
        st.company_name AS organization_name,
        st.company_location AS client_location,
        st.status AS startup_status
    FROM loan l
    INNER JOIN startups st ON l.client_id = st.startup_id
    WHERE $wsql $startupSearch
";

if ($filter === 'customer') {
    $baseSql = $customerSql;
} elseif ($filter === 'member') {
    $baseSql = $memberSql;
} elseif ($filter === 'startup') {
    $baseSql = $startupSql;
} else {
    $baseSql = "($customerSql) UNION ALL ($memberSql) UNION ALL ($startupSql)";
}

$sql = "SELECT * FROM ($baseSql) x ORDER BY id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("SQL Prepare Error: " . e($conn->error));
}

$stmt->execute();
$result = $stmt->get_result();

$allLoans = [];

while ($row = $result->fetch_assoc()) {
    $allLoans[] = $row;
}

$totalLoans = count($allLoans);

/* ---- Stats computed over the FULL filtered/searched set ---- */
$sumAmount = 0;
$sumInterest = 0;
$sumBalance = 0;

$typeStats = [
    'customer' => ['count' => 0, 'amount' => 0, 'interest' => 0, 'balance' => 0],
    'member'   => ['count' => 0, 'amount' => 0, 'interest' => 0, 'balance' => 0],
    'startup'  => ['count' => 0, 'amount' => 0, 'interest' => 0, 'balance' => 0],
];

$statusStats = [
    'CLEARED' => 0,
    'ACTIVE' => 0,
    'OUTSTANDING' => 0,
    'OVERDUE' => 0,
    'DEFAULTED' => 0,
];

foreach ($allLoans as $loan) {
    $amount = (float)($loan['amount'] ?? 0);
    $interest = (float)($loan['interest'] ?? 0);
    $balance = (float)($loan['loan_balance'] ?? 0);
    $typeKey = (string)($loan['client_type'] ?? 'unknown');
    $statusKey = strtoupper((string)($loan['status'] ?? ''));

    $sumAmount += $amount;
    $sumInterest += $interest;
    $sumBalance += $balance;

    if (isset($typeStats[$typeKey])) {
        $typeStats[$typeKey]['count']++;
        $typeStats[$typeKey]['amount'] += $amount;
        $typeStats[$typeKey]['interest'] += $interest;
        $typeStats[$typeKey]['balance'] += $balance;
    }

    if (isset($statusStats[$statusKey])) {
        $statusStats[$statusKey]++;
    }
}

/* ---- Paginate the already-filtered/searched set for display ---- */
$totalPages = max(1, (int)ceil($totalLoans / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$loans = array_slice($allLoans, $offset, $perPage);

function pageUrl(int $p, string $filter, string $search): string
{
    $params = ['filter' => $filter, 'page' => $p];

    if ($search !== '') {
        $params['q'] = $search;
    }

    return '?' . http_build_query($params);
}
?>

<style>
.loan-table-card {
    overflow: hidden;
}

.loan-table-scroll {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}

.loan-table-scroll table {
    min-width: 1320px;
    width: 100%;
    margin-bottom: 0;
}

.loan-table-scroll th,
.loan-table-scroll td {
    white-space: nowrap;
    vertical-align: middle;
}

.loan-table-scroll .client-col {
    min-width: 240px;
    white-space: normal;
}

.loan-table-scroll .contact-col {
    min-width: 180px;
    white-space: normal;
}

.loan-table-scroll .action-col {
    min-width: 170px;
}

.loan-table-scroll::-webkit-scrollbar {
    height: 9px;
}

.loan-table-scroll::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 999px;
}

.loan-table-scroll::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 999px;
}

.loan-table-scroll::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.badge-purple {
    background: #f3e8ff;
    color: #7e22ce;
}

.cell-user {
    display: flex;
    align-items: center;
    gap: .65rem;
}

.cell-user > div:last-child {
    min-width: 0;
}

.cell-name {
    display: block;
    font-weight: 800;
    color: #111827;
}

.cell-sub {
    display: block;
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #64748b;
    font-size: .78rem;
}

.loan-table-toolbar {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: center;
}

.loan-search-form {
    display: flex;
    gap: .6rem;
    align-items: center;
    flex-wrap: wrap;
}

.loan-search-form input[type="text"] {
    min-width: 240px;
    border-radius: 10px;
    border: 1px solid #dbe2ea;
    padding: .55rem .85rem;
    font-size: .85rem;
}

.loan-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    padding: 1rem;
    flex-wrap: wrap;
}

.loan-pagination a,
.loan-pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 .6rem;
    border-radius: 8px;
    font-size: .82rem;
    font-weight: 700;
    text-decoration: none;
    color: #334155;
    border: 1px solid #e2e8f0;
    background: #fff;
}

.loan-pagination a:hover {
    background: #f8fafc;
}

.loan-pagination .active {
    background: #2563eb;
    color: #fff;
    border-color: #2563eb;
}

.loan-pagination .disabled {
    opacity: .4;
    pointer-events: none;
}

@media (max-width: 768px) {
    .loan-table-scroll table {
        min-width: 1180px;
    }
}
</style>

<div class="page-header">
    <div>
        <div class="title">All Loans</div>
        <div class="sub"><?= number_format($totalLoans) ?> record(s)</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-primary" onclick="addLoanModal()">
            <i class="fas fa-plus"></i> New Loan
        </button>

        <button class="btn btn-ghost btn-sm" onclick="download_table_as_csv('tbl-loans','all_loans.csv')">
            <i class="fas fa-file-csv"></i> CSV
        </button>
    </div>
</div>

<div class="stats-grid mb-3">
    <div class="stat-card c-blue">
        <div class="stat-head">
            <div class="stat-ico c-blue"><i class="fas fa-file-invoice-dollar"></i></div>
            <span class="stat-badge flat"><?= number_format($totalLoans) ?> loans</span>
        </div>
        <div class="stat-value"><?= money($sumAmount) ?></div>
        <div class="stat-label">Total Disbursed</div>
    </div>

    <div class="stat-card c-orange">
        <div class="stat-head">
            <div class="stat-ico c-orange"><i class="fas fa-percentage"></i></div>
        </div>
        <div class="stat-value"><?= money($sumInterest) ?></div>
        <div class="stat-label">Total Interest</div>
    </div>

    <div class="stat-card c-red">
        <div class="stat-head">
            <div class="stat-ico c-red"><i class="fas fa-balance-scale"></i></div>
        </div>
        <div class="stat-value"><?= money($sumBalance) ?></div>
        <div class="stat-label">Total Balance</div>
    </div>

    <div class="stat-card c-green">
        <div class="stat-head">
            <div class="stat-ico c-green"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="stat-value"><?= number_format($statusStats['CLEARED']) ?></div>
        <div class="stat-label">Cleared Loans</div>
    </div>
</div>

<div class="stats-grid g3 mb-4">
    <div class="stat-card c-blue">
        <div class="stat-head">
            <div class="stat-ico c-blue"><i class="fas fa-users"></i></div>
            <span class="stat-badge flat"><?= number_format($typeStats['customer']['count']) ?> loans</span>
        </div>
        <div class="stat-value"><?= money($typeStats['customer']['amount']) ?></div>
        <div class="stat-label">Customer Loans</div>
        <div class="stat-foot">Balance: <?= money($typeStats['customer']['balance']) ?></div>
    </div>

    <div class="stat-card c-purple">
        <div class="stat-head">
            <div class="stat-ico c-purple"><i class="fas fa-user-tie"></i></div>
            <span class="stat-badge flat"><?= number_format($typeStats['member']['count']) ?> loans</span>
        </div>
        <div class="stat-value"><?= money($typeStats['member']['amount']) ?></div>
        <div class="stat-label">Member / Staff Loans</div>
        <div class="stat-foot">Balance: <?= money($typeStats['member']['balance']) ?></div>
    </div>

    <div class="stat-card c-orange">
        <div class="stat-head">
            <div class="stat-ico c-orange"><i class="fas fa-rocket"></i></div>
            <span class="stat-badge flat"><?= number_format($typeStats['startup']['count']) ?> loans</span>
        </div>
        <div class="stat-value"><?= money($typeStats['startup']['amount']) ?></div>
        <div class="stat-label">Startup Loans</div>
        <div class="stat-foot">Balance: <?= money($typeStats['startup']['balance']) ?></div>
    </div>
</div>

<div class="d-flex align-center justify-between flex-wrap gap-3 mb-4">
    <div class="pills">
        <?php foreach ([
            'all'         => 'All Loans',
            'customer'    => 'Customers',
            'member'      => 'Members / Staff',
            'startup'     => 'Startups',
          
            'cleared'     => 'Cleared',
            'outstanding' => 'Outstanding',
            'overdue'     => 'Overdue'
        ] as $k => $lbl): ?>
            <a href="?filter=<?= e($k) ?><?= $search !== '' ? '&q=' . e($search) : '' ?>" class="pill <?= $filter === $k ? 'active' : '' ?>">
                <?= e($lbl) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="GET" class="loan-search-form">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <input
            type="text"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Search name, phone, email, company..."
        >
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="fas fa-search"></i> Search
        </button>
        <?php if ($search !== ''): ?>
            <a href="?filter=<?= e($filter) ?>" class="btn btn-ghost btn-sm">
                <i class="fas fa-times"></i> Clear
            </a>
        <?php endif; ?>
    </form>
</div>

<div class="card loan-table-card">
    <div class="card-header">
        <div class="loan-table-toolbar">
            <div>
                <div class="card-title">
                    <span class="card-dot"></span>Loan Records
                </div>
                <div class="card-sub">
                    Scroll sideways to view all columns.
                    <?php if ($search !== ''): ?>
                        Showing results for "<strong><?= e($search) ?></strong>".
                    <?php endif; ?>
                </div>
            </div>

            <span class="badge badge-muted">
                <?= number_format($totalLoans) ?> records
                <?php if ($totalLoans > 0): ?>
                    — page <?= $page ?> of <?= $totalPages ?>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <div class="card-body no-pad">
        <div class="loan-table-scroll">
            <table class="table data-table" id="tbl-loans">
                <thead>
                <tr>
                    <th>#</th>
                    <th class="client-col">Client</th>
                    <th>Type</th>
                    <th class="contact-col">Contact</th>
                    <th>Amount</th>
                    <th>Interest</th>
                    <th>Balance</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Issued</th>
                    <th class="action-col">Actions</th>
                </tr>
                </thead>

                <tbody>
                <?php foreach ($loans as $loan): ?>
                    <?php
                    $dueDate = !empty($loan['due_date']) ? strtotime((string)$loan['due_date']) : false;
                    $issuedDate = !empty($loan['datetime']) ? strtotime((string)$loan['datetime']) : false;

                    $loanStatus = strtoupper((string)($loan['status'] ?? ''));
                    $overdue = $loanStatus !== 'CLEARED' && $dueDate && $dueDate < time();

                    $fname = $loan['fname'] ?? '';
                    $lname = $loan['lname'] ?? '';

                    $clientName = trim($fname . ' ' . $lname);

                    if ($clientName === '') {
                        $clientName = $loan['organization_name'] ?? 'Unknown Client';
                    }

                    if (($loan['client_type'] ?? '') === 'startup') {
                        $clientName = $loan['organization_name'] ?: $clientName;
                    }

                    $initials = strtoupper(substr((string)$clientName, 0, 1));
                    $clientType = (string)($loan['client_type'] ?? 'unknown');
                    ?>
                    <tr>
                        <td class="td-dark"><?= (int)$loan['id'] ?></td>

                        <td class="client-col">
                            <div class="cell-user">
                                <div class="cell-av"><?= e($initials ?: 'U') ?></div>
                                <div>
                                    <span class="cell-name"><?= e($clientName) ?></span>
                                    <span class="cell-sub"><?= e($loan['organization_name'] ?? '') ?></span>
                                </div>
                            </div>
                        </td>

                        <td>
                            <span class="badge <?= typeBadge($clientType) ?>">
                                <?= e(ucfirst($clientType)) ?>
                            </span>
                        </td>

                        <td class="contact-col">
                            <div class="td-dark"><?= e($loan['client_phone'] ?? 'N/A') ?></div>
                            <span class="cell-sub"><?= e($loan['client_email'] ?? '') ?></span>
                        </td>

                        <td class="td-dark"><?= money($loan['amount'] ?? 0) ?></td>

                        <td><?= money($loan['interest'] ?? 0) ?></td>

                        <td class="<?= ((float)($loan['loan_balance'] ?? 0) > 0) ? 'text-warning' : 'text-success' ?> fw-600">
                            <?= money($loan['loan_balance'] ?? 0) ?>
                        </td>

                        <td>
                            <?php if ($dueDate): ?>
                                <span class="<?= $overdue ? 'text-danger fw-600' : 'text-muted' ?> fs-sm">
                                    <?= date('M j, Y', $dueDate) ?>
                                </span>

                                <?php if ($overdue): ?>
                                    <span class="badge badge-danger" style="margin-left:3px;font-size:.6rem">Overdue</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>

                        <td><?= lbadge($loanStatus) ?></td>

                        <td class="text-muted fs-sm">
                            <?= $issuedDate ? date('M j, Y', $issuedDate) : 'N/A' ?>
                        </td>

                        <td class="action-col">
                            <div class="d-flex gap-1 flex-nowrap">
                                <a href="view_loan.php?id=<?= (int)$loan['id'] ?>" class="btn btn-ghost btn-icon-sm" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>

                                <?php if (in_array($loanStatus, ['OUTSTANDING', 'OVERDUE', 'ACTIVE', 'APPROVED'], true)): ?>
                                    <button class="btn btn-success btn-icon-sm" onclick="addPaymentModal(<?= (int)$loan['id'] ?>)" title="Payment">
                                        <i class="fas fa-money-bill"></i>
                                    </button>
                                <?php endif; ?>

                              

                                <a href="repayment_schedule.php?id=<?= (int)$loan['id'] ?>" class="btn btn-ghost btn-icon-sm" title="Schedule">
                                    <i class="fas fa-calendar-alt"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($loans)): ?>
                    <tr>
                        <td colspan="11">
                            <div class="empty-state">
                                <i class="fas fa-search"></i>
                                <h3>No loans found</h3>
                                <p><?= $search !== '' ? 'Try a different search term.' : 'Try a different filter.' ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="loan-pagination">
                <a
                    href="<?= e(pageUrl(max(1, $page - 1), $filter, $search)) ?>"
                    class="<?= $page <= 1 ? 'disabled' : '' ?>"
                >
                    <i class="fas fa-chevron-left"></i>
                </a>

                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);

                if ($start > 1) {
                    echo '<a href="' . e(pageUrl(1, $filter, $search)) . '">1</a>';
                    if ($start > 2) {
                        echo '<span>...</span>';
                    }
                }

                for ($p = $start; $p <= $end; $p++):
                    if ($p === $page): ?>
                        <span class="active"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= e(pageUrl($p, $filter, $search)) ?>"><?= $p ?></a>
                    <?php endif;
                endfor;

                if ($end < $totalPages) {
                    if ($end < $totalPages - 1) {
                        echo '<span>...</span>';
                    }
                    echo '<a href="' . e(pageUrl($totalPages, $filter, $search)) . '">' . $totalPages . '</a>';
                }
                ?>

                <a
                    href="<?= e(pageUrl(min($totalPages, $page + 1), $filter, $search)) ?>"
                    class="<?= $page >= $totalPages ? 'disabled' : '' ?>"
                >
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require "footer.php"; ?>