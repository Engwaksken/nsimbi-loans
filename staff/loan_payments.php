<?php
declare(strict_types=1);

require "nav-head.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    exit("Database connection not established.");
}

if (empty($_SESSION['logged_in'])) {
    exit("User not logged in.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
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
        "REJECTED" => "danger",
        "REVIEWED" => "primary",
        default => "dark",
    };
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function getColumns(mysqli $conn, string $table): array
{
    if (!tableExists($conn, $table)) {
        return [];
    }

    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM `$table`");

    while ($row = $res->fetch_assoc()) {
        $cols[$row['Field']] = true;
    }

    return $cols;
}

function pick(array $columns, array $names, ?string $default = null): ?string
{
    foreach ($names as $name) {
        if (isset($columns[$name])) {
            return $name;
        }
    }

    return $default;
}

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$loanCols = getColumns($conn, "loan");
$payCols = getColumns($conn, "payments");
$requestCols = getColumns($conn, "loan_repayment_requests");

if (!$loanCols) {
    exit("Loan table not found.");
}

if (!$payCols) {
    exit("Payments table not found.");
}

$loanClientField = pick($loanCols, ["client_id", "user_id"], "client_id");
$loanStatusField = pick($loanCols, ["status"], "status");
$loanAmountField = pick($loanCols, ["amount"], "amount");
$loanInterestField = pick($loanCols, ["interest"], null);
$loanPrincipalField = pick($loanCols, ["principal_balance", "loan_balance"], null);
$loanInterestBalanceField = pick($loanCols, ["interest_balance"], null);
$loanOutstandingField = pick($loanCols, ["outstanding_balance"], null);
$loanDueDateField = pick($loanCols, ["due_date"], null);
$loanDateField = pick($loanCols, ["datetime", "created_at"], null);

$payUserField = pick($payCols, ["user_id", "client_id"], "user_id");
$payAmountField = pick($payCols, ["amount", "paid_amount"], "amount");
$payDateField = pick($payCols, ["datetime", "created_at", "payment_date", "date_paid"], "id");
$payReferenceField = pick($payCols, ["reference_number", "reference", "transaction_number", "rec"], null);
$payMethodField = pick($payCols, ["payment_method", "method"], null);

$hasRequests = !empty($requestCols);

$requestAmountField = pick($requestCols, ["requested_amount", "amount"], "amount");
$requestMethodField = pick($requestCols, ["payment_method"], "payment_method");
$requestReferenceField = pick($requestCols, ["reference_number", "reference"], "reference_number");
$requestStatusField = pick($requestCols, ["status"], "status");
$requestDateField = pick($requestCols, ["created_at", "datetime"], "created_at");
$requestReceiptField = pick($requestCols, ["receipt_file", "receipt_file_path", "file_path"], null);

$repayableLoans = [];

$loanSql = "
    SELECT
        l.id AS loan_id,
        l.`$loanClientField` AS client_id,
        l.`$loanAmountField` AS amount,
        " . ($loanInterestField ? "COALESCE(l.`$loanInterestField`, 0)" : "0") . " AS interest,
        " . ($loanPrincipalField ? "COALESCE(l.`$loanPrincipalField`, 0)" : "0") . " AS principal_balance,
        " . ($loanInterestBalanceField ? "COALESCE(l.`$loanInterestBalanceField`, 0)" : "0") . " AS interest_balance,
        " . ($loanOutstandingField ? "COALESCE(l.`$loanOutstandingField`, 0)" : "0") . " AS outstanding_balance,
        COALESCE(l.`$loanStatusField`, '') AS status,
        " . ($loanDueDateField ? "l.`$loanDueDateField`" : "NULL") . " AS due_date,
        " . ($loanDateField ? "l.`$loanDateField`" : "NULL") . " AS loan_date
    FROM loan l
    WHERE l.`$loanClientField` = ?
    AND UPPER(COALESCE(l.`$loanStatusField`, '')) IN ('APPROVED', 'OUTSTANDING', 'OVERDUE')
    ORDER BY l.id DESC
";

$stmt = $conn->prepare($loanSql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();

while ($loan = $res->fetch_assoc()) {
    $principal = (float)($loan['principal_balance'] ?? 0);
    $interestBalance = (float)($loan['interest_balance'] ?? 0);
    $outstanding = (float)($loan['outstanding_balance'] ?? 0);

    if ($outstanding <= 0) {
        $outstanding = $principal + $interestBalance;
    }

    $loan['computed_balance'] = $outstanding;
    $repayableLoans[] = $loan;
}

$stmt->close();

$totalRepayable = 0;
$totalInterestBalance = 0;

foreach ($repayableLoans as $loan) {
    $totalRepayable += (float)$loan['computed_balance'];
    $totalInterestBalance += (float)$loan['interest_balance'];
}

$paymentRows = [];

$paymentSql = "
    SELECT
        p.*,
        COALESCE(l.`$loanStatusField`, '') AS loan_status,
        " . ($loanPrincipalField ? "COALESCE(l.`$loanPrincipalField`, 0)" : "0") . " AS principal_balance,
        " . ($loanInterestBalanceField ? "COALESCE(l.`$loanInterestBalanceField`, 0)" : "0") . " AS interest_balance,
        " . ($loanOutstandingField ? "COALESCE(l.`$loanOutstandingField`, 0)" : "0") . " AS outstanding_balance
    FROM payments p
    LEFT JOIN loan l ON l.id = p.loan_id
    WHERE p.`$payUserField` = ?
    ORDER BY p.id DESC
";

$stmt = $conn->prepare($paymentSql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();

while ($payment = $res->fetch_assoc()) {
    $principal = (float)($payment['principal_balance'] ?? 0);
    $interestBalance = (float)($payment['interest_balance'] ?? 0);
    $outstanding = (float)($payment['outstanding_balance'] ?? 0);

    if ($outstanding <= 0) {
        $outstanding = $principal + $interestBalance;
    }

    $payment['computed_balance'] = $outstanding;
    $paymentRows[] = $payment;
}

$stmt->close();

$totalPaid = 0;

foreach ($paymentRows as $payment) {
    $totalPaid += (float)($payment[$payAmountField] ?? 0);
}

$requestRows = [];

if ($hasRequests) {
    $requestSql = "
        SELECT
            r.*,
            COALESCE(l.`$loanStatusField`, '') AS loan_status,
            " . ($loanPrincipalField ? "COALESCE(l.`$loanPrincipalField`, 0)" : "0") . " AS principal_balance,
            " . ($loanInterestBalanceField ? "COALESCE(l.`$loanInterestBalanceField`, 0)" : "0") . " AS interest_balance,
            " . ($loanOutstandingField ? "COALESCE(l.`$loanOutstandingField`, 0)" : "0") . " AS outstanding_balance
        FROM loan_repayment_requests r
        LEFT JOIN loan l ON l.id = r.loan_id
        WHERE r.client_id = ?
        ORDER BY r.id DESC
    ";

    $stmt = $conn->prepare($requestSql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $principal = (float)($row['principal_balance'] ?? 0);
        $interestBalance = (float)($row['interest_balance'] ?? 0);
        $outstanding = (float)($row['outstanding_balance'] ?? 0);

        if ($outstanding <= 0) {
            $outstanding = $principal + $interestBalance;
        }

        $row['computed_balance'] = $outstanding;
        $requestRows[] = $row;
    }

    $stmt->close();
}
?>

<link rel="stylesheet" href="assets/css/loanpro.css">

<style>
.loan-repay-header,
.loan-repay-card {
    background: #fff;
    border-radius: 18px;
    border: 1px solid #edf2f7;
    box-shadow: 0 8px 25px rgba(15, 23, 42, .06);
    overflow: hidden;
}

.loan-repay-header {
    padding: 22px;
}

.loan-stat {
    background: #fff;
    border: 1px solid #edf2f7;
    border-left: 6px solid #344767;
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 8px 25px rgba(15, 23, 42, .06);
    min-height: 115px;
}

.loan-stat.primary { border-left-color: #0d6efd; }
.loan-stat.warning { border-left-color: #f59e0b; }
.loan-stat.info { border-left-color: #0dcaf0; }
.loan-stat.success { border-left-color: #198754; }

.loan-stat-icon {
    float: right;
    width: 42px;
    height: 42px;
    border-radius: 14px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
}

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

.loan-tabs {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    padding: 18px 20px;
    border-bottom: 1px solid #edf2f7;
}

.loan-tab-btn {
    border: 0;
    background: #f1f5f9;
    color: #475569;
    padding: 10px 16px;
    border-radius: 50px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.loan-tab-btn.active {
    background: #0d6efd;
    color: #fff;
}

.loan-tab-pane {
    display: none;
}

.loan-tab-pane.active {
    display: block;
}

.loan-repay-card table thead th {
    background: #f8fafc;
    color: #475569;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 14px;
    white-space: nowrap;
}

.loan-repay-card table tbody td {
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
    font-size: 12px;
    font-weight: 700;
}
</style>

<div class="container-fluid py-4">

    <div class="loan-repay-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3 class="mb-1">Loan Repayments</h3>
                <p class="text-muted mb-0">Manage repayments, pending approvals, and payment history.</p>
            </div>

            <button type="button"
                    class="btn bg-gradient-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#repaymentModal"
                    onclick="openRepaymentModalFallback()">
                <i class="fas fa-plus-circle me-2"></i>
                Make Repayment
            </button>
        </div>
    </div>

    <?php if ($success !== ""): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= e($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="loan-stat primary">
                <div class="loan-stat-icon bg-primary">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div class="loan-stat-title">Repayable Loans</div>
                <div class="loan-stat-value"><?= count($repayableLoans) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="loan-stat warning">
                <div class="loan-stat-icon bg-warning">
                    <i class="fas fa-scale-balanced"></i>
                </div>
                <div class="loan-stat-title">Outstanding Balance</div>
                <div class="loan-stat-value"><?= money($totalRepayable) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="loan-stat info">
                <div class="loan-stat-icon bg-info">
                    <i class="fas fa-percent"></i>
                </div>
                <div class="loan-stat-title">Interest Balance</div>
                <div class="loan-stat-value"><?= money($totalInterestBalance) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="loan-stat success">
                <div class="loan-stat-icon bg-success">
                    <i class="fas fa-credit-card"></i>
                </div>
                <div class="loan-stat-title">Approved Payments</div>
                <div class="loan-stat-value"><?= money($totalPaid) ?></div>
            </div>
        </div>
    </div>

    <div class="loan-repay-card">

        <div class="loan-tabs">
            <button type="button" class="loan-tab-btn active" data-tab-target="active-loans-pane">
                <i class="fas fa-wallet me-2"></i> Active Loans
            </button>

            <?php if ($hasRequests): ?>
                <button type="button" class="loan-tab-btn" data-tab-target="requests-pane">
                    <i class="fas fa-clock me-2"></i> Repayment Requests
                </button>
            <?php endif; ?>

            <button type="button" class="loan-tab-btn" data-tab-target="payments-pane">
                <i class="fas fa-credit-card me-2"></i> Approved Payments
            </button>
        </div>

        <div id="active-loans-pane" class="loan-tab-pane active">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Loan No.</th>
                        <th>Amount</th>
                        <th>Principal</th>
                        <th>Interest</th>
                        <th>Outstanding</th>
                        <th>Status</th>
                        <th>Due Date</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if ($repayableLoans): ?>
                        <?php $i = 1; ?>
                        <?php foreach ($repayableLoans as $loan): ?>
                            <?php $status = strtoupper((string)$loan['status']); ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><span class="loan-pill">LOAN-<?= (int)$loan['loan_id'] ?></span></td>
                                <td class="fw-bold"><?= money($loan['amount']) ?></td>
                                <td><?= money($loan['principal_balance']) ?></td>
                                <td><?= money($loan['interest_balance']) ?></td>
                                <td class="fw-bold text-danger"><?= money($loan['computed_balance']) ?></td>
                                <td>
                                    <span class="badge bg-gradient-<?= badgeClass($status) ?>">
                                        <?= e($status) ?>
                                    </span>
                                </td>
                                <td><?= e($loan['due_date'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">No repayable loan found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($hasRequests): ?>
            <div id="requests-pane" class="loan-tab-pane">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Loan</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Receipt</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php if ($requestRows): ?>
                            <?php $i = 1; ?>
                            <?php foreach ($requestRows as $r): ?>
                                <?php
                                $status = strtoupper((string)($r[$requestStatusField] ?? ""));
                                $receipt = $requestReceiptField ? (string)($r[$requestReceiptField] ?? "") : "";
                                ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td><span class="loan-pill">LOAN-<?= (int)($r['loan_id'] ?? 0) ?></span></td>
                                    <td class="fw-bold"><?= money($r[$requestAmountField] ?? 0) ?></td>
                                    <td><?= e($r[$requestMethodField] ?? "") ?></td>
                                    <td><?= e($r[$requestReferenceField] ?? "") ?></td>
                                    <td>
                                        <span class="badge bg-gradient-<?= badgeClass($status) ?>">
                                            <?= e($status) ?>
                                        </span>
                                    </td>
                                    <td><?= e($r[$requestDateField] ?? "") ?></td>
                                    <td>
                                        <?php if ($receipt !== ""): ?>
                                            <a href="<?= e($receipt) ?>" target="_blank" class="btn btn-sm bg-gradient-info action-btn">View</a>
                                        <?php else: ?>
                                            <span class="text-muted">No file</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">No repayment requests found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div id="payments-pane" class="loan-tab-pane">
            <div class="table-responsive">
                <table class="table mb-0" id="report">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Loan</th>
                        <th>Paid Amount</th>
                        <th>Reference</th>
                        <th>Method</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if ($paymentRows): ?>
                        <?php $i = 1; ?>
                        <?php foreach ($paymentRows as $payment): ?>
                            <?php
                            $status = strtoupper((string)($payment['loan_status'] ?? "UNKNOWN"));
                            $loanId = (int)($payment['loan_id'] ?? 0);
                            ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><span class="loan-pill">LOAN-<?= $loanId ?></span></td>
                                <td class="fw-bold"><?= money($payment[$payAmountField] ?? 0) ?></td>
                                <td><?= e($payReferenceField ? ($payment[$payReferenceField] ?? "") : "") ?></td>
                                <td><?= e($payMethodField ? ($payment[$payMethodField] ?? "") : "") ?></td>
                                <td class="fw-bold text-danger"><?= money($payment['computed_balance'] ?? 0) ?></td>
                                <td>
                                    <span class="badge bg-gradient-<?= badgeClass($status) ?>">
                                        <?= e($status) ?>
                                    </span>
                                </td>
                                <td><?= e($payment[$payDateField] ?? "") ?></td>
                                <td>
                                    <a href="view_loan.php?id=<?= $loanId ?>&user_id=<?= (int)$client_id ?>"
                                       class="btn btn-sm bg-gradient-info action-btn">
                                        <i class="fas fa-eye me-1"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">No approved payment records found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<div class="modal fade" id="repaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 shadow-lg" action="process_loan_repayment.php" method="POST" enctype="multipart/form-data">

            <div class="modal-header bg-primary">
                <h5 class="modal-title text-white">
                    <i class="fas fa-credit-card me-2"></i>
                    Make Loan Repayment
                </h5>

                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="closeRepaymentModalFallback()"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="client_id" value="<?= (int)$client_id ?>">

                <div class="mb-3">
                    <label class="form-label">Loan</label>
                    <select name="loan_id" class="form-control" required>
                        <option value="">Select loan</option>
                        <?php foreach ($repayableLoans as $loan): ?>
                            <option value="<?= (int)$loan['loan_id'] ?>">
                                LOAN-<?= (int)$loan['loan_id'] ?> - Outstanding <?= money($loan['computed_balance']) ?> - <?= e($loan['status']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Amount Paid</label>
                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-control" required>
                        <option value="">Select method</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Bank Deposit">Bank Deposit</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cash">Cash</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Transaction / Reference Number</label>
                    <input type="text" name="reference_number" class="form-control" placeholder="Enter transaction reference">
                </div>

                <div class="mb-3">
                    <label class="form-label">Receipt / Transaction Screenshot</label>
                    <input type="file" name="receipt_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.webp" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes"></textarea>
                </div>

                <div class="alert alert-info mb-0">
                    Your repayment will wait for approval before the loan balance is reduced.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" onclick="closeRepaymentModalFallback()">
                    Close
                </button>

                <button type="submit" class="btn bg-gradient-primary">
                    Submit Repayment
                </button>
            </div>

        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const tabButtons = document.querySelectorAll(".loan-tab-btn");
    const tabPanes = document.querySelectorAll(".loan-tab-pane");

    tabButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            const targetId = this.getAttribute("data-tab-target");

            tabButtons.forEach(btn => btn.classList.remove("active"));
            tabPanes.forEach(pane => pane.classList.remove("active"));

            this.classList.add("active");

            const targetPane = document.getElementById(targetId);
            if (targetPane) {
                targetPane.classList.add("active");
            }
        });
    });
});

function openRepaymentModalFallback() {
    const modal = document.getElementById("repaymentModal");

    if (typeof bootstrap !== "undefined" && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modal).show();
        return;
    }

    if (typeof $ !== "undefined" && typeof $("#repaymentModal").modal === "function") {
        $("#repaymentModal").modal("show");
        return;
    }

    modal.style.display = "block";
    modal.classList.add("show");
    modal.removeAttribute("aria-hidden");
    document.body.classList.add("modal-open");

    let backdrop = document.createElement("div");
    backdrop.className = "modal-backdrop fade show repayment-fallback-backdrop";
    document.body.appendChild(backdrop);
}

function closeRepaymentModalFallback() {
    const modal = document.getElementById("repaymentModal");

    modal.style.display = "none";
    modal.classList.remove("show");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("modal-open");

    document.querySelectorAll(".repayment-fallback-backdrop").forEach(el => el.remove());
}
</script>

<?php require "footer.php"; ?>