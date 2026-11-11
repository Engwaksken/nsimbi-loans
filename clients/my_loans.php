<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../php/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit;
}

if ((int)($_SESSION['role'] ?? 0) !== 3) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);

if ($user_id <= 0) {
    header('Location: ../login.php');
    exit;
}

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return 'UGX ' . number_format((float)$amount, 0);
}

function fetch_one(mysqli $conn, string $sql, int $id): float
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];

    $stmt->close();

    return (float)($row[0] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Customer profile
|--------------------------------------------------------------------------
*/
$customerStmt = $conn->prepare("
    SELECT
        c.id,
        c.user_id,
        c.phone,
        c.bank_name,
        c.bank_account,
        c.loan_limit,
        c.active,
        c.business_name,
        c.district,
        c.location,
        u.fname,
        u.lname,
        u.email,
        u.username
    FROM customers c
    INNER JOIN user u ON u.id = c.user_id
    WHERE c.user_id = ?
    LIMIT 1
");

if (!$customerStmt) {
    die('Failed to prepare customer query.');
}

$customerStmt->bind_param('i', $user_id);
$customerStmt->execute();
$customerResult = $customerStmt->get_result();
$customer = $customerResult ? $customerResult->fetch_assoc() : null;
$customerStmt->close();

require_once __DIR__ . '/nav-head.php';

if (!$customer) {
    echo '<div class="page-content">
            <div class="alert alert-danger">
                <i class="fa fa-exclamation-circle"></i>
                <span><strong>Client profile not found.</strong><br>Please contact the administrator.</span>
            </div>
          </div>';

    require_once __DIR__ . '/footer.php';
    exit;
}

// loans.client_id stores the user id (customers.user_id), not customers.id.
$client_id = (int)($customer['user_id'] ?? $user_id);
$loan_limit = (float)($customer['loan_limit'] ?? 0);
$customer_active = (int)($customer['active'] ?? 0);

$full_name = trim((string)($customer['fname'] ?? '') . ' ' . (string)($customer['lname'] ?? ''));
if ($full_name === '') {
    $full_name = (string)($customer['username'] ?? 'Client');
}

/*
|--------------------------------------------------------------------------
| Loan statistics
|--------------------------------------------------------------------------
*/
$totalLoans = fetch_one(
    $conn,
    "SELECT COALESCE(SUM(amount),0) FROM loan WHERE client_id = ?",
    $client_id
);

$totalBalance = fetch_one(
    $conn,
    "SELECT COALESCE(SUM(loan_balance),0)
     FROM loan
     WHERE client_id = ?
       AND UPPER(status) IN ('OUTSTANDING','OVERDUE')",
    $client_id
);

$totalPaid = fetch_one(
    $conn,
    "SELECT COALESCE(SUM(amount),0)
     FROM payments
     WHERE user_id = ?
       AND UPPER(status) = 'SUCCESSFUL'",
    $user_id
);

$pendingCount = (int)fetch_one(
    $conn,
    "SELECT COUNT(*)
     FROM loan
     WHERE client_id = ?
       AND UPPER(status) = 'PENDING'",
    $client_id
);

$activeLoan = (int)fetch_one(
    $conn,
    "SELECT COUNT(*)
     FROM loan
     WHERE client_id = ?
       AND UPPER(status) NOT IN ('CLEARED','REJECTED','CANCELLED')",
    $client_id
);

$can_request_new = ($customer_active === 1 && $activeLoan < 1);

/*
|--------------------------------------------------------------------------
| Loans
|--------------------------------------------------------------------------
*/
$loanStmt = $conn->prepare("
    SELECT
        id,
        client_id,
        amount,
        interest_rate,
        interest,
        loan_balance,
        loan_period,
        loan_method,
        phone,
        bank_name,
        bank_account,
        status,
        due_date,
        datetime
    FROM loan
    WHERE client_id = ?
    ORDER BY id DESC
");

if (!$loanStmt) {
    die('Failed to prepare loan query.');
}

$loanStmt->bind_param('i', $client_id);
$loanStmt->execute();
$loans = $loanStmt->get_result();

if (file_exists(__DIR__ . '/services_check.php')) {
    require_once __DIR__ . '/services_check.php';
}
?>

<style>
.my-loans-page {
    padding: 1.4rem 1.5rem;
}

.loan-page-hero {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: var(--r-xl);
    padding: 1.4rem;
    color: #fff;
    box-shadow: var(--shadow-md);
    margin-bottom: 1rem;
}

.loan-page-hero h4 {
    color: #fff;
    font-size: 1.25rem;
    font-weight: 900;
    margin-bottom: .25rem;
}

.loan-page-hero p {
    color: rgba(255,255,255,.75);
    margin: 0;
    font-size: .86rem;
}

.loan-hero-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.loan-summary-box {
    background: var(--body-bg);
    border: 1px dashed var(--border);
    border-radius: var(--r-lg);
    padding: 1rem;
}

.summary-line {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    border-bottom: 1px solid var(--border-light);
    padding: .48rem 0;
}

.summary-line:last-child {
    border-bottom: 0;
}

.summary-line span:first-child {
    color: var(--text-muted);
}

.summary-line span:last-child {
    color: var(--text-dark);
    font-weight: 800;
}

.loan-form-grid {
    display: grid;
    grid-template-columns: 1.4fr .9fr;
    gap: 1rem;
}

.loan-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .85rem;
}

.modal-alert {
    display: none;
}

.loan-actions {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    flex-wrap: nowrap;
}

.loan-action-btn {
    border: 0;
    cursor: pointer;
}

.loan-table-min {
    min-width: 1050px;
}

@media(max-width: 900px) {
    .loan-form-grid,
    .loan-form-row {
        grid-template-columns: 1fr;
    }
}

@media(max-width: 640px) {
    .my-loans-page {
        padding: 1rem;
    }
}

/* =========================
   CUSTOM MODALS
========================= */

.modal-overlay{
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.65);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    padding: 1rem;
    overflow-y: auto;
}

.modal-overlay.open{
    display: flex;
}

.modal-box{
    width: 100%;
    max-width: 520px;
    background: #fff;
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0,0,0,.25);
    animation: modalIn .22s ease;
}

.modal-box.md{
    max-width: 920px;
}

.modal-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:1rem 1.2rem;
    border-bottom:1px solid #edf2f7;
    background:#fff;
}

.modal-title{
    display:flex;
    align-items:center;
    gap:.7rem;
    font-size:1rem;
    font-weight:800;
    color:#0f172a;
    margin:0;
}

.modal-ico{
    width:38px;
    height:38px;
    border-radius:12px;
    background:#eff6ff;
    color:#2563eb;
    display:flex;
    align-items:center;
    justify-content:center;
}

.modal-close{
    border:0;
    background:transparent;
    width:38px;
    height:38px;
    border-radius:10px;
    cursor:pointer;
    color:#64748b;
    transition:.2s;
}

.modal-close:hover{
    background:#f1f5f9;
    color:#0f172a;
}

.modal-body{
    padding:1.2rem;
    background:#fff;
}

.modal-footer{
    padding:1rem 1.2rem;
    border-top:1px solid #edf2f7;
    display:flex;
    justify-content:flex-end;
    gap:.7rem;
    background:#fff;
}

@keyframes modalIn{
    from{
        opacity:0;
        transform:translateY(12px) scale(.97);
    }
    to{
        opacity:1;
        transform:translateY(0) scale(1);
    }
}
</style>

<div class="my-loans-page">

    <div class="loan-page-hero">
        <div class="loan-hero-inner">
            <div>
                <h4>My Loans</h4>
                <p>View your loans, request a new loan, edit pending requests, and submit repayments.</p>
            </div>

          <button
    type="button"
    class="btn btn-secondary"
    data-open-modal="loanRequestModal"
>
    <i class="fa fa-plus"></i>
    Request New Loan
</button>
        </div>
    </div>

    <?php if ($customer_active !== 1): ?>
        <div class="alert alert-warning">
            <i class="fa fa-exclamation-triangle"></i>
            <span>Your customer profile is inactive. You cannot request a new loan until your profile is activated.</span>
        </div>
    <?php endif; ?>

    <?php if (!$can_request_new && $customer_active === 1): ?>
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <span>You currently have a pending, outstanding, or overdue loan. You can only request a new loan after the current one is cleared, rejected, or cancelled.</span>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card c-blue">
            <div class="stat-head">
                <div>
                    <div class="stat-label">Client Name</div>
                    <div class="stat-value sm"><?= esc($full_name); ?></div>
                </div>
                <div class="stat-ico c-blue"><i class="fa fa-user"></i></div>
            </div>
        </div>

        <div class="stat-card c-green">
            <div class="stat-head">
                <div>
                    <div class="stat-label">Loan Limit</div>
                    <div class="stat-value sm"><?= money($loan_limit); ?></div>
                </div>
                <div class="stat-ico c-green"><i class="fa fa-wallet"></i></div>
            </div>
        </div>

        <div class="stat-card c-purple">
            <div class="stat-head">
                <div>
                    <div class="stat-label">Total Borrowed</div>
                    <div class="stat-value sm"><?= money($totalLoans); ?></div>
                </div>
                <div class="stat-ico c-purple"><i class="fa fa-hand-holding-usd"></i></div>
            </div>
        </div>

        <div class="stat-card c-orange">
            <div class="stat-head">
                <div>
                    <div class="stat-label">Outstanding Balance</div>
                    <div class="stat-value sm"><?= money($totalBalance); ?></div>
                </div>
                <div class="stat-ico c-orange"><i class="fa fa-balance-scale"></i></div>
            </div>
        </div>

        <div class="stat-card c-teal">
            <div class="stat-head">
                <div>
                    <div class="stat-label">Total Paid</div>
                    <div class="stat-value sm"><?= money($totalPaid); ?></div>
                </div>
                <div class="stat-ico c-teal"><i class="fa fa-check-circle"></i></div>
            </div>
        </div>

        <div class="stat-card c-indigo">
            <div class="stat-head">
                <div>
                    <div class="stat-label">Pending Loans</div>
                    <div class="stat-value sm"><?= $pendingCount; ?></div>
                </div>
                <div class="stat-ico c-indigo"><i class="fa fa-clock"></i></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">
                    <span class="card-dot"></span>
                    Loan History
                </div>
                <div class="card-sub">Pending loans can be edited. Outstanding or overdue loans can be paid.</div>
            </div>

            <button type="button" class="btn btn-sm btn-outline" onclick="download_table_as_csv('loanTable')">
                <i class="fa fa-download"></i>
                Export CSV
            </button>
        </div>

        <div class="card-body no-pad">
            <div class="table-wrap">
                <table class="table loan-table-min data-table" id="loanTable">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Amount</th>
                        <th>Interest</th>
                        <th>Balance</th>
                        <th>Period</th>
                        <th>Method</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Issue Date</th>
                        <th class="text-center">Due Date</th>
                        <th class="text-center">Action</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if ($loans && $loans->num_rows > 0): ?>
                        <?php $counter = 1; ?>
                        <?php while ($loan = $loans->fetch_assoc()): ?>
                            <?php
                            $status = strtoupper((string)$loan['status']);

                            $badgeClass = match ($status) {
                                'CLEARED' => 'badge-success',
                                'PENDING' => 'badge-warning',
                                'OUTSTANDING' => 'badge-info',
                                'OVERDUE' => 'badge-danger',
                                'REJECTED', 'CANCELLED' => 'badge-muted',
                                default => 'badge-muted',
                            };

                            $canEdit = ($status === 'PENDING');
                            $canPay = in_array($status, ['OUTSTANDING', 'OVERDUE'], true)
                                && (float)$loan['loan_balance'] > 0;
                            ?>

                            <tr>
                                <td><?= $counter++; ?></td>
                                <td class="td-dark"><?= money($loan['amount']); ?></td>
                                <td><?= money($loan['interest']); ?></td>
                                <td><?= money($loan['loan_balance']); ?></td>
                                <td><?= (int)$loan['loan_period']; ?> Month<?= (int)$loan['loan_period'] === 1 ? '' : 's'; ?></td>
                                <td><?= esc($loan['loan_method'] ?? '-'); ?></td>

                                <td class="text-center">
                                    <span class="badge <?= $badgeClass; ?>">
                                        <?= esc($status); ?>
                                    </span>
                                </td>

                                <td class="text-center"><?= esc($loan['datetime'] ?? '-'); ?></td>
                                <td class="text-center"><?= esc($loan['due_date'] ?? '-'); ?></td>

                                <td class="text-center">
                                    <div class="loan-actions">
                                        <a
                                            href="view_loan.php?id=<?= (int)$loan['id']; ?>&user_id=<?= (int)$loan['client_id']; ?>"
                                            class="badge badge-info"
                                            title="View"
                                        >
                                            <i class="fa fa-eye"></i>
                                        </a>

                                        <?php if ($canEdit): ?>
                                            <button
                                                type="button"
                                                class="badge badge-warning loan-action-btn btn-edit-loan"
                                                data-id="<?= (int)$loan['id']; ?>"
                                                title="Edit Pending Loan"
                                            >
                                                <i class="fa fa-edit"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($canPay): ?>
                                            <button
                                                type="button"
                                                class="badge badge-success loan-action-btn btn-pay-loan"
                                                data-id="<?= (int)$loan['id']; ?>"
                                                data-balance="<?= esc($loan['loan_balance']); ?>"
                                                title="Pay Loan"
                                            >
                                                <i class="fa fa-credit-card"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10">
                                <div class="empty-state">
                                    <i class="fa fa-folder-open"></i>
                                    <h3>No loans found</h3>
                                    <p>Your loan history will appear here.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<!-- Request Loan Modal -->
<div class="modal-overlay" id="loanRequestModal">
    <div class="modal-box md">
        <form id="loanRequestForm" class="ajax-loan-form">
            <input type="hidden" name="action" value="create_loan">

            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-ico"><i class="fa fa-plus"></i></span>
                    Request New Loan
                </h5>

                <button type="button" class="modal-close" data-close-modal>
                    <i class="fa fa-times"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="alert modal-alert" role="alert"></div>

                <?php if (!$can_request_new): ?>
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle"></i>
                        <span>
                            You cannot submit a new loan request now because you already have an active, pending, outstanding, or overdue loan.
                        </span>
                    </div>
                <?php endif; ?>

                <div class="loan-form-grid">
                    <div>
                        <div class="form-group">
                            <label class="form-label">Loan Amount</label>
                            <input
                                type="text"
                                class="form-control amount-input"
                                name="amount"
                                required
                                inputmode="decimal"
                                placeholder="e.g. 100,000"
                            >
                            <div class="form-help">Maximum allowed: <?= money($loan_limit); ?></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Loan Period</label>
                            <select class="form-select period-input" name="loan_period" required>
                                <option value="">Select period</option>
                                <option value="1">1 Month - 10% Interest</option>
                                <option value="2">2 Months - 6.6% Interest</option>
                                <option value="3">3 Months - 6.6% Interest</option>
                                <option value="6">6 Months - 6.6% Interest</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Disbursement Method</label>
                            <select class="form-select method-input" name="loan_method" required>
                                <option value="">Select method</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                        </div>

                        <div class="method-mobile" style="display:none;">
                            <div class="form-group">
                                <label class="form-label">Mobile Money Number</label>
                                <input
                                    type="text"
                                    class="form-control phone-input"
                                    name="phone"
                                    value="<?= esc($customer['phone'] ?? ''); ?>"
                                    placeholder="Enter mobile money number"
                                >
                            </div>
                        </div>

                        <div class="method-bank" style="display:none;">
                            <div class="loan-form-row">
                                <div class="form-group">
                                    <label class="form-label">Bank Name</label>
                                    <input
                                        type="text"
                                        class="form-control bank-name-input"
                                        name="bank_name"
                                        value="<?= esc($customer['bank_name'] ?? ''); ?>"
                                    >
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Bank Account</label>
                                    <input
                                        type="text"
                                        class="form-control bank-account-input"
                                        name="bank_account"
                                        value="<?= esc($customer['bank_account'] ?? ''); ?>"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="loan-summary-box">
                            <h6 class="mb-3">Loan Summary</h6>
                            <div class="summary-line">
                                <span>Amount</span>
                                <span class="summary-amount">UGX 0</span>
                            </div>
                            <div class="summary-line">
                                <span>Rate</span>
                                <span class="summary-rate">0%</span>
                            </div>
                            <div class="summary-line">
                                <span>Interest</span>
                                <span class="summary-interest">UGX 0</span>
                            </div>
                            <div class="summary-line">
                                <span>Total Repayable</span>
                                <span class="summary-total">UGX 0</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-close-modal>Close</button>

                <button
                    type="submit"
                    class="btn btn-primary btn-submit"
                    <?= !$can_request_new ? 'disabled' : ''; ?>
                >
                    Submit Request
                </button>
            </div>
        </form>
    </div>
</div>
<!-- Edit Loan Modal -->
<div class="modal-overlay" id="editLoanModal">
    <div class="modal-box md">
        <form id="editLoanForm" class="ajax-loan-form">
            <input type="hidden" name="action" value="update_pending_loan">
            <input type="hidden" name="loan_id" id="edit_loan_id">

            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-ico"><i class="fa fa-edit"></i></span>
                    Edit Pending Loan
                </h5>
                <button type="button" class="modal-close" data-close-modal>
                    <i class="fa fa-times"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="alert modal-alert" role="alert"></div>

                <div class="loan-form-grid">
                    <div>
                        <div class="form-group">
                            <label class="form-label">Loan Amount</label>
                            <input type="text" class="form-control amount-input" name="amount" id="edit_amount" required>
                            <div class="form-help">Maximum allowed: <?= money($loan_limit); ?></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Loan Period</label>
                            <select class="form-select period-input" name="loan_period" id="edit_loan_period" required>
                                <option value="">Select period</option>
                                <option value="1">1 Month - 10% Interest</option>
                                <option value="2">2 Months - 6.6% Interest</option>
                                <option value="3">3 Months - 6.6% Interest</option>
                                <option value="6">6 Months - 6.6% Interest</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Disbursement Method</label>
                            <select class="form-select method-input" name="loan_method" id="edit_loan_method" required>
                                <option value="">Select method</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                        </div>

                        <div class="method-mobile" style="display:none;">
                            <div class="form-group">
                                <label class="form-label">Mobile Money Number</label>
                                <input type="text" class="form-control phone-input" name="phone" id="edit_phone">
                            </div>
                        </div>

                        <div class="method-bank" style="display:none;">
                            <div class="loan-form-row">
                                <div class="form-group">
                                    <label class="form-label">Bank Name</label>
                                    <input type="text" class="form-control bank-name-input" name="bank_name" id="edit_bank_name">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Bank Account</label>
                                    <input type="text" class="form-control bank-account-input" name="bank_account" id="edit_bank_account">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="loan-summary-box">
                            <h6 class="mb-3">Updated Summary</h6>
                            <div class="summary-line"><span>Amount</span><span class="summary-amount">UGX 0</span></div>
                            <div class="summary-line"><span>Rate</span><span class="summary-rate">0%</span></div>
                            <div class="summary-line"><span>Interest</span><span class="summary-interest">UGX 0</span></div>
                            <div class="summary-line"><span>Total Repayable</span><span class="summary-total">UGX 0</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-close-modal>Close</button>
                <button type="submit" class="btn btn-warning btn-submit">Update Pending Loan</button>
            </div>
        </form>
    </div>
</div>

<!-- Pay Loan Modal -->
<div class="modal-overlay" id="payLoanModal">
    <div class="modal-box">
        <form id="payLoanForm">
            <input type="hidden" name="action" value="pay_loan">
            <input type="hidden" name="loan_id" id="pay_loan_id">

            <div class="modal-header">
                <h5 class="modal-title">
                    <span class="modal-ico"><i class="fa fa-credit-card"></i></span>
                    Pay Loan
                </h5>
                <button type="button" class="modal-close" data-close-modal>
                    <i class="fa fa-times"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="alert modal-alert" role="alert"></div>

                <div class="form-group">
                    <label class="form-label">Outstanding Balance</label>
                    <input type="text" class="form-control" id="pay_balance_display" readonly>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Amount</label>
                    <input type="text" class="form-control" name="amount" id="pay_amount" required placeholder="e.g. 50,000">
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select class="form-select" name="payment_method" required>
                        <option value="">Select method</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cash">Cash</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Reference / Transaction ID</label>
                    <input type="text" class="form-control" name="reference" placeholder="Optional transaction reference">
                </div>

                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i>
                    <span>Payment will reduce your outstanding loan balance after submission.</span>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-close-modal>Close</button>
                <button type="submit" class="btn btn-success btn-submit">Submit Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    const LOAN_LIMIT = <?= json_encode($loan_limit); ?>;
    const PROCESS_URL = 'includes/process-loans.php';

    function toNumber(value) {
        return parseFloat(String(value ?? '').replace(/,/g, '').replace(/[^\d.]/g, '')) || 0;
    }

    function formatMoney(amount) {
        return 'UGX ' + Number(amount || 0).toLocaleString(undefined, {
            maximumFractionDigits: 0
        });
    }

    function formatInputMoney(input) {
        const raw = input.value.replace(/,/g, '').replace(/[^\d.]/g, '');

        if (raw === '') {
            input.value = '';
            return;
        }

        const parts = raw.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        input.value = parts.join('.');
    }

    function interestRate(period) {
        period = parseInt(period || 0, 10);

        if (period === 1) return 10;
        if (period === 2 || period === 3 || period === 6) return 6.6;

        return 0;
    }

    function showAlert(container, message, type = 'danger') {
        if (!container) return;

        container.className = 'alert modal-alert alert-' + type;
        container.textContent = message;
        container.style.display = 'flex';
    }

    function clearAlert(container) {
        if (!container) return;

        container.style.display = 'none';
        container.className = 'alert modal-alert';
        container.textContent = '';
    }

    function openModal(modalId) {
        const modal = document.getElementById(modalId);

        if (!modal) {
            alert('Modal not found: ' + modalId);
            return;
        }

        const alertBox = modal.querySelector('.modal-alert');
        clearAlert(alertBox);

        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;

        modal.classList.remove('open');

        if (!document.querySelector('.modal-overlay.open')) {
            document.body.style.overflow = '';
        }
    }

    document.addEventListener('click', function (e) {
        const openBtn = e.target.closest('[data-open-modal]');

        if (openBtn) {
            e.preventDefault();

            const modalId = openBtn.getAttribute('data-open-modal');
            openModal(modalId);
            return;
        }

        const closeBtn = e.target.closest('[data-close-modal]');

        if (closeBtn) {
            e.preventDefault();

            const modal = closeBtn.closest('.modal-overlay');
            closeModal(modal);
            return;
        }

        if (e.target.classList.contains('modal-overlay')) {
            closeModal(e.target);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(function (modal) {
                closeModal(modal);
            });
        }
    });

    function setupLoanForm(form) {
        const amountInput = form.querySelector('.amount-input');
        const periodInput = form.querySelector('.period-input');
        const methodInput = form.querySelector('.method-input');
        const mobileSection = form.querySelector('.method-mobile');
        const bankSection = form.querySelector('.method-bank');
        const phoneInput = form.querySelector('.phone-input');
        const bankNameInput = form.querySelector('.bank-name-input');
        const bankAccountInput = form.querySelector('.bank-account-input');
        const summaryAmount = form.querySelector('.summary-amount');
        const summaryRate = form.querySelector('.summary-rate');
        const summaryInterest = form.querySelector('.summary-interest');
        const summaryTotal = form.querySelector('.summary-total');
        const alertBox = form.querySelector('.modal-alert');

        function updateSummary() {
            const amount = toNumber(amountInput?.value);
            const rate = interestRate(periodInput?.value);
            const interest = amount * (rate / 100);

            if (summaryAmount) summaryAmount.textContent = formatMoney(amount);
            if (summaryRate) summaryRate.textContent = rate + '%';
            if (summaryInterest) summaryInterest.textContent = formatMoney(interest);
            if (summaryTotal) summaryTotal.textContent = formatMoney(amount + interest);
        }

        function toggleMethodFields() {
            const method = methodInput ? methodInput.value : '';

            if (mobileSection) mobileSection.style.display = 'none';
            if (bankSection) bankSection.style.display = 'none';

            if (phoneInput) phoneInput.required = false;
            if (bankNameInput) bankNameInput.required = false;
            if (bankAccountInput) bankAccountInput.required = false;

            if (method === 'Mobile Money') {
                if (mobileSection) mobileSection.style.display = 'block';
                if (phoneInput) phoneInput.required = true;
            }

            if (method === 'Bank Transfer') {
                if (bankSection) bankSection.style.display = 'block';
                if (bankNameInput) bankNameInput.required = true;
                if (bankAccountInput) bankAccountInput.required = true;
            }
        }

        if (amountInput) {
            amountInput.addEventListener('input', function () {
                formatInputMoney(amountInput);
                updateSummary();
            });
        }

        if (periodInput) {
            periodInput.addEventListener('change', updateSummary);
        }

        if (methodInput) {
            methodInput.addEventListener('change', toggleMethodFields);
        }

        toggleMethodFields();
        updateSummary();

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            clearAlert(alertBox);

            const amount = toNumber(amountInput?.value);

            if (amount <= 0) {
                showAlert(alertBox, 'Please enter a valid loan amount.');
                return;
            }

            if (LOAN_LIMIT > 0 && amount > LOAN_LIMIT) {
                showAlert(alertBox, 'Loan amount cannot exceed your loan limit of ' + formatMoney(LOAN_LIMIT) + '.');
                return;
            }

            const formData = new FormData(form);
            formData.set('amount', amount);

            const btn = form.querySelector('.btn-submit');
            const originalText = btn ? btn.textContent.trim() : '';

            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Processing...';
            }

            try {
                const response = await fetch(PROCESS_URL, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (!data.success) {
                    showAlert(alertBox, data.message || 'Request failed.');
                    return;
                }

                showAlert(alertBox, data.message || 'Saved successfully.', 'success');

                setTimeout(function () {
                    window.location.reload();
                }, 900);

            } catch (error) {
                showAlert(alertBox, 'Network error. Please try again.');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = originalText;
                }
            }
        });
    }

    document.querySelectorAll('.ajax-loan-form').forEach(setupLoanForm);

    document.querySelectorAll('.btn-edit-loan').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const loanId = this.dataset.id;

            const formData = new FormData();
            formData.append('action', 'get_loan');
            formData.append('loan_id', loanId);

            try {
                const response = await fetch(PROCESS_URL, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (!data.success) {
                    alert(data.message || 'Failed to load loan.');
                    return;
                }

                const loan = data.loan || {};

                document.getElementById('edit_loan_id').value = loan.id || '';
                document.getElementById('edit_loan_period').value = loan.loan_period || '';
                document.getElementById('edit_loan_method').value = loan.loan_method || '';
                document.getElementById('edit_phone').value = loan.phone || '';
                document.getElementById('edit_bank_name').value = loan.bank_name || '';
                document.getElementById('edit_bank_account').value = loan.bank_account || '';

                const amountInput = document.getElementById('edit_amount');
                amountInput.value = String(parseFloat(loan.amount) || 0);
                amountInput.dispatchEvent(new Event('input', { bubbles: true }));

                document.getElementById('edit_loan_method').dispatchEvent(new Event('change', { bubbles: true }));

                openModal('editLoanModal');

            } catch (error) {
                alert('Network error. Please try again.');
            }
        });
    });

    document.querySelectorAll('.btn-pay-loan').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const payModal = document.getElementById('payLoanModal');
            const alertBox = payModal.querySelector('.modal-alert');

            clearAlert(alertBox);

            document.getElementById('pay_loan_id').value = this.dataset.id || '';
            document.getElementById('pay_balance_display').value = formatMoney(toNumber(this.dataset.balance));
            document.getElementById('pay_amount').value = '';

            const methodSel = payModal.querySelector('[name="payment_method"]');
            const refInput = payModal.querySelector('[name="reference"]');

            if (methodSel) methodSel.value = '';
            if (refInput) refInput.value = '';

            openModal('payLoanModal');
        });
    });

    const payAmountInput = document.getElementById('pay_amount');

    if (payAmountInput) {
        payAmountInput.addEventListener('input', function () {
            formatInputMoney(this);
        });
    }

    const payLoanForm = document.getElementById('payLoanForm');

    if (payLoanForm) {
        payLoanForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const form = this;
            const alertBox = form.querySelector('.modal-alert');

            clearAlert(alertBox);

            const amount = toNumber(document.getElementById('pay_amount').value);

            if (amount <= 0) {
                showAlert(alertBox, 'Please enter a valid payment amount.');
                return;
            }

            const formData = new FormData(form);
            formData.set('amount', amount);

            const btn = form.querySelector('.btn-submit');
            const originalText = btn ? btn.textContent.trim() : '';

            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Processing...';
            }

            try {
                const response = await fetch(PROCESS_URL, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (!data.success) {
                    showAlert(alertBox, data.message || 'Payment failed.');
                    return;
                }

                showAlert(alertBox, data.message || 'Payment submitted successfully.', 'success');

                setTimeout(function () {
                    window.location.reload();
                }, 900);

            } catch (error) {
                showAlert(alertBox, 'Network error. Please try again.');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = originalText;
                }
            }
        });
    }

    window.download_table_as_csv = function (tableId, sep = ',') {
        const table = document.getElementById(tableId);
        if (!table) return;

        const csv = Array.from(table.querySelectorAll('tr')).map(function (row) {
            return Array.from(row.querySelectorAll('td, th')).map(function (cell) {
                return '"' + cell.innerText.replace(/\s+/g, ' ').trim().replace(/"/g, '""') + '"';
            }).join(sep);
        }).join('\n');

        const a = Object.assign(document.createElement('a'), {
            href: 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv),
            download: tableId + '_' + new Date().toISOString().slice(0, 10) + '.csv'
        });

        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    };
})();
</script>
<?php
$loanStmt->close();
require_once __DIR__ . '/footer.php';
?>