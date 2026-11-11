<?php
require "nav-head.php";
require "services_check.php";

$table_name = "Loans";
$phead = "PENDING LOANS";

/* -------------------------------------------------
| AUTH CHECK
------------------------------------------------- */
if (!isset($_SESSION['user_id']) && !isset($_SESSION['logged_in'])) {
    $_SESSION['message'] = "Please login first.";
    $_SESSION['message_type'] = "error";
    header("Location: ../login.php");
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
$user_role = (int)($_SESSION['role'] ?? 0);

if ($user_id <= 0) {
    exit("Invalid session.");
}

if ($user_role !== 3) {
    $_SESSION['message'] = "Access denied. Clients only.";
    $_SESSION['message_type'] = "error";
    header("Location: dashboard.php");
    exit;
}

/* -------------------------------------------------
| HELPERS
------------------------------------------------- */
function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* -------------------------------------------------
| FETCH CUSTOMER USING SESSION USER ID
------------------------------------------------- */
$client_stmt = $conn->prepare("
    SELECT 
        c.id,
        c.user_id,
        c.phone,
        c.loan_limit,
        u.fname,
        u.lname,
        u.username,
        u.email
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    WHERE c.user_id = ?
    LIMIT 1
");

if (!$client_stmt) {
    exit("Failed to prepare customer query.");
}

$client_stmt->bind_param("i", $user_id);
$client_stmt->execute();
$client_result = $client_stmt->get_result();
$client = $client_result ? $client_result->fetch_assoc() : null;
$client_stmt->close();

if (!$client) {
    echo '<div class="container py-4"><div class="alert alert-danger">Client profile not found.</div></div>';
    require "footer.php";
    exit;
}

// loans.client_id stores the user id (customers.user_id).
$loan_client_id = (int)($client['user_id'] ?? $user_id);
$full_name = trim(($client['fname'] ?? '') . ' ' . ($client['lname'] ?? ''));
if ($full_name === '') {
    $full_name = $client['username'] ?? 'Client';
}

/* -------------------------------------------------
| FETCH CLIENT PENDING LOANS USING customers.id
------------------------------------------------- */
$loan_stmt = $conn->prepare("
    SELECT 
        id,
        amount,
        interest,
        loan_balance,
        loan_period,
        loan_method,
        status,
        due_date,
        datetime
    FROM loan
    WHERE client_id = ?
      AND status = 'PENDING'
    ORDER BY id DESC
");

if (!$loan_stmt) {
    exit("Failed to prepare loan query.");
}

$loan_stmt->bind_param("i", $loan_client_id);
$loan_stmt->execute();
$loan_result = $loan_stmt->get_result();

$pending_loans = [];
$total_pending_amount = 0;
$total_pending_balance = 0;

while ($row = $loan_result->fetch_assoc()) {
    $pending_loans[] = $row;
    $total_pending_amount += (float)($row['amount'] ?? 0);
    $total_pending_balance += (float)($row['loan_balance'] ?? 0);
}
$loan_stmt->close();
?>

<div class="container-fluid py-4">
    <?php if (isset($_SESSION['message'])): ?>
        <?php
        $message = $_SESSION['message'];
        $type = $_SESSION['message_type'] ?? 'info';
        unset($_SESSION['message'], $_SESSION['message_type']);
        ?>
        <div id="session-alert" class="alert <?= $type === 'success' ? 'alert-success' : 'alert-danger'; ?> alert-dismissible fade show" role="alert">
            <?= esc($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <script>
            setTimeout(function () {
                const alertBox = document.getElementById('session-alert');
                if (alertBox) {
                    alertBox.style.display = 'none';
                }
            }, 4000);
        </script>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) && $_GET['msg'] !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= esc($_GET['msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-xl-4 col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <small class="text-muted d-block">Client</small>
                    <h6 class="mb-0"><?= esc($full_name); ?></h6>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <small class="text-muted d-block">Pending Requests</small>
                    <h6 class="mb-0"><?= number_format(count($pending_loans)); ?></h6>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-12">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <small class="text-muted d-block">Total Pending Amount</small>
                    <h6 class="mb-0">UGX <?= number_format($total_pending_amount, 2); ?></h6>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h6 class="mb-0"><?= esc($phead); ?></h6>
                        <small class="text-muted">All pending loan requests submitted by you</small>
                    </div>
                    <button class="btn bg-gradient-success btn-sm" onclick="download_table_as_csv('report')">
                        <i class="fa fa-download me-1"></i> Export CSV
                    </button>
                </div>

                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0" id="report">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">#</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Amount</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Interest</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Balance</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Period</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Method</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Request Date</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pending_loans)): ?>
                                    <?php $counter = 1; ?>
                                    <?php foreach ($pending_loans as $loan): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex px-2 py-1">
                                                    <div class="d-flex flex-column justify-content-center">
                                                        <h6 class="mb-0 text-sm"><?= $counter++; ?></h6>
                                                    </div>
                                                </div>
                                            </td>

                                            <td>
                                                <p class="text-xs font-weight-bold mb-0">UGX <?= number_format((float)$loan['amount'], 2); ?></p>
                                            </td>

                                            <td>
                                                <p class="text-xs font-weight-bold mb-0"><?= number_format((float)$loan['interest'], 2); ?></p>
                                            </td>

                                            <td>
                                                <p class="text-xs font-weight-bold mb-0">UGX <?= number_format((float)$loan['loan_balance'], 2); ?></p>
                                            </td>

                                            <td>
                                                <p class="text-xs font-weight-bold mb-0"><?= (int)$loan['loan_period']; ?> Month(s)</p>
                                            </td>

                                            <td>
                                                <p class="text-xs font-weight-bold mb-0"><?= esc($loan['loan_method'] ?? '-'); ?></p>
                                            </td>

                                            <td class="align-middle text-center text-sm">
                                                <?php
                                                $status = strtoupper((string)($loan['status'] ?? ''));
                                                $badgeClass = 'secondary';

                                                if ($status === 'CLEARED') {
                                                    $badgeClass = 'success';
                                                } elseif ($status === 'OVERDUE') {
                                                    $badgeClass = 'danger';
                                                } elseif ($status === 'PENDING') {
                                                    $badgeClass = 'warning';
                                                } elseif ($status === 'OUTSTANDING') {
                                                    $badgeClass = 'info';
                                                }
                                                ?>
                                                <span class="badge badge-sm bg-gradient-<?= $badgeClass; ?>">
                                                    <?= esc($loan['status'] ?? '-'); ?>
                                                </span>
                                            </td>

                                            <td class="align-middle text-center">
                                                <span class="text-secondary text-xs font-weight-bold">
                                                    <?= esc($loan['datetime'] ?? '-'); ?>
                                                </span>
                                            </td>

                                            <td class="align-middle text-center">
                                                <div class="d-flex justify-content-center gap-2">
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-info mb-0"
                                                        title="Edit Loan"
                                                        onclick='openEditModal(<?= json_encode([
                                                            "id" => (int)$loan["id"],
                                                            "amount" => (float)$loan["amount"],
                                                            "interest" => (float)$loan["interest"]
                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                                    >
                                                        <i class="fa fa-edit"></i>
                                                    </button>

                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-danger mb-0"
                                                        title="Cancel Loan"
                                                        onclick="openCancelModal(<?= (int)$loan['id']; ?>)"
                                                    >
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">
                                            No pending loan requests found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($pending_loans)): ?>
                        <div class="px-4 pb-3">
                            <div class="alert alert-light border mb-0">
                                <strong>Total Pending Balance:</strong> UGX <?= number_format($total_pending_balance, 2); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Loan Modal -->
<div class="modal fade" id="editLoanModal" tabindex="-1" aria-labelledby="editLoanModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="editLoanForm" onsubmit="submitEditLoan(event)">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editLoanModalLabel">Edit Loan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div id="edit-loan-alert" class="alert d-none" role="alert"></div>

                    <input type="hidden" name="loan_id" id="edit-loan-id">

                    <div class="form-group">
                        <label for="edit-amount" class="form-label">Amount</label>
                        <input type="number" class="form-control" id="edit-amount" name="amount" required min="1" step="0.01">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Loan Modal -->
<div class="modal fade" id="cancelLoanModal" tabindex="-1" aria-labelledby="cancelLoanModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="cancelLoanForm" onsubmit="submitCancelLoan(event)">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelLoanModalLabel">Cancel Loan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div id="cancel-loan-alert" class="alert d-none" role="alert"></div>
                    <p class="mb-0">Are you sure you want to cancel this pending loan request?</p>
                    <input type="hidden" name="loan_id" id="cancel-loan-id">
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">No</button>
                    <button type="submit" class="btn btn-danger">Yes, Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require "footer.php"; ?>

<script>
function showAlert(elementId, message, type = 'success') {
    const alertBox = document.getElementById(elementId);
    if (!alertBox) return;

    alertBox.className = `alert alert-${type}`;
    alertBox.textContent = message;
    alertBox.classList.remove('d-none');

    setTimeout(() => {
        alertBox.classList.add('d-none');
        alertBox.textContent = '';
    }, 3000);
}

function clearAlert(elementId) {
    const alertBox = document.getElementById(elementId);
    if (alertBox) {
        alertBox.classList.add('d-none');
        alertBox.textContent = '';
    }
}

function openEditModal(loan) {
    clearAlert('edit-loan-alert');

    document.getElementById('edit-loan-id').value = loan.id || '';
    document.getElementById('edit-amount').value = loan.amount || '';

    const modal = new bootstrap.Modal(document.getElementById('editLoanModal'));
    modal.show();
}

function openCancelModal(loanId) {
    clearAlert('cancel-loan-alert');

    document.getElementById('cancel-loan-id').value = loanId;

    const modal = new bootstrap.Modal(document.getElementById('cancelLoanModal'));
    modal.show();
}

function submitEditLoan(event) {
    event.preventDefault();

    const loanId = document.getElementById('edit-loan-id').value;
    const amount = document.getElementById('edit-amount').value;

    fetch('edit_loan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            loan_id: loanId,
            amount: amount
        })
    })
    .then(response => response.text())
    .then(data => {
        showAlert('edit-loan-alert', 'Loan updated successfully!', 'success');
        setTimeout(() => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('editLoanModal'));
            if (modal) modal.hide();
            location.reload();
        }, 1000);
    })
    .catch(error => {
        console.error(error);
        showAlert('edit-loan-alert', 'Error updating loan.', 'danger');
    });
}

function submitCancelLoan(event) {
    event.preventDefault();

    const loanId = document.getElementById('cancel-loan-id').value;

    fetch('cancel_loan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            loan_id: loanId
        })
    })
    .then(response => response.text())
    .then(data => {
        showAlert('cancel-loan-alert', 'Loan cancelled successfully!', 'success');
        setTimeout(() => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('cancelLoanModal'));
            if (modal) modal.hide();
            location.reload();
        }, 1000);
    })
    .catch(error => {
        console.error(error);
        showAlert('cancel-loan-alert', 'Error cancelling loan.', 'danger');
    });
}
</script>