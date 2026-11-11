<?php
declare(strict_types=1);

$phead = 'Pending Loans';
require "nav-head.php";
require "services_check.php";
require_once __DIR__ . "/loan_audit.php";
require_once __DIR__ . "/../includes/loan_workflow.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !$conn instanceof mysqli) {
    die("Database connection not established.");
}


$currentRole   = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$isReviewer    = ($currentRole === 1);
$isOfficer     = ($currentRole === 2);

$roleLabel = match ($currentRole) {
    1 => 'Reviewer',
    2 => 'Loan Officer',
    default => 'Viewer (read-only)',
};

// Older pending requests may not have an officer yet. Backfill them so every
// loan in this queue is owned by a loan officer.
if ($isReviewer) {
    loan_backfill_pending_assignments($conn);
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return 'UGX ' . number_format((float)($value ?? 0));
}

function typeBadge(string $type): string
{
    return match ($type) {
        'customer' => 'badge-primary',
        'member'   => 'badge-purple',
        'startup'  => 'badge-warning',
        'unknown'  => 'badge-secondary',
        default    => 'badge-muted',
    };
}

function statusBadge(string $status): string
{
    return match ($status) {
        'PENDING'        => 'badge-warning',
        'INFO_REQUESTED' => 'badge-info',
        'INFO_PROVIDED'  => 'badge-purple',
        default          => 'badge-secondary',
    };
}

function statusLabel(string $status): string
{
    return match ($status) {
        'PENDING'        => 'Pending',
        'INFO_REQUESTED' => 'Awaiting Officer',
        'INFO_PROVIDED'  => 'Ready for Review',
        default          => ucfirst(strtolower($status)),
    };
}

$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? 'all');

$allowedTypes = ['all', 'customer', 'member', 'startup', 'unknown'];
if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = 'all';
}

// Pagination
$perPageOptions = [10, 20, 50, 100];
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 10;
}

$page = max(1, (int)($_GET['page'] ?? 1));

$reviewableStatuses = ['PENDING', 'INFO_PROVIDED'];
if (empty($_SESSION['loan_review_csrf'])) {
    $_SESSION['loan_review_csrf'] = bin2hex(random_bytes(32));
}
$reviewCsrf = $_SESSION['loan_review_csrf'];

/*
|--------------------------------------------------------------------------
| Resolve the loan owner safely
|--------------------------------------------------------------------------
|
| client_id values can overlap between customers, members and startups.
| Joining all three tables using client_id alone can therefore display the
| wrong client. The preferred fix is a loan.client_type column.
|
| When that column exists, every join is restricted by client_type.
| For older databases without the column, the fallback resolver only accepts
| IDs that appear in exactly one client table. Ambiguous IDs are shown as
| "unknown" rather than exposing another client's information.
|
*/
$hasClientTypeColumn = false;
$columnCheck = $conn->query("SHOW COLUMNS FROM loan LIKE 'client_type'");

if ($columnCheck instanceof mysqli_result) {
    $hasClientTypeColumn = $columnCheck->num_rows > 0;
    $columnCheck->free();
}

$clientResolverJoin = '';

if ($hasClientTypeColumn) {
    $clientTypeExpr = "
        CASE
            WHEN LOWER(TRIM(COALESCE(l.client_type, ''))) = 'customer' THEN 'customer'
            WHEN LOWER(TRIM(COALESCE(l.client_type, ''))) = 'member' THEN 'member'
            WHEN LOWER(TRIM(COALESCE(l.client_type, ''))) = 'startup' THEN 'startup'
            ELSE 'unknown'
        END
    ";
} else {
    $clientResolverJoin = "
        LEFT JOIN (
            SELECT
                client_links.client_id,
                CASE
                    WHEN COUNT(*) = 1 THEN MAX(client_links.client_type)
                    ELSE 'unknown'
                END AS client_type
            FROM (
                SELECT user_id AS client_id, 'customer' AS client_type
                FROM customers
                WHERE user_id IS NOT NULL

                UNION ALL

                SELECT user_id AS client_id, 'member' AS client_type
                FROM members
                WHERE user_id IS NOT NULL

                UNION ALL

                SELECT id AS client_id, 'startup' AS client_type
                FROM startups
                WHERE id IS NOT NULL
            ) AS client_links
            GROUP BY client_links.client_id
        ) AS client_map
            ON client_map.client_id = l.client_id
    ";

    $clientTypeExpr = "COALESCE(client_map.client_type, 'unknown')";
}

$customerJoinType = $hasClientTypeColumn
    ? "LOWER(TRIM(COALESCE(l.client_type, ''))) = 'customer'"
    : "client_map.client_type = 'customer'";

$memberJoinType = $hasClientTypeColumn
    ? "LOWER(TRIM(COALESCE(l.client_type, ''))) = 'member'"
    : "client_map.client_type = 'member'";

$startupJoinType = $hasClientTypeColumn
    ? "LOWER(TRIM(COALESCE(l.client_type, ''))) = 'startup'"
    : "client_map.client_type = 'startup'";

$baseFrom = "
    FROM loan l

    {$clientResolverJoin}

    LEFT JOIN customers c
        ON {$customerJoinType}
       AND l.client_id = c.user_id

    LEFT JOIN user uc
        ON c.user_id = uc.id

    LEFT JOIN members m
        ON {$memberJoinType}
       AND l.client_id = m.user_id

    LEFT JOIN user um
        ON m.user_id = um.id

    LEFT JOIN startups st
        ON {$startupJoinType}
       AND l.client_id = st.id

    LEFT JOIN user officer
        ON l.assigned_officer_id = officer.id
";

$whereSql = "
    WHERE UPPER(TRIM(COALESCE(l.status, ''))) IN ('PENDING', 'INFO_PROVIDED')
";

$bindParams = [];
$bindTypes = '';

if ($typeFilter !== 'all') {
    $whereSql .= " AND ({$clientTypeExpr}) = ? ";
    $bindParams[] = $typeFilter;
    $bindTypes .= 's';
}

if ($search !== '') {
    $like = "%{$search}%";

    $whereSql .= "
        AND (
            COALESCE(uc.fname, um.fname, st.ceo_name, c.business_name, '') LIKE ?
            OR COALESCE(uc.lname, um.lname, '') LIKE ?
            OR CONCAT(COALESCE(uc.fname, um.fname, st.ceo_name, c.business_name, ''), ' ', COALESCE(uc.lname, um.lname, '')) LIKE ?
            OR COALESCE(c.business_name, m.employer, st.company_name, '') LIKE ?
            OR COALESCE(c.email, uc.email, m.work_email, um.email, st.email, '') LIKE ?
            OR COALESCE(c.phone, m.phone, st.phone, '') LIKE ?
            OR COALESCE(c.nin, m.nin, '') LIKE ?
            OR COALESCE(c.location, m.department, st.company_location, '') LIKE ?
            OR COALESCE(c.district, m.home_address, st.status, '') LIKE ?
            OR CAST(l.id AS CHAR) LIKE ?
            OR CAST(l.client_id AS CHAR) LIKE ?
            OR CAST(l.amount AS CHAR) LIKE ?
            OR CAST(l.interest AS CHAR) LIKE ?
            OR CAST(l.loan_balance AS CHAR) LIKE ?
            OR CAST(l.datetime AS CHAR) LIKE ?
            OR COALESCE(l.status, '') LIKE ?
        )
    ";

    for ($i = 0; $i < 16; $i++) {
        $bindParams[] = $like;
        $bindTypes .= 's';
    }
}

// Full filtered totals for statistics and pagination.
$summarySql = "
    SELECT
        COUNT(*) AS total_count,
        COALESCE(SUM(l.amount), 0) AS total_amount,
        COALESCE(SUM(l.interest), 0) AS total_interest,
        COALESCE(SUM(l.loan_balance), 0) AS total_balance,
        COALESCE(SUM(CASE WHEN ({$clientTypeExpr}) = 'customer' THEN 1 ELSE 0 END), 0) AS customer_count,
        COALESCE(SUM(CASE WHEN ({$clientTypeExpr}) = 'customer' THEN l.amount ELSE 0 END), 0) AS customer_amount,
        COALESCE(SUM(CASE WHEN ({$clientTypeExpr}) = 'member' THEN 1 ELSE 0 END), 0) AS member_count,
        COALESCE(SUM(CASE WHEN ({$clientTypeExpr}) = 'member' THEN l.amount ELSE 0 END), 0) AS member_amount,
        COALESCE(SUM(CASE WHEN ({$clientTypeExpr}) = 'startup' THEN 1 ELSE 0 END), 0) AS startup_count,
        COALESCE(SUM(CASE WHEN ({$clientTypeExpr}) = 'startup' THEN l.amount ELSE 0 END), 0) AS startup_amount,
        COALESCE(SUM(CASE WHEN ({$clientTypeExpr}) = 'unknown' THEN 1 ELSE 0 END), 0) AS unknown_count,
        COALESCE(SUM(CASE WHEN ({$clientTypeExpr}) = 'unknown' THEN l.amount ELSE 0 END), 0) AS unknown_amount
    {$baseFrom}
    {$whereSql}
";

$summaryStmt = $conn->prepare($summarySql);
if (!$summaryStmt) {
    die("Loan summary query failed: " . h($conn->error));
}
if (!empty($bindParams)) {
    $summaryStmt->bind_param($bindTypes, ...$bindParams);
}
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$totalRows = (int)($summary['total_count'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
        l.*,
        {$clientTypeExpr} AS resolved_client_type,

        COALESCE(
            NULLIF(uc.fname, ''),
            NULLIF(um.fname, ''),
            NULLIF(st.ceo_name, ''),
            NULLIF(c.business_name, ''),
            ''
        ) AS fname,

        COALESCE(
            NULLIF(uc.lname, ''),
            NULLIF(um.lname, ''),
            ''
        ) AS lname,

        COALESCE(
            NULLIF(c.email, ''),
            NULLIF(uc.email, ''),
            NULLIF(m.work_email, ''),
            NULLIF(um.email, ''),
            NULLIF(st.email, ''),
            ''
        ) AS client_email,

        COALESCE(
            NULLIF(c.phone, ''),
            NULLIF(m.phone, ''),
            NULLIF(st.phone, ''),
            ''
        ) AS client_phone,

        COALESCE(
            NULLIF(c.nin, ''),
            NULLIF(m.nin, ''),
            ''
        ) AS client_nin,

        COALESCE(
            NULLIF(c.business_name, ''),
            NULLIF(m.employer, ''),
            NULLIF(st.company_name, ''),
            ''
        ) AS organization_name,

        COALESCE(
            NULLIF(c.location, ''),
            NULLIF(m.department, ''),
            NULLIF(st.company_location, ''),
            ''
        ) AS client_location,

        COALESCE(
            NULLIF(c.district, ''),
            NULLIF(m.home_address, ''),
            ''
        ) AS client_district,

        officer.fname AS officer_fname,
        officer.lname AS officer_lname,

        c.id AS customer_table_id,
        c.user_id AS customer_user_id,
        m.id AS member_table_id,
        m.user_id AS member_user_id,
        st.id AS startup_table_id

    {$baseFrom}
    {$whereSql}
    ORDER BY l.id DESC
    LIMIT ? OFFSET ?
";

$pageBindParams = $bindParams;
$pageBindTypes = $bindTypes . 'ii';
$pageBindParams[] = $perPage;
$pageBindParams[] = $offset;

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Loan query failed: " . h($conn->error));
}
$stmt->bind_param($pageBindTypes, ...$pageBindParams);
$stmt->execute();
$result = $stmt->get_result();

$loans = [];
while ($row = $result->fetch_assoc()) {
    $loans[] = $row;
}
$stmt->close();

// Pull the full audit trail for every loan on this page in a single query.
$auditTrailByLoan = getLoanAuditTrailBulk($conn, array_column($loans, 'id'));

// Officers available for the "Assign to Loan Officer" dropdown.
$loanOfficers = getLoanOfficers($conn);

$stats = [
    'count' => $totalRows,
    'amount' => (float)($summary['total_amount'] ?? 0),
    'interest' => (float)($summary['total_interest'] ?? 0),
    'balance' => (float)($summary['total_balance'] ?? 0),
];

$typeStats = [
    'customer' => [
        'count' => (int)($summary['customer_count'] ?? 0),
        'amount' => (float)($summary['customer_amount'] ?? 0),
    ],
    'member' => [
        'count' => (int)($summary['member_count'] ?? 0),
        'amount' => (float)($summary['member_amount'] ?? 0),
    ],
    'startup' => [
        'count' => (int)($summary['startup_count'] ?? 0),
        'amount' => (float)($summary['startup_amount'] ?? 0),
    ],
    'unknown' => [
        'count' => (int)($summary['unknown_count'] ?? 0),
        'amount' => (float)($summary['unknown_amount'] ?? 0),
    ],
];

$avgAmount = $stats['count'] > 0 ? $stats['amount'] / $stats['count'] : 0;

$showingFrom = $totalRows > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalRows);

function paginationUrl(int $targetPage, string $search, string $typeFilter, int $perPage): string
{
    return '?' . http_build_query([
        'type' => $typeFilter,
        'search' => $search,
        'per_page' => $perPage,
        'page' => $targetPage,
    ]);
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

.border-purple-band::before { background: #7e22ce; }
.border-primary-band::before { background: #0d6efd; }
.border-orange-band::before { background: #f97316; }
.border-warning-band::before { background: #f59e0b; }
.border-secondary-band::before { background: #6b7280; }

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

.stat-icon.bg-purple { background: #7e22ce; }
.stat-icon.bg-primary { background: #0d6efd; }
.stat-icon.bg-orange { background: #f97316; }
.stat-icon.bg-warning { background: #f59e0b; }
.stat-icon.bg-secondary { background: #6b7280; }

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

.pending-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-left: 5px solid #f59e0b;
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: .9rem;
    box-shadow: 0 6px 18px rgba(15,23,42,.05);
}

.pending-card.status-info_requested { border-left-color: #0ea5e9; }
.pending-card.status-info_provided { border-left-color: #7e22ce; }

.pending-card-top {
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
    background: #2563eb;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    flex-shrink: 0;
}

.pending-meta {
    display: flex;
    align-items: center;
    gap: 2rem;
    flex-wrap: wrap;
}

.search-box {
    max-width: 580px;
}

.badge-purple {
    background: #f3e8ff;
    color: #7e22ce;
}

.badge-secondary {
    background: #e5e7eb;
    color: #374151;
}

.badge-info {
    background: #e0f2fe;
    color: #0369a1;
}

.fs-sm { font-size: .85rem; }
.fs-xs { font-size: .72rem; }
.text-muted { color: #64748b !important; }

.workflow-note {
    margin-top: .6rem;
    padding: .6rem .8rem;
    border-radius: 10px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    font-size: .82rem;
    color: #334155;
}

.workflow-note strong { color: #111827; }

.audit-entry {
    border-bottom: 1px solid #eef0f3;
    padding: .55rem 0;
}

.audit-entry:last-child { border-bottom: 0; }

.audit-action {
    font-weight: 700;
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.audit-action.approved { color: #16a34a; }
.audit-action.rejected { color: #dc2626; }
.audit-action.info_requested { color: #0369a1; }
.audit-action.info_provided { color: #7e22ce; }

.role-badge {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    font-size: .75rem;
    font-weight: 700;
    color: #374151;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: .3rem .75rem;
}

.review-panel {
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #fbfcfd;
    padding: 1rem;
    margin-top: .75rem;
}

.review-tabs {
    display: flex;
    gap: .35rem;
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.review-tab-btn {
    border: none;
    background: transparent;
    padding: .5rem 1rem;
    font-size: .8rem;
    font-weight: 700;
    color: #64748b;
    border-bottom: 3px solid transparent;
    border-radius: 8px 8px 0 0;
    cursor: pointer;
}

.review-tab-btn:hover { background: #f1f5f9; }

.review-tab-btn.tab-approve.active { color: #16a34a; border-bottom-color: #16a34a; background: #f0fdf4; }
.review-tab-btn.tab-reject.active { color: #dc2626; border-bottom-color: #dc2626; background: #fef2f2; }
.review-tab-btn.tab-info.active { color: #0369a1; border-bottom-color: #0369a1; background: #f0f9ff; }

.review-tab-content.d-none { display: none; }

.review-toggle-btn .fa-chevron-down {
    transition: transform .15s ease;
}

.review-toggle-btn[aria-expanded="true"] .fa-chevron-down {
    transform: rotate(180deg);
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

    .pending-meta {
        gap: 1rem;
        width: 100%;
    }
}

.bulk-bar [hidden], .bulk-fields[hidden] { display:none !important; }
.bulk-bar { display:flex; align-items:center; flex-wrap:wrap; gap:.75rem; padding:1rem;
    border:1px solid #cbd5e1; border-left:5px solid #2563eb; background:#eff6ff; border-radius:14px; margin-bottom:1rem; }
.bulk-bar .selection-count { font-weight:800; color:#1d4ed8; }
.loan-pick { width:19px; height:19px; accent-color:#2563eb; cursor:pointer; flex-shrink:0; }
.pending-card.selected-loan { box-shadow:0 0 0 2px #2563eb; }
.bulk-fields { width:100%; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.7rem; }
.bulk-fields label { font-size:.82rem; font-weight:700; }
.bulk-fields .wide { grid-column:1 / -1; }
@media(max-width:650px) { .bulk-fields { grid-template-columns:1fr; } .bulk-fields .wide { grid-column:auto; } }
</style>

<?php if (!empty($_SESSION['loan_action_feedback'])): ?>
<div class="alert alert-info" role="status"><?= h($_SESSION['loan_action_feedback']) ?></div>
<?php unset($_SESSION['loan_action_feedback']); endif; ?>
<div class="page-header mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <div class="title">Pending Loan Applications</div>
        <div class="sub">
            <?= number_format($stats['count']) ?>
            application<?= $stats['count'] === 1 ? '' : 's' ?> awaiting review
        </div>
    </div>
    <span class="role-badge"><i class="fas fa-user-shield"></i> Viewing as: <?= h($roleLabel) ?></span>
</div>

<div class="stats-grid">
    <div class="stat-card border-purple-band">
        <div class="stat-flex">
            <div>
                <div class="stat-label">Pending Applications</div>
                <div class="stat-value"><?= number_format($stats['count']) ?></div>
            </div>
            <div class="stat-icon bg-purple">
                <i class="fas fa-hourglass-half"></i>
            </div>
        </div>
    </div>

    <div class="stat-card border-primary-band">
        <div class="stat-flex">
            <div>
                <div class="stat-label">Total Requested</div>
                <div class="stat-value"><?= money($stats['amount']) ?></div>
            </div>
            <div class="stat-icon bg-primary">
                <i class="fas fa-coins"></i>
            </div>
        </div>
    </div>

    <div class="stat-card border-orange-band">
        <div class="stat-flex">
            <div>
                <div class="stat-label">Avg. Amount</div>
                <div class="stat-value"><?= money($avgAmount) ?></div>
            </div>
            <div class="stat-icon bg-orange">
                <i class="fas fa-calculator"></i>
            </div>
        </div>
    </div>

    <div class="stat-card border-warning-band">
        <div class="stat-flex">
            <div>
                <div class="stat-label">Pending Interest</div>
                <div class="stat-value"><?= money($stats['interest']) ?></div>
            </div>
            <div class="stat-icon bg-warning">
                <i class="fas fa-percentage"></i>
            </div>
        </div>
    </div>
</div>

<div class="stats-grid g3 mb-4">
    <div class="stat-card border-primary-band">
        <div class="stat-flex">
            <div>
                <div class="stat-label">Customer Pending</div>
                <div class="stat-value"><?= number_format($typeStats['customer']['count']) ?></div>
                <div class="stat-small"><?= money($typeStats['customer']['amount']) ?> requested</div>
            </div>
            <div class="stat-icon bg-primary">
                <i class="fas fa-users"></i>
            </div>
        </div>
    </div>

    <div class="stat-card border-purple-band">
        <div class="stat-flex">
            <div>
                <div class="stat-label">Member / Staff Pending</div>
                <div class="stat-value"><?= number_format($typeStats['member']['count']) ?></div>
                <div class="stat-small"><?= money($typeStats['member']['amount']) ?> requested</div>
            </div>
            <div class="stat-icon bg-purple">
                <i class="fas fa-user-tie"></i>
            </div>
        </div>
    </div>

    <div class="stat-card border-orange-band">
        <div class="stat-flex">
            <div>
                <div class="stat-label">Startup Pending</div>
                <div class="stat-value"><?= number_format($typeStats['startup']['count']) ?></div>
                <div class="stat-small"><?= money($typeStats['startup']['amount']) ?> requested</div>
            </div>
            <div class="stat-icon bg-orange">
                <i class="fas fa-rocket"></i>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="mb-0"><?= h($phead) ?></h5>
            <small class="text-muted">Filter pending applications by client type or search.</small>
        </div>

        <form method="GET" class="d-flex search-box w-100 w-md-auto">
            <input type="hidden" name="type" value="<?= h($typeFilter) ?>">

            <input type="text"
                   name="search"
                   value="<?= h($search) ?>"
                   class="form-control form-control-sm me-2"
                   placeholder="Search name, phone, company, amount...">

            <select name="per_page" class="form-select form-select-sm me-2" style="max-width:105px" title="Items per page">
                <?php foreach ($perPageOptions as $option): ?>
                    <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>>
                        <?= $option ?> / page
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-sm bg-gradient-primary me-2">
                <i class="fas fa-search"></i>
            </button>

            <?php if ($search !== ''): ?>
                <a href="<?= h(basename($_SERVER['PHP_SELF'])) ?>?type=<?= h($typeFilter) ?>&per_page=<?= $perPage ?>"
                   class="btn btn-sm btn-light">
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="d-flex align-center justify-between flex-wrap gap-3 mb-4">
    <div class="pills">
        <?php foreach ([
            'all' => 'All',
            'customer' => 'Customers',
            'member' => 'Members / Staff',
            'startup' => 'Startups',
            'unknown' => 'Unknown'
        ] as $key => $label): ?>
            <a href="?type=<?= h($key) ?>&search=<?= urlencode($search) ?>&per_page=<?= $perPage ?>"
               class="pill <?= $typeFilter === $key ? 'active' : '' ?>">
                <?= h($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="fs-sm text-muted">
        Showing <?= number_format($showingFrom) ?>-<?= number_format($showingTo) ?>
        of <?= number_format($totalRows) ?> application<?= $totalRows === 1 ? '' : 's' ?>
    </div>
</div>

<?php if ($isReviewer && !empty($loans)): ?>
<form method="POST" action="review_loan_action.php" id="bulkLoanForm">
    <input type="hidden" name="csrf_token" value="<?= h($reviewCsrf) ?>">
    <input type="hidden" name="bulk" value="1">
    <div class="bulk-bar" aria-label="Bulk loan actions">
        <label class="d-flex align-items-center gap-2 mb-0">
            <input class="loan-pick" type="checkbox" id="selectAllLoans" aria-label="Select all loans on this page">
            <strong>Select all on this page</strong>
        </label>
        <span class="selection-count" id="loanSelectionCount" aria-live="polite">0 selected</span>
        <button class="btn btn-sm btn-outline-secondary" id="clearLoanSelection" type="button">Clear</button>
        <div class="ms-auto d-flex flex-wrap align-items-center gap-2">
            <label class="mb-0" for="bulkLoanAction"><strong>Bulk action</strong></label>
            <select name="action" id="bulkLoanAction" class="form-select form-select-sm" required style="min-width:190px">
                <option value="">Choose action...</option>
                <option value="approve">Approve selected</option>
                <option value="reject">Reject selected</option>
                <option value="request_info">Request review / more info</option>
                <option value="delete">Delete selected (soft delete)</option>
            </select>
            <button class="btn btn-primary btn-sm" type="submit" id="submitBulkLoans" disabled>
                <i class="fas fa-check-double"></i> Apply to selected
            </button>
        </div>
        <div class="bulk-fields" id="bulkLoanFields" hidden>
            <div class="wide" data-bulk-field="reason" hidden>
                <label for="bulkRejectReason">Reason for rejection (required)</label>
                <textarea class="form-control" id="bulkRejectReason" name="reason" rows="2" disabled></textarea>
            </div>
            <div class="wide" data-bulk-field="info" hidden>
                <label for="bulkInfoDetails">Information required (required)</label>
                <textarea class="form-control" id="bulkInfoDetails" name="info_details" rows="2" disabled></textarea>
            </div>
            <div data-bulk-field="officer" hidden>
                <label for="bulkOfficer">Assign to loan officer (required)</label>
                <select class="form-select" name="officer_id" id="bulkOfficer" disabled>
                    <option value="">Select a loan officer...</option>
                    <?php foreach ($loanOfficers as $officer): ?>
                        <option value="<?= (int)$officer['id'] ?>"><?= h(trim($officer['fname'].' '.$officer['lname'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wide" data-bulk-field="delete_reason" hidden>
                <label for="bulkDeleteReason">Reason for deletion (required; applications will be retained for audit)</label>
                <textarea class="form-control" id="bulkDeleteReason" name="delete_reason" rows="2" disabled></textarea>
            </div>
            <div class="wide">
                <label for="bulkComments">Internal comments (optional)</label>
                <textarea class="form-control" id="bulkComments" name="comments" rows="2" placeholder="One note applied to all selected loans"></textarea>
            </div>
        </div>
        <small class="text-muted w-100">Selection covers the current page only. Choose one action for all selected applications. Deletion is a soft deletion (status DELETED).</small>
    </div>
</form>
<?php endif; ?>

<?php if (empty($loans)): ?>

    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-check-circle" style="color:var(--secondary);opacity:.5"></i>
                <h3>All Clear!</h3>
                <p>No pending loan applications at this time.</p>
            </div>
        </div>
    </div>

<?php else: ?>

    <?php foreach ($loans as $loan): ?>
        <?php
        $loanId = (int)$loan['id'];
        $clientType = (string)($loan['resolved_client_type'] ?? 'unknown');
        $status = strtoupper(trim((string)($loan['status'] ?? 'PENDING')));

        $fname = trim((string)($loan['fname'] ?? ''));
        $lname = trim((string)($loan['lname'] ?? ''));
        $organization = trim((string)($loan['organization_name'] ?? ''));
        $phone = trim((string)($loan['client_phone'] ?? ''));

        $clientName = trim($fname . ' ' . $lname);

        if ($clientType === 'startup') {
            $clientName = $organization !== '' ? $organization : ($fname !== '' ? $fname : 'Startup');
        }

        if ($clientType === 'customer' && $clientName === '') {
            if ($organization !== '') {
                $clientName = $organization;
            } elseif ($phone !== '') {
                $clientName = 'Customer - ' . $phone;
            } else {
                $clientName = 'Customer #' . (int)($loan['client_id'] ?? 0);
            }
        }

        if ($clientType === 'member' && $clientName === '') {
            if ($organization !== '') {
                $clientName = $organization;
            } elseif ($phone !== '') {
                $clientName = 'Member - ' . $phone;
            } else {
                $clientName = 'Member #' . (int)($loan['client_id'] ?? 0);
            }
        }

        if ($clientName === '') {
            $clientName = 'Unknown Client #' . (int)($loan['client_id'] ?? 0);
        }

        $initials = strtoupper(substr($clientName, 0, 1));

        $appliedDate = !empty($loan['datetime'])
            ? date('M j, Y', strtotime((string)$loan['datetime']))
            : 'N/A';

        $officerName = trim((string)($loan['officer_fname'] ?? '') . ' ' . (string)($loan['officer_lname'] ?? ''));
        $officerResponse = trim((string)($loan['officer_response'] ?? ''));
        $infoRequestDetails = trim((string)($loan['info_request_details'] ?? ''));

        $trail = $auditTrailByLoan[$loanId] ?? [];

        $assignedOfficerId = (int)($loan['assigned_officer_id'] ?? 0);
        $isAssignedToMe = $isOfficer && $currentUserId > 0 && $assignedOfficerId === $currentUserId;
        ?>

        <div class="pending-card status-<?= h(strtolower($status)) ?>">
            <div class="pending-card-top">
                <div class="d-flex align-center gap-3">
                    <?php if ($isReviewer): ?>
                        <input type="checkbox" class="loan-pick loan-row-check" form="bulkLoanForm"
                               name="ids[]" value="<?= $loanId ?>" aria-label="Select loan #<?= $loanId ?>">
                    <?php endif; ?>
                    <div class="cell-av"><?= h($initials ?: 'U') ?></div>

                    <div>
                        <div style="font-weight:700;color:var(--text-dark);font-size:.9rem">
                            <?= h($clientName) ?>
                        </div>

                        <div class="fs-sm text-muted">
                            <?php if ($organization !== '' && $organization !== $clientName): ?>
                                <?= h($organization) ?>
                            <?php endif; ?>

                            <?php if ($phone !== ''): ?>
                                <?= $organization !== '' && $organization !== $clientName ? ' &middot; ' : '' ?>
                                <?= h($phone) ?>
                            <?php endif; ?>

                            <?php if ($organization === '' && $phone === ''): ?>
                                Loan Client ID: <?= h($loan['client_id'] ?? '') ?>
                            <?php endif; ?>
                        </div>

                        <div class="mt-1">
                            <span class="badge <?= typeBadge($clientType) ?>">
                                <?= h(ucfirst($clientType)) ?>
                            </span>
                            <span class="badge <?= statusBadge($status) ?>">
                                <?= h(statusLabel($status)) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="pending-meta">
                    <div>
                        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.06em">Amount</div>
                        <div style="font-weight:800;color:var(--primary);font-family:'Outfit',sans-serif">
                            <?= money($loan['amount'] ?? 0) ?>
                        </div>
                    </div>

                    <div>
                        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.06em">Interest</div>
                        <div class="fs-sm text-muted"><?= money($loan['interest'] ?? 0) ?></div>
                    </div>

                    <div>
                        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.06em">Applied</div>
                        <div class="fs-sm text-muted"><?= h($appliedDate) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($status === 'INFO_PROVIDED'): ?>
                <div class="workflow-note">
                    <strong><i class="fas fa-reply"></i> Loan officer response<?= $officerName !== '' ? ' from ' . h($officerName) : '' ?>:</strong>
                    <div class="mt-1"><?= nl2br(h($officerResponse !== '' ? $officerResponse : 'No response text was recorded.')) ?></div>
                    <?php if ($infoRequestDetails !== ''): ?>
                        <div class="mt-2 text-muted"><strong>Original request:</strong> <?= nl2br(h($infoRequestDetails)) ?></div>
                    <?php endif; ?>
                </div>
            <?php elseif ($status === 'INFO_REQUESTED'): ?>
                <div class="workflow-note">
                    <strong><i class="fas fa-user-clock"></i> Awaiting <?= $officerName !== '' ? h($officerName) : 'assigned officer' ?>:</strong>
                    <div class="mt-1"><?= nl2br(h($infoRequestDetails)) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($trail)): ?>
                <div class="mt-2">
                    <a class="fs-xs text-muted" data-bs-toggle="collapse" href="#audit-<?= $loanId ?>" role="button">
                        <i class="fas fa-clock-rotate-left"></i> View history (<?= count($trail) ?>)
                    </a>
                    <div class="collapse mt-2" id="audit-<?= $loanId ?>">
                        <div class="p-2" style="background:#fafbfc;border-radius:10px;border:1px solid #eef0f3;">
                            <?php foreach ($trail as $entry): ?>
                                <?php
                                $entryActor = trim((string)($entry['fname'] ?? '') . ' ' . (string)($entry['lname'] ?? ''));
                                $entryActor = $entryActor !== '' ? $entryActor : 'User #' . (int)$entry['actor_id'];
                                $entryWhen = !empty($entry['created_at']) ? date('M j, Y g:i A', strtotime((string)$entry['created_at'])) : '';
                                ?>
                                <div class="audit-entry fs-sm">
                                    <span class="audit-action <?= h($entry['action']) ?>"><?= h(str_replace('_', ' ', $entry['action'])) ?></span>
                                    <span class="text-muted"> &middot; <?= h($entryActor) ?> &middot; <?= h($entryWhen) ?></span>
                                    <?php if (!empty($entry['reason'])): ?>
                                        <div><strong>Reason:</strong> <?= nl2br(h($entry['reason'])) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['comments'])): ?>
                                        <div><strong>Comment:</strong> <?= nl2br(h($entry['comments'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="d-flex gap-2 flex-wrap mt-3">
                <a href="view_loan.php?id=<?= urlencode((string)$loanId) ?>"
                   class="btn btn-ghost btn-sm">
                    <i class="fas fa-eye"></i> View
                </a>

                <?php if ($isReviewer): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm review-toggle-btn"
                            data-panel="reviewPanel-<?= $loanId ?>" aria-expanded="false">
                        <i class="fas fa-clipboard-check"></i> Review Application
                        <i class="fas fa-chevron-down fs-xs"></i>
                    </button>
                <?php elseif ($isAssignedToMe && $status === 'INFO_REQUESTED'): ?>
                    <a href="officer_loan_requests.php" class="btn btn-info btn-sm">
                        <i class="fas fa-reply"></i> Respond to Request
                    </a>
                <?php elseif ($isOfficer): ?>
                    <span class="fs-xs text-muted align-self-center">Read-only &mdash; not assigned to you</span>
                <?php else: ?>
                    <span class="fs-xs text-muted align-self-center">Read-only for your role</span>
                <?php endif; ?>
            </div>

            <?php if ($isReviewer): ?>
                <div id="reviewPanel-<?= $loanId ?>" class="review-panel d-none">
                    <div class="review-tabs" role="tablist">
                        <button type="button" class="review-tab-btn tab-approve active" data-tab="approve-<?= $loanId ?>">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" class="review-tab-btn tab-reject" data-tab="reject-<?= $loanId ?>">
                            <i class="fas fa-times"></i> Reject
                        </button>
                        <button type="button" class="review-tab-btn tab-info" data-tab="info-<?= $loanId ?>">
                            <i class="fas fa-circle-question"></i> Request More Info
                        </button>
                    </div>

                    <div class="review-tab-content" data-tab-content="approve-<?= $loanId ?>">
                        <form method="POST" action="review_loan_action.php">
                            <input type="hidden" name="csrf_token" value="<?= h($reviewCsrf) ?>">
                            <input type="hidden" name="id" value="<?= $loanId ?>">
                            <input type="hidden" name="action" value="approve">

                            <label class="form-label fs-sm">Reviewer comments (optional)</label>
                            <textarea name="comments" class="form-control mb-3" rows="3"
                                      placeholder="Any notes for the audit trail..."></textarea>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-success btn-sm"
                                        onclick="return confirm('Approve this loan application?')">
                                    Confirm Approval
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="review-tab-content d-none" data-tab-content="reject-<?= $loanId ?>">
                        <form method="POST" action="review_loan_action.php"
                              onsubmit="return this.reason.value.trim().length > 0 || (alert('A rejection reason is required.') && false);">
                            <input type="hidden" name="csrf_token" value="<?= h($reviewCsrf) ?>">
                            <input type="hidden" name="id" value="<?= $loanId ?>">
                            <input type="hidden" name="action" value="reject">

                            <label class="form-label fs-sm">Reason for rejection <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control mb-3" rows="2" required
                                      placeholder="Visible to the applicant and the assigned loan officer"></textarea>

                            <label class="form-label fs-sm">Additional comments (optional)</label>
                            <textarea name="comments" class="form-control mb-3" rows="2"
                                      placeholder="Internal note for the audit trail"></textarea>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-danger btn-sm">Confirm Rejection</button>
                            </div>
                        </form>
                    </div>

                    <div class="review-tab-content d-none" data-tab-content="info-<?= $loanId ?>">
                        <form method="POST" action="review_loan_action.php"
                              onsubmit="return this.info_details.value.trim().length > 0 || (alert('Please describe what information is needed.') && false);">
                            <input type="hidden" name="csrf_token" value="<?= h($reviewCsrf) ?>">
                            <input type="hidden" name="id" value="<?= $loanId ?>">
                            <input type="hidden" name="action" value="request_info">

                            <label class="form-label fs-sm">What's needed from the applicant? <span class="text-danger">*</span></label>
                            <textarea name="info_details" class="form-control mb-3" rows="3" required
                                      placeholder="e.g. proof of income, updated NIN, collateral documents..."></textarea>

                            <label class="form-label fs-sm">Assign to loan officer <span class="text-danger">*</span></label>
                            <select name="officer_id" class="form-select mb-3" required>
                                <option value="">Select a loan officer...</option>
                                <?php foreach ($loanOfficers as $officer): ?>
                                    <option value="<?= (int)$officer['id'] ?>">
                                        <?= h(trim($officer['fname'] . ' ' . $officer['lname'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($loanOfficers)): ?>
                                <div class="text-danger fs-xs mb-3">No loan officers found. Add a user with role = 2 first.</div>
                            <?php endif; ?>

                            <label class="form-label fs-sm">Notes for the officer (optional)</label>
                            <textarea name="comments" class="form-control mb-3" rows="2"
                                      placeholder="Any extra context for the audit trail"></textarea>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-info btn-sm">Send Request</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php endforeach; ?>

    <?php if ($totalPages > 1): ?>
        <?php
        $windowStart = max(1, $page - 2);
        $windowEnd = min($totalPages, $page + 2);
        ?>
        <nav class="mt-4 mb-3" aria-label="Pending loans pagination">
            <ul class="pagination pagination-sm justify-content-center flex-wrap">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $page > 1 ? h(paginationUrl($page - 1, $search, $typeFilter, $perPage)) : '#' ?>" aria-label="Previous">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>

                <?php if ($windowStart > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= h(paginationUrl(1, $search, $typeFilter, $perPage)) ?>">1</a>
                    </li>
                    <?php if ($windowStart > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $windowStart; $i <= $windowEnd; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= h(paginationUrl($i, $search, $typeFilter, $perPage)) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <?php if ($windowEnd < $totalPages): ?>
                    <?php if ($windowEnd < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= h(paginationUrl($totalPages, $search, $typeFilter, $perPage)) ?>">
                            <?= $totalPages ?>
                        </a>
                    </li>
                <?php endif; ?>

                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $page < $totalPages ? h(paginationUrl($page + 1, $search, $typeFilter, $perPage)) : '#' ?>" aria-label="Next">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>

<?php endif; ?>

<script>
document.addEventListener('click', function (e) {
    var toggleBtn = e.target.closest('.review-toggle-btn');
    if (toggleBtn) {
        var panel = document.getElementById(toggleBtn.getAttribute('data-panel'));
        if (panel) {
            var nowHidden = panel.classList.toggle('d-none');
            toggleBtn.setAttribute('aria-expanded', nowHidden ? 'false' : 'true');
        }
        return;
    }

    var tabBtn = e.target.closest('.review-tab-btn');
    if (tabBtn) {
        var panelRoot = tabBtn.closest('.review-panel');
        if (!panelRoot) {
            return;
        }

        panelRoot.querySelectorAll('.review-tab-btn').forEach(function (btn) {
            btn.classList.remove('active');
        });
        tabBtn.classList.add('active');

        panelRoot.querySelectorAll('.review-tab-content').forEach(function (content) {
            content.classList.add('d-none');
        });

        var target = tabBtn.getAttribute('data-tab');
        var activeContent = panelRoot.querySelector('[data-tab-content="' + target + '"]');
        if (activeContent) {
            activeContent.classList.remove('d-none');
        }
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('bulkLoanForm');
    if (!form) return;
    const checks = Array.from(document.querySelectorAll('.loan-row-check'));
    const selectAll = document.getElementById('selectAllLoans');
    const clear = document.getElementById('clearLoanSelection');
    const counter = document.getElementById('loanSelectionCount');
    const action = document.getElementById('bulkLoanAction');
    const submit = document.getElementById('submitBulkLoans');
    const fields = document.getElementById('bulkLoanFields');
    function updateSelection() {
        const count = checks.filter(x => x.checked).length;
        counter.textContent = `${count} selected`;
        selectAll.checked = checks.length > 0 && count === checks.length;
        selectAll.indeterminate = count > 0 && count < checks.length;
        checks.forEach(x => x.closest('.pending-card')?.classList.toggle('selected-loan', x.checked));
        submit.disabled = count === 0 || action.value === '';
    }
    function updateAction() {
        const val = action.value;
        const enabled = {reason: val === 'reject', info: val === 'request_info', officer: val === 'request_info', delete_reason: val === 'delete'};
        fields.hidden = !val;
        Object.entries(enabled).forEach(([name, active]) => {
            const group = fields.querySelector(`[data-bulk-field="${name}"]`);
            if (!group) return;
            group.hidden = !active;
            group.querySelectorAll('textarea,select,input').forEach(control => {
                control.disabled = !active;
                control.required = active;
            });
        });
        updateSelection();
    }
    checks.forEach(x => x.addEventListener('change', updateSelection));
    selectAll.addEventListener('change', () => { checks.forEach(x => x.checked = selectAll.checked); updateSelection(); });
    clear.addEventListener('click', () => { checks.forEach(x => x.checked = false); updateSelection(); });
    action.addEventListener('change', updateAction);
    form.addEventListener('submit', e => {
        const count = checks.filter(x => x.checked).length;
        if (!count || !action.value) { e.preventDefault(); alert('Select at least one loan and an action.'); return; }
        const labels = {approve:'approve',reject:'reject',request_info:'request additional information for',delete:'soft-delete'};
        if (!confirm(`Are you sure you want to ${labels[action.value]} ${count} selected loan(s)?`)) e.preventDefault();
    });
    updateAction();
});
</script>

<?php require "footer.php"; ?>