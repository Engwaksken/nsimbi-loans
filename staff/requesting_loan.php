<?php
declare(strict_types=1);

require "nav-head.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in'])) {
    die("Access denied.");
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found.");
}

$conn->set_charset("utf8mb4");

$member_id = (int)$_SESSION['logged_in'];

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
        u.id,
        u.fname,
        u.lname,
        u.email,
        m.phone,
        m.nin,
        m.bank_name,
        m.bank_account,
        m.loan_limit,
        m.net_salary,
        m.gross_salary
    FROM user u
    LEFT JOIN members m ON m.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die("Client profile not found.");
}

$stmt = $conn->prepare("
    SELECT COUNT(*) AS loan_count
    FROM loan
    WHERE client_id = ?
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$loanStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("
    SELECT 
        id,
        loan_balance,
        outstanding_balance,
        status
    FROM loan
    WHERE client_id = ?
    AND UPPER(status) IN ('OUTSTANDING', 'OVERDUE', 'PENDING')
    ORDER BY id DESC
    LIMIT 1
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$activeLoan = $stmt->get_result()->fetch_assoc();
$stmt->close();

$loan_count = (int)($loanStats['loan_count'] ?? 0);
$loan_limit = (float)($user['loan_limit'] ?? 0);
$active_balance = 0;

if ($activeLoan) {
    $active_balance = (float)($activeLoan['outstanding_balance'] ?? 0);
    if ($active_balance <= 0) {
        $active_balance = (float)($activeLoan['loan_balance'] ?? 0);
    }
}

$phone = $user['phone'] ?? '';
$bank_name = $user['bank_name'] ?? '';
$bank_account = $user['bank_account'] ?? '';

$message = $_SESSION['message'] ?? '';
$message_type = $_SESSION['message_type'] ?? 'info';
unset($_SESSION['message'], $_SESSION['message_type']);

$can_apply = !$activeLoan;
?>

<link rel="stylesheet" href="assets/css/loanpro.css">

<style>
.loan-request-header {
    background: linear-gradient(135deg, #ffffff, #f8fafc);
    border-radius: 20px;
    border: 1px solid #eef2f7;
    box-shadow: 0 8px 26px rgba(15, 23, 42, .06);
    padding: 24px;
}

.loan-request-card,
.loan-side-card {
    background: #fff;
    border: 1px solid #eef2f7;
    border-radius: 20px;
    box-shadow: 0 8px 26px rgba(15, 23, 42, .06);
    overflow: hidden;
}

.loan-side-card {
    border-left: 6px solid #344767;
}

.loan-side-card.primary { border-left-color: #0d6efd; }
.loan-side-card.warning { border-left-color: #f59e0b; }
.loan-side-card.success { border-left-color: #198754; }

.loan-card-title {
    font-weight: 800;
    color: #1e293b;
}

.loan-muted-label {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: .05em;
}

.loan-value {
    font-size: 19px;
    font-weight: 800;
    color: #1e293b;
}

.form-control,
.form-select {
    border-radius: 12px;
    min-height: 45px;
}

.loan-section-title {
    font-size: 13px;
    font-weight: 800;
    text-transform: uppercase;
    color: #475569;
    letter-spacing: .04em;
    margin-bottom: 14px;
}

.disbursement-box {
    border: 1px dashed #cbd5e1;
    background: #f8fafc;
    border-radius: 16px;
    padding: 16px;
    display: none;
}
</style>

<div class="container-fluid py-4">

    <div class="loan-request-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3 class="mb-1">
                    <i class="fas fa-hand-holding-usd me-2 text-primary"></i>
                    Apply for a Loan
                </h3>
                <p class="text-muted mb-0">
                    Submit a new loan request using your registered profile and payment details.
                </p>
            </div>

            <a href="loans.php" class="btn btn-light">
                <i class="fas fa-arrow-left me-1"></i>
                Back to Loans
            </a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div id="session-alert"
             class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-danger'; ?> alert-dismissible fade show"
             role="alert">
            <?= e($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <div class="col-xl-4 col-lg-5">

            <div class="loan-side-card primary p-4 mb-3">
                <div class="loan-muted-label">Loan Limit</div>
                <div class="loan-value"><?= money($loan_limit) ?></div>
            </div>

            <div class="loan-side-card warning p-4 mb-3">
                <div class="loan-muted-label">Active / Pending Balance</div>
                <div class="loan-value"><?= money($active_balance) ?></div>
            </div>

            <div class="loan-side-card success p-4 mb-3">
                <div class="loan-muted-label">Total Loan Requests</div>
                <div class="loan-value"><?= (int)$loan_count ?></div>
            </div>

            <div class="loan-side-card p-4">
                <h6 class="loan-card-title mb-3">
                    <i class="fas fa-user-circle me-2"></i>
                    Applicant Summary
                </h6>

                <div class="mb-3">
                    <div class="loan-muted-label">Name</div>
                    <div class="fw-bold">
                        <?= e(trim(($user['fname'] ?? '') . ' ' . ($user['lname'] ?? '')) ?: 'N/A') ?>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="loan-muted-label">Phone</div>
                    <div class="fw-bold"><?= e($phone ?: 'N/A') ?></div>
                </div>

                <div class="mb-0">
                    <div class="loan-muted-label">NIN</div>
                    <div class="fw-bold"><?= e($user['nin'] ?? 'N/A') ?></div>
                </div>
            </div>

        </div>

        <div class="col-xl-8 col-lg-7">

            <div class="loan-request-card">

                <div class="p-4 border-bottom">
                    <h5 class="loan-card-title mb-1">Loan Request Form</h5>
                    <small class="text-muted">
                        Fill in the loan amount, period, and preferred disbursement method.
                    </small>
                </div>

                <div class="p-4">

                    <?php if (!$can_apply): ?>

                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            You currently have an active, overdue, or pending loan. Please clear or wait for approval before applying for another loan.
                        </div>

                    <?php else: ?>

                        <form method="post" action="requested_loan.php" id="loanRequestForm">

                            <input type="hidden" name="client" value="<?= (int)$member_id ?>">

                            <div class="loan-section-title">Loan Details</div>

                            <div class="row g-3">

                                <div class="col-md-6">
                                    <label for="amount" class="form-label">Loan Amount</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="1"
                                        max="<?= e((string)$loan_limit) ?>"
                                        class="form-control"
                                        id="amount"
                                        name="amount"
                                        placeholder="Enter loan amount"
                                        required
                                    >
                                    <small class="text-muted">
                                        Maximum allowed: <?= money($loan_limit) ?>
                                    </small>
                                </div>

                                <div class="col-md-6">
                                    <label for="loan_period" class="form-label">Loan Period</label>
                                    <select class="form-select" id="loan_period" name="loan_period" required>
                                        <option value="" selected disabled>Select period</option>
                                        <option value="1">1 Month</option>
                                        <option value="2">2 Months</option>
                                        <option value="3">3 Months</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="loan_method" class="form-label">Disbursement Method</label>
                                    <select class="form-select" id="loan_method" name="loan_method" required>
                                        <option value="" selected disabled>Select method</option>
                                        <option value="Mobile Money">Mobile Money</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                    </select>
                                </div>

                            </div>

                            <hr class="my-4">

                            <div class="loan-section-title">Disbursement Details</div>

                            <div class="disbursement-box" id="mobile_money_section">
                                <div class="mb-0">
                                    <label for="phone" class="form-label">Mobile Money Number</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="phone"
                                        name="phone"
                                        value="<?= e($phone) ?>"
                                        placeholder="Enter mobile money number"
                                    >
                                </div>
                            </div>

                            <div class="disbursement-box" id="bank_section">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="bank_name" class="form-label">Bank Name</label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            id="bank_name"
                                            name="bank_name"
                                            value="<?= e($bank_name) ?>"
                                            placeholder="Enter bank name"
                                        >
                                    </div>

                                    <div class="col-md-6">
                                        <label for="bank_account" class="form-label">Bank Account Number</label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            id="bank_account"
                                            name="bank_account"
                                            value="<?= e($bank_account) ?>"
                                            placeholder="Enter account number"
                                        >
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-info mt-4">
                                <i class="fas fa-info-circle me-2"></i>
                                Your request will be submitted for review before approval and disbursement.
                            </div>

                            <div class="d-flex justify-content-end gap-2 mt-4">
                                <a href="loans.php" class="btn btn-light">
                                    Cancel
                                </a>

                                <button type="submit" name="add" class="btn bg-gradient-primary">
                                    <i class="fas fa-paper-plane me-1"></i>
                                    Submit Loan Request
                                </button>
                            </div>

                        </form>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>

<script src="js/loanpro.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const method = document.getElementById("loan_method");
    const mobileBox = document.getElementById("mobile_money_section");
    const bankBox = document.getElementById("bank_section");
    const phone = document.getElementById("phone");
    const bankName = document.getElementById("bank_name");
    const bankAccount = document.getElementById("bank_account");
    const form = document.getElementById("loanRequestForm");

    function toggleMethodFields() {
        if (!method) return;

        const value = method.value;

        mobileBox.style.display = value === "Mobile Money" ? "block" : "none";
        bankBox.style.display = value === "Bank Transfer" ? "block" : "none";

        if (phone) {
            phone.required = value === "Mobile Money";
        }

        if (bankName) {
            bankName.required = value === "Bank Transfer";
        }

        if (bankAccount) {
            bankAccount.required = value === "Bank Transfer";
        }
    }

    if (method) {
        method.addEventListener("change", toggleMethodFields);
        toggleMethodFields();
    }

    if (form) {
        form.addEventListener("submit", function (e) {
            const amount = parseFloat(document.getElementById("amount").value || "0");
            const limit = parseFloat("<?= (float)$loan_limit ?>");

            if (limit > 0 && amount > limit) {
                e.preventDefault();
                alert("Loan amount cannot exceed your loan limit of <?= money($loan_limit) ?>.");
            }
        });
    }

    setTimeout(function () {
        const alertBox = document.getElementById("session-alert");
        if (alertBox) {
            alertBox.style.display = "none";
        }
    }, 4000);
});
</script>

<?php require "footer.php"; ?>