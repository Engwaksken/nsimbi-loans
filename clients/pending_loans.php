<?php
$phead = "Pending Loans";

require "nav-head.php";
require_once __DIR__ . '/../includes/loan_workflow.php';

/*
 * Client portal styling comes from assets/css/loanpro.css and
 * assets/js/loanpro.js (stats-grid / stat-card, modal-overlay + Modal,
 * Toast). Bootstrap is NOT loaded here, so don't use its grid or modals.
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

$client = null;
$pending_loans = [];

try {
    $has_category = loan_table_has_column($conn, 'customers', 'client_category');

    $stmt = $conn->prepare("
        SELECT c.id, c.user_id, c.loan_limit,
               " . ($has_category ? "c.client_category" : "'business' AS client_category") . ",
               u.fname, u.lname, u.username
        FROM customers c
        INNER JOIN user u ON u.id = c.user_id
        WHERE c.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($client) {
        // loan.client_id stores the user id (customers.user_id).
        $loan_client_id = (int)$client['user_id'];

        $stmt = $conn->prepare("
            SELECT id, amount, interest, loan_balance, loan_period, loan_method,
                   phone, bank_name, bank_account, status, due_date, datetime
            FROM loan
            WHERE client_id = ?
              AND UPPER(status) = 'PENDING'
            ORDER BY id DESC
        ");
        $stmt->bind_param("i", $loan_client_id);
        $stmt->execute();
        $pending_loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    error_log('pending_loans load failed: ' . $e->getMessage());
    echo '<div class="alert alert-danger">We could not load your pending loans right now. Please try again later.</div>';
    require "footer.php";
    exit;
}

if (!$client) {
    echo '<div class="alert alert-danger">Client profile not found. Please contact the administrator.</div>';
    require "footer.php";
    exit;
}

$full_name = trim(($client['fname'] ?? '') . ' ' . ($client['lname'] ?? ''));
if ($full_name === '') {
    $full_name = $client['username'] ?: 'Client';
}

$loan_limit       = (float)($client['loan_limit'] ?? 0);
$is_new_onboarded = ($client['client_category'] ?? 'business') === 'new_onboarded';
$period_options   = $is_new_onboarded ? [8, 9, 10, 11, 12] : [1, 2, 3, 6];

$total_amount = 0.0;
$total_repayable = 0.0;
foreach ($pending_loans as $loan) {
    $total_amount    += (float)$loan['amount'];
    $total_repayable += (float)$loan['loan_balance'];
}

$flash_message = $_SESSION['message'] ?? '';
$flash_type    = $_SESSION['message_type'] ?? 'info';
unset($_SESSION['message'], $_SESSION['message_type']);
?>

<?php if ($flash_message !== ''): ?>
    <div id="_flash" data-type="<?= esc($flash_type); ?>" style="display:none"><?= esc($flash_message); ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <div class="title">Pending Loan Requests</div>
        <div class="sub">Requests waiting for approval. You can edit or cancel them until they are reviewed.</div>
    </div>
    <a href="requesting_loan" class="btn btn-primary btn-sm">
        <i class="fas fa-plus"></i> Request a Loan
    </a>
</div>

<div class="stats-grid">
    <div class="stat-card c-blue">
        <div class="stat-head">
            <div>
                <div class="stat-label">Client</div>
                <div class="stat-value sm"><?= esc($full_name); ?></div>
            </div>
            <div class="stat-ico c-blue"><i class="fas fa-user"></i></div>
        </div>
    </div>

    <div class="stat-card c-orange">
        <div class="stat-head">
            <div>
                <div class="stat-label">Pending Requests</div>
                <div class="stat-value"><?= number_format(count($pending_loans)); ?></div>
            </div>
            <div class="stat-ico c-orange"><i class="fas fa-hourglass-half"></i></div>
        </div>
    </div>

    <div class="stat-card c-green">
        <div class="stat-head">
            <div>
                <div class="stat-label">Total Requested</div>
                <div class="stat-value sm"><?= money($total_amount); ?></div>
            </div>
            <div class="stat-ico c-green"><i class="fas fa-hand-holding-usd"></i></div>
        </div>
    </div>

    <div class="stat-card c-purple">
        <div class="stat-head">
            <div>
                <div class="stat-label">Total Repayable</div>
                <div class="stat-value sm"><?= money($total_repayable); ?></div>
            </div>
            <div class="stat-ico c-purple"><i class="fas fa-file-invoice-dollar"></i></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title"><span class="card-dot"></span> Pending Requests</div>
            <div class="card-sub">All pending loan requests submitted by you</div>
        </div>
        <?php if ($pending_loans): ?>
            <button type="button" class="btn btn-sm btn-outline" onclick="download_table_as_csv('pendingLoansTable', 'pending-loans.csv')">
                <i class="fas fa-download"></i> Export CSV
            </button>
        <?php endif; ?>
    </div>

    <div class="card-body no-pad">
        <?php if (!$pending_loans): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No pending loan requests</h3>
                <p>When you request a loan it will appear here until it is reviewed.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table" id="pendingLoansTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Amount</th>
                            <th>Interest</th>
                            <th>Repayable</th>
                            <th>Period</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Requested</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_loans as $i => $loan): ?>
                            <?php
                            $loan_json = json_encode([
                                'id'           => (int)$loan['id'],
                                'amount'       => (float)$loan['amount'],
                                'loan_period'  => (int)$loan['loan_period'],
                                'loan_method'  => (string)($loan['loan_method'] ?? ''),
                                'phone'        => (string)($loan['phone'] ?? ''),
                                'bank_name'    => (string)($loan['bank_name'] ?? ''),
                                'bank_account' => (string)($loan['bank_account'] ?? ''),
                            ]);
                            $period = (int)$loan['loan_period'];
                            ?>
                            <tr>
                                <td><?= $i + 1; ?></td>
                                <td class="fw-600"><?= money($loan['amount']); ?></td>
                                <td><?= money($loan['interest']); ?></td>
                                <td class="fw-600"><?= money($loan['loan_balance']); ?></td>
                                <td><?= $period; ?> <?= $period === 1 ? 'Month' : 'Months'; ?></td>
                                <td><?= esc($loan['loan_method'] ?: '-'); ?></td>
                                <td><span class="badge badge-warning"><?= esc($loan['status']); ?></span></td>
                                <td><?= esc($loan['datetime'] ? date('d M Y, H:i', strtotime((string)$loan['datetime'])) : '-'); ?></td>
                                <td class="text-center">
                                    <div class="d-flex gap-2 justify-center">
                                        <button type="button" class="btn btn-info btn-icon btn-sm js-edit-loan" title="Edit request" data-loan="<?= esc($loan_json); ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-danger btn-icon btn-sm js-cancel-loan" title="Cancel request" data-id="<?= (int)$loan['id']; ?>" data-amount="<?= esc(money($loan['amount'])); ?>">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Pending Loan Modal -->
<div class="modal-overlay" id="editLoanModal">
    <div class="modal-box">
        <form id="editLoanForm" novalidate>
            <input type="hidden" name="action" value="update_pending_loan">
            <input type="hidden" name="loan_id" id="edit_loan_id">

            <div class="modal-header">
                <div class="modal-title">
                    <span class="modal-ico"><i class="fas fa-edit"></i></span>
                    Edit Pending Loan
                </div>
                <button type="button" class="modal-close" data-close-modal aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger js-modal-error" style="display:none"></div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="edit_amount">Loan Amount</label>
                        <input type="text" class="form-control" id="edit_amount" name="amount" inputmode="decimal" required>
                        <?php if ($loan_limit > 0): ?>
                            <div class="form-help">Maximum allowed: <?= money($loan_limit); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit_period">Loan Period</label>
                        <select class="form-select" id="edit_period" name="loan_period" required>
                            <?php foreach ($period_options as $months): ?>
                                <option value="<?= $months; ?>">
                                    <?= $months; ?> <?= $months === 1 ? 'Month' : 'Months'; ?>
                                    - <?= $is_new_onboarded ? '3% monthly' : ($months === 1 ? '10%' : '6.6%'); ?> interest
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="edit_method">Disbursement Method</label>
                    <select class="form-select" id="edit_method" name="loan_method" required>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                    </select>
                </div>

                <div class="form-group js-method" data-method="Mobile Money">
                    <label class="form-label" for="edit_phone">Mobile Money Number</label>
                    <input type="text" class="form-control" id="edit_phone" name="phone">
                </div>

                <div class="grid-2 js-method" data-method="Bank Transfer">
                    <div class="form-group">
                        <label class="form-label" for="edit_bank_name">Bank Name</label>
                        <input type="text" class="form-control" id="edit_bank_name" name="bank_name">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_bank_account">Account Number</label>
                        <input type="text" class="form-control" id="edit_bank_account" name="bank_account">
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-item"><div class="info-label">Interest</div><div class="info-value" id="edit_sum_interest">UGX 0.00</div></div>
                    <div class="info-item"><div class="info-label">Total Repayable</div><div class="info-value" id="edit_sum_total">UGX 0.00</div></div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-close-modal>Close</button>
                <button type="submit" class="btn btn-primary js-submit">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Pending Loan Modal -->
<div class="modal-overlay" id="cancelLoanModal">
    <div class="modal-box sm">
        <form id="cancelLoanForm">
            <input type="hidden" name="action" value="cancel_pending_loan">
            <input type="hidden" name="loan_id" id="cancel_loan_id">

            <div class="modal-header">
                <div class="modal-title">
                    <span class="modal-ico"><i class="fas fa-exclamation-triangle"></i></span>
                    Cancel Loan Request
                </div>
                <button type="button" class="modal-close" data-close-modal aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger js-modal-error" style="display:none"></div>
                <p class="mb-0">
                    Cancel your pending request for <strong id="cancel_loan_amount"></strong>?
                    This cannot be undone.
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-close-modal>Keep Request</button>
                <button type="submit" class="btn btn-danger js-submit">
                    <i class="fas fa-times"></i> Yes, Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const PROCESS_URL = 'includes/process-loans.php';
    const IS_NEW_ONBOARDED = <?= json_encode($is_new_onboarded); ?>;
    const LOAN_LIMIT = <?= json_encode($loan_limit); ?>;

    const $ = (id) => document.getElementById(id);

    function toNumber(value) {
        return parseFloat(String(value || '').replace(/[^\d.]/g, '')) || 0;
    }

    function formatMoney(value) {
        return 'UGX ' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Same rules as clients/requested_loan.php and includes/process-loans.php.
    function interestFor(amount, period) {
        if (IS_NEW_ONBOARDED) return amount * 0.03 * period;
        if (period === 1) return amount * 0.10;
        if ([2, 3, 6].includes(period)) return amount * 0.066;
        return 0;
    }

    function showError(form, message) {
        const box = form.querySelector('.js-modal-error');
        box.textContent = message;
        box.style.display = message ? '' : 'none';
    }

    function openModal(id) {
        const form = $(id).querySelector('form');
        showError(form, '');
        Modal.open(id);
    }

    // Close buttons and backdrop clicks (Escape is handled by loanpro.js).
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay || e.target.closest('[data-close-modal]')) {
                Modal.close(overlay.id);
            }
        });
    });

    /* ---------- Edit ---------- */
    const editForm = $('editLoanForm');

    function toggleMethod() {
        const method = $('edit_method').value;
        editForm.querySelectorAll('.js-method').forEach(function (el) {
            el.style.display = el.dataset.method === method ? '' : 'none';
        });
    }

    function updateSummary() {
        const amount = toNumber($('edit_amount').value);
        const interest = interestFor(amount, parseInt($('edit_period').value, 10));
        $('edit_sum_interest').textContent = formatMoney(interest);
        $('edit_sum_total').textContent = formatMoney(amount + interest);
    }

    document.querySelectorAll('.js-edit-loan').forEach(function (button) {
        button.addEventListener('click', function () {
            const loan = JSON.parse(this.dataset.loan);

            $('edit_loan_id').value = loan.id;
            $('edit_amount').value = Number(loan.amount).toLocaleString(undefined, { maximumFractionDigits: 2 });
            $('edit_period').value = String(loan.loan_period);
            if (!$('edit_period').value) $('edit_period').selectedIndex = 0;
            $('edit_method').value = loan.loan_method === 'Bank Transfer' ? 'Bank Transfer' : 'Mobile Money';
            $('edit_phone').value = loan.phone;
            $('edit_bank_name').value = loan.bank_name;
            $('edit_bank_account').value = loan.bank_account;

            toggleMethod();
            updateSummary();
            openModal('editLoanModal');
        });
    });

    $('edit_method').addEventListener('change', toggleMethod);
    $('edit_period').addEventListener('change', updateSummary);
    $('edit_amount').addEventListener('input', updateSummary);

    editForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const amount = toNumber($('edit_amount').value);
        const method = $('edit_method').value;

        if (amount <= 0) return showError(editForm, 'Please enter a valid loan amount.');
        if (LOAN_LIMIT > 0 && amount > LOAN_LIMIT) return showError(editForm, 'Amount cannot exceed your loan limit of ' + formatMoney(LOAN_LIMIT) + '.');
        if (method === 'Mobile Money' && !$('edit_phone').value.trim()) return showError(editForm, 'Please enter your Mobile Money number.');
        if (method === 'Bank Transfer' && (!$('edit_bank_name').value.trim() || !$('edit_bank_account').value.trim())) {
            return showError(editForm, 'Please enter both bank name and account number.');
        }

        const data = new FormData(editForm);
        data.set('amount', String(amount));
        submit(editForm, data, 'editLoanModal');
    });

    /* ---------- Cancel ---------- */
    const cancelForm = $('cancelLoanForm');

    document.querySelectorAll('.js-cancel-loan').forEach(function (button) {
        button.addEventListener('click', function () {
            $('cancel_loan_id').value = this.dataset.id;
            $('cancel_loan_amount').textContent = this.dataset.amount;
            openModal('cancelLoanModal');
        });
    });

    cancelForm.addEventListener('submit', function (e) {
        e.preventDefault();
        submit(cancelForm, new FormData(cancelForm), 'cancelLoanModal');
    });

    /* ---------- Shared submit ---------- */
    async function submit(form, data, modalId) {
        const button = form.querySelector('.js-submit');
        const original = button.innerHTML;

        showError(form, '');
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Please wait...';

        try {
            const response = await fetch(PROCESS_URL, { method: 'POST', body: data, credentials: 'same-origin' });
            const result = await response.json();

            if (!result.success) {
                showError(form, result.message || 'Request failed. Please try again.');
                return;
            }

            Modal.close(modalId);
            Toast.success(result.message || 'Done.');
            setTimeout(function () { location.reload(); }, 900);
        } catch (err) {
            console.error(err);
            showError(form, 'Could not reach the server. Please try again.');
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    }
})();
</script>

<?php require "footer.php"; ?>
