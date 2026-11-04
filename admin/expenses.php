<?php
declare(strict_types=1);

require "nav-head.php";

if (!isset($conn)) {
    die("Database connection not established.");
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return 'UGX ' . number_format((float)$value, 2);
}

$selected_month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : 0;
$selected_year  = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : (int)date('Y');

$categories = [];
$catStmt = $conn->prepare("SELECT id, name FROM expense_categories ORDER BY name ASC");
$catStmt->execute();
$catResult = $catStmt->get_result();

while ($cat = $catResult->fetch_assoc()) {
    $categories[] = $cat;
}
$catStmt->close();

if ($selected_month > 0) {
    $stmt = $conn->prepare("
        SELECT *
        FROM expenses
        WHERE MONTH(expense_date) = ?
        AND YEAR(expense_date) = ?
        ORDER BY expense_date DESC, id DESC
    ");
    $stmt->bind_param("ii", $selected_month, $selected_year);
} else {
    $stmt = $conn->prepare("
        SELECT *
        FROM expenses
        ORDER BY expense_date DESC, id DESC
    ");
}

$stmt->execute();
$result = $stmt->get_result();

$expenses = [];
while ($row = $result->fetch_assoc()) {
    $expenses[] = $row;
}
$stmt->close();

$totalAmount = 0;
$cashTotal = 0;
$mobileTotal = 0;
$bankTotal = 0;

foreach ($expenses as $expense) {
    $amount = (float)($expense['amount'] ?? 0);
    $totalAmount += $amount;

    $method = strtolower((string)($expense['payment_method'] ?? ''));

    if ($method === 'cash') {
        $cashTotal += $amount;
    } elseif ($method === 'mobile money') {
        $mobileTotal += $amount;
    } elseif ($method === 'bank transfer') {
        $bankTotal += $amount;
    }
}

$reportTitle = $selected_month > 0
    ? "Expenses Report for " . date('F', mktime(0, 0, 0, $selected_month, 1)) . " {$selected_year}"
    : "All Expenses Report";
?>

<style>
.expense-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.expense-stat-card {
    background: #fff;
    border: 0;
    border-left: 5px solid #2563eb;
    border-radius: 16px;
    padding: 1rem;
    box-shadow: 0 6px 20px rgba(15,23,42,.06);
}

.expense-stat-card.success { border-left-color: #16a34a; }
.expense-stat-card.warning { border-left-color: #f59e0b; }
.expense-stat-card.dark { border-left-color: #111827; }

.expense-stat-flex {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.expense-stat-label {
    font-size: .72rem;
    font-weight: 800;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.expense-stat-value {
    font-size: 1.2rem;
    font-weight: 900;
    color: #111827;
    margin-top: .3rem;
}

.expense-stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    background: #2563eb;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.expense-stat-card.success .expense-stat-icon { background: #16a34a; }
.expense-stat-card.warning .expense-stat-icon { background: #f59e0b; }
.expense-stat-card.dark .expense-stat-icon { background: #111827; }

.expense-card {
    background: #fff;
    border-radius: 18px;
    border: 0;
    box-shadow: 0 8px 24px rgba(15,23,42,.08);
    overflow: hidden;
}

.expense-card-header {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.expense-table-scroll {
    width: 100%;
    overflow-x: auto;
}

.expense-table {
    min-width: 1050px;
    width: 100%;
    margin-bottom: 0;
}

.expense-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: .9rem 1rem;
    white-space: nowrap;
}

.expense-table td {
    padding: .85rem 1rem;
    vertical-align: middle;
    white-space: nowrap;
}

.expense-category {
    font-weight: 800;
    color: #111827;
}

.expense-description {
    display: block;
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #64748b;
    font-size: .8rem;
}

.expense-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(15, 23, 42, .55);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.expense-modal-overlay.show {
    display: flex;
}

.expense-modal-box {
    width: 100%;
    max-width: 720px;
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 24px 60px rgba(15,23,42,.25);
    overflow: hidden;
}

.expense-modal-header {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.expense-modal-title {
    margin: 0;
    font-weight: 900;
    color: #111827;
}

.expense-modal-close {
    border: 0;
    background: #f3f4f6;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 1.2rem;
}

.expense-modal-body {
    padding: 1.2rem;
}

.expense-modal-footer {
    padding: 1rem 1.2rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: .5rem;
}

.expense-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.expense-alert {
    display: none;
    padding: .75rem .9rem;
    border-radius: 10px;
    margin-bottom: 1rem;
}

.expense-alert.success {
    display: block;
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.expense-alert.error {
    display: block;
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

@media (max-width: 992px) {
    .expense-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 576px) {
    .expense-stats-grid,
    .expense-form-grid {
        grid-template-columns: 1fr;
    }

    .expense-modal-box {
        max-width: 100%;
    }
}
</style>

<div class="container-fluid py-4">

    <div class="page-header mb-4">
        <div>
            <div class="title">Expenses</div>
            <div class="sub"><?= h($reportTitle) ?></div>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <a href="expense_categories.php" class="btn btn-warning">
                <i class="fa-solid fa-folder-tree me-1"></i> Categories
            </a>

            <button type="button" class="btn btn-primary" id="openAddExpense">
                <i class="fas fa-plus-circle me-1"></i> Add Expense
            </button>

            <button class="btn bg-gradient-success" onclick="download_table_as_csv('report')">
                <i class="fas fa-download me-1"></i> CSV
            </button>

            <button class="btn bg-gradient-info" onclick="printReport()">
                <i class="fas fa-print me-1"></i> Print
            </button>
        </div>
    </div>

    <div class="expense-stats-grid">
        <div class="expense-stat-card">
            <div class="expense-stat-flex">
                <div>
                    <div class="expense-stat-label">Total Expenses</div>
                    <div class="expense-stat-value"><?= number_format(count($expenses)) ?></div>
                </div>
                <div class="expense-stat-icon">
                    <i class="fas fa-receipt"></i>
                </div>
            </div>
        </div>

        <div class="expense-stat-card success">
            <div class="expense-stat-flex">
                <div>
                    <div class="expense-stat-label">Total Amount</div>
                    <div class="expense-stat-value"><?= money($totalAmount) ?></div>
                </div>
                <div class="expense-stat-icon">
                    <i class="fas fa-coins"></i>
                </div>
            </div>
        </div>

        <div class="expense-stat-card warning">
            <div class="expense-stat-flex">
                <div>
                    <div class="expense-stat-label">Cash Payments</div>
                    <div class="expense-stat-value"><?= money($cashTotal) ?></div>
                </div>
                <div class="expense-stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
            </div>
        </div>

        <div class="expense-stat-card dark">
            <div class="expense-stat-flex">
                <div>
                    <div class="expense-stat-label">Bank / Mobile</div>
                    <div class="expense-stat-value"><?= money($bankTotal + $mobileTotal) ?></div>
                </div>
                <div class="expense-stat-icon">
                    <i class="fas fa-university"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="expense-card">
        <div class="expense-card-header">
            <div>
                <h5 class="mb-0"><?= h($reportTitle) ?></h5>
                <small class="text-muted">Filter, add, and edit expenses without leaving this page.</small>
            </div>

            <form method="GET" class="d-flex align-items-end gap-2 flex-wrap">
                <div>
                    <label class="form-label mb-1">Month</label>
                    <select name="month" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $selected_month ? 'selected' : '' ?>>
                                <?= h(date('F', mktime(0, 0, 0, $m, 10))) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label mb-1">Year</label>
                    <input type="number" name="year" class="form-control form-control-sm" value="<?= h($selected_year) ?>">
                </div>

                <div>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
            </form>
        </div>

        <div class="expense-table-scroll">
            <table class="table expense-table" id="report">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Amount</th>
                    <th>Payment Method</th>
                    <th>Paid To</th>
                    <th>Action</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!empty($expenses)): ?>
                    <?php foreach ($expenses as $index => $row): ?>
                        <tr>
                            <td><?= number_format($index + 1) ?></td>
                            <td><?= h($row['expense_date'] ?? '') ?></td>
                            <td>
                                <div class="expense-category"><?= h($row['category'] ?? '') ?></div>
                            </td>
                            <td>
                                <span class="expense-description"><?= h($row['description'] ?? '') ?></span>
                            </td>
                            <td><strong><?= money($row['amount'] ?? 0) ?></strong></td>
                            <td><?= h($row['payment_method'] ?? '') ?></td>
                            <td><?= h($row['paid_to'] ?? '') ?></td>
                            <td>
                                <button type="button"
                                        class="btn btn-sm btn-info js-edit-expense"
                                        data-id="<?= (int)$row['id'] ?>"
                                        data-category="<?= h($row['category'] ?? '') ?>"
                                        data-description="<?= h($row['description'] ?? '') ?>"
                                        data-amount="<?= h($row['amount'] ?? 0) ?>"
                                        data-payment-method="<?= h($row['payment_method'] ?? '') ?>"
                                        data-paid-to="<?= h($row['paid_to'] ?? '') ?>"
                                        data-expense-date="<?= h($row['expense_date'] ?? '') ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">No expense found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Expense Modal -->
<div class="expense-modal-overlay" id="expenseModal">
    <div class="expense-modal-box">
        <form id="expenseForm">
            <div class="expense-modal-header">
                <h5 class="expense-modal-title" id="expenseModalTitle">Add Expense</h5>
                <button type="button" class="expense-modal-close js-close-expense-modal">&times;</button>
            </div>

            <div class="expense-modal-body">
                <div id="expenseFormAlert" class="expense-alert"></div>

                <input type="hidden" name="id" id="expense_id">
                <input type="hidden" name="action" id="expense_action" value="create">

                <div class="expense-form-grid">
                    <div>
                        <label class="form-label fw-bold">Category *</label>
                        <select name="category_name" id="expense_category" class="form-select" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= h($cat['name']) ?>"><?= h($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label fw-bold">Amount *</label>
                        <input type="number" step="0.01" min="1" name="amount" id="expense_amount" class="form-control" required>
                    </div>

                    <div>
                        <label class="form-label fw-bold">Payment Method</label>
                        <select name="payment_method" id="expense_payment_method" class="form-select">
                            <option value="">Select Payment Method</option>
                            <option value="Cash">Cash</option>
                            <option value="Mobile Money">Mobile Money</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label fw-bold">Paid To</label>
                        <input type="text" name="paid_to" id="expense_paid_to" class="form-control">
                    </div>

                    <div>
                        <label class="form-label fw-bold">Expense Date *</label>
                        <input type="date" name="expense_date" id="expense_date" class="form-control" required>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label fw-bold">Description</label>
                    <textarea name="description" id="expense_description" class="form-control" rows="3"></textarea>
                </div>
            </div>

            <div class="expense-modal-footer">
                <button type="button" class="btn btn-light js-close-expense-modal">Cancel</button>
                <button type="submit" class="btn btn-success" id="saveExpenseBtn">
                    <i class="fas fa-save me-1"></i> Save Expense
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('expenseModal');
    const form = document.getElementById('expenseForm');
    const alertBox = document.getElementById('expenseFormAlert');

    function openModal() {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }

    function showAlert(message, type = 'error') {
        alertBox.className = 'expense-alert ' + type;
        alertBox.textContent = message;
    }

    function clearAlert() {
        alertBox.className = 'expense-alert';
        alertBox.textContent = '';
    }

    function resetForm() {
        form.reset();
        document.getElementById('expense_id').value = '';
        document.getElementById('expense_action').value = 'create';
        document.getElementById('expense_date').value = new Date().toISOString().slice(0, 10);
        document.getElementById('expenseModalTitle').textContent = 'Add Expense';
        clearAlert();
    }

    document.getElementById('openAddExpense')?.addEventListener('click', function () {
        resetForm();
        openModal();
    });

    document.querySelectorAll('.js-edit-expense').forEach(btn => {
        btn.addEventListener('click', function () {
            resetForm();

            document.getElementById('expenseModalTitle').textContent = 'Edit Expense';
            document.getElementById('expense_action').value = 'update';
            document.getElementById('expense_id').value = this.dataset.id || '';
            document.getElementById('expense_category').value = this.dataset.category || '';
            document.getElementById('expense_description').value = this.dataset.description || '';
            document.getElementById('expense_amount').value = this.dataset.amount || '';
            document.getElementById('expense_payment_method').value = this.dataset.paymentMethod || '';
            document.getElementById('expense_paid_to').value = this.dataset.paidTo || '';
            document.getElementById('expense_date').value = this.dataset.expenseDate || '';

            openModal();
        });
    });

    document.querySelectorAll('.js-close-expense-modal').forEach(btn => {
        btn.addEventListener('click', closeModal);
    });

    modal?.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    form?.addEventListener('submit', async function (event) {
        event.preventDefault();

        clearAlert();

        const btn = document.getElementById('saveExpenseBtn');
        const oldHtml = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

        try {
            const response = await fetch('includes/process-expense.php', {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Failed to save expense.');
            }

            showAlert(data.message || 'Expense saved successfully.', 'success');

            setTimeout(() => {
                window.location.reload();
            }, 700);

        } catch (error) {
            showAlert(error.message || 'Failed to save expense.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = oldHtml;
        }
    });
});

function printReport() {
    const printWindow = window.open('', '_blank');
    const originalTable = document.getElementById('report');
    const reportTitle = <?= json_encode($reportTitle) ?>;
    const tableClone = originalTable.cloneNode(true);

    tableClone.querySelectorAll('tr').forEach(row => {
        if (row.lastElementChild) row.removeChild(row.lastElementChild);
    });

    printWindow.document.write(`
        <html>
        <head>
            <title>${reportTitle}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h2 { text-align: center; margin-bottom: 20px; color: #333; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; }
                th { background-color: #f2f2f2; font-weight: bold; text-align: center; }
            </style>
        </head>
        <body>
            <h2>${reportTitle}</h2>
            <p style="text-align: center;">Generated on: ${new Date().toLocaleDateString()}</p>
            ${tableClone.outerHTML}
        </body>
        </html>
    `);

    printWindow.document.close();
    printWindow.focus();

    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 250);
}

function download_table_as_csv(table_id) {
    const table = document.getElementById(table_id);
    const rows = table.querySelectorAll('tr');
    const csv = [];

    rows.forEach(row => {
        const cols = Array.from(row.querySelectorAll('td, th'));

        if (cols.length > 0) {
            cols.pop();
        }

        const rowData = cols.map(col => {
            const text = col.innerText.replace(/,/g, '').replace(/"/g, '""');
            return `"${text}"`;
        });

        csv.push(rowData.join(','));
    });

    const csvString = csv.join('\n');
    const filename = 'expense_report_' + new Date().toISOString().slice(0, 10) + '.csv';

    const link = document.createElement('a');
    link.style.display = 'none';
    link.setAttribute('href', 'data:text/csv;charset=utf-8,' + encodeURIComponent(csvString));
    link.setAttribute('download', filename);

    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require "footer.php"; ?>