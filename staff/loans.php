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
        "APPROVED" => "info",
        "PENDING" => "secondary",
        default => "dark",
    };
}

$sql = "
    SELECT 
        l.*,
        m.user_id,
        m.nin,
        m.phone AS member_phone,
        m.loan_limit,
        m.bank_name AS member_bank_name,
        m.bank_account AS member_bank_account
    FROM loan l
    LEFT JOIN members m ON m.user_id = l.client_id
    WHERE l.client_id = ?
    AND UPPER(COALESCE(l.status, '')) <> 'PENDING'
    ORDER BY l.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();

$loans = [];

$totalDisbursed = 0;
$totalPrincipal = 0;
$totalInterest = 0;
$totalOutstanding = 0;
$activeLoans = 0;
$clearedLoans = 0;

while ($row = $result->fetch_assoc()) {
    $principal = (float)($row['loan_balance'] ?? $row['loan_balance'] ?? 0);
    $interest = (float)($row['interest_balance'] ?? 0);
    $outstanding = (float)($row['outstanding_balance'] ?? 0);

    if ($outstanding <= 0) {
        $outstanding = $principal + $interest;
    }

    $row['computed_principal'] = $principal;
    $row['computed_interest'] = $interest;
    $row['computed_outstanding'] = $outstanding;

    $loans[] = $row;

    $totalDisbursed += (float)($row['amount'] ?? 0);
    $totalPrincipal += $principal;
    $totalInterest += $interest;
    $totalOutstanding += $outstanding;

    if (strtoupper((string)($row['status'] ?? "")) === "CLEARED") {
        $clearedLoans++;
    } else {
        $activeLoans++;
    }
}

$stmt->close();
?>

<link rel="stylesheet" href="assets/css/loanpro.css">

<style>
.loan-header {
    background: #fff;
    border-radius: 18px;
    padding: 22px;
    border: 1px solid #edf2f7;
    box-shadow: 0 8px 25px rgba(15, 23, 42, .06);
}

.loan-stat {
    background: #fff;
    border-radius: 18px;
    padding: 20px;
    border: 1px solid #edf2f7;
    border-left: 6px solid #344767;
    box-shadow: 0 8px 25px rgba(15, 23, 42, .06);
    min-height: 115px;
}

.loan-stat.primary { border-left-color: #0d6efd; }
.loan-stat.warning { border-left-color: #f59e0b; }
.loan-stat.info { border-left-color: #0dcaf0; }
.loan-stat.danger { border-left-color: #dc3545; }
.loan-stat.success { border-left-color: #198754; }
.loan-stat.dark { border-left-color: #344767; }

.loan-stat-title {
    font-size: 11px;
    text-transform: uppercase;
    color: #64748b;
    font-weight: 700;
    letter-spacing: .05em;
    margin-bottom: 8px;
}

.loan-stat-value {
    font-size: 20px;
    font-weight: 800;
    color: #1e293b;
}

.loan-stat-icon {
    float: right;
    width: 42px;
    height: 42px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
}

.loan-card {
    background: #fff;
    border-radius: 18px;
    border: 1px solid #edf2f7;
    box-shadow: 0 8px 25px rgba(15, 23, 42, .06);
    overflow: hidden;
}

.loan-card table thead th {
    background: #f8fafc;
    color: #475569;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 14px;
    white-space: nowrap;
}

.loan-card table tbody td {
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

    <div class="loan-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3 class="mb-1">Loans History</h3>
                <p class="text-muted mb-0">
                    Track your loans, balances, repayment status, and topup requests.
                </p>
            </div>

            <button class="btn bg-gradient-success mb-0" onclick="download_table_as_csv('loansTable')">
                <i class="fas fa-file-csv me-2"></i> Export CSV
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">
            <div class="loan-stat primary">
                <div class="loan-stat-icon bg-primary">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="loan-stat-title">Total Disbursed</div>
                <div class="loan-stat-value"><?= money($totalDisbursed) ?></div>
            </div>
        </div>

       <!-- <div class="col-xl-3 col-md-6">
            <div class="loan-stat warning">
                <div class="loan-stat-icon bg-warning">
                    <i class="fas fa-scale-balanced"></i>
                </div>
                <div class="loan-stat-title">Principal Balance</div>
                <div class="loan-stat-value"><?= money($totalPrincipal) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="loan-stat info">
                <div class="loan-stat-icon bg-info">
                    <i class="fas fa-percent"></i>
                </div>
                <div class="loan-stat-title">Interest Balance</div>
                <div class="loan-stat-value"><?= money($totalInterest) ?></div>
            </div>
        </div> -->

        <div class="col-xl-3 col-md-6">
            <div class="loan-stat danger">
                <div class="loan-stat-icon bg-danger">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="loan-stat-title">Total Outstanding</div>
                <div class="loan-stat-value"><?= money($totalOutstanding) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="loan-stat success">
                <div class="loan-stat-icon bg-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="loan-stat-title">Cleared Loans</div>
                <div class="loan-stat-value"><?= (int)$clearedLoans ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="loan-stat dark">
                <div class="loan-stat-icon bg-dark">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="loan-stat-title">Active Loans</div>
                <div class="loan-stat-value"><?= (int)$activeLoans ?></div>
            </div>
        </div>

    </div>

    <div class="loan-card">

        <div class="p-4 border-bottom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">Loan Records</h5>
                    <small class="text-muted">
                        <?= count($loans) ?> loan record(s) found
                    </small>
                </div>

                <span class="badge bg-gradient-primary">
                    <?= count($loans) ?> Loans
                </span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table mb-0" id="loansTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Loan No.</th>
                        <th>Amount</th>
                        <th>Principal</th>
                        <th>Interest</th>
                        <th>Outstanding</th>
                        <th>Status</th>
                        <th>Issue Date</th>
                        <th>Due Date</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!empty($loans)): ?>
                        <?php $i = 1; ?>

                        <?php foreach ($loans as $loan): ?>
                            <?php
                                $loan_id = (int)$loan['id'];
                                $loan_no = "LOAN-" . $loan_id;
                                $status = strtoupper((string)($loan['status'] ?? ""));
                                $status_class = badgeClass($status);
                                $issue_date = $loan['datetime'] ?? "-";
                                $due_date = $loan['due_date'] ?? "-";
                                $user_id = (int)($loan['client_id'] ?? $client_id);

                                $can_topup = in_array($status, ["OUTSTANDING", "OVERDUE"], true)
                                    && (float)$loan['computed_outstanding'] > 0;
                            ?>

                            <tr>
                                <td><?= $i++ ?></td>

                                <td>
                                    <span class="loan-pill"><?= e($loan_no) ?></span>
                                </td>

                                <td class="fw-bold"><?= money($loan['amount'] ?? 0) ?></td>

                                <td><?= money($loan['computed_principal']) ?></td>

                                <td><?= money($loan['computed_interest']) ?></td>

                                <td class="fw-bold text-danger">
                                    <?= money($loan['computed_outstanding']) ?>
                                </td>

                                <td>
                                    <span class="badge bg-gradient-<?= e($status_class) ?>">
                                        <?= e($status) ?>
                                    </span>
                                </td>

                                <td><?= e($issue_date) ?></td>

                                <td><?= e($due_date) ?></td>

                                <td class="text-center">
                                    <a href="view_loan.php?id=<?= $loan_id ?>&user_id=<?= $user_id ?>"
                                       class="btn btn-sm bg-gradient-info action-btn">
                                        <i class="fas fa-eye me-1"></i> View
                                    </a>

                                    <?php if ($can_topup): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm bg-gradient-warning action-btn topup-btn"
                                            data-loan-id="<?= $loan_id ?>"
                                            data-loan-number="<?= e($loan_no) ?>"
                                            data-principal="<?= e((string)$loan['computed_principal']) ?>"
                                            data-interest="<?= e((string)$loan['computed_interest']) ?>"
                                            data-outstanding="<?= e((string)$loan['computed_outstanding']) ?>"
                                        >
                                            <i class="fas fa-plus-circle me-1"></i> Topup
                                        </button>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark">No Topup</span>
                                    <?php endif; ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-2x mb-3 d-block"></i>
                                No loan records found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

            </table>
        </div>

    </div>

</div>
<div class="modal fade" id="topupModal" tabindex="-1" role="dialog" aria-labelledby="topupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">

        <form method="POST" action="process-loan-topup.php" class="modal-content border-0 shadow-lg">

            <div class="modal-header bg-warning">
                <h5 class="modal-title text-dark" id="topupModalLabel">
                    <i class="fas fa-plus-circle me-2"></i>
                    Request Loan Topup
                </h5>

                <button type="button" class="btn-close close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">

                <input type="hidden" name="loan_id" id="topup_loan_id">
                <input type="hidden" name="client_id" value="<?= (int)$client_id ?>">

                <div class="mb-3">
                    <label class="form-label">Loan Number</label>
                    <input type="text" class="form-control" id="topup_loan_number" readonly>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Principal Balance</label>
                        <input type="text" class="form-control" id="topup_principal" readonly>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Interest Balance</label>
                        <input type="text" class="form-control" id="topup_interest" readonly>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Outstanding Balance</label>
                    <input type="text" class="form-control" id="topup_outstanding" readonly>
                </div>

                <div class="mb-3">
                    <label class="form-label">Requested Topup Amount</label>
                    <input type="number" step="0.01" min="1" name="requested_amount" class="form-control" required>
                    <small class="text-muted">
                        Existing outstanding balance will be cleared first.
                    </small>
                </div>

                <div class="mb-3">
                    <label class="form-label">Reason for Topup</label>
                    <textarea name="reason" class="form-control" rows="3" required></textarea>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-dismiss="modal">
                    Close
                </button>

                <button type="submit" class="btn bg-gradient-warning">
                    Submit Request
                </button>
            </div>

        </form>

    </div>
</div>
<!-- JQUERY -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<!-- BOOTSTRAP 5 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/loanpro.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {

    document.querySelectorAll(".topup-btn").forEach(function (button) {

        button.addEventListener("click", function () {

            const modalEl = document.getElementById("topupModal");

            document.getElementById("topup_loan_id").value =
                this.dataset.loanId || "";

            document.getElementById("topup_loan_number").value =
                this.dataset.loanNumber || "";

            document.getElementById("topup_principal").value =
                parseFloat(this.dataset.principal || 0).toFixed(2);

            document.getElementById("topup_interest").value =
                parseFloat(this.dataset.interest || 0).toFixed(2);

            document.getElementById("topup_outstanding").value =
                parseFloat(this.dataset.outstanding || 0).toFixed(2);

            if (typeof bootstrap !== "undefined" && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
                return;
            }

            if (typeof $ !== "undefined" && typeof $("#topupModal").modal === "function") {
                $("#topupModal").modal("show");
                return;
            }

            alert("Bootstrap modal JavaScript is not loaded.");
        });

    });

});
</script>

<?php require "footer.php"; ?>