<?php
declare(strict_types=1);

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

function badgeStatus($status): string
{
    $status = strtoupper((string)($status ?? 'OVERDUE'));

    $class = match ($status) {
        'CLEARED' => 'badge-success',
        'ACTIVE' => 'badge-primary',
        'APPROVED' => 'badge-success',
        'OUTSTANDING' => 'badge-warning',
        'OVERDUE', 'DEFAULTED' => 'badge-danger',
        'REJECTED', 'DECLINED', 'CANCELLED', 'DENIED' => 'badge-muted',
        default => 'badge-muted',
    };

    return '<span class="badge ' . $class . '">' . h($status) . '</span>';
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

function daysOverdue($date): string
{
    if (empty($date)) {
        return 'N/A';
    }

    try {
        $due = new DateTime(date('Y-m-d', strtotime((string)$date)));
        $today = new DateTime(date('Y-m-d'));

        if ($due < $today) {
            return $due->diff($today)->days . ' day(s)';
        }

        return '0 day(s)';
    } catch (Throwable $e) {
        return 'N/A';
    }
}

$phead = "Overdue Loans";

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : 'all';

$allowedTypes = ['all', 'customer', 'member', 'startup'];

if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = 'all';
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| Overdue condition
|--------------------------------------------------------------------------
| Fix:
| - Excludes REJECTED / DECLINED / CANCELLED / DENIED loans completely.
| - Applies exclusion to table, pagination count, and stats because all queries
|   are built from this same condition.
*/
$overdueCondition = "
    (
        UPPER(COALESCE(l.status, '')) NOT IN ('REJECTED', 'DECLINED', 'CANCELLED', 'DENIED')
        AND (
            UPPER(COALESCE(l.status, '')) IN ('OVERDUE', 'DEFAULTED')
            OR (
                l.due_date IS NOT NULL
                AND l.due_date != ''
                AND DATE(l.due_date) < CURDATE()
                AND COALESCE(l.loan_balance, 0) > 0
            )
        )
    )
";

$searchSqlCustomer = '';
$searchSqlMember = '';
$searchSqlStartup = '';

$paramsCustomer = [];
$paramsMember = [];
$paramsStartup = [];

$typesCustomer = '';
$typesMember = '';
$typesStartup = '';

if ($search !== '') {
    $like = "%{$search}%";

    $searchSqlCustomer = "
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
            OR l.status LIKE ?
            OR CAST(l.id AS CHAR) LIKE ?
            OR CAST(l.amount AS CHAR) LIKE ?
            OR CAST(l.interest AS CHAR) LIKE ?
            OR CAST(l.loan_balance AS CHAR) LIKE ?
            OR CAST(l.due_date AS CHAR) LIKE ?
        )
    ";

    for ($i = 0; $i < 15; $i++) {
        $paramsCustomer[] = $like;
        $typesCustomer .= 's';
    }

    $searchSqlMember = "
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
            OR l.status LIKE ?
            OR CAST(l.id AS CHAR) LIKE ?
            OR CAST(l.amount AS CHAR) LIKE ?
            OR CAST(l.interest AS CHAR) LIKE ?
            OR CAST(l.loan_balance AS CHAR) LIKE ?
            OR CAST(l.due_date AS CHAR) LIKE ?
        )
    ";

    for ($i = 0; $i < 15; $i++) {
        $paramsMember[] = $like;
        $typesMember .= 's';
    }

    $searchSqlStartup = "
        AND (
            st.company_name LIKE ?
            OR st.ceo_name LIKE ?
            OR st.email LIKE ?
            OR st.phone LIKE ?
            OR st.company_location LIKE ?
            OR st.status LIKE ?
            OR l.status LIKE ?
            OR CAST(l.id AS CHAR) LIKE ?
            OR CAST(l.amount AS CHAR) LIKE ?
            OR CAST(l.interest AS CHAR) LIKE ?
            OR CAST(l.loan_balance AS CHAR) LIKE ?
            OR CAST(l.due_date AS CHAR) LIKE ?
        )
    ";

    for ($i = 0; $i < 12; $i++) {
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
    WHERE {$overdueCondition}
    {$searchSqlCustomer}
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
    WHERE {$overdueCondition}
    {$searchSqlMember}
";

/*
 * FIX (confirmed): loan.client_id stores startups.startup_id, which
 * is itself a foreign key into user.id — same pattern as
 * customers.user_id / members.user_id. The previous join on st.id
 * matched zero rows, which is why overdue startup loans never
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
    WHERE {$overdueCondition}
    {$searchSqlStartup}
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

$countSql = "SELECT COUNT(*) AS total FROM ($baseSql) x";
$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    die("Count query failed: " . h($conn->error));
}

if (!empty($bindParams)) {
    $countStmt->bind_param($bindTypes, ...$bindParams);
}

$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRows = $countResult ? (int)($countResult->fetch_assoc()['total'] ?? 0) : 0;
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));

$dataSql = "
    SELECT *
    FROM ($baseSql) x
    ORDER BY DATE(due_date) ASC, id DESC
    LIMIT ? OFFSET ?
";

$dataParams = array_merge($bindParams, [$limit, $offset]);
$dataTypes = $bindTypes . 'ii';

$stmt = $conn->prepare($dataSql);

if (!$stmt) {
    die("Loan query failed: " . h($conn->error));
}

$stmt->bind_param($dataTypes, ...$dataParams);
$stmt->execute();
$result = $stmt->get_result();

$loans = [];

while ($row = $result->fetch_assoc()) {
    $loans[] = $row;
}

$statsSql = "SELECT * FROM ($baseSql) x";
$statsStmt = $conn->prepare($statsSql);

if (!$statsStmt) {
    die("Stats query failed: " . h($conn->error));
}

if (!empty($bindParams)) {
    $statsStmt->bind_param($bindTypes, ...$bindParams);
}

$statsStmt->execute();
$statsResult = $statsStmt->get_result();

$stats = [
    'total_overdue' => 0,
    'total_amount' => 0,
    'total_interest' => 0,
    'total_balance' => 0,
];

$typeStats = [
    'customer' => ['count' => 0, 'amount' => 0, 'balance' => 0],
    'member'   => ['count' => 0, 'amount' => 0, 'balance' => 0],
    'startup'  => ['count' => 0, 'amount' => 0, 'balance' => 0],
];

while ($r = $statsResult->fetch_assoc()) {
    $clientType = (string)($r['client_type'] ?? 'unknown');
    $amount = (float)($r['amount'] ?? 0);
    $interest = (float)($r['interest'] ?? 0);
    $balance = (float)($r['loan_balance'] ?? 0);

    $stats['total_overdue']++;
    $stats['total_amount'] += $amount;
    $stats['total_interest'] += $interest;
    $stats['total_balance'] += $balance;

    if (isset($typeStats[$clientType])) {
        $typeStats[$clientType]['count']++;
        $typeStats[$clientType]['amount'] += $amount;
        $typeStats[$clientType]['balance'] += $balance;
    }
}

$statsStmt->close();
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

.border-danger-band::before { background: #dc3545; }
.border-primary-band::before { background: #0d6efd; }
.border-warning-band::before { background: #f59e0b; }
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
    flex-shrink: 0;
}

.stat-icon.bg-danger { background: #dc3545; }
.stat-icon.bg-primary { background: #0d6efd; }
.stat-icon.bg-warning { background: #f59e0b; }
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
    font-size: 1.2rem;
    font-weight: 800;
    color: #111827;
    margin: 0;
    word-break: break-word;
}

.stat-small {
    font-size: .78rem;
    color: #64748b;
    margin-top: .3rem;
}

.overdue-table-card {
    border: 0;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
    overflow: hidden;
}

.overdue-table-scroll {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}

.overdue-table-scroll table {
    min-width: 1320px;
    width: 100%;
    margin-bottom: 0;
}

.overdue-table-scroll th,
.overdue-table-scroll td {
    white-space: nowrap;
    vertical-align: middle;
}

.overdue-table-scroll::-webkit-scrollbar {
    height: 9px;
}

.overdue-table-scroll::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 999px;
}

.overdue-table-scroll::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 999px;
}

.overdue-table-scroll::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
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

.client-sub {
    display: block;
    max-width: 240px;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #64748b;
    font-size: .78rem;
}

.text-money {
    font-weight: 700;
}

.badge-purple {
    background: #f3e8ff;
    color: #7e22ce;
}

.loan-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(15, 23, 42, .55);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.loan-modal-overlay.show {
    display: flex;
}

.loan-modal-box {
    width: 100%;
    max-width: 560px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 25px 60px rgba(15, 23, 42, .25);
    overflow: hidden;
}

.loan-modal-header {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.loan-modal-header h5 {
    margin: 0;
    font-weight: 800;
}

.loan-modal-body {
    padding: 1.2rem;
}

.loan-modal-footer {
    padding: 1rem 1.2rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: .5rem;
}

.loan-modal-close {
    border: 0;
    background: #f3f4f6;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 1.2rem;
    line-height: 1;
}

.loan-modal-alert {
    border-radius: 12px;
    padding: .85rem;
    margin-bottom: 1rem;
    background: #fee2e2;
    color: #991b1b;
}

.loan-modal-overlay .form-control,
.loan-modal-overlay .form-select {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    padding: .65rem .75rem;
}

.loan-modal-overlay .form-label {
    display: block;
    font-weight: 700;
    margin-bottom: .35rem;
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

    .loan-modal-box {
        max-width: 100%;
    }
}
</style>

<div class="container-fluid py-4">

    <div class="page-header mb-4">
        <div>
            <div class="title"><?= h($phead) ?></div>
            <div class="sub"><?= number_format($totalRows) ?> overdue loan<?= $totalRows === 1 ? '' : 's' ?></div>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card border-danger-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Overdue Loans</div>
                    <div class="stat-value"><?= number_format($stats['total_overdue']) ?></div>
                </div>
                <div class="stat-icon bg-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-primary-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Total Principal</div>
                    <div class="stat-value"><?= money($stats['total_amount']) ?></div>
                </div>
                <div class="stat-icon bg-primary">
                    <i class="fas fa-coins"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-warning-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Total Interest</div>
                    <div class="stat-value"><?= money($stats['total_interest']) ?></div>
                </div>
                <div class="stat-icon bg-warning">
                    <i class="fas fa-percentage"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-dark-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Outstanding Balance</div>
                    <div class="stat-value text-danger"><?= money($stats['total_balance']) ?></div>
                </div>
                <div class="stat-icon bg-dark">
                    <i class="fas fa-wallet"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="stats-grid g3 mb-4">
        <div class="stat-card border-primary-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Customer Overdue</div>
                    <div class="stat-value"><?= number_format($typeStats['customer']['count']) ?></div>
                    <div class="stat-small"><?= money($typeStats['customer']['balance']) ?> balance</div>
                </div>
                <div class="stat-icon bg-primary">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-purple-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Member / Staff Overdue</div>
                    <div class="stat-value"><?= number_format($typeStats['member']['count']) ?></div>
                    <div class="stat-small"><?= money($typeStats['member']['balance']) ?> balance</div>
                </div>
                <div class="stat-icon bg-purple">
                    <i class="fas fa-user-tie"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-orange-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Startup Overdue</div>
                    <div class="stat-value"><?= number_format($typeStats['startup']['count']) ?></div>
                    <div class="stat-small"><?= money($typeStats['startup']['balance']) ?> balance</div>
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
            ] as $k => $label): ?>
                <a href="?type=<?= h($k) ?>&search=<?= urlencode($search) ?>"
                   class="pill <?= $typeFilter === $k ? 'active' : '' ?>">
                    <?= h($label) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card overdue-table-card mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h5 class="mb-0"><?= h($phead) ?></h5>
                <small class="text-muted">
                    Showing <?= number_format($totalRows) ?> overdue loan<?= $totalRows === 1 ? '' : 's' ?>
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

                <button type="button"
                        class="btn btn-sm bg-gradient-success ms-2"
                        onclick="download_table_as_csv('report','overdue_loans.csv')">
                    <i class="fas fa-file-csv me-1"></i> CSV
                </button>
            </form>
        </div>

        <div class="card-body p-0">
            <?php if (!empty($loans)): ?>
                <div class="overdue-table-scroll">
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
                            <th class="text-center">Days Overdue</th>
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
                                    <small class="client-sub">
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
                                <td><span class="text-money text-danger"><?= money($loan['loan_balance'] ?? 0) ?></span></td>

                                <td class="text-center">
                                    <?= badgeStatus($loan['status'] ?? 'OVERDUE') ?>
                                </td>

                                <td class="text-center">
                                    <span class="text-secondary text-xs fw-bold"><?= h($issueDate) ?></span>
                                </td>

                                <td class="text-center">
                                    <span class="text-secondary text-xs fw-bold"><?= h($dueDate) ?></span>
                                </td>

                                <td class="text-center">
                                    <span class="text-danger text-xs fw-bold">
                                        <?= h(daysOverdue($loan['due_date'] ?? '')) ?>
                                    </span>
                                </td>

                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1 flex-nowrap">
                                        <a href="view_loan.php?id=<?= urlencode((string)$loan['id']) ?>"
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
                                        </a>

                                        <button type="button"
                                                class="btn btn-sm btn-success js-open-repay"
                                                data-loan-id="<?= (int)$loan['id'] ?>"
                                                data-client="<?= h($clientName) ?>"
                                                data-balance="<?= h($loan['loan_balance'] ?? 0) ?>">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="px-3 py-3">
                        <ul class="pagination justify-content-center flex-wrap mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link"
                                   href="?page=<?= max(1, $page - 1) ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($typeFilter) ?>">
                                    Previous
                                </a>
                            </li>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            ?>

                            <?php for ($p = $start; $p <= $end; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link"
                                       href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($typeFilter) ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link"
                                   href="?page=<?= min($totalPages, $page + 1) ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($typeFilter) ?>">
                                    Next
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <h6>No overdue loans found</h6>
                    <p class="text-muted mb-0">
                        Loans appear here when status is OVERDUE or due date has passed with a remaining balance.
                        Rejected, declined, cancelled, and denied loans are excluded.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="loan-modal-overlay" id="repayLoanModal">
    <div class="loan-modal-box">
        <form method="POST" action="ajax/loan/process-repayment.php">
            <div class="loan-modal-header">
                <h5>
                    <i class="fas fa-money-bill-wave me-2"></i>
                    Repay Overdue Loan
                </h5>

                <button type="button" class="loan-modal-close js-close-loan-modal">
                   x
                </button>
            </div>

            <div class="loan-modal-body">
                <input type="hidden" name="loan_id" id="repay_loan_id">

                <div class="loan-modal-alert">
                    <strong id="repay_client_name">Client</strong><br>
                    Outstanding Balance:
                    <strong id="repay_balance_text">UGX 0</strong>
                </div>

                <div class="mb-3">
                    <label class="form-label">Amount</label>
                    <input type="number"
                           name="amount"
                           id="repay_amount"
                           class="form-control"
                           min="1"
                           step="0.01"
                           required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <option value="Cash">Cash</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Bank">Bank</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Reference</label>
                    <input type="text" name="reference" class="form-control">
                </div>

                <div>
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>

            <div class="loan-modal-footer">
                <button type="button" class="btn btn-light js-close-loan-modal">
                    Cancel
                </button>

                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save me-1"></i>
                    Save Repayment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function moneyFormat(amount) {
        return 'UGX ' + Math.round(Number(amount || 0)).toLocaleString();
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;

        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeModals() {
        document.querySelectorAll('.loan-modal-overlay').forEach(modal => {
            modal.classList.remove('show');
        });

        document.body.style.overflow = '';
    }

    document.querySelectorAll('.js-open-repay').forEach(btn => {
        btn.addEventListener('click', function () {
            const loanId = this.dataset.loanId;
            const client = this.dataset.client || 'Client';
            const balance = parseFloat(this.dataset.balance || 0);

            document.getElementById('repay_loan_id').value = loanId;
            document.getElementById('repay_client_name').textContent = client;
            document.getElementById('repay_balance_text').textContent = moneyFormat(balance);

            const amountInput = document.getElementById('repay_amount');
            amountInput.value = '';
            amountInput.max = balance;

            openModal('repayLoanModal');
        });
    });

    document.querySelectorAll('.js-close-loan-modal').forEach(btn => {
        btn.addEventListener('click', closeModals);
    });

    document.querySelectorAll('.loan-modal-overlay').forEach(modal => {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModals();
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModals();
        }
    });
});
</script>

<?php
$stmt->close();
require "footer.php";
?>