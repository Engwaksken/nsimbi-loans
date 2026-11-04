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

$stmt = $conn->prepare("
    SELECT 
        id,
        amount,
        interest,
        interest_rate,
        loan_period,
        loan_balance,
        status,
        datetime,
        due_date
    FROM loan
    WHERE client_id = ?
    AND UPPER(status) = 'PENDING'
    ORDER BY id DESC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();

$pendingLoans = [];
$totalAmount = 0;
$totalInterest = 0;
$totalBalance = 0;

while ($row = $result->fetch_assoc()) {
    $pendingLoans[] = $row;
    $totalAmount += (float)($row['amount'] ?? 0);
    $totalInterest += (float)($row['interest'] ?? 0);
    $totalBalance += (float)($row['loan_balance'] ?? 0);
}

$stmt->close();
?>

<link rel="stylesheet" href="assets/css/loanpro.css">

<style>
.pending-header,
.pending-card {
    background: #fff;
    border-radius: 18px;
    border: 1px solid #edf2f7;
    box-shadow: 0 8px 25px rgba(15,23,42,.06);
    overflow: hidden;
}

.pending-header {
    padding: 22px;
}

.pending-stat {
    background: #fff;
    border: 1px solid #edf2f7;
    border-left: 6px solid #344767;
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 8px 25px rgba(15,23,42,.06);
    min-height: 110px;
}

.pending-stat.primary { border-left-color: #0d6efd; }
.pending-stat.warning { border-left-color: #f59e0b; }
.pending-stat.info { border-left-color: #0dcaf0; }
.pending-stat.danger { border-left-color: #dc3545; }

.pending-stat-title {
    font-size: 11px;
    text-transform: uppercase;
    color: #64748b;
    font-weight: 700;
    letter-spacing: .05em;
    margin-bottom: 8px;
}

.pending-stat-value {
    font-size: 20px;
    font-weight: 800;
    color: #1e293b;
}

.pending-stat-icon {
    float: right;
    width: 42px;
    height: 42px;
    border-radius: 14px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
}

.pending-card table thead th {
    background: #f8fafc;
    color: #475569;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 14px;
    white-space: nowrap;
}

.pending-card table tbody td {
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

    <div class="pending-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3 class="mb-1">
                    <i class="fas fa-clock me-2 text-warning"></i>
                    Pending Loans
                </h3>
                <p class="text-muted mb-0">
                    View, edit, or cancel your pending loan applications before approval.
                </p>
            </div>

            <a href="requesting_loan.php" class="btn bg-gradient-primary">
                <i class="fas fa-plus-circle me-1"></i>
                New Loan Request
            </a>
        </div>
    </div>

    <div id="pageAlert" class="alert d-none"></div>

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">
            <div class="pending-stat primary">
                <div class="pending-stat-icon bg-primary">
                    <i class="fas fa-list"></i>
                </div>
                <div class="pending-stat-title">Pending Requests</div>
                <div class="pending-stat-value"><?= count($pendingLoans) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="pending-stat warning">
                <div class="pending-stat-icon bg-warning">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="pending-stat-title">Total Amount</div>
                <div class="pending-stat-value"><?= money($totalAmount) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="pending-stat info">
                <div class="pending-stat-icon bg-info">
                    <i class="fas fa-percent"></i>
                </div>
                <div class="pending-stat-title">Total Interest</div>
                <div class="pending-stat-value"><?= money($totalInterest) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="pending-stat danger">
                <div class="pending-stat-icon bg-danger">
                    <i class="fas fa-scale-balanced"></i>
                </div>
                <div class="pending-stat-title">Total Balance</div>
                <div class="pending-stat-value"><?= money($totalBalance) ?></div>
            </div>
        </div>

    </div>

    <div class="pending-card">

        <div class="p-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-1">Pending Loan Applications</h5>
                <small class="text-muted">
                    <?= count($pendingLoans) ?> pending record(s) found.
                </small>
            </div>

            <button class="btn btn-sm bg-gradient-success" onclick="download_table_as_csv('pendingLoansTable')">
                <i class="fas fa-file-csv me-1"></i>
                Export CSV
            </button>
        </div>

        <div class="table-responsive">
            <table class="table mb-0" id="pendingLoansTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Loan</th>
                        <th>Amount</th>
                        <th>Interest</th>
                        <th>Period</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Request Date</th>
                        <th>Due Date</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($pendingLoans): ?>
                        <?php $i = 1; ?>
                        <?php foreach ($pendingLoans as $loan): ?>
                            <?php
                                $loanId = (int)$loan['id'];
                                $amount = (float)$loan['amount'];
                                $interest = (float)$loan['interest'];
                                $period = (int)$loan['loan_period'];
                                $balance = (float)$loan['loan_balance'];
                            ?>

                            <tr id="loan-row-<?= $loanId ?>">
                                <td><?= $i++ ?></td>

                                <td>
                                    <span class="loan-pill">LOAN-<?= $loanId ?></span>
                                </td>

                                <td class="fw-bold"><?= money($amount) ?></td>
                                <td><?= money($interest) ?></td>
                                <td><?= $period ?> Month(s)</td>
                                <td class="fw-bold text-danger"><?= money($balance) ?></td>

                                <td>
                                    <span class="badge bg-gradient-warning">
                                        <?= e($loan['status']) ?>
                                    </span>
                                </td>

                                <td><?= e($loan['datetime'] ?? '-') ?></td>
                                <td><?= e($loan['due_date'] ?? '-') ?></td>

                                <td class="text-center">
                                    <button
                                        type="button"
                                        class="btn btn-sm bg-gradient-info action-btn"
                                        onclick='openEditModal(<?= json_encode([
                                            "id" => $loanId,
                                            "amount" => $amount,
                                            "loan_period" => $period
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                    >
                                        <i class="fas fa-edit me-1"></i>
                                        Edit
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-sm bg-gradient-danger action-btn"
                                        onclick="openCancelModal(<?= $loanId ?>)"
                                    >
                                        <i class="fas fa-trash me-1"></i>
                                        Cancel
                                    </button>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-2x mb-3 d-block"></i>
                                No pending loans found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

            </table>
        </div>

    </div>

</div>

<div class="modal fade" id="editLoanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="editLoanForm" class="modal-content border-0 shadow-lg">

            <div class="modal-header bg-info">
                <h5 class="modal-title text-white">
                    <i class="fas fa-edit me-2"></i>
                    Edit Pending Loan
                </h5>

                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="closeModalFallback('editLoanModal')"></button>
            </div>

            <div class="modal-body">
                <div id="editLoanAlert" class="alert d-none"></div>

                <input type="hidden" name="loan_id" id="edit_loan_id">

                <div class="mb-3">
                    <label class="form-label">Loan Amount</label>
                    <input type="number" step="0.01" min="1" name="amount" id="edit_amount" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Loan Period</label>
                    <select name="loan_period" id="edit_loan_period" class="form-select" required>
                        <option value="">Select period</option>
                        <option value="1">1 Month</option>
                        <option value="2">2 Months</option>
                        <option value="3">3 Months</option>
                    </select>
                </div>

                <div class="alert alert-info mb-0">
                    Interest and loan balance will be recalculated automatically.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" onclick="closeModalFallback('editLoanModal')">
                    Close
                </button>

                <button type="submit" class="btn bg-gradient-info">
                    Save Changes
                </button>
            </div>

        </form>
    </div>
</div>

<div class="modal fade" id="cancelLoanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="cancelLoanForm" class="modal-content border-0 shadow-lg">

            <div class="modal-header bg-danger">
                <h5 class="modal-title text-white">
                    <i class="fas fa-trash me-2"></i>
                    Cancel Pending Loan
                </h5>

                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="closeModalFallback('cancelLoanModal')"></button>
            </div>

            <div class="modal-body">
                <div id="cancelLoanAlert" class="alert d-none"></div>

                <input type="hidden" name="loan_id" id="cancel_loan_id">

                <p class="mb-0">
                    Are you sure you want to cancel this pending loan request?
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" onclick="closeModalFallback('cancelLoanModal')">
                    No
                </button>

                <button type="submit" class="btn bg-gradient-danger">
                    Yes, Cancel
                </button>
            </div>

        </form>
    </div>
</div>

<script src="js/loanpro.js"></script>

<script>
function showBox(id, message, type = "success") {
    const box = document.getElementById(id);
    if (!box) return;

    box.className = "alert alert-" + type;
    box.textContent = message;
    box.classList.remove("d-none");
}

function hideBox(id) {
    const box = document.getElementById(id);
    if (!box) return;

    box.classList.add("d-none");
    box.textContent = "";
}

function openModalFallback(id) {
    const modal = document.getElementById(id);

    if (typeof bootstrap !== "undefined" && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modal).show();
        return;
    }

    if (typeof $ !== "undefined" && typeof $("#" + id).modal === "function") {
        $("#" + id).modal("show");
        return;
    }

    modal.style.display = "block";
    modal.classList.add("show");
    modal.removeAttribute("aria-hidden");
    document.body.classList.add("modal-open");

    const backdrop = document.createElement("div");
    backdrop.className = "modal-backdrop fade show custom-modal-backdrop";
    document.body.appendChild(backdrop);
}

function closeModalFallback(id) {
    const modal = document.getElementById(id);

    if (typeof bootstrap !== "undefined" && bootstrap.Modal) {
        const instance = bootstrap.Modal.getInstance(modal);
        if (instance) instance.hide();
        return;
    }

    if (typeof $ !== "undefined" && typeof $("#" + id).modal === "function") {
        $("#" + id).modal("hide");
        return;
    }

    modal.style.display = "none";
    modal.classList.remove("show");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("modal-open");

    document.querySelectorAll(".custom-modal-backdrop").forEach(el => el.remove());
}

function openEditModal(loan) {
    hideBox("editLoanAlert");

    document.getElementById("edit_loan_id").value = loan.id || "";
    document.getElementById("edit_amount").value = loan.amount || "";
    document.getElementById("edit_loan_period").value = loan.loan_period || "";

    openModalFallback("editLoanModal");
}

function openCancelModal(loanId) {
    hideBox("cancelLoanAlert");
    document.getElementById("cancel_loan_id").value = loanId || "";
    openModalFallback("cancelLoanModal");
}

document.getElementById("editLoanForm").addEventListener("submit", function (event) {
    event.preventDefault();

    const formData = new FormData(this);

    fetch("ajax_update_pending_loan.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            showBox("editLoanAlert", data.message || "Update failed.", "danger");
            return;
        }

        showBox("editLoanAlert", data.message, "success");

        setTimeout(() => {
            closeModalFallback("editLoanModal");
            window.location.reload();
        }, 900);
    })
    .catch(() => {
        showBox("editLoanAlert", "Network error while updating loan.", "danger");
    });
});

document.getElementById("cancelLoanForm").addEventListener("submit", function (event) {
    event.preventDefault();

    const formData = new FormData(this);

    fetch("ajax_cancel_pending_loan.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            showBox("cancelLoanAlert", data.message || "Cancel failed.", "danger");
            return;
        }

        showBox("cancelLoanAlert", data.message, "success");

        setTimeout(() => {
            closeModalFallback("cancelLoanModal");
            window.location.reload();
        }, 900);
    })
    .catch(() => {
        showBox("cancelLoanAlert", "Network error while cancelling loan.", "danger");
    });
});
</script>

<?php require "footer.php"; ?>