<?php
$phead = "Request a Loan";

require "nav-head.php";
require_once __DIR__ . "/../includes/loan_workflow.php";

/*
 * Loan request page. The form lives in a modal (loanpro.js Modal) and posts
 * to requested_loan.php, which re-validates everything server side.
 * Bootstrap is not loaded in the client portal; use loanpro.css classes.
 */

$user_id   = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
$user_role = (int)($_SESSION['role'] ?? 0);

if ($user_id <= 0 || $user_role !== 3) {
    echo '<div class="alert alert-danger">Access denied. Please login as a client.</div>';
    require "footer.php";
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

$customer = null;
$loan_count = 0;
$total_borrowed = 0.0;
$active_loan = null;

try {
    $has_client_category = loan_table_has_column($conn, 'customers', 'client_category');

    $stmt = $conn->prepare("
        SELECT id, user_id, phone, bank_name, bank_account, business_name,
               district, location, loan_limit, active,
               " . ($has_client_category ? "client_category" : "'business' AS client_category") . "
        FROM customers
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($customer) {
        // Loan rows use the user id (customers.user_id), not customers.id.
        $client_id = (int)$customer['user_id'];

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS loan_count, COALESCE(SUM(amount), 0) AS total_borrowed
            FROM loan
            WHERE client_id = ?
              AND UPPER(status) NOT IN ('PENDING', 'REJECTED', 'CANCELLED', 'DECLINED')
        ");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $loan_count = (int)$row['loan_count'];
        $total_borrowed = (float)$row['total_borrowed'];

        $stmt = $conn->prepare("
            SELECT id, amount, loan_balance, loan_method, status, due_date
            FROM loan
            WHERE client_id = ?
              AND UPPER(COALESCE(status, '')) NOT IN ('CLEARED', 'REJECTED', 'CANCELLED', 'DECLINED')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $active_loan = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    error_log('requesting_loan load failed: ' . $e->getMessage());
    echo '<div class="alert alert-danger">We could not load your profile right now. Please try again later.</div>';
    require "footer.php";
    exit;
}

if (!$customer) {
    echo '<div class="alert alert-danger"><strong>Client profile not found.</strong>&nbsp;No customer record exists for this account. Please contact the administrator.</div>';
    require "footer.php";
    exit;
}

$phone            = trim((string)$customer['phone']);
$bank_name        = trim((string)$customer['bank_name']);
$bank_account     = trim((string)$customer['bank_account']);
$loan_limit       = (float)$customer['loan_limit'];
$is_active        = (int)$customer['active'] === 1;
$is_new_onboarded = ($customer['client_category'] ?? 'business') === 'new_onboarded';
$can_request      = $is_active && !$active_loan;

if ($is_new_onboarded) {
    $period_options = [];
    foreach ([8, 9, 10, 11, 12] as $m) {
        $period_options[$m] = "{$m} months · 3% per month";
    }
} else {
    $period_options = [1 => '1 month · 10% interest', 2 => '2 months · 6.6% interest', 3 => '3 months · 6.6% interest', 6 => '6 months · 6.6% interest'];
}

$full_name = trim((string)($_SESSION['fname'] ?? '') . ' ' . (string)($_SESSION['lname'] ?? '')) ?: 'Client';

$flash_message = $_SESSION['message'] ?? '';
$flash_type    = $_SESSION['message_type'] ?? 'info';
unset($_SESSION['message'], $_SESSION['message_type']);

// A failed submission comes back here: reopen the form with the error inside.
$reopen_with_error = $flash_message !== '' && $flash_type !== 'success' && $can_request;
?>

<style>
.lr-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem}
.lr-step{display:flex;gap:.75rem;align-items:flex-start}
.lr-step-no{width:30px;height:30px;border-radius:50%;background:var(--primary-xlight);color:var(--primary);font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.85rem}
.lr-step h4{font-size:.86rem;margin:0 0 .15rem;color:var(--text-dark)}
.lr-step p{font-size:.78rem;margin:0;color:var(--text-muted)}
.lr-rates{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.25rem}
.lr-rate{border:1px solid var(--border);border-radius:var(--r);padding:.5rem .8rem;background:var(--body-bg);font-size:.8rem}
.lr-rate b{display:block;color:var(--text-dark);font-size:.92rem}
.lr-form{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:1.4rem}
.lr-section{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--primary);margin:0 0 .75rem;display:flex;align-items:center;gap:.4rem}
.lr-section:not(:first-child){margin-top:1.1rem;padding-top:1.1rem;border-top:1px dashed var(--border)}
.lr-methods{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:1rem}
.lr-method{position:relative;border:1.5px solid var(--border);border-radius:var(--r);padding:.7rem .8rem;cursor:pointer;display:flex;align-items:center;gap:.6rem;font-size:.85rem;font-weight:600;color:var(--text-dark);transition:border-color .15s,background .15s}
.lr-method input{position:absolute;opacity:0;pointer-events:none}
.lr-method i{color:var(--primary);width:18px;text-align:center}
.lr-method:has(input:checked){border-color:var(--primary);background:var(--primary-xlight)}
.lr-method:has(input:focus-visible){outline:2px solid var(--primary);outline-offset:2px}
.lr-input-prefix{position:relative}
.lr-input-prefix span{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);font-size:.78rem;font-weight:700;color:var(--text-muted);pointer-events:none}
.lr-input-prefix .form-control{padding-left:3.1rem;font-weight:700;font-size:1rem}
.lr-summary{position:sticky;top:0;background:var(--body-bg);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.1rem}
.lr-summary h5{margin:0 0 .8rem;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)}
.lr-row{display:flex;justify-content:space-between;gap:.75rem;font-size:.84rem;padding:.42rem 0;color:var(--text-body)}
.lr-row b{color:var(--text-dark);text-align:right}
.lr-row.total{border-top:1px solid var(--border);margin-top:.35rem;padding-top:.75rem;font-size:.95rem}
.lr-row.total b{color:var(--primary);font-size:1.1rem}
.lr-note{font-size:.75rem;color:var(--text-muted);margin-top:.9rem;line-height:1.5}
.lr-limit{height:6px;background:var(--border);border-radius:6px;overflow:hidden;margin-top:.45rem}
.lr-limit div{height:100%;width:0;background:var(--primary);transition:width .2s}
.lr-limit.over div{background:var(--danger)}
.form-control.is-invalid,.form-select.is-invalid{border-color:var(--danger)}
@media(max-width:860px){.lr-form,.lr-steps{grid-template-columns:1fr}.lr-summary{position:static}}
</style>

<?php if ($flash_message !== '' && !$reopen_with_error): ?>
    <div id="_flash" data-type="<?= esc($flash_type); ?>" style="display:none"><?= esc($flash_message); ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <div class="title">Request a Loan</div>
        <div class="sub">Check your eligibility, then submit a request for approval.</div>
    </div>
    <button type="button" class="btn btn-primary" id="openLoanRequest" <?= $can_request ? '' : 'disabled'; ?>>
        <i class="fas fa-plus"></i> New Loan Request
    </button>
</div>

<div class="stats-grid">
    <div class="stat-card c-green">
        <div class="stat-head">
            <div>
                <div class="stat-label">Loan Limit</div>
                <div class="stat-value sm"><?= $loan_limit > 0 ? money($loan_limit) : 'Not set'; ?></div>
            </div>
            <div class="stat-ico c-green"><i class="fas fa-shield-alt"></i></div>
        </div>
    </div>

    <div class="stat-card c-blue">
        <div class="stat-head">
            <div>
                <div class="stat-label">Loans Taken</div>
                <div class="stat-value"><?= number_format($loan_count); ?></div>
            </div>
            <div class="stat-ico c-blue"><i class="fas fa-file-invoice-dollar"></i></div>
        </div>
    </div>

    <div class="stat-card c-purple">
        <div class="stat-head">
            <div>
                <div class="stat-label">Total Borrowed</div>
                <div class="stat-value sm"><?= money($total_borrowed); ?></div>
            </div>
            <div class="stat-ico c-purple"><i class="fas fa-coins"></i></div>
        </div>
    </div>

    <div class="stat-card <?= $can_request ? 'c-teal' : 'c-orange'; ?>">
        <div class="stat-head">
            <div>
                <div class="stat-label">Eligibility</div>
                <div class="stat-value sm"><?= $can_request ? 'Eligible' : (!$is_active ? 'Profile inactive' : 'Loan in progress'); ?></div>
            </div>
            <div class="stat-ico <?= $can_request ? 'c-teal' : 'c-orange'; ?>">
                <i class="fas fa-<?= $can_request ? 'check-circle' : 'pause-circle'; ?>"></i>
            </div>
        </div>
    </div>
</div>

<?php if (!$is_active): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i>
        Your customer profile is inactive. Please contact the administrator before requesting a loan.
    </div>
<?php elseif ($active_loan): ?>
    <div class="alert alert-warning">
        <i class="fas fa-info-circle"></i>
        <div>
            You already have a <strong><?= esc(strtolower((string)$active_loan['status'])); ?></strong> loan
            (#<?= (int)$active_loan['id']; ?>, <?= money($active_loan['amount']); ?>, balance <?= money($active_loan['loan_balance']); ?>).
            You can request a new loan once it is cleared.
            <a href="<?= strtoupper((string)$active_loan['status']) === 'PENDING' ? 'pending_loans' : 'view_loan.php?id=' . (int)$active_loan['id']; ?>" class="fw-700">View loan</a>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">
        <div>
            <div class="card-title"><span class="card-dot"></span> How it works</div>
            <div class="card-sub">Requests are reviewed by a loan officer, usually within one working day.</div>
        </div>
    </div>
    <div class="card-body">
        <div class="lr-steps">
            <div class="lr-step">
                <div class="lr-step-no">1</div>
                <div><h4>Submit your request</h4><p>Choose an amount up to your limit, a period, and how you want to receive the money.</p></div>
            </div>
            <div class="lr-step">
                <div class="lr-step-no">2</div>
                <div><h4>Officer review</h4><p>Your request stays <strong>PENDING</strong> while it is reviewed. You can edit or cancel it until then.</p></div>
            </div>
            <div class="lr-step">
                <div class="lr-step-no">3</div>
                <div><h4>Disbursement</h4><p>Once approved, funds are sent to your Mobile Money number or bank account.</p></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><span class="card-dot"></span> Your loan terms</div>
    </div>
    <div class="card-body">
        <div class="lr-rates">
            <?php if ($is_new_onboarded): ?>
                <div class="lr-rate"><b>8 – 12 months</b>3% interest per month</div>
                <div class="lr-rate"><b>Collateral</b>Required, with evidence (JPG, PNG or PDF)</div>
            <?php else: ?>
                <div class="lr-rate"><b>1 month</b>10% interest</div>
                <div class="lr-rate"><b>2, 3 or 6 months</b>6.6% interest</div>
            <?php endif; ?>
            <div class="lr-rate"><b>Maximum amount</b><?= $loan_limit > 0 ? money($loan_limit) : 'Set by the administrator'; ?></div>
        </div>
    </div>
</div>

<?php if ($can_request): ?>
<!-- Loan Request Modal -->
<div class="modal-overlay" id="loanRequestModal">
    <div class="modal-box md">
        <form method="post" enctype="multipart/form-data" action="requested_loan.php" id="loanRequestForm" novalidate>
            <div class="modal-header">
                <div class="modal-title">
                    <span class="modal-ico"><i class="fas fa-file-invoice-dollar"></i></span>
                    New Loan Request
                </div>
                <button type="button" class="modal-close" data-close-modal aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger" id="lrError" <?= $reopen_with_error ? '' : 'style="display:none"'; ?>>
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= $reopen_with_error ? esc($flash_message) : ''; ?></span>
                </div>

                <div class="lr-form">
                    <div>
                        <div class="lr-section"><i class="fas fa-calculator"></i> Loan details</div>

                        <div class="form-group">
                            <label for="amount" class="form-label">Amount</label>
                            <div class="lr-input-prefix">
                                <span>UGX</span>
                                <input type="text" class="form-control" id="amount" name="amount" placeholder="0" inputmode="decimal" autocomplete="off" required>
                            </div>
                            <?php if ($loan_limit > 0): ?>
                                <div class="lr-limit" id="lrLimitBar"><div></div></div>
                                <div class="form-help" id="lrLimitText">Up to <?= money($loan_limit); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="loan_period" class="form-label">Repayment period</label>
                            <select class="form-select" id="loan_period" name="loan_period" required>
                                <option value="">Select a period</option>
                                <?php foreach ($period_options as $months => $label): ?>
                                    <option value="<?= $months; ?>"><?= esc($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="lr-section"><i class="fas fa-wallet"></i> Receive money via</div>

                        <div class="lr-methods">
                            <label class="lr-method">
                                <input type="radio" name="loan_method" value="Mobile Money" <?= $phone !== '' || $bank_account === '' ? 'checked' : ''; ?>>
                                <i class="fas fa-mobile-alt"></i> Mobile Money
                            </label>
                            <label class="lr-method">
                                <input type="radio" name="loan_method" value="Bank Transfer" <?= $phone === '' && $bank_account !== '' ? 'checked' : ''; ?>>
                                <i class="fas fa-university"></i> Bank Transfer
                            </label>
                        </div>

                        <div class="form-group" data-method="Mobile Money">
                            <label for="phone" class="form-label">Mobile Money number</label>
                            <input type="tel" class="form-control" id="phone" name="phone" value="<?= esc($phone); ?>" placeholder="e.g. 0772 123456">
                        </div>

                        <div class="grid-2" data-method="Bank Transfer">
                            <div class="form-group">
                                <label for="bank_name" class="form-label">Bank name</label>
                                <input type="text" class="form-control" id="bank_name" name="bank_name" value="<?= esc($bank_name); ?>" placeholder="e.g. Stanbic Bank">
                            </div>
                            <div class="form-group">
                                <label for="bank_account" class="form-label">Account number</label>
                                <input type="text" class="form-control" id="bank_account" name="bank_account" value="<?= esc($bank_account); ?>" placeholder="e.g. 9030012345678">
                            </div>
                        </div>

                        <?php if ($is_new_onboarded): ?>
                            <div class="lr-section"><i class="fas fa-shield-alt"></i> Collateral</div>
                            <div class="form-group">
                                <label for="collateral_description" class="form-label">Description</label>
                                <textarea class="form-control" id="collateral_description" name="collateral_description" rows="3" required placeholder="e.g. Motorcycle UBF 123K, logbook in my name"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="collateral_document" class="form-label">Evidence</label>
                                <input class="form-control" type="file" id="collateral_document" name="collateral_document" accept=".jpg,.jpeg,.png,.pdf" required>
                                <div class="form-help">JPG, PNG or PDF, up to 5 MB.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <aside>
                        <div class="lr-summary">
                            <h5>Loan summary</h5>
                            <div class="lr-row"><span>Amount</span><b id="sumAmount">UGX 0.00</b></div>
                            <div class="lr-row"><span>Period</span><b id="sumPeriod">-</b></div>
                            <div class="lr-row"><span>Interest rate</span><b id="sumRate">-</b></div>
                            <div class="lr-row"><span>Interest</span><b id="sumInterest">UGX 0.00</b></div>
                            <div class="lr-row total"><span>Total repayable</span><b id="sumTotal">UGX 0.00</b></div>
                            <div class="lr-note">
                                <i class="fas fa-info-circle"></i>
                                Your request is submitted as <strong>PENDING</strong> and reviewed by a loan officer.
                                You can edit or cancel it from Pending Loans until then.
                            </div>
                        </div>
                    </aside>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
                <button type="submit" class="btn btn-primary" id="lrSubmit">
                    <i class="fas fa-paper-plane"></i> Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const LOAN_LIMIT = <?= json_encode($loan_limit); ?>;
    const IS_NEW_ONBOARDED = <?= json_encode($is_new_onboarded); ?>;
    const MODAL_ID = 'loanRequestModal';

    const form = document.getElementById('loanRequestForm');
    const amountInput = document.getElementById('amount');
    const periodInput = document.getElementById('loan_period');
    const errorBox = document.getElementById('lrError');
    const submitBtn = document.getElementById('lrSubmit');
    const limitBar = document.getElementById('lrLimitBar');
    const limitText = document.getElementById('lrLimitText');
    const $id = (id) => document.getElementById(id);

    const toNumber = (v) => parseFloat(String(v || '').replace(/[^\d.]/g, '')) || 0;
    const money = (v) => 'UGX ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Same rules as requested_loan.php. Returns the total % for the period.
    function totalRate(period) {
        if (IS_NEW_ONBOARDED) return period >= 8 && period <= 12 ? 3 * period : 0;
        if (period === 1) return 10;
        if ([2, 3, 6].includes(period)) return 6.6;
        return 0;
    }

    function method() {
        const checked = form.querySelector('input[name="loan_method"]:checked');
        return checked ? checked.value : '';
    }

    function showError(message, field) {
        form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        errorBox.querySelector('span').textContent = message || '';
        errorBox.style.display = message ? '' : 'none';
        if (field) {
            field.classList.add('is-invalid');
            field.focus();
        }
        return false;
    }

    function toggleMethod() {
        const current = method();
        form.querySelectorAll('[data-method]').forEach((el) => {
            el.style.display = el.dataset.method === current ? '' : 'none';
        });
    }

    function update() {
        const amount = toNumber(amountInput.value);
        const period = parseInt(periodInput.value, 10) || 0;
        const rate = totalRate(period);
        const interest = amount * rate / 100;

        $id('sumAmount').textContent = money(amount);
        $id('sumPeriod').textContent = period ? period + (period === 1 ? ' month' : ' months') : '-';
        $id('sumRate').textContent = !rate ? '-' : (IS_NEW_ONBOARDED ? '3% × ' + period + ' = ' + rate + '%' : rate + '%');
        $id('sumInterest').textContent = money(interest);
        $id('sumTotal').textContent = money(amount + interest);

        if (limitBar && LOAN_LIMIT > 0) {
            const pct = Math.min(100, amount / LOAN_LIMIT * 100);
            limitBar.firstElementChild.style.width = pct + '%';
            limitBar.classList.toggle('over', amount > LOAN_LIMIT);
            limitText.textContent = amount > LOAN_LIMIT
                ? 'Above your limit of ' + money(LOAN_LIMIT)
                : money(Math.max(0, LOAN_LIMIT - amount)) + ' of ' + money(LOAN_LIMIT) + ' still available';
        }
    }

    amountInput.addEventListener('input', function () {
        // Thousands separators while typing.
        const raw = this.value.replace(/[^\d.]/g, '');
        if (raw !== '') {
            const parts = raw.split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            this.value = parts.slice(0, 2).join('.');
        }
        this.classList.remove('is-invalid');
        update();
    });
    periodInput.addEventListener('change', update);
    form.querySelectorAll('input[name="loan_method"]').forEach((r) => r.addEventListener('change', toggleMethod));

    form.addEventListener('submit', function (e) {
        const amount = toNumber(amountInput.value);
        const m = method();

        if (amount <= 0) { e.preventDefault(); return showError('Please enter the amount you want to borrow.', amountInput); }
        if (LOAN_LIMIT > 0 && amount > LOAN_LIMIT) { e.preventDefault(); return showError('The amount cannot exceed your loan limit of ' + money(LOAN_LIMIT) + '.', amountInput); }
        if (!periodInput.value) { e.preventDefault(); return showError('Please choose a repayment period.', periodInput); }
        if (m === 'Mobile Money' && !$id('phone').value.trim()) { e.preventDefault(); return showError('Please enter your Mobile Money number.', $id('phone')); }
        if (m === 'Bank Transfer') {
            if (!$id('bank_name').value.trim()) { e.preventDefault(); return showError('Please enter your bank name.', $id('bank_name')); }
            if (!$id('bank_account').value.trim()) { e.preventDefault(); return showError('Please enter your account number.', $id('bank_account')); }
        }
        if (IS_NEW_ONBOARDED) {
            const desc = $id('collateral_description');
            const file = $id('collateral_document');
            if (!desc.value.trim()) { e.preventDefault(); return showError('Please describe your collateral.', desc); }
            if (!file.files.length) { e.preventDefault(); return showError('Please upload collateral evidence.', file); }
            if (file.files[0].size > 5 * 1024 * 1024) { e.preventDefault(); return showError('Collateral evidence must be 5 MB or smaller.', file); }
        }

        showError('');
        amountInput.value = String(amount);
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    });

    // Open / close.
    document.getElementById('openLoanRequest').addEventListener('click', function () {
        Modal.open(MODAL_ID);
        setTimeout(() => amountInput.focus(), 150);
    });
    document.getElementById(MODAL_ID).addEventListener('click', function (e) {
        if (e.target === this || e.target.closest('[data-close-modal]')) {
            Modal.close(MODAL_ID);
        }
    });

    // Browser back-button restores the page from cache with the button disabled.
    window.addEventListener('pageshow', function () {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
    });

    toggleMethod();
    update();

    <?php if ($reopen_with_error): ?>
    document.addEventListener('DOMContentLoaded', function () { Modal.open(MODAL_ID); });
    <?php endif; ?>
})();
</script>
<?php endif; ?>

<?php require "footer.php"; ?>
