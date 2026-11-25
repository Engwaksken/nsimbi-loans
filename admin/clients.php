<?php
$phead = 'Clients';
require "nav-head.php";
require "services_check.php";
require_once dirname(__DIR__) . '/includes/profile_photo.php';

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$limit  = 10;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$search = trim($_GET['q'] ?? '');
$type   = trim($_GET['type'] ?? '');
$district = trim($_GET['district'] ?? '');

// ---- Period (by the date the client signed up) ----
$periods = [
    'all'        => 'All time',
    'today'      => 'Today',
    'this_week'  => 'This week',
    'this_month' => 'This month',
    'last_month' => 'Last month',
    'last_90'    => 'Last 90 days',
    'this_year'  => 'This year',
    'custom'     => 'Custom dates',
];
$period = (string)($_GET['period'] ?? 'all');
if (!isset($periods[$period])) {
    $period = 'all';
}
$validDate = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false ? $d : null;
[$from, $to] = match ($period) {
    'today'      => [date('Y-m-d'), date('Y-m-d')],
    'this_week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
    'this_month' => [date('Y-m-01'), date('Y-m-d')],
    'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'last_90'    => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
    'this_year'  => [date('Y-01-01'), date('Y-m-d')],
    'custom'     => [$validDate($_GET['from'] ?? null), $validDate($_GET['to'] ?? null)],
    default      => [null, null],
};
if ($from !== null && $to !== null && $from > $to) {
    [$from, $to] = [$to, $from];
}

// ---- Dropdown options (only the officer's own clients for loan officers) ----
$scope = officer_client_sql('c');

$typeOptions = [];
$res = $conn->query("
    SELECT MIN(TRIM(c.business_type)) AS name
    FROM customers c
    WHERE TRIM(COALESCE(c.business_type, '')) <> ''" . $scope . "
    GROUP BY LOWER(TRIM(c.business_type))
    ORDER BY name
");
while ($res && $row = $res->fetch_assoc()) {
    $typeOptions[] = $row['name'];
}

$districtOptions = [];
$res = $conn->query("
    SELECT MIN(TRIM(c.district)) AS name
    FROM customers c
    WHERE TRIM(COALESCE(c.district, '')) <> ''" . $scope . "
    GROUP BY LOWER(TRIM(c.district))
    ORDER BY name
");
while ($res && $row = $res->fetch_assoc()) {
    $districtOptions[] = $row['name'];
}

// ---- Filters (shared by the list, the count and the stat cards) ----
$where  = "WHERE 1 = 1" . $scope;
$params = [];
$types  = "";

if ($search !== "") {
    $where .= " AND (
        u.fname LIKE ? OR
        u.lname LIKE ? OR
        CONCAT(u.fname, ' ', u.lname) LIKE ? OR
        u.email LIKE ? OR
        c.phone LIKE ? OR
        c.business_name LIKE ? OR
        c.business_type LIKE ? OR
        c.district LIKE ? OR
        c.location LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    $types .= "sssssssss";
}

if ($type !== "") {
    $where .= " AND LOWER(TRIM(c.business_type)) = LOWER(?)";
    $params[] = $type;
    $types .= "s";
}

if ($district !== "") {
    $where .= " AND LOWER(TRIM(c.district)) = LOWER(?)";
    $params[] = $district;
    $types .= "s";
}

if ($from !== null) {
    $where .= " AND c.created_at >= ?";
    $params[] = $from . ' 00:00:00';
    $types .= "s";
}

if ($to !== null) {
    $where .= " AND c.created_at <= ?";
    $params[] = $to . ' 23:59:59';
    $types .= "s";
}

$filtered = $search !== '' || $type !== '' || $district !== '' || $period !== 'all';

// Query string that keeps the filters across pages.
$filterQuery = http_build_query(array_filter([
    'q'        => $search,
    'period'   => $period !== 'all' ? $period : '',
    'from'     => $period === 'custom' ? ($from ?? '') : '',
    'to'       => $period === 'custom' ? ($to ?? '') : '',
    'type'     => $type,
    'district' => $district,
], static fn($v) => $v !== ''));
$query = $filterQuery !== '' ? '&' . $filterQuery : '';

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    $where
");
if ($types !== "") {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int)$countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));

$sql = "
    SELECT 
        u.id,
        u.fname,
        u.lname,
        u.image_url,
        u.email,
        u.status,
        c.phone,
        c.business_name,
        c.business_type,
        c.location,
        c.district,
        c.loan_limit,
        c.nok_name,
        c.nok_tel,
        c.active,
        (
            SELECT COUNT(*) 
            FROM loan 
            WHERE client_id = u.id 
            AND status != 'PENDING'
        ) AS ln,
        (
            SELECT COALESCE(SUM(loan_balance), 0) 
            FROM loan 
            WHERE client_id = u.id 
            AND status != 'CLEARED' 
            AND status != 'PENDING'
        ) AS bal
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    $where
    ORDER BY c.id DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $bindTypes  = $types . "ii";
    $bindValues = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($bindTypes, ...$bindValues);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$clients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$statsSql = "
    SELECT 
        COUNT(*) AS total_clients,
        SUM(CASE WHEN c.active = 1 AND u.status = 1 THEN 1 ELSE 0 END) AS active_clients,
        SUM(CASE WHEN l.loan_count > 0 THEN 1 ELSE 0 END) AS clients_with_loans,
        COALESCE(SUM(l.total_balance), 0) AS total_outstanding
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    LEFT JOIN (
        SELECT 
            client_id,
            COUNT(CASE WHEN status != 'PENDING' THEN 1 END) AS loan_count,
            COALESCE(SUM(
                CASE 
                    WHEN status != 'CLEARED' AND status != 'PENDING' 
                    THEN loan_balance 
                    ELSE 0 
                END
            ), 0) AS total_balance
        FROM loan
        GROUP BY client_id
    ) l ON l.client_id = u.id
    $where
";

$statsStmt = $conn->prepare($statsSql);
if ($types !== "") {
    $statsStmt->bind_param($types, ...$params);
}
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

$totalClients     = (int)($stats['total_clients'] ?? 0);
$activeClients    = (int)($stats['active_clients'] ?? 0);
$clientsWithLoans = (int)($stats['clients_with_loans'] ?? 0);
$totalOutstanding = (float)($stats['total_outstanding'] ?? 0);
?>

<div class="page-header">
    <div>
        <div class="title">Business Clients</div>
        <div class="sub"><?= number_format($totalRows) ?> <?= $filtered ? 'matching' : 'registered' ?> clients</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="add_client.php" class="btn btn-primary">
            <i class="fas fa-user-plus"></i> Add Client
        </a>

        <a href="import_clients.php" class="btn btn-success">
            <i class="fas fa-file-import"></i> Import CSV
        </a>

       <!-- <a href="download_client_template.php" class="btn btn-ghost btn-sm">
            <i class="fas fa-file-csv"></i> Download Template
        </a> -->

        <button type="button" class="btn btn-ghost btn-sm" onclick="download_table_as_csv('tbl-clients','clients_export.csv')">
            <i class="fas fa-file-csv"></i> Export
        </button>
    </div>
</div>

<div class="stats-grid clients-stats-grid">
    <div class="stat-card c-blue">
        <div class="stat-head">
            <div class="stat-ico c-blue">
                <i class="fas fa-users"></i>
            </div>
        </div>
        <div class="stat-value sm"><?= number_format($totalClients) ?></div>
        <div class="stat-label">Total Clients</div>
    </div>

    <div class="stat-card c-green">
        <div class="stat-head">
            <div class="stat-ico c-green">
                <i class="fas fa-user-check"></i>
            </div>
        </div>
        <div class="stat-value sm"><?= number_format($activeClients) ?></div>
        <div class="stat-label">Active Clients</div>
    </div>

    <div class="stat-card c-indigo">
        <div class="stat-head">
            <div class="stat-ico c-indigo">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
        </div>
        <div class="stat-value sm"><?= number_format($clientsWithLoans) ?></div>
        <div class="stat-label">Have Loans</div>
    </div>

    <div class="stat-card c-orange">
        <div class="stat-head">
            <div class="stat-ico c-orange">
                <i class="fas fa-balance-scale"></i>
            </div>
        </div>
        <div class="stat-value">UGX <?= number_format($totalOutstanding) ?></div>
        <div class="stat-label">Total Outstanding</div>
    </div>
</div>

<div class="card clients-card">
    <div class="card-header clients-toolbar">
        <form method="GET" class="clients-filter-form">
            <div class="input-group clients-filter-search">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input
                    type="text"
                    name="q"
                    class="form-control"
                    value="<?= e($search) ?>"
                    placeholder="Search client, phone, email, business..."
                    aria-label="Search"
                >
            </div>

            <select name="period" class="form-select" aria-label="Sign-up period"
                    onchange="this.form.querySelector('.clients-custom-dates').hidden = this.value !== 'custom'; if (this.value !== 'custom') this.form.submit();">
                <?php foreach ($periods as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $period === $key ? ' selected' : '' ?>><?= e($key === 'all' ? 'Period: All time' : $label) ?></option>
                <?php endforeach; ?>
            </select>

            <span class="clients-custom-dates"<?= $period === 'custom' ? '' : ' hidden' ?>>
                <input type="date" name="from" class="form-control" value="<?= e($from ?? '') ?>" aria-label="From">
                <input type="date" name="to" class="form-control" value="<?= e($to ?? '') ?>" aria-label="To">
            </span>

            <select name="type" class="form-select" aria-label="Business type" onchange="this.form.submit()">
                <option value="">All types</option>
                <?php foreach ($typeOptions as $opt): ?>
                    <option value="<?= e($opt) ?>"<?= strcasecmp($opt, $type) === 0 ? ' selected' : '' ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="district" class="form-select" aria-label="District" onchange="this.form.submit()">
                <option value="">All districts</option>
                <?php foreach ($districtOptions as $opt): ?>
                    <option value="<?= e($opt) ?>"<?= strcasecmp($opt, $district) === 0 ? ' selected' : '' ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn-primary" type="submit"><i class="fas fa-filter"></i> Filter</button>

            <?php if ($filtered): ?>
                <a href="clients.php" class="btn btn-light">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card-body no-pad">
        <div class="table-responsive client-table-scroll">
            <table class="table data-table clients-table" id="tbl-clients">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Client</th>
                        <th>Phone</th>
                        <th>Business</th>
                        <th>Type</th>
                        <th>District</th>
                        <th>Loan Limit</th>
                        <th>Loans</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!empty($clients)): ?>
                        <?php $i = $offset + 1; foreach ($clients as $c): ?>
                            <?php
                            $clientId = (int)$c['id'];
                            $fullName = trim(($c['fname'] ?? '') . ' ' . ($c['lname'] ?? ''));
                            $initials = strtoupper(substr($c['fname'] ?? '', 0, 1) . substr($c['lname'] ?? '', 0, 1));
                            $isActive = ((int)$c['active'] === 1 && (int)$c['status'] === 1);
                            ?>
                            <tr id="client-row-<?= $clientId ?>">
                                <td><?= $i++ ?></td>

                                <td>
                                    <div class="cell-user">
                                        <?= profile_avatar($c['image_url'] ?? '', $initials ?: 'CL', 'cell-av') ?>

                                        <div>
                                            <div class="cell-name"><?= e($fullName ?: 'Unnamed Client') ?></div>
                                            <div class="cell-sub"><?= e($c['email'] ?? 'N/A') ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="text-muted fs-sm">
                                    <?= e($c['phone'] ?: 'N/A') ?>
                                </td>

                                <td class="td-dark text-truncate-cell">
                                    <?= e($c['business_name'] ?: 'N/A') ?>
                                </td>

                                <td>
                                    <span class="badge badge-muted">
                                        <?= e($c['business_type'] ?: 'N/A') ?>
                                    </span>
                                </td>

                                <td class="text-muted fs-sm">
                                    <?= e($c['district'] ?: ($c['location'] ?: 'N/A')) ?>
                                </td>

                                <td class="td-dark">
                                    UGX <?= number_format((float)($c['loan_limit'] ?? 0)) ?>
                                </td>

                                <td>
                                    <a 
                                        href="view_client.php?user_id=<?= $clientId ?>&tab=loans"
                                        class="badge <?= (int)$c['ln'] > 0 ? 'badge-primary' : 'badge-muted' ?>"
                                    >
                                        <?= (int)$c['ln'] ?> loan<?= (int)$c['ln'] !== 1 ? 's' : '' ?>
                                    </a>
                                </td>

                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Blocked</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="action-buttons">
                                        <a href="view_client.php?user_id=<?= $clientId ?>" 
                                           class="btn btn-ghost btn-icon-sm" 
                                           title="View Client">
                                            <i class="fas fa-eye"></i>
                                        </a>

                                      

                                        <a href="delete_client.php?client_id=<?= $clientId ?>" 
                                           class="btn btn-danger btn-icon-sm" 
                                           title="Delete Client"
                                           onclick="return confirm('Are you sure you want to delete this client?');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10">
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <h3>No clients found</h3>
                                    <p>No matching client records were found.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-footer clients-pagination">
        <div class="pagination-info">
            Page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= max(1, $page - 1) . $query ?>">
                            Previous
                        </a>
                    </li>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    ?>

                    <?php for ($p = $start; $p <= $end; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p . $query ?>">
                                <?= $p ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= min($totalPages, $page + 1) . $query ?>">
                            Next
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<style>
.clients-filter-form{
    display:flex;
    align-items:center;
    gap:.5rem;
    flex-wrap:nowrap;
    width:100%;
    overflow-x:auto;
}
.clients-filter-form .clients-filter-search{ flex:1 1 260px; min-width:220px; }
.clients-filter-form .form-select{ width:auto; flex:0 0 auto; max-width:200px; }
.clients-filter-form .btn{ flex:0 0 auto; white-space:nowrap; }
.clients-custom-dates{ display:inline-flex; gap:.5rem; flex:0 0 auto; }
.clients-custom-dates[hidden]{ display:none; }
.clients-custom-dates .form-control{ width:150px; }
@media (max-width: 991px){
    .clients-filter-form{ flex-wrap:wrap; }
    .clients-filter-form .clients-filter-search{ flex-basis:100%; }
}
</style>

<?php require "footer.php"; ?>