<?php
$phead = "STAFF MEMBERS";

// The PDF export runs before nav-head.php (which prints the page), so load
// the session, database and officer scope here, and check access the same way.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/officer_assignment.php';
if (empty($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), [1, 2, 6], true)) {
    header('Location: ../login.php');
    exit;
}
$exportPdf = ($_GET['export'] ?? '') === 'pdf';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$limit  = $exportPdf ? 1000000 : 10;
$page   = $exportPdf ? 1 : max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$search = trim($_GET['q'] ?? '');
$employer = trim($_GET['employer'] ?? '');
$employment = trim($_GET['employment'] ?? '');
$status = in_array($_GET['status'] ?? '', ['active', 'blocked'], true) ? $_GET['status'] : '';
$loans = in_array($_GET['loans'] ?? '', ['with', 'outstanding', 'without'], true) ? $_GET['loans'] : '';

// ---- Period (by the date the staff member registered) ----
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

$scope = officer_client_sql('members');

// ---- Dropdown options (only the officer's own staff borrowers for loan officers) ----
$distinctOptions = static function (mysqli $conn, string $column, string $scope): array {
    $options = [];
    $res = $conn->query("
        SELECT MIN(TRIM(members.{$column})) AS name
        FROM members
        WHERE TRIM(COALESCE(members.{$column}, '')) <> ''" . $scope . "
        GROUP BY LOWER(TRIM(members.{$column}))
        ORDER BY name
    ");
    while ($res && $row = $res->fetch_assoc()) {
        $options[] = $row['name'];
    }
    return $options;
};
$employerOptions = $distinctOptions($conn, 'employer', $scope);
$employmentOptions = $distinctOptions($conn, 'employment_status', $scope);

// Loans that count: anything past the application stage.
$loanLive = "UPPER(COALESCE(loan.status, '')) NOT IN ('', 'PENDING', 'REJECTED', 'CANCELLED', 'DELETED')";
$loanOpen = "UPPER(COALESCE(loan.status, '')) NOT IN ('', 'PENDING', 'REJECTED', 'CANCELLED', 'DELETED', 'CLEARED')";

// ---- Filters (shared by the list, the count and the stat cards) ----
$where = "WHERE 1 = 1" . $scope;
$params = [];
$types = "";

if ($search !== "") {
    $where .= "
        AND (
            user.fname LIKE ? OR
            user.lname LIKE ? OR
            CONCAT(user.fname, ' ', user.lname) LIKE ? OR
            user.email LIKE ? OR
            members.phone LIKE ? OR
            members.home_address LIKE ? OR
            members.employer LIKE ? OR
            members.employment_status LIKE ? OR
            members.bank_name LIKE ? OR
            members.bank_account LIKE ?)
    ";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    $types .= "ssssssssss";
}

if ($employer !== "") {
    $where .= " AND LOWER(TRIM(members.employer)) = LOWER(?)";
    $params[] = $employer;
    $types .= "s";
}

if ($employment !== "") {
    $where .= " AND LOWER(TRIM(members.employment_status)) = LOWER(?)";
    $params[] = $employment;
    $types .= "s";
}

if ($status === 'active') {
    $where .= " AND user.status = 1";
} elseif ($status === 'blocked') {
    $where .= " AND COALESCE(user.status, 0) <> 1";
}

if ($loans === 'with') {
    $where .= " AND EXISTS (SELECT 1 FROM loan WHERE loan.client_id = members.user_id AND $loanLive)";
} elseif ($loans === 'outstanding') {
    $where .= " AND EXISTS (SELECT 1 FROM loan WHERE loan.client_id = members.user_id AND $loanOpen AND loan.loan_balance > 0)";
} elseif ($loans === 'without') {
    $where .= " AND NOT EXISTS (SELECT 1 FROM loan WHERE loan.client_id = members.user_id AND $loanLive)";
}

if ($from !== null) {
    $where .= " AND members.created_at >= ?";
    $params[] = $from . ' 00:00:00';
    $types .= "s";
}

if ($to !== null) {
    $where .= " AND members.created_at <= ?";
    $params[] = $to . ' 23:59:59';
    $types .= "s";
}

$filtered = $search !== '' || $employer !== '' || $employment !== '' || $status !== '' || $loans !== '' || $period !== 'all';

// Query string that keeps the filters across pages.
$filterQuery = http_build_query(array_filter([
    'q'          => $search,
    'period'     => $period !== 'all' ? $period : '',
    'from'       => $period === 'custom' ? ($from ?? '') : '',
    'to'         => $period === 'custom' ? ($to ?? '') : '',
    'employer'   => $employer,
    'employment' => $employment,
    'status'     => $status,
    'loans'      => $loans,
], static fn($v) => $v !== ''));
$query = $filterQuery !== '' ? '&' . $filterQuery : '';

$runStmt = static function (mysqli $conn, string $sql, string $types, array $params) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
};

$totalRows = (int)$runStmt($conn, "
    SELECT COUNT(*) AS total
    FROM members
    INNER JOIN user ON members.user_id = user.id
    $where
", $types, $params)->fetch_assoc()['total'];
$totalPages = max(1, (int)ceil($totalRows / $limit));

$staff = $runStmt($conn, "
    SELECT
        user.id AS user_table_id,
        user.fname,
        user.lname,
        user.email,
        user.status,
        user.image_url AS profile_photo,
        members.*
    FROM members
    INNER JOIN user ON members.user_id = user.id
    $where
    ORDER BY members.id DESC
    LIMIT ? OFFSET ?
", $types . "ii", array_merge($params, [$limit, $offset]))->fetch_all(MYSQLI_ASSOC);

$stats = $runStmt($conn, "
    SELECT
        COUNT(*) AS total_staff,
        SUM(CASE WHEN user.status = 1 THEN 1 ELSE 0 END) AS active_staff,
        SUM(CASE WHEN l.live_loans > 0 THEN 1 ELSE 0 END) AS staff_with_loans,
        COALESCE(SUM(l.balance), 0) AS total_outstanding
    FROM members
    INNER JOIN user ON members.user_id = user.id
    LEFT JOIN (
        SELECT loan.client_id,
               SUM(CASE WHEN $loanLive THEN 1 ELSE 0 END) AS live_loans,
               COALESCE(SUM(CASE WHEN $loanOpen THEN loan.loan_balance ELSE 0 END), 0) AS balance
        FROM loan
        GROUP BY loan.client_id
    ) l ON l.client_id = members.user_id
    $where
", $types, $params)->fetch_assoc();

$totalStaff       = (int)($stats['total_staff'] ?? 0);
$activeStaff      = (int)($stats['active_staff'] ?? 0);
$staffWithLoans   = (int)($stats['staff_with_loans'] ?? 0);
$totalOutstanding = (float)($stats['total_outstanding'] ?? 0);

/* ============================================================
   PDF EXPORT (every staff member matching the filters)
============================================================ */
if ($exportPdf) {
    require_once __DIR__ . '/vendor/autoload.php';
    date_default_timezone_set('Africa/Kampala');

    // What the list was filtered by, for the heading.
    $filterNotes = [];
    if ($search !== '') $filterNotes[] = 'Search: "' . $search . '"';
    if ($period !== 'all') {
        $filterNotes[] = 'Registered: ' . ($period === 'custom'
            ? ($from ?? 'start') . ' to ' . ($to ?? 'today')
            : $periods[$period]);
    }
    if ($employer !== '') $filterNotes[] = 'Employer: ' . $employer;
    if ($employment !== '') $filterNotes[] = 'Employment: ' . $employment;
    if ($status !== '') $filterNotes[] = 'Status: ' . ucfirst($status);
    if ($loans !== '') {
        $filterNotes[] = 'Loans: ' . ['with' => 'Have loans', 'outstanding' => 'Balance outstanding', 'without' => 'No loans'][$loans];
    }

    ob_start();
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 16mm 10mm; }
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8.5px; color: #1f2937; }
            h1 { color: #123299; margin: 0 0 6px; font-size: 17px; }
            .meta { color: #64748b; margin-bottom: 8px; }
            table { width: 100%; border-collapse: collapse; }
            .summary td { border: 1px solid #e5e7eb; background: #f8fafc; padding: 7px; width: 25%; }
            .label { color: #64748b; font-size: 7px; text-transform: uppercase; }
            .value { font-size: 11px; font-weight: bold; color: #111827; }
            .rpt th { background: #123299; color: #fff; padding: 5px 4px; text-align: left; font-size: 7.5px; }
            .rpt td { border-bottom: 1px solid #e5e7eb; padding: 4px; vertical-align: top; }
            .rpt tr:nth-child(even) td { background: #f8fafc; }
            .num { text-align: right; }
        </style>
    </head>
    <body>
    <h1>Staff Members</h1>
    <div class="meta">
        Generated: <?= e(date('F d, Y H:i')) ?> &middot; <?= number_format($totalStaff) ?> staff member(s)
        <?php if ($filterNotes): ?><br>Filtered by: <?= e(implode(' | ', $filterNotes)) ?><?php endif; ?>
    </div>

    <table class="summary">
        <tr>
            <td><div class="label"><?= $filtered ? 'Matching Staff' : 'Total Staff' ?></div><div class="value"><?= number_format($totalStaff) ?></div></td>
            <td><div class="label">Active</div><div class="value"><?= number_format($activeStaff) ?><?= $totalStaff > $activeStaff ? ' (' . number_format($totalStaff - $activeStaff) . ' blocked)' : '' ?></div></td>
            <td><div class="label">Have Loans</div><div class="value"><?= number_format($staffWithLoans) ?></div></td>
            <td><div class="label">Total Outstanding</div><div class="value">UGX <?= number_format($totalOutstanding) ?></div></td>
        </tr>
    </table>
    <br>

    <table class="rpt">
        <thead>
        <tr>
            <th>#</th><th>Staff Name</th><th>Phone</th><th>Email</th><th>Home Address</th><th>Employer</th>
            <th>Employment</th><th>Bank</th><th>Bank Acc</th><th class="num">Loan Limit</th><th>Status</th><th>Registered</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($staff): ?>
            <?php foreach ($staff as $i => $row): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e(trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? '')) ?: 'Unnamed Staff') ?></td>
                    <td><?= e($row['phone'] ?? '') ?></td>
                    <td><?= e($row['email'] ?? '') ?></td>
                    <td><?= e($row['home_address'] ?? '') ?></td>
                    <td><?= e($row['employer'] ?? '') ?></td>
                    <td><?= e($row['employment_status'] ?? '') ?></td>
                    <td><?= e($row['bank_name'] ?? '') ?></td>
                    <td><?= e($row['bank_account'] ?? '') ?></td>
                    <td class="num"><?= number_format((float)($row['loan_limit'] ?? 0)) ?></td>
                    <td><?= (int)$row['status'] === 1 ? 'Active' : 'Blocked' ?></td>
                    <td><?= !empty($row['created_at']) ? e(date('d/m/Y', strtotime((string)$row['created_at']))) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="12" style="text-align:center;padding:15px">No staff members found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    require_once dirname(__DIR__) . '/includes/pdf_brand.php';
    $dompdf->loadHtml(pdf_brand_html($conn, $html));
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    pdf_brand_footer($dompdf, $conn);
    $dompdf->stream('Staff_Members_' . date('Ymd_His') . '.pdf', ['Attachment' => false]);
    exit;
}

require "nav-head.php";
?>

<div class="page-header">
    <div>
        <div class="title"><?= e($phead) ?></div>
        <div class="sub"><?= number_format($totalRows) ?> <?= $filtered ? 'matching' : 'registered' ?> staff members</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-ghost btn-sm" onclick="download_table_as_csv('report','staff_members.csv')">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>

        <a href="?export=pdf<?= e($query) ?>" target="_blank" rel="noopener" class="btn btn-danger btn-sm" title="All staff matching the filters">
            <i class="fas fa-file-pdf"></i> Export PDF
        </a>
    </div>
</div>

<div class="stats-grid clients-stats-grid">
    <div class="stat-card c-blue">
        <div class="stat-head"><div class="stat-ico c-blue"><i class="fas fa-id-badge"></i></div></div>
        <div class="stat-value sm"><?= number_format($totalStaff) ?></div>
        <div class="stat-label"><?= $filtered ? 'Matching Staff' : 'Total Staff' ?></div>
    </div>
    <div class="stat-card c-green">
        <div class="stat-head"><div class="stat-ico c-green"><i class="fas fa-user-check"></i></div></div>
        <div class="stat-value sm"><?= number_format($activeStaff) ?></div>
        <div class="stat-label">Active<?= $totalStaff > $activeStaff ? ' (' . number_format($totalStaff - $activeStaff) . ' blocked)' : '' ?></div>
    </div>
    <div class="stat-card c-indigo">
        <div class="stat-head"><div class="stat-ico c-indigo"><i class="fas fa-file-invoice-dollar"></i></div></div>
        <div class="stat-value sm"><?= number_format($staffWithLoans) ?></div>
        <div class="stat-label">Have Loans</div>
    </div>
    <div class="stat-card c-orange">
        <div class="stat-head"><div class="stat-ico c-orange"><i class="fas fa-balance-scale"></i></div></div>
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
                    placeholder="Search staff name, phone, email, employer, bank..."
                    aria-label="Search"
                >
            </div>

            <select name="period" class="form-select" aria-label="Registration period"
                    onchange="this.form.querySelector('.clients-custom-dates').hidden = this.value !== 'custom'; if (this.value !== 'custom') this.form.submit();">
                <?php foreach ($periods as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $period === $key ? ' selected' : '' ?>><?= e($key === 'all' ? 'Period: All time' : $label) ?></option>
                <?php endforeach; ?>
            </select>

            <span class="clients-custom-dates"<?= $period === 'custom' ? '' : ' hidden' ?>>
                <input type="date" name="from" class="form-control" value="<?= e($from ?? '') ?>" aria-label="From">
                <input type="date" name="to" class="form-control" value="<?= e($to ?? '') ?>" aria-label="To">
            </span>

            <select name="employer" class="form-select" aria-label="Employer" onchange="this.form.submit()">
                <option value="">All employers</option>
                <?php foreach ($employerOptions as $opt): ?>
                    <option value="<?= e($opt) ?>"<?= strcasecmp($opt, $employer) === 0 ? ' selected' : '' ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="employment" class="form-select" aria-label="Employment" onchange="this.form.submit()">
                <option value="">All employment</option>
                <?php foreach ($employmentOptions as $opt): ?>
                    <option value="<?= e($opt) ?>"<?= strcasecmp($opt, $employment) === 0 ? ' selected' : '' ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="form-select" aria-label="Status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
                <option value="blocked"<?= $status === 'blocked' ? ' selected' : '' ?>>Blocked</option>
            </select>

            <select name="loans" class="form-select" aria-label="Loans" onchange="this.form.submit()">
                <option value="">All loans</option>
                <option value="with"<?= $loans === 'with' ? ' selected' : '' ?>>Have loans</option>
                <option value="outstanding"<?= $loans === 'outstanding' ? ' selected' : '' ?>>Balance outstanding</option>
                <option value="without"<?= $loans === 'without' ? ' selected' : '' ?>>No loans</option>
            </select>

            <button class="btn btn-primary" type="submit"><i class="fas fa-filter"></i> Filter</button>

            <?php if ($filtered): ?>
                <a href="<?= e(basename($_SERVER['PHP_SELF'])) ?>" class="btn btn-light">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card-body no-pad">
        <div class="table-responsive client-table-scroll">
            <table class="table data-table clients-table" id="report">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Staff Name</th>
                        <th>Home Address</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Employer</th>
                        <th>Employment</th>
                        <th>Bank Name</th>
                        <th>Bank Acc</th>
                        <th>Loan Limit</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!empty($staff)): ?>
                        <?php $counter = $offset + 1; foreach ($staff as $row): ?>
                            <?php
                            $userId = (int)$row['user_id'];
                            $fullName = trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
                            $initials = strtoupper(substr($row['fname'] ?? '', 0, 1) . substr($row['lname'] ?? '', 0, 1));
                            ?>
                            <tr id="staff-row-<?= $userId ?>">
                                <td><?= $counter++ ?></td>

                                <td>
                                    <div class="cell-user">
                                        <?= profile_avatar($row['profile_photo'] ?? '', $initials ?: 'ST', 'cell-av') ?>

                                        <div>
                                            <div class="cell-name"><?= e($fullName ?: 'Unnamed Staff') ?></div>
                                            <div class="cell-sub">ID: <?= e($userId) ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="text-muted fs-sm text-truncate-cell">
                                    <?= e($row['home_address'] ?? 'N/A') ?>
                                </td>

                                <td class="text-muted fs-sm">
                                    <?= e($row['phone'] ?? 'N/A') ?>
                                </td>

                                <td class="text-muted fs-sm">
                                    <?= e($row['email'] ?? 'N/A') ?>
                                </td>

                                <td class="td-dark text-truncate-cell">
                                    <?= e($row['employer'] ?? 'N/A') ?>
                                </td>

                                <td>
                                    <span class="badge badge-muted">
                                        <?= e($row['employment_status'] ?? 'N/A') ?>
                                    </span>
                                </td>

                                <td class="td-dark text-truncate-cell">
                                    <?= e($row['bank_name'] ?? 'N/A') ?>
                                </td>

                                <td class="text-muted fs-sm">
                                    <?= e($row['bank_account'] ?? 'N/A') ?>
                                </td>

                                <td class="td-dark">
                                    UGX <?= number_format((float)($row['loan_limit'] ?? 0)) ?>
                                </td>

                                <td>
                                    <?php if ((int)$row['status'] === 1): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Blocked</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-info btn-icon-sm" onclick="viewStaffModal(<?= $userId ?>)" title="View Staff">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <button type="button" class="btn btn-primary btn-icon-sm" onclick="staffLoanHistoryModal(<?= $userId ?>)" title="Loan History">
                                            <i class="fas fa-file-invoice-dollar"></i>
                                        </button>

                                        <button type="button" class="btn btn-warning btn-icon-sm" onclick="editStaffModal(<?= $userId ?>)" title="Edit Staff">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <button type="button" class="btn btn-danger btn-icon-sm" onclick="deleteStaffModal(<?= $userId ?>)" title="Delete Staff">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12">
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <h3>No staff members found</h3>
                                    <p>No matching staff records were found.</p>
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
                        <a class="page-link" href="?page=<?= max(1, $page - 1) . $query ?>">Previous</a>
                    </li>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    ?>

                    <?php for ($p = $start; $p <= $end; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p . $query ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= min($totalPages, $page + 1) . $query ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<script>
function ensureStaffPopup() {
    if (document.getElementById('staff-custom-popup')) {
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.id = 'staff-custom-popup';
    wrapper.innerHTML = `
        <div class="staff-popup-backdrop" data-popup-close="1"></div>
        <div class="staff-popup-dialog" role="dialog" aria-modal="true" aria-labelledby="staff-popup-title">
            <div class="staff-popup-icon" id="staff-popup-icon">
                <i class="fas fa-check"></i>
            </div>

            <div class="staff-popup-content">
                <h3 id="staff-popup-title">Message</h3>
                <p id="staff-popup-message"></p>
            </div>

            <div class="staff-popup-actions" id="staff-popup-actions">
                <button type="button" class="btn btn-primary" id="staff-popup-ok">
                    OK
                </button>
            </div>
        </div>
    `;

    document.body.appendChild(wrapper);

    if (!document.getElementById('staff-popup-styles')) {
        const style = document.createElement('style');
        style.id = 'staff-popup-styles';
        style.textContent = `
            #staff-custom-popup {
                position: fixed;
                inset: 0;
                z-index: 99999;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            #staff-custom-popup.is-open {
                display: flex;
            }

            .staff-popup-backdrop {
                position: absolute;
                inset: 0;
                background: rgba(15, 23, 42, .58);
                backdrop-filter: blur(3px);
            }

            .staff-popup-dialog {
                position: relative;
                width: min(430px, 100%);
                background: #fff;
                border-radius: 18px;
                box-shadow: 0 24px 70px rgba(15, 23, 42, .25);
                padding: 28px;
                text-align: center;
                animation: staffPopupIn .18s ease-out;
            }

            .staff-popup-icon {
                width: 62px;
                height: 62px;
                margin: 0 auto 16px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 26px;
                background: #dcfce7;
                color: #15803d;
            }

            .staff-popup-icon.error {
                background: #fee2e2;
                color: #b91c1c;
            }

            .staff-popup-icon.warning {
                background: #fef3c7;
                color: #b45309;
            }

            .staff-popup-content h3 {
                margin: 0 0 8px;
                font-size: 21px;
                font-weight: 700;
                color: #0f172a;
            }

            .staff-popup-content p {
                margin: 0;
                color: #64748b;
                line-height: 1.6;
            }

            .staff-popup-actions {
                margin-top: 22px;
                display: flex;
                justify-content: center;
                gap: 10px;
                flex-wrap: wrap;
            }

            .staff-popup-actions .btn {
                min-width: 110px;
            }

            @keyframes staffPopupIn {
                from {
                    opacity: 0;
                    transform: translateY(8px) scale(.98);
                }
                to {
                    opacity: 1;
                    transform: translateY(0) scale(1);
                }
            }
        `;

        document.head.appendChild(style);
    }
}

function closeStaffPopup() {
    const popup = document.getElementById('staff-custom-popup');
    if (popup) {
        popup.classList.remove('is-open');
    }
}

function staffPopup(type, title, message, options = {}) {
    ensureStaffPopup();

    return new Promise(resolve => {
        const popup = document.getElementById('staff-custom-popup');
        const iconBox = document.getElementById('staff-popup-icon');
        const icon = iconBox.querySelector('i');
        const titleEl = document.getElementById('staff-popup-title');
        const messageEl = document.getElementById('staff-popup-message');
        const actions = document.getElementById('staff-popup-actions');

        iconBox.className = 'staff-popup-icon';
        icon.className = 'fas fa-check';

        if (type === 'error') {
            iconBox.classList.add('error');
            icon.className = 'fas fa-times';
        } else if (type === 'warning' || type === 'confirm') {
            iconBox.classList.add('warning');
            icon.className = 'fas fa-exclamation';
        }

        titleEl.textContent = title || 'Message';
        messageEl.textContent = message || '';

        const finish = value => {
            closeStaffPopup();
            resolve(value);
        };

        if (type === 'confirm') {
            actions.innerHTML = `
                <button type="button" class="btn btn-light" id="staff-popup-cancel">
                    Cancel
                </button>
                <button type="button" class="btn btn-danger" id="staff-popup-confirm">
                    ${options.confirmText || 'Continue'}
                </button>
            `;

            document.getElementById('staff-popup-cancel').onclick = () => finish(false);
            document.getElementById('staff-popup-confirm').onclick = () => finish(true);
        } else {
            actions.innerHTML = `
                <button type="button" class="btn btn-primary" id="staff-popup-ok">
                    ${options.okText || 'OK'}
                </button>
            `;

            document.getElementById('staff-popup-ok').onclick = () => finish(true);
        }

        popup.classList.add('is-open');
    });
}


async function staffPost(url, payload) {
    let options = {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    };

    if (payload instanceof FormData) {
        options.body = payload;
    } else {
        const formData = new FormData();

        Object.entries(payload || {}).forEach(([key, value]) => {
            formData.append(key, value ?? '');
        });

        options.body = formData;
    }

    const response = await fetch(url, options);
    const responseText = (await response.text()).trim();

    let data = null;

    try {
        data = JSON.parse(responseText);
    } catch (parseError) {
        const firstBrace = responseText.indexOf('{');
        const lastBrace = responseText.lastIndexOf('}');

        if (firstBrace !== -1 && lastBrace > firstBrace) {
            const possibleJson = responseText.slice(firstBrace, lastBrace + 1);

            try {
                data = JSON.parse(possibleJson);
                console.warn(
                    'The server returned extra output around its JSON response.',
                    responseText
                );
            } catch (ignored) {
                data = null;
            }
        }

        if (!data) {
            console.error('Non-JSON response from ' + url, responseText);

            if (response.status === 401 || response.status === 403) {
                throw new Error(
                    'Your session may have expired. Refresh the page and sign in again.'
                );
            }

            if (response.status >= 500) {
                throw new Error(
                    'The server encountered an error while saving. Please check the PHP error log.'
                );
            }

            if (
                /<!doctype/i.test(responseText) ||
                /<html/i.test(responseText) ||
                /<body/i.test(responseText)
            ) {
                throw new Error(
                    'The save request was redirected to an HTML page instead of the AJAX endpoint.'
                );
            }

            throw new Error(
                'The server did not return a valid save response. Please check the PHP error log.'
            );
        }
    }

    if (!data || typeof data !== 'object') {
        throw new Error('The server returned an invalid response.');
    }

    if (!response.ok || data.success === false) {
        throw new Error(data.message || 'The server could not complete the request.');
    }

    return data;
}

function staffFriendlyError(error, fallback = 'The request could not be completed.') {
    const message = String(error && error.message ? error.message : '').trim();

    if (!message) {
        return fallback;
    }

    // Never place a returned HTML document/source code in a popup.
    if (
        /<!doctype/i.test(message) ||
        /<html/i.test(message) ||
        /<body/i.test(message) ||
        /<div/i.test(message) ||
        /<a\s/i.test(message) ||
        /class\s*=/i.test(message) ||
        message.length > 350
    ) {
        return fallback;
    }

    return message;
}

async function staffAjaxGet(url, modalId, title, icon = 'user', size = 'lg') {
    Modal.open(modalId, { title, icon, size });
    Modal.loading(modalId);

    try {
        const html = await Ajax.get(url);
        Modal.setBody(modalId, html);
        bindStaffForms(modalId);
    } catch (error) {
        console.error(error);

        Modal.setBody(modalId, '');

        await staffPopup(
            'error',
            'Unable to Load',
            staffFriendlyError(
                error,
                'The requested staff information could not be loaded. Please refresh the page and try again.'
            )
        );
    }
}

function viewStaffModal(userId) {
    staffAjaxGet(
        'ajax/process-staff.php?action=view&user_id=' + encodeURIComponent(userId),
        'm-view-staff',
        'Staff Profile',
        'user'
    );
}

function editStaffModal(userId) {
    staffAjaxGet(
        'ajax/process-staff.php?action=edit&user_id=' + encodeURIComponent(userId),
        'm-edit-staff',
        'Edit Staff',
        'user-edit'
    );
}

function staffLoanHistoryModal(userId) {
    staffAjaxGet(
        'ajax/process-staff.php?action=loans&user_id=' + encodeURIComponent(userId),
        'm-staff-loans',
        'Staff Loan History',
        'file-invoice-dollar'
    );
}

async function deleteStaffModal(userId) {
    const confirmed = await staffPopup(
        'confirm',
        'Delete Staff Member?',
        'This action will permanently remove this staff member. Staff with active loans cannot be deleted.',
        { confirmText: 'Delete Staff' }
    );

    if (!confirmed) {
        return;
    }

    try {
        const response = await staffPost('ajax/process-staff.php', {
            action: 'delete',
            user_id: userId
        });

        if (!response.success) {
            throw new Error(response.message || 'Delete failed.');
        }

        const row = document.getElementById('staff-row-' + userId);

        if (row) {
            row.remove();
        }

        await staffPopup(
            'success',
            'Staff Deleted',
            response.message || 'Staff member deleted successfully.'
        );
    } catch (error) {
        console.error(error);

        await staffPopup(
            'error',
            'Unable to Delete Staff',
            staffFriendlyError(
                error,
                'The staff member could not be deleted. Please try again.'
            )
        );
    }
}

function bindStaffForms(modalId) {
    const body = document.getElementById(modalId + '-body');
    if (!body) return;

    const passwordButtons = body.querySelectorAll('[data-password-toggle]');

    passwordButtons.forEach(button => {
        if (button.dataset.toggleBound === '1') return;

        button.dataset.toggleBound = '1';

        button.addEventListener('click', function() {
            const inputId = button.getAttribute('data-password-toggle');
            const input = document.getElementById(inputId);

            if (!input) return;

            const icon = button.querySelector('i');
            const showing = input.type === 'text';

            input.type = showing ? 'password' : 'text';

            if (icon) {
                icon.classList.toggle('fa-eye', showing);
                icon.classList.toggle('fa-eye-slash', !showing);
            }

            button.setAttribute(
                'title',
                showing ? 'Show password' : 'Hide password'
            );

            button.setAttribute(
                'aria-label',
                showing ? 'Show password' : 'Hide password'
            );
        });
    });

    const forms = body.querySelectorAll('form[data-ajax="true"], form.ajax-form');

    forms.forEach(form => {
        if (form.dataset.bound === '1') return;

        form.dataset.bound = '1';

        form.addEventListener('submit', async function(event) {
            event.preventDefault();

            const btn = form.querySelector('[type="submit"]');
            const submitUrl = form.getAttribute('action') || 'ajax/process-staff.php';
            const oldText = btn ? btn.innerHTML : '';

            try {
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
                }

                const data = await staffPost(submitUrl, new FormData(form));

                if (!data.success) {
                    throw new Error(data.message || 'Save failed.');
                }

                await staffPopup(
                    'success',
                    'Changes Saved',
                    data.message || 'Staff details saved successfully.'
                );

                window.location.reload();

            } catch (error) {
                console.error(error);

                await staffPopup(
                    'error',
                    'Unable to Save Changes',
                    staffFriendlyError(
                        error,
                        'The changes could not be saved. Please refresh the page and try again.'
                    )
                );
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldText;
                }
            }
        });
    });
}
</script>

<?php require "footer.php"; ?>