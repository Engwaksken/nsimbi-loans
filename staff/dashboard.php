<?php
declare(strict_types=1);

require "db.php";
require "nav-head.php";
require "services_check.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logged_in'])) {
    exit("User not logged in.");
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    exit("Database connection not found.");
}

$conn->set_charset("utf8mb4");

$client_id = (int)$_SESSION['logged_in'];

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function money($value): string
{
    return "UGX " . number_format((float)$value, 2);
}

function badgeClass(string $status): string
{
    return match (strtoupper(trim($status))) {
        "CLEARED" => "success",
        "OVERDUE" => "danger",
        "OUTSTANDING" => "warning",
        "PENDING" => "secondary",
        default => "dark",
    };
}

function fetchOne(mysqli $conn, string $sql, int $client_id): array
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $row;
}

$totalLoans = fetchOne($conn, "
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM loan
    WHERE client_id = ?
    AND UPPER(COALESCE(status, '')) <> 'PENDING'
", $client_id);

$totalCount = fetchOne($conn, "
    SELECT COUNT(*) AS total
    FROM loan
    WHERE client_id = ?
", $client_id);

$totalInterest = fetchOne($conn, "
    SELECT COALESCE(SUM(interest), 0) AS total
    FROM loan
    WHERE client_id = ?
", $client_id);

$clearedLoans = fetchOne($conn, "
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM loan
    WHERE client_id = ?
    AND UPPER(status) = 'CLEARED'
", $client_id);

$outstandingLoans = fetchOne($conn, "
    SELECT 
        COALESCE(SUM(
            CASE 
                WHEN outstanding_balance > 0 THEN outstanding_balance
                WHEN loan_balance > 0 THEN loan_balance
                ELSE principal_balance + interest_balance
            END
        ), 0) AS total
    FROM loan
    WHERE client_id = ?
    AND UPPER(status) IN ('OUTSTANDING', 'OVERDUE')
", $client_id);

$paidAmount = fetchOne($conn, "
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM payments
    WHERE user_id = ?
    AND UPPER(status) = 'SUCCESSFUL'
", $client_id);

$pendingLoans = fetchOne($conn, "
    SELECT COUNT(*) AS total
    FROM loan
    WHERE client_id = ?
    AND UPPER(status) = 'PENDING'
", $client_id);

$stmt = $conn->prepare("
    SELECT *
    FROM loan
    WHERE client_id = ?
    AND UPPER(COALESCE(status, '')) <> 'PENDING'
    ORDER BY id DESC
    LIMIT 5
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$recentLoans = $stmt->get_result();
?>

<link rel="stylesheet" href="assets/css/loanpro.css">

<style>
.dashboard-header {
    background: #fff;
    border-radius: 18px;
    padding: 22px;
    border: 1px solid #edf2f7;
    box-shadow: 0 8px 25px rgba(15, 23, 42, .06);
}

.dashboard-stat {
    display: block;
    text-decoration: none;
    color: inherit;
    background: #fff;
    border-radius: 18px;
    padding: 20px;
    border: 1px solid #edf2f7;
    border-left: 6px solid #344767;
    box-shadow: 0 8px 25px rgba(15, 23, 42, .06);
    min-height: 118px;
    transition: all .2s ease;
}

.dashboard-stat:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 30px rgba(15, 23, 42, .1);
}

.dashboard-stat.primary { border-left-color: #0d6efd; }
.dashboard-stat.warning { border-left-color: #f59e0b; }
.dashboard-stat.success { border-left-color: #198754; }
.dashboard-stat.danger { border-left-color: #dc3545; }
.dashboard-stat.info { border-left-color: #0dcaf0; }
.dashboard-stat.dark { border-left-color: #344767; }

.dashboard-stat-icon {
    float: right;
    width: 42px;
    height: 42px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
}

.dashboard-stat-title {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: .05em;
    margin-bottom: 8px;
}

.dashboard-stat-value {
    font-size: 20px;
    font-weight: 800;
    color: #1e293b;
}

.dashboard-card {
    background: #fff;
    border-radius: 18px;
    border: 1px solid #edf2f7;
    box-shadow: 0 8px 25px rgba(15, 23, 42, .06);
    overflow: hidden;
}

.dashboard-card table thead th {
    background: #f8fafc;
    color: #475569;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 14px;
    white-space: nowrap;
}

.dashboard-card table tbody td {
    padding: 14px;
    vertical-align: middle;
    white-space: nowrap;
}

.loan-pill {
    background: #eef2ff;
    color: #3730a3;
    padding: 6px 10px;
    border-radius: 50px;
    font-weight: 700;
    font-size: 12px;
}

.action-btn {
    border-radius: 50px;
    padding: 7px 12px;
    font-size: 12px;
    font-weight: 700;
}
</style>

<div class="container-fluid py-4">

    <div class="dashboard-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3 class="mb-1">Client Dashboard</h3>
                <p class="text-muted mb-0">
                    Overview of your loans, repayments, balances, and recent activity.
                </p>
            </div>

            <a href="requesting_loan.php" class="btn bg-gradient-primary mb-0">
                <i class="fas fa-plus-circle me-2"></i>
                Request Loan
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">

        <div class="col-xl-4 col-md-6">
            <a href="loans.php" class="dashboard-stat primary">
                <div class="dashboard-stat-icon bg-primary">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="dashboard-stat-title">Total Loans</div>
                <div class="dashboard-stat-value"><?= money($totalLoans['total'] ?? 0) ?></div>
            </a>
        </div>

        <div class="col-xl-4 col-md-6">
            <a href="outstanding.php?check=2" class="dashboard-stat warning">
                <div class="dashboard-stat-icon bg-warning">
                    <i class="fas fa-scale-balanced"></i>
                </div>
                <div class="dashboard-stat-title">Outstanding Balance</div>
                <div class="dashboard-stat-value"><?= money($outstandingLoans['total'] ?? 0) ?></div>
            </a>
        </div>

        <div class="col-xl-4 col-md-6">
            <a href="cleared.php?check=1" class="dashboard-stat success">
                <div class="dashboard-stat-icon bg-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="dashboard-stat-title">Cleared Loans</div>
                <div class="dashboard-stat-value"><?= money($clearedLoans['total'] ?? 0) ?></div>
            </a>
        </div>

        <div class="col-xl-4 col-md-6">
            <a href="loans.php" class="dashboard-stat dark">
                <div class="dashboard-stat-icon bg-dark">
                    <i class="fas fa-list"></i>
                </div>
                <div class="dashboard-stat-title">No. Loans Requested</div>
                <div class="dashboard-stat-value"><?= number_format((int)($totalCount['total'] ?? 0)) ?></div>
            </a>
        </div>

        <div class="col-xl-4 col-md-6">
            <a href="loan_payments.php" class="dashboard-stat info">
                <div class="dashboard-stat-icon bg-info">
                    <i class="fas fa-credit-card"></i>
                </div>
                <div class="dashboard-stat-title">Paid Amount</div>
                <div class="dashboard-stat-value"><?= money($paidAmount['total'] ?? 0) ?></div>
            </a>
        </div>

        <div class="col-xl-4 col-md-6">
            <a href="pending_loans.php" class="dashboard-stat danger">
                <div class="dashboard-stat-icon bg-danger">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="dashboard-stat-title">Pending Loans</div>
                <div class="dashboard-stat-value"><?= number_format((int)($pendingLoans['total'] ?? 0)) ?></div>
            </a>
        </div>

    </div>

    <div class="dashboard-card">

        <div class="p-4 border-bottom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">My Recent Loans</h5>
                    <small class="text-muted">Latest 5 non-pending loan records.</small>
                </div>

                <a href="loans.php" class="btn btn-sm bg-gradient-primary">
                    View All
                </a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Loan No.</th>
                        <th>Amount</th>
                        <th>Interest</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Datetime</th>
                        <th>Due Date</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($recentLoans && $recentLoans->num_rows > 0): ?>
                        <?php $counter = 1; ?>
                        <?php while ($loan = $recentLoans->fetch_assoc()): ?>
                            <?php
                                $loan_id = (int)$loan['id'];
                                $loan_no = "LOAN-" . $loan_id;
                                $status = strtoupper((string)($loan['status'] ?? ''));
                                $balance = (float)($loan['outstanding_balance'] ?? 0);

                                if ($balance <= 0) {
                                    $balance = (float)($loan['loan_balance'] ?? 0);
                                }

                                $statusClass = badgeClass($status);
                            ?>

                            <tr>
                                <td><?= $counter++ ?></td>

                                <td>
                                    <span class="loan-pill"><?= e($loan_no) ?></span>
                                </td>

                                <td class="fw-bold"><?= money($loan['amount'] ?? 0) ?></td>

                                <td><?= money($loan['interest'] ?? 0) ?></td>

                                <td class="fw-bold text-danger"><?= money($balance) ?></td>

                                <td>
                                    <span class="badge bg-gradient-<?= e($statusClass) ?>">
                                        <?= e($status) ?>
                                    </span>
                                </td>

                                <td><?= e($loan['datetime'] ?? '-') ?></td>

                                <td><?= e($loan['due_date'] ?? '-') ?></td>

                                <td class="text-center">
                                    <a href="view_loan.php?id=<?= $loan_id ?>&user_id=<?= (int)$client_id ?>"
                                       class="btn btn-sm bg-gradient-info action-btn">
                                        <i class="fas fa-eye me-1"></i>
                                        View
                                    </a>
                                </td>
                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-2x mb-3 d-block"></i>
                                No recent loans found.
                            </td>
                        </tr>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

</div>

<?php
$stmt->close();
require "footer.php";
?>