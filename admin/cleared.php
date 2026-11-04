<?php
declare(strict_types=1);

require "nav-head.php";
require "services_check.php";

if (!isset($conn)) {
    die("Database connection not established.");
}

$phead = "Cleared Loans";

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return 'UGX ' . number_format((float)$value);
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

$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? 'all');

$allowedTypes = ['all', 'customer', 'member', 'startup'];
if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = 'all';
}

/* ---- Pagination ---- */
$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$whereStatus = "l.status = 'CLEARED'";

$searchCustomer = '';
$searchMember = '';
$searchStartup = '';

$paramsCustomer = [];
$paramsMember = [];
$paramsStartup = [];

$typesCustomer = '';
$typesMember = '';
$typesStartup = '';

if ($search !== '') {
    $like = "%{$search}%";

    $searchCustomer = "
        AND (
            u.fname LIKE ?
            OR u.lname LIKE ?
            OR CONCAT(u.fname, ' ', u.lname) LIKE ?
            OR COALESCE(c.email, u.email) LIKE ?
            OR c.phone LIKE ?
            OR c.nin LIKE ?
            OR c.business_name LIKE ?
            OR c.location LIKE ?
            OR c.district LIKE ?
            OR CAST(l.id AS CHAR) LIKE ?
            OR CAST(l.amount AS CHAR) LIKE ?
            OR CAST(l.interest AS CHAR) LIKE ?
            OR CAST(l.loan_balance AS CHAR) LIKE ?
            OR CAST(l.due_date AS CHAR) LIKE ?
        )
    ";

    for ($i = 0; $i < 14; $i++) {
        $paramsCustomer[] = $like;
        $typesCustomer .= 's';
    }

    $searchMember = "
        AND (
            u.fname LIKE ?
            OR u.lname LIKE ?
            OR CONCAT(u.fname, ' ', u.lname) LIKE ?
            OR COALESCE(m.work_email, u.email) LIKE ?
            OR m.phone LIKE ?
            OR m.nin LIKE ?
            OR m.employer LIKE ?
            OR m.department LIKE ?
            OR m.home_address LIKE ?
            OR CAST(l.id AS CHAR) LIKE ?
            OR CAST(l.amount AS CHAR) LIKE ?
            OR CAST(l.interest AS CHAR) LIKE ?
            OR CAST(l.loan_balance AS CHAR) LIKE ?
            OR CAST(l.due_date AS CHAR) LIKE ?
        )
    ";

    for ($i = 0; $i < 14; $i++) {
        $paramsMember[] = $like;
        $typesMember .= 's';
    }

    $searchStartup = "
        AND (
            st.company_name LIKE ?
            OR st.ceo_name LIKE ?
            OR st.email LIKE ?
            OR st.phone LIKE ?
            OR st.company_location LIKE ?
            OR st.status LIKE ?
            OR CAST(l.id AS CHAR) LIKE ?
            OR CAST(l.amount AS CHAR) LIKE ?
            OR CAST(l.interest AS CHAR) LIKE ?
            OR CAST(l.loan_balance AS CHAR) LIKE ?
            OR CAST(l.due_date AS CHAR) LIKE ?
        )
    ";

    for ($i = 0; $i < 11; $i++) {
        $paramsStartup[] = $like;
        $typesStartup .= 's';
    }
}

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
    WHERE {$whereStatus}
    {$searchCustomer}
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
    WHERE {$whereStatus}
    {$searchMember}
";

/*
 * FIX (confirmed): loan.client_id stores startups.startup_id, which
 * is itself a foreign key into user.id — same pattern as
 * customers.user_id / members.user_id. The previous join on st.id
 * matched zero rows, which is why cleared startup loans never
 * appeared here.
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
    WHERE {$whereStatus}
    {$searchStartup}
";

if ($typeFilter === 'customer') {
    $baseSql = $customerSql;
    $bindParams = $paramsCustomer;
    $bindTypes = $typesCustomer;
} elseif ($typeFilter === 'member') {
    $baseSql = $memberSql;
    $bindParams = $paramsMember;
    $bindTypes = $typesMember;
} elseif ($typeFilter === 'startup') {
    $baseSql = $startupSql;
    $bindParams = $paramsStartup;
    $bindTypes = $typesStartup;
} else {
    $baseSql = "
        ($customerSql)
        UNION ALL
        ($memberSql)
        UNION ALL
        ($startupSql)
    ";

    $bindParams = array_merge($paramsCustomer, $paramsMember, $paramsStartup);
    $bindTypes = $typesCustomer . $typesMember . $typesStartup;
}

$sql = "
    SELECT *
    FROM ($baseSql) x
    ORDER BY id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Loan query failed: " . h($conn->error));
}

if (!empty($bindParams)) {
    $stmt->bind_param($bindTypes, ...$bindParams);
}

$stmt->execute();
$result = $stmt->get_result();

$allLoans = [];

while ($row = $result->fetch_assoc()) {
    $allLoans[] = $row;
}

/* ---- Stats computed over the FULL filtered/searched set ---- */
$stats = [
    'count' => 0,
    'amount' => 0,
    'interest' => 0,
    'balance' => 0,
];

$typeStats = [
    'customer' => ['count' => 0, 'amount' => 0],
    'member'   => ['count' => 0, 'amount' => 0],
    'startup'  => ['count' => 0, 'amount' => 0],
];

foreach ($allLoans as $loan) {
    $clientType = (string)($loan['client_type'] ?? 'unknown');
    $amount = (float)($loan['amount'] ?? 0);
    $interest = (float)($loan['interest'] ?? 0);
    $balance = (float)($loan['loan_balance'] ?? 0);

    $stats['count']++;
    $stats['amount'] += $amount;
    $stats['interest'] += $interest;
    $stats['balance'] += $balance;

    if (isset($typeStats[$clientType])) {
        $typeStats[$clientType]['count']++;
        $typeStats[$clientType]['amount'] += $amount;
    }
}

/* ---- Paginate the already-filtered/searched set for display ---- */
$totalLoans = count($allLoans);
$totalPages = max(1, (int)ceil($totalLoans / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$loans = array_slice($allLoans, $offset, $perPage);

function pageUrl(int $p, string $type, string $search): string
{
    $params = ['type' => $type, 'page' => $p];

    if ($search !== '') {
        $params['search'] = $search;
    }

    return '?' . http_build_query($params);
}
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.stats-grid.g3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
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

.border-success-band::before { background: #16a34a; }
.border-primary-band::before { background: #0d6efd; }
.border-dark-band::before { background: #111827; }
.border-purple-band::before { background: #7e22ce; }
.border-orange-band::before { background: #f97316; }

.stat-flex {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
}

.stat-icon.bg-success { background: #16a34a; }
.stat-icon.bg-primary { background: #0d6efd; }
.stat-icon.bg-dark { background: #111827; }
.stat-icon.bg-purple { background: #7e22ce; }
.stat-icon.bg-orange { background: #f97316; }

.stat-label {
    font-size: .72rem;
    font-weight: 700;
    color: #6b7280;
    margin-bottom: .35rem;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.stat-value {
    font-size: 1.15rem;
    font-weight: 800;
    color: #111827;
    margin: 0;
}

.stat-small {
    font-size: .78rem;
    color: #64748b;
    margin-top: .3rem;
}

.cleared-table-card {
    border: 0;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
}

.search-box {
    max-width: 580px;
}

.table thead th {
    font-size: .72rem;
    text-transform: uppercase;
    color: #6c757d;
    letter-spacing: .04em;
}

.table td {
    vertical-align: middle;
}

.client-name {
    font-weight: 700;
    color: #212529;
}

.text-money {
    font-weight: 700;
}

.badge-purple {
    background: #f3e8ff;
    color: #7e22ce;
}

.badge-muted {
    background: #f1f5f9;
    color: #475569;
}

.cleared-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    padding: 1rem;
    flex-wrap: wrap;
}

.cleared-pagination a,
.cleared-pagination span {
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

.cleared-pagination a:hover {
    background: #f8fafc;
}

.cleared-pagination .active {
    background: #16a34a;
    color: #fff;
    border-color: #16a34a;
}

.cleared-pagination .disabled {
    opacity: .4;
    pointer-events: none;
}

@media (max-width: 992px) {
    .stats-grid,
    .stats-grid.g3 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 576px) {
    .stats-grid,
    .stats-grid.g3 {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container-fluid py-4">

    <div class="page-header mb-4">
        <div>
            <div class="title"><?= h($phead) ?></div>
            <div class="sub"><?= number_format($stats['count']) ?> cleared loan<?= $stats['count'] === 1 ? '' : 's' ?></div>
        </div>

        <div>
            <button class="btn btn-sm bg-gradient-success" onclick="download_table_as_csv('report','cleared_loans.csv')">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </button>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card border-success-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Cleared Loans</div>
                    <div class="stat-value"><?= number_format($stats['count']) ?></div>
                </div>
                <div class="stat-icon bg-success">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-primary-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Total Principal</div>
                    <div class="stat-value"><?= money($stats['amount']) ?></div>
                </div>
                <div class="stat-icon bg-primary">
                    <i class="fas fa-coins"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-dark-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Total Interest</div>
                    <div class="stat-value"><?= money($stats['interest']) ?></div>
                </div>
                <div class="stat-icon bg-dark">
                    <i class="fas fa-percentage"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-success-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Remaining Balance</div>
                    <div class="stat-value"><?= money($stats['balance']) ?></div>
                </div>
                <div class="stat-icon bg-success">
                    <i class="fas fa-wallet"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="stats-grid g3 mb-4">
        <div class="stat-card border-primary-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Customer Cleared</div>
                    <div class="stat-value"><?= number_format($typeStats['customer']['count']) ?></div>
                    <div class="stat-small"><?= money($typeStats['customer']['amount']) ?> principal</div>
                </div>
                <div class="stat-icon bg-primary">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-purple-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Member / Staff Cleared</div>
                    <div class="stat-value"><?= number_format($typeStats['member']['count']) ?></div>
                    <div class="stat-small"><?= money($typeStats['member']['amount']) ?> principal</div>
                </div>
                <div class="stat-icon bg-purple">
                    <i class="fas fa-user-tie"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-orange-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Startup Cleared</div>
                    <div class="stat-value"><?= number_format($typeStats['startup']['count']) ?></div>
                    <div class="stat-small"><?= money($typeStats['startup']['amount']) ?> principal</div>
                </div>
                <div class="stat-icon bg-orange">
                    <i class="fas fa-rocket"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex align-center justify-between flex-wrap gap-3 mb-3">
        <div class="pills">
            <?php foreach ([
                'all' => 'All',
                'customer' => 'Customers',
                'member' => 'Members / Staff',
                'startup' => 'Startups'
            ] as $key => $label): ?>
                <a href="?type=<?= h($key) ?>&search=<?= urlencode($search) ?>"
                   class="pill <?= $typeFilter === $key ? 'active' : '' ?>">
                    <?= h($label) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card cleared-table-card mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h5 class="mb-0"><?= h($phead) ?></h5>
                <small class="text-muted">
                    Showing <?= number_format($stats['count']) ?> cleared loan<?= $stats['count'] === 1 ? '' : 's' ?>
                    <?php if ($totalLoans > 0): ?>
                        — page <?= $page ?> of <?= $totalPages ?>
                    <?php endif; ?>
                </small>
            </div>

            <form method="GET" class="d-flex search-box w-100 w-md-auto">
                <input type="hidden" name="type" value="<?= h($typeFilter) ?>">

                <input type="text"
                       name="search"
                       value="<?= h($search) ?>"
                       class="form-control form-control-sm me-2"
                       placeholder="Search client, phone, company, amount, date...">

                <button type="submit" class="btn btn-sm bg-gradient-primary me-2">
                    <i class="fas fa-search"></i>
                </button>

                <?php if ($search !== ''): ?>
                    <a href="<?= h(basename($_SERVER['PHP_SELF'])) ?>?type=<?= h($typeFilter) ?>"
                       class="btn btn-sm btn-light">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card-body px-0 pt-0 pb-2">
            <?php if (!empty($loans)): ?>
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0" id="report">
                        <thead>
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Client</th>
                            <th>Type</th>
                            <th>Contact</th>
                            <th>Amount</th>
                            <th>Interest</th>
                            <th>Balance</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Issue Date</th>
                            <th class="text-center">Due Date</th>
                            <th class="text-center">Action</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($loans as $index => $loan): ?>
                            <?php
                            $clientType = (string)($loan['client_type'] ?? 'unknown');
                            $clientName = trim(($loan['fname'] ?? '') . ' ' . ($loan['lname'] ?? ''));

                            if ($clientType === 'startup') {
                                $clientName = $loan['organization_name'] ?: ($loan['fname'] ?? 'Startup');
                            }

                            if ($clientName === '') {
                                $clientName = $loan['organization_name'] ?? 'N/A';
                            }

                            $issueDate = !empty($loan['datetime'])
                                ? date("M d, Y", strtotime((string)$loan['datetime']))
                                : 'N/A';

                            $dueDate = !empty($loan['due_date'])
                                ? date("M d, Y", strtotime((string)$loan['due_date']))
                                : 'N/A';
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="text-sm fw-bold"><?= number_format($offset + $index + 1) ?></span>
                                </td>

                                <td>
                                    <div class="client-name">
                                        <i class="fas fa-user-circle me-1 text-secondary"></i>
                                        <?= h($clientName) ?>
                                    </div>
                                    <small class="text-muted">
                                        Loan ID: #<?= (int)$loan['id'] ?>
                                        <?php if (!empty($loan['organization_name']) && $clientType !== 'startup'): ?>
                                            . <?= h($loan['organization_name']) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>

                                <td>
                                    <span class="badge <?= typeBadge($clientType) ?>">
                                        <?= h(ucfirst($clientType)) ?>
                                    </span>
                                </td>

                                <td>
                                    <div><?= h($loan['client_phone'] ?? 'N/A') ?></div>
                                    <small class="text-muted"><?= h($loan['client_email'] ?? '') ?></small>
                                </td>

                                <td><span class="text-money"><?= money($loan['amount'] ?? 0) ?></span></td>
                                <td><span class="text-money"><?= money($loan['interest'] ?? 0) ?></span></td>
                                <td><span class="text-money text-success"><?= money($loan['loan_balance'] ?? 0) ?></span></td>

                                <td class="text-center">
                                    <span class="badge badge-success">
                                        <?= h($loan['status'] ?? 'CLEARED') ?>
                                    </span>
                                </td>

                                <td class="text-center">
                                    <span class="text-secondary text-xs fw-bold"><?= h($issueDate) ?></span>
                                </td>

                                <td class="text-center">
                                    <span class="text-secondary text-xs fw-bold"><?= h($dueDate) ?></span>
                                </td>

                                <td class="text-center">
                                    <a href="view_loan.php?id=<?= urlencode((string)$loan['id']) ?>"
                                       class="btn btn-sm btn-outline-primary mb-0">
                                        <i class="fas fa-eye me-1"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="cleared-pagination">
                        <a
                            href="<?= h(pageUrl(max(1, $page - 1), $typeFilter, $search)) ?>"
                            class="<?= $page <= 1 ? 'disabled' : '' ?>"
                        >
                            <i class="fas fa-chevron-left"></i>
                        </a>

                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);

                        if ($start > 1) {
                            echo '<a href="' . h(pageUrl(1, $typeFilter, $search)) . '">1</a>';
                            if ($start > 2) {
                                echo '<span>…</span>';
                            }
                        }

                        for ($p = $start; $p <= $end; $p++):
                            if ($p === $page): ?>
                                <span class="active"><?= $p ?></span>
                            <?php else: ?>
                                <a href="<?= h(pageUrl($p, $typeFilter, $search)) ?>"><?= $p ?></a>
                            <?php endif;
                        endfor;

                        if ($end < $totalPages) {
                            if ($end < $totalPages - 1) {
                                echo '<span>…</span>';
                            }
                            echo '<a href="' . h(pageUrl($totalPages, $typeFilter, $search)) . '">' . $totalPages . '</a>';
                        }
                        ?>

                        <a
                            href="<?= h(pageUrl(min($totalPages, $page + 1), $typeFilter, $search)) ?>"
                            class="<?= $page >= $totalPages ? 'disabled' : '' ?>"
                        >
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                    <h6>No cleared loans found</h6>
                    <p class="text-muted mb-0">Cleared loans will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$stmt->close();
require "footer.php";
?>