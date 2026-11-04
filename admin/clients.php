<?php
$phead = 'Clients';
require "nav-head.php";
require "services_check.php";

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$limit  = 10;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$search = trim($_GET['q'] ?? '');

$where  = "";
$params = [];
$types  = "";

if ($search !== "") {
    $where = "WHERE 
        u.fname LIKE ? OR
        u.lname LIKE ? OR
        u.email LIKE ? OR
        c.phone LIKE ? OR
        c.business_name LIKE ? OR
        c.business_type LIKE ? OR
        c.district LIKE ? OR
        c.location LIKE ?";

    $like = "%{$search}%";

    $params = [
        $like, $like, $like, $like,
        $like, $like, $like, $like
    ];

    $types = "ssssssss";
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    $where
";

$countStmt = $conn->prepare($countSql);

if (!empty($params)) {
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
";

$stats = $conn->query($statsSql)->fetch_assoc();

$totalClients     = (int)($stats['total_clients'] ?? 0);
$activeClients    = (int)($stats['active_clients'] ?? 0);
$clientsWithLoans = (int)($stats['clients_with_loans'] ?? 0);
$totalOutstanding = (float)($stats['total_outstanding'] ?? 0);
?>

<div class="page-header">
    <div>
        <div class="title">Business Clients</div>
        <div class="sub"><?= number_format($totalRows) ?> registered clients</div>
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
        <form method="GET" class="clients-search-form">
            <div class="input-group">
                <span class="input-group-text">
                    <i class="fas fa-search"></i>
                </span>

                <input 
                    type="text" 
                    name="q" 
                    class="form-control"
                    value="<?= e($search) ?>"
                    placeholder="Search client, phone, email, business..."
                >

                <button class="btn btn-primary" type="submit">
                    Search
                </button>

                <?php if ($search !== ""): ?>
                    <a href="clients.php" class="btn btn-light">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
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
                                        <div class="cell-av">
                                            <?= e($initials ?: 'CL') ?>
                                        </div>

                                        <div>
                                            <div class="cell-name"><?= e($fullName ?: 'Unnamed Client') ?></div>
                                            <div class="cell-sub"><?= e($c['email'] ?? '—') ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="text-muted fs-sm">
                                    <?= e($c['phone'] ?: '—') ?>
                                </td>

                                <td class="td-dark text-truncate-cell">
                                    <?= e($c['business_name'] ?: '—') ?>
                                </td>

                                <td>
                                    <span class="badge badge-muted">
                                        <?= e($c['business_type'] ?: '—') ?>
                                    </span>
                                </td>

                                <td class="text-muted fs-sm">
                                    <?= e($c['district'] ?: ($c['location'] ?: '—')) ?>
                                </td>

                                <td class="td-dark">
                                    UGX <?= number_format((float)($c['loan_limit'] ?? 0)) ?>
                                </td>

                                <td>
                                    <a 
                                        href="client_loan_details.php?client_id=<?= $clientId ?>"
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
                    <?php $query = $search !== "" ? '&q=' . urlencode($search) : ''; ?>

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

<?php require "footer.php"; ?>