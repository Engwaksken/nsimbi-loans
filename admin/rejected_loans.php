<?php
declare(strict_types=1);

require "nav-head.php";
require "services_check.php";
require_once __DIR__ . "/loan_audit.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !$conn instanceof mysqli) {
    die("Database connection not established.");
}

$current_user_role = $_SESSION['role'] ?? 0;
$phead = "Rejected Loans";

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

$whereStatus = "l.status = 'REJECTED'";

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
            OR c.business_name LIKE ?
            OR c.location LIKE ?
            OR CAST(l.id AS CHAR) LIKE ?
            OR CAST(l.amount AS CHAR) LIKE ?
            OR CAST(l.loan_period AS CHAR) LIKE ?
            OR COALESCE(l.rejection_reason, '') LIKE ?
        )
    ";

    for ($i = 0; $i < 11; $i++) {
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
            OR m.employer LIKE ?
            OR m.department LIKE ?
            OR CAST(l.id AS CHAR) LIKE ?
            OR CAST(l.amount AS CHAR) LIKE ?
            OR CAST(l.loan_period AS CHAR) LIKE ?
            OR COALESCE(l.rejection_reason, '') LIKE ?
        )
    ";

    for ($i = 0; $i < 11; $i++) {
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
            OR CAST(l.id AS CHAR) LIKE ?
            OR CAST(l.amount AS CHAR) LIKE ?
            OR CAST(l.loan_period AS CHAR) LIKE ?
            OR COALESCE(l.rejection_reason, '') LIKE ?
        )
    ";

    for ($i = 0; $i < 9; $i++) {
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
        c.business_name AS organization_name,
        c.location AS client_location
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
        m.employer AS organization_name,
        m.department AS client_location
    FROM loan l
    INNER JOIN members m ON l.client_id = m.user_id
    INNER JOIN user u ON m.user_id = u.id
    WHERE {$whereStatus}
    {$searchMember}
";

$startupSql = "
    SELECT
        l.*,
        'startup' AS client_type,
        st.ceo_name AS fname,
        '' AS lname,
        st.email AS client_email,
        st.phone AS client_phone,
        st.company_name AS organization_name,
        st.company_location AS client_location
    FROM loan l
    INNER JOIN startups st ON l.client_id = st.id
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

    $bindParams = array_merge(
        $paramsCustomer,
        $paramsMember,
        $paramsStartup
    );

    $bindTypes = $typesCustomer . $typesMember . $typesStartup;
}

$sql = "
    SELECT *
    FROM ($baseSql) x
    ORDER BY id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Query failed: " . h($conn->error));
}

if (!empty($bindParams)) {
    $stmt->bind_param($bindTypes, ...$bindParams);
}

$stmt->execute();

$result = $stmt->get_result();

$loans = [];

while ($row = $result->fetch_assoc()) {
    $loans[] = $row;
}

// Pull the audit trail for every rejected loan on this page in one query,
// so we can show the reviewer's optional comments alongside the mandatory
// rejection reason already stored on the loan row itself.
$auditTrailByLoan = getLoanAuditTrailBulk($conn, array_column($loans, 'id'));

function latestRejectionComment(array $trail): ?string
{
    foreach ($trail as $entry) {
        if (($entry['action'] ?? '') === 'rejected' && !empty($entry['comments'])) {
            return (string)$entry['comments'];
        }
    }

    return null;
}

function rejectedByName(array $trail): ?string
{
    foreach ($trail as $entry) {
        if (($entry['action'] ?? '') === 'rejected') {
            $name = trim(($entry['fname'] ?? '') . ' ' . ($entry['lname'] ?? ''));
            return $name !== '' ? $name : null;
        }
    }

    return null;
}

$stats = [
    'count' => 0,
    'amount' => 0,
    'interest' => 0,
];

$typeStats = [
    'customer' => ['count' => 0],
    'member'   => ['count' => 0],
    'startup'  => ['count' => 0],
];

foreach ($loans as $loan) {

    $stats['count']++;
    $stats['amount'] += (float)($loan['amount'] ?? 0);
    $stats['interest'] += (float)($loan['interest'] ?? 0);

    $type = (string)($loan['client_type'] ?? '');

    if (isset($typeStats[$type])) {
        $typeStats[$type]['count']++;
    }
}
?>

<style>
.stats-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:1rem;
    margin-bottom:1rem;
}

.stats-grid.g3{
    grid-template-columns:repeat(3,minmax(0,1fr));
}

.stat-card{
    background:#fff;
    border-radius:16px;
    padding:1rem;
    position:relative;
    overflow:hidden;
    box-shadow:0 6px 18px rgba(15,23,42,.06);
}

.stat-card::before{
    content:"";
    position:absolute;
    left:0;
    top:0;
    width:5px;
    height:100%;
}

.border-danger-band::before{background:#dc3545;}
.border-primary-band::before{background:#0d6efd;}
.border-dark-band::before{background:#111827;}
.border-purple-band::before{background:#7e22ce;}
.border-orange-band::before{background:#f97316;}

.stat-flex{
    display:flex;
    align-items:center;
    justify-content:space-between;
}

.stat-label{
    font-size:.72rem;
    text-transform:uppercase;
    color:#64748b;
    font-weight:700;
    margin-bottom:.35rem;
}

.stat-value{
    font-size:1.15rem;
    font-weight:800;
    color:#111827;
}

.stat-icon{
    width:50px;
    height:50px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
}

.bg-danger{background:#dc3545;color:#ffffff;}
.bg-primary{background:#0d6efd;}
.bg-dark{background:#111827;}
.bg-purple{background:#7e22ce;}
.bg-orange{background:#f97316;}

.badge-purple{
    background:#f3e8ff;
    color:#7e22ce;
}

.rejected-table-card{
    border:0;
    border-radius:18px;
    box-shadow:0 8px 24px rgba(15,23,42,.08);
}

.client-name{
    font-weight:700;
    color:#111827;
}

.table thead th{
    font-size:.72rem;
    text-transform:uppercase;
    letter-spacing:.05em;
}

.reason-cell,
.comment-cell {
    display: block;
    max-width: 240px;
    white-space: normal;
    font-size: .82rem;
    line-height: 1.35;
}

.reason-cell {
    color: #991b1b;
}

.comment-cell {
    color: #475569;
}

.reason-empty,
.comment-empty {
    color: #9ca3af;
    font-style: italic;
    font-size: .8rem;
}

.rejected-by {
    display: block;
    margin-top: .2rem;
    font-size: .7rem;
    color: #9ca3af;
}

@media(max-width:992px){
    .stats-grid,
    .stats-grid.g3{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media(max-width:576px){
    .stats-grid,
    .stats-grid.g3{
        grid-template-columns:1fr;
    }
}
</style>

<div class="container-fluid py-4">

    <div class="page-header mb-4">
        <div>
            <div class="title"><?= h($phead) ?></div>
            <div class="sub">
                <?= number_format($stats['count']) ?> rejected loan<?= $stats['count'] === 1 ? '' : 's' ?>
            </div>
        </div>

        <button class="btn btn-sm bg-gradient-success"
                onclick="download_table_as_csv('rejected_report','rejected_loans.csv')">
            <i class="fas fa-file-csv me-1"></i> Export CSV
        </button>
    </div>

    <div class="stats-grid">

        <div class="stat-card border-danger-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Rejected Loans</div>
                    <div class="stat-value"><?= number_format($stats['count']) ?></div>
                </div>

                <div class="stat-icon bg-danger">
                    <i class="fas fa-times-circle"></i>
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

        <div class="stat-card border-dark-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Interest Requested</div>
                    <div class="stat-value"><?= money($stats['interest']) ?></div>
                </div>

                <div class="stat-icon bg-dark">
                    <i class="fas fa-percentage"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-danger-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Review Status</div>
                    <div class="stat-value">Rejected</div>
                </div>

                <div class="stat-icon bg-danger">
                    <i class="fas fa-ban"></i>
                </div>
            </div>
        </div>

    </div>

    <div class="stats-grid g3 mb-4">

        <div class="stat-card border-primary-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Customer Rejected</div>
                    <div class="stat-value"><?= number_format($typeStats['customer']['count']) ?></div>
                </div>

                <div class="stat-icon bg-primary">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-purple-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Member Rejected</div>
                    <div class="stat-value"><?= number_format($typeStats['member']['count']) ?></div>
                </div>

                <div class="stat-icon bg-purple">
                    <i class="fas fa-user-tie"></i>
                </div>
            </div>
        </div>

        <div class="stat-card border-orange-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label">Startup Rejected</div>
                    <div class="stat-value"><?= number_format($typeStats['startup']['count']) ?></div>
                </div>

                <div class="stat-icon bg-orange">
                    <i class="fas fa-rocket"></i>
                </div>
            </div>
        </div>

    </div>

    <div class="card rejected-table-card mb-4">

        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">

            <div>
                <h5 class="mb-0"><?= h($phead) ?></h5>
                <small class="text-muted">
                    Showing rejected loan applications
                </small>
            </div>

            <form method="GET" class="d-flex w-100" style="max-width:580px">

                <input type="hidden"
                       name="type"
                       value="<?= h($typeFilter) ?>">

                <input type="text"
                       name="search"
                       value="<?= h($search) ?>"
                       class="form-control form-control-sm me-2"
                       placeholder="Search client, company, amount, reason...">

                <button type="submit"
                        class="btn btn-sm bg-gradient-primary me-2">
                    <i class="fas fa-search"></i>
                </button>

                <?php if ($search !== ''): ?>
                    <a href="<?= h(basename($_SERVER['PHP_SELF'])) ?>"
                       class="btn btn-sm btn-light">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>

            </form>

        </div>

        <div class="card-body px-0 pt-0 pb-2">

            <div class="d-flex align-center justify-between flex-wrap gap-3 px-3 pt-3">

                <div class="pills">
                    <?php foreach ([
                        'all' => 'All',
                        'customer' => 'Customers',
                        'member' => 'Members',
                        'startup' => 'Startups'
                    ] as $key => $label): ?>

                        <a href="?type=<?= h($key) ?>&search=<?= urlencode($search) ?>"
                           class="pill <?= $typeFilter === $key ? 'active' : '' ?>">
                            <?= h($label) ?>
                        </a>

                    <?php endforeach; ?>
                </div>

            </div>

            <?php if (!empty($loans)): ?>

                <div class="table-responsive p-0">

                    <table class="table align-items-center mb-0"
                           id="rejected_report">

                        <thead>
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Client</th>
                            <th>Type</th>
                            <th>Contact</th>
                            <th>Amount</th>
                            <th>Interest</th>
                            <th>Loan Period</th>
                            <th class="text-center">Request Date</th>
                            <th>Rejection Reason</th>
                            <th>Reviewer Comments</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($loans as $index => $loan): ?>

                            <?php
                            $clientType = (string)($loan['client_type'] ?? '');
                            $loanIdInt = (int)$loan['id'];

                            $clientName = trim(
                                ($loan['fname'] ?? '') . ' ' .
                                ($loan['lname'] ?? '')
                            );

                            if ($clientType === 'startup') {
                                $clientName = $loan['organization_name']
                                    ?: ($loan['fname'] ?? 'Startup');
                            }

                            if ($clientName === '') {
                                $clientName = 'N/A';
                            }

                            $requestDate = !empty($loan['datetime'])
                                ? date('M d, Y', strtotime((string)$loan['datetime']))
                                : 'N/A';

                            $trail = $auditTrailByLoan[$loanIdInt] ?? [];
                            $reason = trim((string)($loan['rejection_reason'] ?? ''));
                            $comment = latestRejectionComment($trail);
                            $rejectedBy = rejectedByName($trail);
                            ?>

                            <tr>

                                <td class="ps-4">
                                    <?= number_format($index + 1) ?>
                                </td>

                                <td>

                                    <div class="client-name">
                                        <i class="fas fa-user-circle me-1 text-secondary"></i>
                                        <?= h($clientName) ?>
                                    </div>

                                    <small class="text-muted">
                                        Loan ID: #<?= (int)$loan['id'] ?>

                                        <?php if (!empty($loan['organization_name'])): ?>
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

                                    <div>
                                        <?= h($loan['client_phone'] ?? 'N/A') ?>
                                    </div>

                                    <small class="text-muted">
                                        <?= h($loan['client_email'] ?? '') ?>
                                    </small>

                                </td>

                                <td><?= money($loan['amount'] ?? 0) ?></td>

                                <td><?= money($loan['interest'] ?? 0) ?></td>

                                <td>
                                    <?= h($loan['loan_period'] ?? 0) ?> Months
                                </td>

                                <td class="text-center">
                                    <?= h($requestDate) ?>
                                </td>

                                <!-- REJECTION REASON -->
                                <td>
                                    <?php if ($reason !== ''): ?>
                                        <span class="reason-cell"><?= nl2br(h($reason)) ?></span>
                                    <?php else: ?>
                                        <span class="reason-empty">No reason recorded</span>
                                    <?php endif; ?>

                                    <?php if ($rejectedBy !== null): ?>
                                        <span class="rejected-by">by <?= h($rejectedBy) ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- REVIEWER COMMENTS -->
                                <td>
                                    <?php if ($comment !== null && $comment !== ''): ?>
                                        <span class="comment-cell"><?= nl2br(h($comment)) ?></span>
                                    <?php else: ?>
                                        <span class="comment-empty">No additional comments</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center">
                                    <span class="badge bg-danger">
                                        REJECTED
                                    </span>
                                </td>

                                <td class="text-center">

                                    <?php if ($current_user_role == 1): ?>

                                        <button
                                            class="btn btn-sm btn-info"
                                            onclick="openActionModal('<?= (int)$loan['id'] ?>','approve')">
                                            <i class="fas fa-check"></i>
                                        </button>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="text-center py-5">

                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>

                    <h6>No rejected loans found</h6>

                    <p class="text-muted mb-0">
                        Rejected loans will appear here.
                    </p>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- This re-approves a previously rejected loan. It posts to
     review_loan_action.php (the same reviewer action handler used on
     pending_loans.php), which moves the loan to APPROVED and hands it to
     an accountant for disbursement — the same path as any fresh approval. -->
<div class="modal fade"
     id="actionModal"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <form method="POST"
              action="review_loan_action.php">

            <input type="hidden"
                   name="id"
                   id="actionLoanId">

            <input type="hidden"
                   name="action"
                   id="actionType">

            <div class="modal-content">

                <div class="modal-header">

                    <h5 class="modal-title"
                        id="actionModalLabel"></h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body text-center"
                     id="actionModalBody"></div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="btn"
                            id="actionModalBtn"></button>

                </div>

            </div>

        </form>

    </div>

</div>

<script>
function openActionModal(loanId, action)
{
    document.getElementById('actionLoanId').value = loanId;
    document.getElementById('actionType').value = action;

    const title = document.getElementById('actionModalLabel');
    const body  = document.getElementById('actionModalBody');
    const btn   = document.getElementById('actionModalBtn');

    if (action === 'approve') {

        title.innerText = 'Confirm Approval';

        body.innerHTML =
            'Are you sure you want to approve this previously rejected loan? ' +
            'It will be sent to accounts for disbursement.';

        btn.innerText = 'Approve';
        btn.className = 'btn btn-info';
    }

    const modal = new bootstrap.Modal(
        document.getElementById('actionModal')
    );

    modal.show();
}

function download_table_as_csv(table_id, filename)
{
    let table = document.getElementById(table_id);

    let rows = Array.from(table.querySelectorAll('tr'));

    let csv = rows.map(row => {

        let cols = Array.from(row.querySelectorAll('th,td'));

        return cols.map(col =>
            `"${col.innerText.replace(/"/g,'""')}"`
        ).join(',');

    }).join('\n');

    let a = document.createElement('a');

    a.href =
        'data:text/csv;charset=utf-8,' +
        encodeURIComponent(csv);

    a.download = filename || 'export.csv';

    a.click();
}
</script>

<?php
$stmt->close();
require "footer.php";
?>