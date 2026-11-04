<?php
declare(strict_types=1);

require "nav-head.php";

$phead = "LOAN PAYMENTS REPORT";

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function money($v): string { return 'UGX ' . number_format((float)$v); }

function typeBadge(string $type): string
{
    return match ($type) {
        'customer' => 'badge-primary',
        'member'   => 'badge-purple',
        'startup'  => 'badge-warning',
        default    => 'badge-muted',
    };
}

$selected_month = $_GET['month'] ?? date('Y-m');
$filter_type = $_GET['filter'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';

if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}

$allowed_filters = ['all', 'successful', 'failed'];
$allowed_types = ['all', 'customer', 'member', 'startup'];

if (!in_array($filter_type, $allowed_filters, true)) $filter_type = 'all';
if (!in_array($type_filter, $allowed_types, true)) $type_filter = 'all';

$month_display = date('F Y', strtotime($selected_month . '-01'));

$wherePayment = "DATE_FORMAT(p.datetime, '%Y-%m') = ?";
$paramsCustomer = [$selected_month];
$paramsMember = [$selected_month];
$paramsStartup = [$selected_month];
$typesCustomer = "s";
$typesMember = "s";
$typesStartup = "s";

if ($filter_type === 'successful') {
    $wherePayment .= " AND p.status = 'SUCCESSFUL'";
} elseif ($filter_type === 'failed') {
    $wherePayment .= " AND p.status != 'SUCCESSFUL'";
}

$customerSql = "
    SELECT 
        p.id AS payment_id,
        p.amount,
        p.status,
        p.datetime,
        l.id AS loan_id,
        l.client_id,
        l.amount AS loan_amount,
        l.interest AS loan_interest,
        l.loan_balance,
        'customer' AS client_type,
        u.fname,
        u.lname,
        COALESCE(c.email, u.email) AS client_email,
        c.phone AS client_phone,
        c.business_name AS organization_name
    FROM payments p
    INNER JOIN loan l ON p.loan_id = l.id
    INNER JOIN customers c ON l.client_id = c.user_id
    INNER JOIN user u ON c.user_id = u.id
    WHERE {$wherePayment}
";

$memberSql = "
    SELECT 
        p.id AS payment_id,
        p.amount,
        p.status,
        p.datetime,
        l.id AS loan_id,
        l.client_id,
        l.amount AS loan_amount,
        l.interest AS loan_interest,
        l.loan_balance,
        'member' AS client_type,
        u.fname,
        u.lname,
        COALESCE(m.work_email, u.email) AS client_email,
        m.phone AS client_phone,
        m.employer AS organization_name
    FROM payments p
    INNER JOIN loan l ON p.loan_id = l.id
    INNER JOIN members m ON l.client_id = m.user_id
    INNER JOIN user u ON m.user_id = u.id
    WHERE {$wherePayment}
";

/*
 * FIX: loan.client_id stores startups.startup_id for startup loans
 * (confirmed), not startups.id. The previous join on st.id matched
 * zero rows, which is why startup payments never appeared in this
 * report.
 */
$startupSql = "
    SELECT 
        p.id AS payment_id,
        p.amount,
        p.status,
        p.datetime,
        l.id AS loan_id,
        l.client_id,
        l.amount AS loan_amount,
        l.interest AS loan_interest,
        l.loan_balance,
        'startup' AS client_type,
        st.ceo_name AS fname,
        '' AS lname,
        st.email AS client_email,
        st.phone AS client_phone,
        st.company_name AS organization_name
    FROM payments p
    INNER JOIN loan l ON p.loan_id = l.id
    INNER JOIN startups st ON l.client_id = st.startup_id
    WHERE {$wherePayment}
";

if ($type_filter === 'customer') {
    $baseSql = $customerSql;
    $bindParams = $paramsCustomer;
    $bindTypes = $typesCustomer;
} elseif ($type_filter === 'member') {
    $baseSql = $memberSql;
    $bindParams = $paramsMember;
    $bindTypes = $typesMember;
} elseif ($type_filter === 'startup') {
    $baseSql = $startupSql;
    $bindParams = $paramsStartup;
    $bindTypes = $typesStartup;
} else {
    $baseSql = "($customerSql) UNION ALL ($memberSql) UNION ALL ($startupSql)";
    $bindParams = array_merge($paramsCustomer, $paramsMember, $paramsStartup);
    $bindTypes = $typesCustomer . $typesMember . $typesStartup;
}

$query = "SELECT * FROM ($baseSql) x ORDER BY payment_id DESC";
$stmt = $conn->prepare($query);

if (!$stmt) {
    die("Payment query failed: " . h($conn->error));
}

$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$result = $stmt->get_result();

$payments = [];
while ($row = $result->fetch_assoc()) {
    $payments[] = $row;
}

$total_payments = count($payments);
$successful_count = 0;
$failed_count = 0;
$total_amount = 0;
$successful_amount = 0;
$failed_amount = 0;

$typeStats = [
    'customer' => ['count' => 0, 'amount' => 0],
    'member'   => ['count' => 0, 'amount' => 0],
    'startup'  => ['count' => 0, 'amount' => 0],
];

foreach ($payments as $payment) {
    $amount = (float)($payment['amount'] ?? 0);
    $status = strtoupper((string)($payment['status'] ?? ''));
    $clientType = (string)($payment['client_type'] ?? '');

    $total_amount += $amount;

    if ($status === 'SUCCESSFUL') {
        $successful_count++;
        $successful_amount += $amount;
    } else {
        $failed_count++;
        $failed_amount += $amount;
    }

    if (isset($typeStats[$clientType])) {
        $typeStats[$clientType]['count']++;
        $typeStats[$clientType]['amount'] += $amount;
    }
}


$openLoansSql = "
    (
        SELECT 
            l.id AS loan_id,
            l.client_id,
            l.amount,
            l.interest,
            l.loan_balance,
            l.status,
            'customer' AS client_type,
            CONCAT(u.fname, ' ', u.lname) AS client_name,
            c.phone AS client_phone,
            c.business_name AS organization_name
        FROM loan l
        INNER JOIN customers c ON l.client_id = c.user_id
        INNER JOIN user u ON c.user_id = u.id
        WHERE l.status IN ('OUTSTANDING','OVERDUE','ACTIVE','APPROVED')
        AND COALESCE(l.loan_balance,0) > 0
    )
    UNION ALL
    (
        SELECT 
            l.id AS loan_id,
            l.client_id,
            l.amount,
            l.interest,
            l.loan_balance,
            l.status,
            'member' AS client_type,
            CONCAT(u.fname, ' ', u.lname) AS client_name,
            m.phone AS client_phone,
            m.employer AS organization_name
        FROM loan l
        INNER JOIN members m ON l.client_id = m.user_id
        INNER JOIN user u ON m.user_id = u.id
        WHERE l.status IN ('OUTSTANDING','OVERDUE','ACTIVE','APPROVED')
        AND COALESCE(l.loan_balance,0) > 0
    )
    UNION ALL
    (
        SELECT 
            l.id AS loan_id,
            l.client_id,
            l.amount,
            l.interest,
            l.loan_balance,
            l.status,
            'startup' AS client_type,
            st.company_name AS client_name,
            st.phone AS client_phone,
            st.ceo_name AS organization_name
        FROM loan l
        INNER JOIN startups st ON l.client_id = st.startup_id
        WHERE l.status IN ('OUTSTANDING','OVERDUE','ACTIVE','APPROVED')
        AND COALESCE(l.loan_balance,0) > 0
    )
    ORDER BY loan_id DESC
";

$openLoans = [];
$openRes = $conn->query($openLoansSql);
if ($openRes) {
    while ($r = $openRes->fetch_assoc()) {
        $openLoans[] = $r;
    }
}
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

<style>
.loan-page { background:#f8fafc; }
.loan-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;padding:1rem}
.loan-summary-grid.g3{grid-template-columns:repeat(3,minmax(0,1fr));padding-top:0}
.loan-summary-card{background:#fff;border:1px solid #e2e8f0;border-left:5px solid #2563eb;border-radius:16px;box-shadow:0 6px 20px rgba(15,23,42,.05);overflow:hidden}
.loan-summary-card.success{border-left-color:#16a34a}
.loan-summary-card.info{border-left-color:#0891b2}
.loan-summary-card.danger{border-left-color:#dc2626}
.loan-summary-card.purple{border-left-color:#7e22ce}
.loan-summary-card.orange{border-left-color:#f97316}
.loan-summary-body{padding:1.15rem}
.loan-summary-flex{display:flex;align-items:center;justify-content:space-between;gap:1rem}
.loan-summary-title{margin:0 0 .4rem;font-size:.78rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.loan-summary-value{margin:0;font-size:1.25rem;font-weight:800;color:#0f172a;line-height:1.1}
.loan-summary-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;flex-shrink:0}
.loan-summary-icon.primary{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.loan-summary-icon.success{background:linear-gradient(135deg,#22c55e,#15803d)}
.loan-summary-icon.info{background:linear-gradient(135deg,#06b6d4,#0e7490)}
.loan-summary-icon.danger{background:linear-gradient(135deg,#ef4444,#b91c1c)}
.loan-summary-icon.purple{background:linear-gradient(135deg,#9333ea,#6b21a8)}
.loan-summary-icon.orange{background:linear-gradient(135deg,#f97316,#c2410c)}
.loan-table-wrap{padding:0 1rem 1rem}
.loan-table-scroll{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
.loan-table{margin:0;min-width:1120px}
.loan-table thead th{background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#334155;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:.95rem 1rem;white-space:nowrap}
.loan-table tbody td{padding:.95rem 1rem;vertical-align:middle;border-color:#f1f5f9;white-space:nowrap}
.loan-client{font-weight:800;color:#0f172a}
.loan-amount{font-weight:800;color:#0f172a}
.status-badge{display:inline-flex;align-items:center;justify-content:center;padding:.45rem .8rem;border-radius:999px;font-size:.72rem;font-weight:800;letter-spacing:.3px}
.status-success{background:#dcfce7;color:#166534}
.status-failed{background:#fee2e2;color:#991b1b}
.badge-purple{background:#f3e8ff;color:#7e22ce}
.badge-muted{background:#f1f5f9;color:#475569}
.filter-form{display:flex;align-items:end;gap:1rem;flex-wrap:wrap}
.filter-group label{font-size:.78rem;font-weight:700;color:#475569;margin-bottom:.4rem}
.filter-group .form-control,.filter-group .form-select{min-width:190px;border-radius:10px;border:1px solid #dbe2ea;box-shadow:none}
.modal-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}
.receipt-link{font-weight:700;color:#2563eb;text-decoration:none}
@media(max-width:1200px){.loan-summary-grid,.loan-summary-grid.g3{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:768px){.loan-summary-grid,.loan-summary-grid.g3,.modal-form-grid{grid-template-columns:1fr}.loan-summary-value{font-size:1.1rem}}
@media print{.btn,.filter-form,.loan-summary-grid{display:none!important}.card{box-shadow:none!important;border:none!important}@page{margin:1cm}}
</style>

<div class="container-fluid py-4 loan-page">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 pb-0">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-1 fw-bold"><?= h($phead) ?></h5>
                    <p class="text-muted mb-0">Report for <?= h($month_display) ?></p>
                </div>

                <div class="col-md-6 text-md-end mt-3 mt-md-0">
                    <div class="btn-group">
                        <button class="btn btn-primary btn-sm" onclick="openRepaymentModal()">
                            <i class="fas fa-plus-circle me-1"></i> Add Repayment
                        </button>

                        <button class="btn btn-outline-success btn-sm" onclick="exportToPDF()">
                            <i class="fas fa-file-pdf me-1"></i> Export PDF
                        </button>

                        <button class="btn btn-outline-info btn-sm" onclick="download_table_as_csv('report')">
                            <i class="fas fa-file-csv me-1"></i> Export CSV
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <label>Select Month</label>
                        <input type="month" name="month" class="form-control" value="<?= h($selected_month) ?>">
                    </div>

                    <div class="filter-group">
                        <label>Payment Status</label>
                        <select name="filter" class="form-select">
                            <option value="all" <?= $filter_type === 'all' ? 'selected' : '' ?>>All Payments</option>
                            <option value="successful" <?= $filter_type === 'successful' ? 'selected' : '' ?>>Successful Only</option>
                            <option value="failed" <?= $filter_type === 'failed' ? 'selected' : '' ?>>Failed Only</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Client Type</label>
                        <select name="type" class="form-select">
                            <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="customer" <?= $type_filter === 'customer' ? 'selected' : '' ?>>Customers</option>
                            <option value="member" <?= $type_filter === 'member' ? 'selected' : '' ?>>Members / Staff</option>
                            <option value="startup" <?= $type_filter === 'startup' ? 'selected' : '' ?>>Startups</option>
                        </select>
                    </div>

                    <div>
                        <button type="submit" class="btn bg-gradient-primary">
                            <i class="fas fa-filter me-1"></i> Apply Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="loan-summary-grid">
                <div class="loan-summary-card">
                    <div class="loan-summary-body">
                        <div class="loan-summary-flex">
                            <div>
                                <p class="loan-summary-title">Total Payments</p>
                                <h3 class="loan-summary-value"><?= number_format($total_payments) ?></h3>
                            </div>
                            <div class="loan-summary-icon primary"><i class="fas fa-list"></i></div>
                        </div>
                    </div>
                </div>

                <div class="loan-summary-card success">
                    <div class="loan-summary-body">
                        <div class="loan-summary-flex">
                            <div>
                                <p class="loan-summary-title">Successful</p>
                                <h3 class="loan-summary-value"><?= number_format($successful_count) ?></h3>
                            </div>
                            <div class="loan-summary-icon success"><i class="fas fa-check-circle"></i></div>
                        </div>
                    </div>
                </div>

                <div class="loan-summary-card danger">
                    <div class="loan-summary-body">
                        <div class="loan-summary-flex">
                            <div>
                                <p class="loan-summary-title">Failed / Other</p>
                                <h3 class="loan-summary-value"><?= number_format($failed_count) ?></h3>
                            </div>
                            <div class="loan-summary-icon danger"><i class="fas fa-times-circle"></i></div>
                        </div>
                    </div>
                </div>

                <div class="loan-summary-card info">
                    <div class="loan-summary-body">
                        <div class="loan-summary-flex">
                            <div>
                                <p class="loan-summary-title">Total Amount</p>
                                <h3 class="loan-summary-value"><?= money($total_amount) ?></h3>
                            </div>
                            <div class="loan-summary-icon info"><i class="fas fa-money-bill-wave"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="loan-summary-grid g3">
                <div class="loan-summary-card">
                    <div class="loan-summary-body">
                        <div class="loan-summary-flex">
                            <div>
                                <p class="loan-summary-title">Customer Payments</p>
                                <h3 class="loan-summary-value"><?= number_format($typeStats['customer']['count']) ?></h3>
                                <small class="text-muted"><?= money($typeStats['customer']['amount']) ?></small>
                            </div>
                            <div class="loan-summary-icon primary"><i class="fas fa-users"></i></div>
                        </div>
                    </div>
                </div>

                <div class="loan-summary-card purple">
                    <div class="loan-summary-body">
                        <div class="loan-summary-flex">
                            <div>
                                <p class="loan-summary-title">Member / Staff Payments</p>
                                <h3 class="loan-summary-value"><?= number_format($typeStats['member']['count']) ?></h3>
                                <small class="text-muted"><?= money($typeStats['member']['amount']) ?></small>
                            </div>
                            <div class="loan-summary-icon purple"><i class="fas fa-user-tie"></i></div>
                        </div>
                    </div>
                </div>

                <div class="loan-summary-card orange">
                    <div class="loan-summary-body">
                        <div class="loan-summary-flex">
                            <div>
                                <p class="loan-summary-title">Startup Payments</p>
                                <h3 class="loan-summary-value"><?= number_format($typeStats['startup']['count']) ?></h3>
                                <small class="text-muted"><?= money($typeStats['startup']['amount']) ?></small>
                            </div>
                            <div class="loan-summary-icon orange"><i class="fas fa-rocket"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="loan-table-wrap">
                <div class="loan-table-scroll">
                    <table class="table loan-table align-items-center" id="report">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Client</th>
                            <th>Type</th>
                            <th>Contact</th>
                            <th>Amount</th>
                            <th>Loan ID</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Datetime</th>
                            <th class="text-center">Receipt</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php if (!empty($payments)): ?>
                            <?php foreach ($payments as $index => $row): ?>
                                <?php
                                $clientType = (string)($row['client_type'] ?? '');
                                $clientName = trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));

                                if ($clientType === 'startup') {
                                    $clientName = $row['organization_name'] ?: ($row['fname'] ?? 'Startup');
                                }

                                if ($clientName === '') $clientName = 'N/A';

                                $status = strtoupper((string)($row['status'] ?? ''));
                                ?>
                                <tr>
                                    <td><?= number_format($index + 1) ?></td>
                                    <td>
                                        <div class="loan-client"><?= h($clientName) ?></div>
                                        <small class="text-muted"><?= h($row['organization_name'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?= typeBadge($clientType) ?>">
                                            <?= h(ucfirst($clientType)) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?= h($row['client_phone'] ?? 'N/A') ?></div>
                                        <small class="text-muted"><?= h($row['client_email'] ?? '') ?></small>
                                    </td>
                                    <td><span class="loan-amount"><?= money($row['amount'] ?? 0) ?></span></td>
                                    <td>#<?= h($row['loan_id'] ?? '') ?></td>
                                    <td class="text-center">
                                        <?php if ($status === 'SUCCESSFUL'): ?>
                                            <span class="status-badge status-success">SUCCESSFUL</span>
                                        <?php else: ?>
                                            <span class="status-badge status-failed"><?= h($status ?: 'FAILED') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?= !empty($row['datetime']) ? date('Y-m-d H:i', strtotime((string)$row['datetime'])) : 'N/A' ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="receipt.php?payment_id=<?= (int)$row['payment_id'] ?>" target="_blank" class="receipt-link">
                                            <i class="fas fa-receipt"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-4">No records found for the selected filters.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>



<div class="rp-modal-overlay" id="repaymentModal">
    <div class="rp-modal-box">
        <form method="POST" action="includes/process-repayment.php" id="repaymentForm">
            <div class="rp-modal-header">
                <h5 class="rp-modal-title">
                    <i class="fas fa-money-bill-wave me-2"></i>Add Loan Repayment
                </h5>
                <button type="button" class="rp-modal-close" onclick="closeRepaymentModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="rp-modal-body">
                <div id="repaymentMessage"></div>

                <div class="modal-form-grid">
                    <div>
                        <label class="form-label fw-bold">Select Loan</label>
                        <select name="loan_id" id="loan_id" class="form-select" required>
                            <option value="">Select loan...</option>
                            <?php foreach ($openLoans as $loan): ?>
                                <option
                                    value="<?= (int)$loan['loan_id'] ?>"
                                    data-balance="<?= h($loan['loan_balance']) ?>"
                                    data-client="<?= h($loan['client_name']) ?>"
                                    data-type="<?= h($loan['client_type']) ?>">
                                    #<?= (int)$loan['loan_id'] ?> - <?= h($loan['client_name']) ?>
                                    (<?= h(ucfirst($loan['client_type'])) ?>) -
                                    Balance: <?= money($loan['loan_balance']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label fw-bold">Payment Amount</label>
                        <input type="number" name="amount" id="payment_amount" class="form-control" min="1" step="0.01" required>
                        <small class="text-muted" id="loanBalanceHint">Select loan to view balance.</small>
                    </div>

                    <div>
                        <label class="form-label fw-bold">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="Cash">Cash</option>
                            <option value="Mobile Money">Mobile Money</option>
                            <option value="Bank">Bank</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label fw-bold">Reference</label>
                        <input type="text" name="reference" class="form-control" placeholder="Transaction reference">
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label fw-bold">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes"></textarea>
                </div>
            </div>

            <div class="rp-modal-footer">
                <button type="button" class="btn btn-light" onclick="closeRepaymentModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i>Save Repayment
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.rp-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, .55);
    z-index: 1050;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
}
.rp-modal-overlay.open {
    display: flex;
}
.rp-modal-box {
    background: #fff;
    border-radius: 16px;
    width: 100%;
    max-width: 720px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 24px 60px rgba(0,0,0,.25);
}
.rp-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.1rem 1.4rem;
    border-bottom: 1px solid #e2e8f0;
}
.rp-modal-title { margin: 0; font-weight: 800; }
.rp-modal-close {
    background: none;
    border: none;
    font-size: 1rem;
    color: #64748b;
    cursor: pointer;
}
.rp-modal-body { padding: 1.4rem; }
.rp-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: .75rem;
    padding: 1.1rem 1.4rem;
    border-top: 1px solid #e2e8f0;
}
body.rp-modal-locked { overflow: hidden; }
</style>

<script>
function openRepaymentModal() {
    const overlay = document.getElementById('repaymentModal');
    overlay.classList.add('open');
    document.body.classList.add('rp-modal-locked');
}

function closeRepaymentModal() {
    const overlay = document.getElementById('repaymentModal');
    overlay.classList.remove('open');
    document.body.classList.remove('rp-modal-locked');
}

document.getElementById('repaymentModal')?.addEventListener('click', function (e) {
    if (e.target === this) {
        closeRepaymentModal();
    }
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeRepaymentModal();
    }
});

const loanSelect = document.getElementById('loan_id');
const amountInput = document.getElementById('payment_amount');
const balanceHint = document.getElementById('loanBalanceHint');
const repaymentForm = document.getElementById('repaymentForm');
const repaymentMessage = document.getElementById('repaymentMessage');

loanSelect?.addEventListener('change', function () {
    const selected = this.options[this.selectedIndex];
    const balance = parseFloat(selected.dataset.balance || 0);

    if (balance > 0) {
        amountInput.max = balance;
        balanceHint.textContent = 'Outstanding balance: UGX ' + Math.round(balance).toLocaleString();
    } else {
        amountInput.removeAttribute('max');
        balanceHint.textContent = 'Select loan to view balance.';
    }
});

repaymentForm?.addEventListener('submit', async function (e) {
    e.preventDefault();

    repaymentMessage.innerHTML = '';

    const btn = repaymentForm.querySelector('[type="submit"]');
    const oldHtml = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';

    try {
        const res = await fetch(repaymentForm.action, {
            method: 'POST',
            body: new FormData(repaymentForm),
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const data = await res.json();

        if (!data.success) {
            throw new Error(data.message || 'Repayment failed.');
        }

        repaymentMessage.innerHTML =
            '<div class="alert alert-success">' + data.message + '</div>';

        if (data.receipt_url) {
            window.open(data.receipt_url, '_blank');
        }

        setTimeout(() => {
            window.location.reload();
        }, 900);

    } catch (err) {
        repaymentMessage.innerHTML =
            '<div class="alert alert-danger">' + err.message + '</div>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = oldHtml;
    }
});

function exportToPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');

    doc.setFontSize(16);
    doc.text('Loan Payments Report', 14, 20);
    doc.setFontSize(11);
    doc.text('Report Period: <?= addslashes($month_display) ?>', 14, 30);

    doc.autoTable({
        html: '#report',
        startY: 40,
        styles: { fontSize: 8 },
        headStyles: { fillColor: [37, 99, 235] }
    });

    doc.save('loan-payments-report-<?= h($selected_month) ?>.pdf');
}

function download_table_as_csv(table_id) {
    const rows = document.querySelectorAll('table#' + table_id + ' tr');
    let csv = [];

    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        let rowData = [];

        cols.forEach(col => {
            rowData.push('"' + col.innerText.replace(/"/g, '""') + '"');
        });

        csv.push(rowData.join(','));
    });

    const csvFile = new Blob([csv.join('\n')], { type:'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.download = 'loan-payments-report-<?= h($selected_month) ?>.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
}
</script>

<?php
$stmt->close();
require "footer.php";
?>