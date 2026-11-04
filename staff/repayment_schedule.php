<?php
declare(strict_types=1);

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
$phead = "Repayment Schedule";

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
        "OUTSTANDING" => "warning",
        "OVERDUE" => "danger",
        "APPROVED" => "info",
        "CLEARED" => "success",
        default => "secondary",
    };
}

$stmt = $conn->prepare("
    SELECT 
        id,
        amount,
        interest,
        interest_rate,
        loan_period,
        datetime,
        due_date,
        loan_balance,
        principal_balance,
        interest_balance,
        outstanding_balance,
        status
    FROM loan
    WHERE client_id = ?
    AND UPPER(status) IN ('OUTSTANDING', 'OVERDUE', 'APPROVED')
    ORDER BY id DESC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();

$loans = [];
$totalPrincipal = 0;
$totalInterest = 0;
$totalRepayable = 0;
$totalInstallments = 0;

while ($loan = $result->fetch_assoc()) {
    $amount = (float)($loan['amount'] ?? 0);
    $interestRate = (float)($loan['interest_rate'] ?? 0);
    $interest = (float)($loan['interest'] ?? 0);
    $period = (int)($loan['loan_period'] ?? 0);

    if ($interest <= 0 && $interestRate > 0) {
        $interest = ($amount * $interestRate) / 100;
    }

    if ($period <= 0) {
        $period = 1;
    }

    $loanBalance = (float)($loan['loan_balance'] ?? 0);
    $outstanding = (float)($loan['outstanding_balance'] ?? 0);
    $principalBalance = (float)($loan['principal_balance'] ?? 0);
    $interestBalance = (float)($loan['interest_balance'] ?? 0);

    if ($outstanding <= 0) {
        $outstanding = $principalBalance + $interestBalance;
    }

    if ($outstanding <= 0) {
        $outstanding = $loanBalance > 0 ? $loanBalance : ($amount + $interest);
    }

    $total = $amount + $interest;
    $monthly = $total / $period;

    $loan['computed_interest'] = $interest;
    $loan['computed_total'] = $total;
    $loan['computed_monthly'] = $monthly;
    $loan['computed_outstanding'] = $outstanding;
    $loan['computed_period'] = $period;

    $loans[] = $loan;

    $totalPrincipal += $amount;
    $totalInterest += $interest;
    $totalRepayable += $total;
    $totalInstallments += $period;
}

$stmt->close();
?>

<link rel="stylesheet" href="assets/css/loanpro.css">

<style>
.repay-plan-header,
.repay-plan-card {
    background: #fff;
    border-radius: 18px;
    border: 1px solid #edf2f7;
    box-shadow: 0 8px 25px rgba(15, 23, 42, .06);
    overflow: hidden;
}

.repay-plan-header {
    padding: 22px;
}

.repay-stat {
    background: #fff;
    border: 1px solid #edf2f7;
    border-left: 6px solid #344767;
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 8px 25px rgba(15, 23, 42, .06);
    min-height: 115px;
}

.repay-stat.primary { border-left-color: #0d6efd; }
.repay-stat.warning { border-left-color: #f59e0b; }
.repay-stat.info { border-left-color: #0dcaf0; }
.repay-stat.success { border-left-color: #198754; }

.repay-stat-icon {
    float: right;
    width: 42px;
    height: 42px;
    border-radius: 14px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
}

.repay-stat-title {
    font-size: 11px;
    text-transform: uppercase;
    color: #64748b;
    font-weight: 700;
    letter-spacing: .05em;
    margin-bottom: 8px;
}

.repay-stat-value {
    font-size: 20px;
    font-weight: 800;
    color: #1e293b;
}

.repay-plan-card table thead th {
    background: #f8fafc;
    color: #475569;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 14px;
    white-space: nowrap;
}

.repay-plan-card table tbody td {
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

.installment-pill {
    background: #ecfdf5;
    color: #047857;
    padding: 6px 10px;
    border-radius: 50px;
    font-weight: 700;
    font-size: 12px;
}

.action-btn {
    border-radius: 50px;
    font-size: 12px;
    font-weight: 700;
}
</style>

<div class="container-fluid py-4">

    <div class="repay-plan-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3 class="mb-1">
                    <i class="fas fa-calendar-alt me-2 text-primary"></i>
                    <?= e($phead) ?>
                </h3>
                <p class="text-muted mb-0">
                    View your expected monthly repayment installments and due dates.
                </p>
            </div>

            <button class="btn bg-gradient-success mb-0" onclick="download_table_as_csv('report')">
                <i class="fas fa-file-csv me-2"></i>
                Export CSV
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">
            <div class="repay-stat primary">
                <div class="repay-stat-icon bg-primary">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="repay-stat-title">Principal Amount</div>
                <div class="repay-stat-value"><?= money($totalPrincipal) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="repay-stat warning">
                <div class="repay-stat-icon bg-warning">
                    <i class="fas fa-percent"></i>
                </div>
                <div class="repay-stat-title">Total Interest</div>
                <div class="repay-stat-value"><?= money($totalInterest) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="repay-stat info">
                <div class="repay-stat-icon bg-info">
                    <i class="fas fa-scale-balanced"></i>
                </div>
                <div class="repay-stat-title">Total Repayable</div>
                <div class="repay-stat-value"><?= money($totalRepayable) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="repay-stat success">
                <div class="repay-stat-icon bg-success">
                    <i class="fas fa-list-ol"></i>
                </div>
                <div class="repay-stat-title">Installments</div>
                <div class="repay-stat-value"><?= number_format($totalInstallments) ?></div>
            </div>
        </div>

    </div>

    <div class="repay-plan-card">

        <div class="p-4 border-bottom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">Repayment Plan</h5>
                    <small class="text-muted">
                        <?= count($loans) ?> active loan(s) with repayment schedules.
                    </small>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table mb-0" id="report">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Loan</th>
                        <th>Installment</th>
                        <th>Principal</th>
                        <th>Interest</th>
                        <th>Total Repayable</th>
                        <th>Monthly Payment</th>
                        <th>Repayment Date</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($loans): ?>
                        <?php $counter = 1; ?>

                        <?php foreach ($loans as $loan): ?>
                            <?php
                                $loanId = (int)$loan['id'];
                                $amount = (float)$loan['amount'];
                                $interest = (float)$loan['computed_interest'];
                                $total = (float)$loan['computed_total'];
                                $monthly = (float)$loan['computed_monthly'];
                                $period = (int)$loan['computed_period'];
                                $status = strtoupper((string)$loan['status']);

                                try {
                                    $startDate = new DateTime((string)($loan['datetime'] ?? date('Y-m-d')));
                                } catch (Throwable $e) {
                                    $startDate = new DateTime();
                                }
                            ?>

                            <?php for ($i = 1; $i <= $period; $i++): ?>
                                <?php
                                    $repaymentDate = (clone $startDate)
                                        ->modify("+{$i} month")
                                        ->format("d M Y");
                                ?>

                                <tr>
                                    <td><?= $counter++ ?></td>

                                    <td>
                                        <span class="loan-pill">
                                            LOAN-<?= $loanId ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="installment-pill">
                                            <?= $i ?> / <?= $period ?>
                                        </span>
                                    </td>

                                    <td><?= money($amount) ?></td>

                                    <td><?= money($interest) ?></td>

                                    <td class="fw-bold"><?= money($total) ?></td>

                                    <td class="fw-bold text-primary">
                                        <?= money($monthly) ?>
                                    </td>

                                    <td><?= e($repaymentDate) ?></td>

                                    <td>
                                        <span class="badge bg-gradient-<?= badgeClass($status) ?>">
                                            <?= e($status) ?>
                                        </span>
                                    </td>
                                </tr>

                            <?php endfor; ?>

                        <?php endforeach; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-2x mb-3 d-block"></i>
                                No repayment schedule found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

            </table>
        </div>

    </div>

</div>

<script>
function download_table_as_csv(table_id) {
    const table = document.getElementById(table_id);
    if (!table) return;

    const rows = Array.from(table.querySelectorAll("tr"));
    const csv = [];

    rows.forEach(function (row) {
        const cols = Array.from(row.querySelectorAll("td, th"));
        const data = cols.map(function (col) {
            return '"' + col.innerText.replace(/"/g, '""').trim() + '"';
        });

        csv.push(data.join(","));
    });

    const csvFile = new Blob([csv.join("\n")], {
        type: "text/csv;charset=utf-8;"
    });

    const link = document.createElement("a");
    link.href = URL.createObjectURL(csvFile);
    link.download = "repayment_schedule.csv";
    link.style.display = "none";

    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require "footer.php"; ?>