<?php
require "nav-head.php";

if (!isset($_SESSION['user_id']) && !isset($_SESSION['logged_in'])) {
    $_SESSION['message'] = "Please login first.";
    $_SESSION['message_type'] = "error";
    header("Location: ../index.php");
    exit;
}

$user_id   = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
$user_role = (int)($_SESSION['role'] ?? 0);

if ($user_id <= 0) {
    echo '<div class="page-content"><div class="alert alert-danger">Invalid session. Please login again.</div></div>';
    require "footer.php";
    exit;
}

if ($user_role !== 3) {
    $_SESSION['message'] = "Access denied. Clients only.";
    $_SESSION['message_type'] = "error";
    header("Location: dashboard.php");
    exit;
}

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return 'UGX ' . number_format((float)$amount, 2);
}

$stmt = $conn->prepare("
    SELECT
        id, user_id, gender, dob, phone, bank_name, bank_account, email, client_category,
        business_name, business_type, business_period, amount_last, amount_this,
        district, location, nok_name, nok_tel, nok_rel, nin, loan_limit,
        credit_score, active, created_at
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

if (!$stmt) {
    echo '<div class="page-content"><div class="alert alert-danger">Failed to prepare customer query.</div></div>';
    require "footer.php";
    exit;
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$customer = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$customer) {
    echo '<div class="page-content">
            <div class="alert alert-danger">
                <strong>Client profile not found.</strong><br>
                No customer record was found for this account.
            </div>
          </div>';
    require "footer.php";
    exit;
}

$customer_id   = (int)($customer['id'] ?? 0);
$phone         = trim((string)($customer['phone'] ?? ''));
$bank_name     = trim((string)($customer['bank_name'] ?? ''));
$bank_account  = trim((string)($customer['bank_account'] ?? ''));
$loan_limit    = (float)($customer['loan_limit'] ?? 0);
$business_name = trim((string)($customer['business_name'] ?? ''));
$district      = trim((string)($customer['district'] ?? ''));
$location_name = trim((string)($customer['location'] ?? ''));
$active_flag   = (int)($customer['active'] ?? 0);
$is_new_onboarded = ($customer['client_category'] ?? 'business') === 'new_onboarded';

$full_name = trim((string)($_SESSION['fname'] ?? '') . ' ' . (string)($_SESSION['lname'] ?? ''));
if ($full_name === '') {
    $full_name = 'Client';
}

$loan_count = 0;
$total_borrowed = 0.0;
$active_loan = null;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS loan_count, COALESCE(SUM(amount), 0) AS total_borrowed
    FROM loan
    WHERE client_id = ?
");

if ($stmt) {
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $loan_count = (int)($row['loan_count'] ?? 0);
    $total_borrowed = (float)($row['total_borrowed'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT id, amount, interest, loan_balance, loan_period, loan_method, status, due_date, datetime
    FROM loan
    WHERE client_id = ?
      AND UPPER(status) NOT IN ('CLEARED', 'REJECTED', 'CANCELLED')
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $active_loan = $res ? $res->fetch_assoc() : null;
    $stmt->close();
}

$can_request = ($active_flag === 1 && !$active_loan);
?>

<div class="loan-request-page">

    <?php if (isset($_SESSION['message'])): ?>
        <?php
        $message = $_SESSION['message'];
        $type = $_SESSION['message_type'] ?? 'info';
        unset($_SESSION['message'], $_SESSION['message_type']);
        ?>
        <div id="session-alert" class="alert <?= $type === 'success' ? 'alert-success' : 'alert-danger'; ?>">
            <?= esc($message); ?>
        </div>
    <?php endif; ?>

    <div class="loan-request-hero">
        <div>
            <h4>Request Loan</h4>
            <p>Complete the form below to submit your loan request for approval.</p>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card c-blue">
            <div class="stat-label">Client Name</div>
            <div class="stat-value sm"><?= esc($full_name); ?></div>
        </div>

        <div class="stat-card c-green">
            <div class="stat-label">Loan Limit</div>
            <div class="stat-value sm"><?= money($loan_limit); ?></div>
        </div>

        <div class="stat-card c-purple">
            <div class="stat-label">Loan Count</div>
            <div class="stat-value sm"><?= number_format($loan_count); ?></div>
        </div>

        <div class="stat-card c-orange">
            <div class="stat-label">Total Borrowed</div>
            <div class="stat-value sm"><?= money($total_borrowed); ?></div>
        </div>

        <div class="stat-card c-teal">
            <div class="stat-label">Business Name</div>
            <div class="stat-value sm"><?= esc($business_name !== '' ? $business_name : '-'); ?></div>
        </div>

        <div class="stat-card c-indigo">
            <div class="stat-label">District</div>
            <div class="stat-value sm"><?= esc($district !== '' ? $district : '-'); ?></div>
        </div>

        <div class="stat-card c-pink">
            <div class="stat-label">Location</div>
            <div class="stat-value sm"><?= esc($location_name !== '' ? $location_name : '-'); ?></div>
        </div>
    </div>

    <?php if ($active_flag !== 1): ?>
        <div class="alert alert-warning">
            Your customer profile is inactive. Please contact the administrator before requesting a loan.
        </div>
    <?php endif; ?>

    <?php if ($active_loan): ?>
        <div class="alert alert-warning">
            <div>
                <strong>You already have an active loan request or uncleared loan.</strong><br>
                <strong>Status:</strong> <?= esc($active_loan['status'] ?? '-'); ?><br>
                <strong>Loan Amount:</strong> <?= money($active_loan['amount'] ?? 0); ?><br>
                <strong>Outstanding Balance:</strong> <?= money($active_loan['loan_balance'] ?? 0); ?><br>
                <strong>Loan Method:</strong> <?= esc($active_loan['loan_method'] ?? '-'); ?><br>
                <strong>Due Date:</strong> <?= esc($active_loan['due_date'] ?? '-'); ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card loan-request-card">
        <div class="card-header">
            <div>
                <div class="card-title">
                    <span class="card-dot"></span>
                    Loan Request Form
                </div>
                <div class="card-sub">Fill in your preferred amount, repayment period and disbursement method.</div>
            </div>
        </div>

        <div class="card-body">
            <form method="post" enctype="multipart/form-data" action="requested_loan.php" id="loanRequestForm" novalidate>
                <div class="loan-request-grid">
                    <div>
                        <div class="form-group">
                            <label for="amount" class="form-label">Loan Amount</label>
                            <input
                                type="text"
                                class="form-control"
                                id="amount"
                                name="amount"
                                placeholder="e.g. 100,000"
                                required
                                inputmode="decimal"
                                <?= !$can_request ? 'disabled' : ''; ?>
                            >
                            <div class="form-help">Maximum allowed: <?= money($loan_limit); ?></div>
                        </div>

                        <div class="form-group">
                            <label for="loan_period" class="form-label">Loan Period</label>
                            <select
                                class="form-select loan-select"
                                id="loan_period"
                                name="loan_period"
                                required
                                <?= !$can_request ? 'disabled' : ''; ?>
                            >
                                <option value="">Select loan period</option>
                                <?php if ($is_new_onboarded): ?>
                                    <?php for ($months = 8; $months <= 12; $months++): ?>
                                        <option value="<?= $months; ?>"><?= $months; ?> Months - 3% monthly interest</option>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <option value="1">1 Month - 10% Interest</option>
                                    <option value="2">2 Months - 6.6% Interest</option>
                                    <option value="3">3 Months - 6.6% Interest</option>
                                    <option value="6">6 Months - 6.6% Interest</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="loan_method" class="form-label">Preferred Disbursement Method</label>
                            <select
                                class="form-select loan-select"
                                id="loan_method"
                                name="loan_method"
                                required
                                <?= !$can_request ? 'disabled' : ''; ?>
                            >
                                <option value="">Select method</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                        </div>

                        <div class="form-group method-section" id="mobile_money_section">
                            <label for="phone" class="form-label">Mobile Money Number</label>
                            <input
                                type="text"
                                class="form-control"
                                id="phone"
                                name="phone"
                                value="<?= esc($phone); ?>"
                                placeholder="Enter Mobile Money number"
                                <?= !$can_request ? 'disabled' : ''; ?>
                            >
                        </div>

                        <div class="method-section" id="bank_section">
                            <div class="loan-request-inner-grid">
                                <div class="form-group">
                                    <label for="bank_name" class="form-label">Bank Name</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="bank_name"
                                        name="bank_name"
                                        value="<?= esc($bank_name); ?>"
                                        placeholder="e.g. Stanbic Bank"
                                        <?= !$can_request ? 'disabled' : ''; ?>
                                    >
                                </div>

                                <div class="form-group">
                                    <label for="bank_account" class="form-label">Bank Account Number</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="bank_account"
                                        name="bank_account"
                                        value="<?= esc($bank_account); ?>"
                                        placeholder="e.g. 1234567890"
                                        <?= !$can_request ? 'disabled' : ''; ?>
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($is_new_onboarded): ?>
                        <div class="form-group mt-3">
                            <label for="collateral_description" class="form-label">Collateral Details (required)</label>
                            <textarea class="form-control" id="collateral_description" name="collateral_description" rows="3" required <?= !$can_request ? 'disabled' : ''; ?> placeholder="Describe the collateral offered"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="collateral_document" class="form-label">Collateral Evidence (required)</label>
                            <input class="form-control" type="file" id="collateral_document" name="collateral_document" accept=".jpg,.jpeg,.png,.pdf" required <?= !$can_request ? 'disabled' : ''; ?>>
                            <small class="form-help">Upload a JPG, PNG, or PDF (maximum 5 MB).</small>
                        </div>
                    <?php endif; ?>

                    <div>
                        <div class="loan-summary-box">
                            <h6>Loan Summary</h6>

                            <div class="summary-row">
                                <span>Requested Amount</span>
                                <span id="summary_amount">UGX 0.00</span>
                            </div>

                            <div class="summary-row">
                                <span>Interest Rate</span>
                                <span id="summary_rate">0%</span>
                            </div>

                            <div class="summary-row">
                                <span>Interest Amount</span>
                                <span id="summary_interest">UGX 0.00</span>
                            </div>

                            <div class="summary-row">
                                <span>Total Repayable</span>
                                <span id="summary_total">UGX 0.00</span>
                            </div>

                            <div class="summary-row">
                                <span>Period</span>
                                <span id="summary_period">-</span>
                            </div>
                        </div>

                        <div class="alert alert-info mt-3">
                            Your loan request will be submitted as <strong>PENDING</strong> until approved by the administrator.
                        </div>
                    </div>
                </div>

                <div class="loan-request-actions">
                    <button
                        type="submit"
                        class="btn btn-primary btn-lg"
                        <?= !$can_request ? 'disabled' : ''; ?>
                    >
                        Submit Loan Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const loanLimit = <?= json_encode($loan_limit); ?>;

    const loanMethod = document.getElementById('loan_method');
    const mobileMoneySection = document.getElementById('mobile_money_section');
    const bankSection = document.getElementById('bank_section');

    const phoneInput = document.getElementById('phone');
    const bankNameInput = document.getElementById('bank_name');
    const bankAccountInput = document.getElementById('bank_account');

    const amountInput = document.getElementById('amount');
    const periodInput = document.getElementById('loan_period');
    const form = document.getElementById('loanRequestForm');

    const summaryAmount = document.getElementById('summary_amount');
    const summaryRate = document.getElementById('summary_rate');
    const summaryInterest = document.getElementById('summary_interest');
    const summaryTotal = document.getElementById('summary_total');
    const summaryPeriod = document.getElementById('summary_period');

    function toNumber(value) {
        return parseFloat(String(value || '').replace(/,/g, '').replace(/[^\d.]/g, '')) || 0;
    }

    function formatMoney(amount) {
        return 'UGX ' + Number(amount || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function getInterestRate(period) {
        period = parseInt(period || 0, 10);

        if (period === 1) return 10;
        if (period === 2 || period === 3 || period === 6) return 6.6;

        return 0;
    }

    function formatAmountInput() {
        if (!amountInput) return;

        let value = amountInput.value.replace(/,/g, '').replace(/[^\d.]/g, '');

        if (value !== '') {
            const parts = value.split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            amountInput.value = parts.join('.');
        }
    }

    function hideMethodSections() {
        if (mobileMoneySection) mobileMoneySection.classList.remove('active');
        if (bankSection) bankSection.classList.remove('active');

        if (phoneInput) phoneInput.required = false;
        if (bankNameInput) bankNameInput.required = false;
        if (bankAccountInput) bankAccountInput.required = false;
    }

    function toggleMethodFields() {
        if (!loanMethod) return;

        const method = loanMethod.value;

        hideMethodSections();

        if (method === 'Mobile Money') {
            mobileMoneySection.classList.add('active');
            phoneInput.required = true;
        }

        if (method === 'Bank Transfer') {
            bankSection.classList.add('active');
            bankNameInput.required = true;
            bankAccountInput.required = true;
        }
    }

    function updateSummary() {
        const amount = toNumber(amountInput ? amountInput.value : 0);
        const period = parseInt(periodInput ? periodInput.value : 0, 10);
        const rate = getInterestRate(period);
        const interest = amount * (rate / 100);
        const total = amount + interest;

        summaryAmount.textContent = formatMoney(amount);
        summaryRate.textContent = rate ? rate + '%' : '0%';
        summaryInterest.textContent = formatMoney(interest);
        summaryTotal.textContent = formatMoney(total);
        summaryPeriod.textContent = period ? period + (period === 1 ? ' Month' : ' Months') : '-';
    }

    if (loanMethod) {
        loanMethod.addEventListener('change', function () {
            toggleMethodFields();
            updateSummary();
        });
    }

    if (amountInput) {
        amountInput.addEventListener('input', function () {
            formatAmountInput();
            updateSummary();
        });
    }

    if (periodInput) {
        periodInput.addEventListener('change', updateSummary);
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            const amount = toNumber(amountInput.value);

            if (amount <= 0) {
                e.preventDefault();
                alert('Please enter a valid loan amount.');
                amountInput.focus();
                return false;
            }

            if (loanLimit > 0 && amount > loanLimit) {
                e.preventDefault();
                alert('Loan amount cannot exceed your loan limit of ' + formatMoney(loanLimit) + '.');
                amountInput.focus();
                return false;
            }

            if (!periodInput.value) {
                e.preventDefault();
                alert('Please select a loan period.');
                periodInput.focus();
                return false;
            }

            if (!loanMethod.value) {
                e.preventDefault();
                alert('Please select a disbursement method.');
                loanMethod.focus();
                return false;
            }

            amountInput.value = String(amount);
        });
    }

    hideMethodSections();
    toggleMethodFields();
    updateSummary();

    const sessionAlert = document.getElementById('session-alert');
    if (sessionAlert) {
        setTimeout(function () {
            sessionAlert.style.display = 'none';
        }, 4000);
    }
});
</script>

<?php require "footer.php"; ?>
