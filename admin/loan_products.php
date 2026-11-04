<?php
require 'nav-head.php';

function lp_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function lp_money($value): string
{
    return 'UGX ' . number_format((float)$value);
}

$products = mysqli_query($conn, "
    SELECT *
    FROM loan_products
    ORDER BY id DESC
");

if (!$products) {
    die('Loan products query failed: ' . mysqli_error($conn));
}
?>
<style>
.lp-products-page {
    padding: 1rem;
}

.lp-page-header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.lp-page-title {
    font-size: 1.35rem;
    font-weight: 800;
    color: #111827;
    margin: 0;
}

.lp-page-subtitle {
    color: #64748b;
    margin: .25rem 0 0;
}

.lp-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    box-shadow: 0 8px 24px rgba(15,23,42,.05);
}

.lp-card-header {
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.lp-card-title {
    font-weight: 800;
    color: #111827;
}

.lp-card-body {
    padding: 1rem;
}

.lp-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1100px;
}

.lp-table th {
    background: #f8fafc;
    color: #475569;
    font-size: .76rem;
    text-transform: uppercase;
    padding: .8rem;
    border-bottom: 1px solid #e5e7eb;
    white-space: nowrap;
    text-align: left;
}

.lp-table td {
    padding: .85rem;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}

.lp-muted {
    color: #64748b;
    font-size: .82rem;
}

.lp-actions {
    display: flex;
    justify-content: flex-end;
    gap: .4rem;
}

.lp-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .28rem .6rem;
    font-size: .72rem;
    font-weight: 800;
}

.lp-badge-success {
    background: #dcfce7;
    color: #166534;
}

.lp-badge-danger {
    background: #fee2e2;
    color: #991b1b;
}

.lp-alert {
    padding: .85rem 1rem;
    border-radius: 12px;
    margin-bottom: 1rem;
}

.lp-alert-success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.lp-alert-danger {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

/* =========================================================
   FIXED MODALS - HEADER/FOOTER ALWAYS VISIBLE
========================================================= */

.lp-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, .58);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    overflow: hidden;
}

.lp-modal-overlay.open {
    display: flex;
}

.lp-modal-box {
    width: min(920px, 100%);
    height: calc(100vh - 2rem);
    max-height: calc(100vh - 2rem);
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 25px 70px rgba(0,0,0,.25);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.lp-modal-box form {
    height: 100%;
    min-height: 0;
    display: flex;
    flex-direction: column;
}

.lp-modal-header {
    flex: 0 0 auto;
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    background: #fff;
    z-index: 2;
}

.lp-modal-title {
    margin: 0;
    font-weight: 800;
    color: #111827;
    font-size: 1.05rem;
}

.lp-modal-close {
    border: 0;
    background: #f1f5f9;
    color: #334155;
    border-radius: 10px;
    width: 36px;
    height: 36px;
    cursor: pointer;
    flex: 0 0 auto;
}

.lp-modal-close:hover {
    background: #e2e8f0;
}

.lp-modal-body {
    flex: 1 1 auto;
    min-height: 0;
    padding: 1.2rem;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
}

.lp-modal-footer {
    flex: 0 0 auto;
    padding: 1rem 1.2rem;
    border-top: 1px solid #e5e7eb;
    background: #fff;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: .6rem;
    flex-wrap: wrap;
    z-index: 2;
}

/* Better modal scrollbar */
.lp-modal-body::-webkit-scrollbar {
    width: 8px;
}

.lp-modal-body::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}

.lp-modal-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.lp-modal-body::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* =========================================================
   FORM STYLING
========================================================= */

.lp-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.lp-form-grid .full {
    grid-column: 1 / -1;
}

.lp-form-label {
    display: block;
    font-weight: 800;
    color: #374151;
    font-size: .85rem;
    margin-bottom: .35rem;
}

.lp-form-control {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    padding: .7rem .8rem;
    outline: none;
    background: #fff;
    color: #111827;
}

.lp-form-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}

textarea.lp-form-control {
    resize: vertical;
    min-height: 84px;
}

select.lp-form-control {
    cursor: pointer;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 768px) {
    .lp-products-page {
        padding: .75rem;
    }

    .lp-modal-overlay {
        align-items: stretch;
        padding: .75rem;
    }

    .lp-modal-box {
        width: 100%;
        height: calc(100vh - 1.5rem);
        max-height: calc(100vh - 1.5rem);
        border-radius: 14px;
    }

    .lp-form-grid {
        grid-template-columns: 1fr;
    }

    .lp-modal-header,
    .lp-modal-body,
    .lp-modal-footer {
        padding: 1rem;
    }

    .lp-modal-footer {
        justify-content: stretch;
    }

    .lp-modal-footer .btn {
        flex: 1;
        justify-content: center;
    }
}
</style>

<div class="container-fluid lp-products-page">

    <div class="lp-page-header">
        <div>
            <h3 class="lp-page-title">Loan Products</h3>
            <p class="lp-page-subtitle">Manage loan periods, interest rates, charges, and repayment rules.</p>
        </div>

        <button type="button" class="btn btn-primary" onclick="openProductModal('addProductModal')">
            <i class="fas fa-plus-circle me-1"></i> Add Loan Product
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="lp-alert lp-alert-success">
            <i class="fas fa-check-circle me-1"></i> Loan product saved successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="lp-alert lp-alert-danger">
            <i class="fas fa-exclamation-circle me-1"></i> <?= lp_e($_GET['error']) ?>
        </div>
    <?php endif; ?>

    <div class="lp-card">
        <div class="lp-card-header">
            <span class="lp-card-title">
                <i class="fas fa-list me-1"></i> Loan Products List
            </span>
        </div>

        <div class="lp-card-body table-responsive">
            <table class="table data-table lp-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Code</th>
                    <th>Product</th>
                    <th>Period</th>
                    <th>Interest</th>
                    <th>Amount Range</th>
                    <th>Processing Fee</th>
                    <th>Penalty</th>
                    <th>Frequency</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>

                <tbody>
                <?php $i = 1; while ($row = mysqli_fetch_assoc($products)): ?>
                    <tr>
                        <td><?= $i++ ?></td>

                        <td>
                            <strong><?= lp_e($row['product_code']) ?></strong>
                        </td>

                        <td>
                            <strong><?= lp_e($row['product_name']) ?></strong><br>
                            <span class="lp-muted"><?= lp_e($row['description'] ?? '') ?></span>
                        </td>

                        <td><?= (int)$row['loan_period_months'] ?> Month(s)</td>

                        <td><?= number_format((float)$row['interest_rate'], 2) ?>%</td>

                        <td>
                            <?= lp_money($row['minimum_amount']) ?>
                            -
                            <?= lp_money($row['maximum_amount']) ?>
                        </td>

                        <td><?= lp_money($row['processing_fee']) ?></td>

                        <td><?= number_format((float)$row['penalty_rate'], 2) ?>%</td>

                        <td><?= lp_e(ucfirst((string)$row['repayment_frequency'])) ?></td>

                        <td><?= lp_e(ucfirst((string)$row['interest_type'])) ?></td>

                        <td>
                            <span class="lp-badge <?= $row['status'] === 'active' ? 'lp-badge-success' : 'lp-badge-danger' ?>">
                                <?= lp_e(ucfirst((string)$row['status'])) ?>
                            </span>
                        </td>

                        <td class="text-end">
                            <div class="lp-actions">
                                <button type="button"
                                        class="btn btn-sm btn-info"
                                        data-product='<?= lp_e(json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) ?>'
                                        onclick="editProductFromButton(this)">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <a href="includes/process-loan-product.php?action=delete&id=<?= (int)$row['id'] ?>"
                                   onclick="return confirm('Delete this loan product?')"
                                   class="btn btn-sm btn-danger">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ADD PRODUCT MODAL -->
<div class="lp-modal-overlay" id="addProductModal">
    <div class="lp-modal-box">
        <form method="POST" action="includes/process-loan-product.php">
            <input type="hidden" name="action" value="create">

            <div class="lp-modal-header">
                <h5 class="lp-modal-title">
                    <i class="fas fa-plus-circle me-1"></i> Add Loan Product
                </h5>

                <button type="button" class="lp-modal-close" onclick="closeProductModal('addProductModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="lp-modal-body">
                <div class="lp-form-grid">

                    <div>
                        <label class="lp-form-label">Product Code</label>
                        <input type="text" name="product_code" class="lp-form-control" placeholder="LP-001" required>
                    </div>

                    <div>
                        <label class="lp-form-label">Product Name</label>
                        <input type="text" name="product_name" class="lp-form-control" placeholder="1 Month Business Loan" required>
                    </div>

                    <div class="full">
                        <label class="lp-form-label">Description</label>
                        <textarea name="description" class="lp-form-control" rows="3"></textarea>
                    </div>

                    <div>
                        <label class="lp-form-label">Loan Period Months</label>
                        <input type="number" name="loan_period_months" class="lp-form-control" min="1" required>
                    </div>

                    <div>
                        <label class="lp-form-label">Interest Rate (%)</label>
                        <input type="number" name="interest_rate" class="lp-form-control" step="0.01" min="0" required>
                    </div>

                    <div>
                        <label class="lp-form-label">Minimum Amount</label>
                        <input type="number" name="minimum_amount" class="lp-form-control" step="0.01" min="0" value="0">
                    </div>

                    <div>
                        <label class="lp-form-label">Maximum Amount</label>
                        <input type="number" name="maximum_amount" class="lp-form-control" step="0.01" min="0" value="0">
                    </div>

                    <div>
                        <label class="lp-form-label">Processing Fee</label>
                        <input type="number" name="processing_fee" class="lp-form-control" step="0.01" min="0" value="0">
                    </div>

                    <div>
                        <label class="lp-form-label">Penalty Rate (%)</label>
                        <input type="number" name="penalty_rate" class="lp-form-control" step="0.01" min="0" value="0">
                    </div>

                    <div>
                        <label class="lp-form-label">Repayment Frequency</label>
                        <select name="repayment_frequency" class="lp-form-control">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly" selected>Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>

                    <div>
                        <label class="lp-form-label">Interest Type</label>
                        <select name="interest_type" class="lp-form-control">
                            <option value="flat">Flat</option>
                            <option value="reducing">Reducing Balance</option>
                        </select>
                    </div>

                    <div>
                        <label class="lp-form-label">Status</label>
                        <select name="status" class="lp-form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                </div>
            </div>

            <div class="lp-modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeProductModal('addProductModal')">
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Product
                </button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT PRODUCT MODAL -->
<div class="lp-modal-overlay" id="editProductModal">
    <div class="lp-modal-box">
        <form method="POST" action="includes/process-loan-product.php">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">

            <div class="lp-modal-header">
                <h5 class="lp-modal-title">
                    <i class="fas fa-edit me-1"></i> Edit Loan Product
                </h5>

                <button type="button" class="lp-modal-close" onclick="closeProductModal('editProductModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="lp-modal-body">
                <div class="lp-form-grid">

                    <div>
                        <label class="lp-form-label">Product Code</label>
                        <input type="text" name="product_code" id="edit_product_code" class="lp-form-control" required>
                    </div>

                    <div>
                        <label class="lp-form-label">Product Name</label>
                        <input type="text" name="product_name" id="edit_product_name" class="lp-form-control" required>
                    </div>

                    <div class="full">
                        <label class="lp-form-label">Description</label>
                        <textarea name="description" id="edit_description" class="lp-form-control" rows="3"></textarea>
                    </div>

                    <div>
                        <label class="lp-form-label">Loan Period Months</label>
                        <input type="number" name="loan_period_months" id="edit_loan_period_months" class="lp-form-control" min="1" required>
                    </div>

                    <div>
                        <label class="lp-form-label">Interest Rate (%)</label>
                        <input type="number" name="interest_rate" id="edit_interest_rate" class="lp-form-control" step="0.01" min="0" required>
                    </div>

                    <div>
                        <label class="lp-form-label">Minimum Amount</label>
                        <input type="number" name="minimum_amount" id="edit_minimum_amount" class="lp-form-control" step="0.01" min="0">
                    </div>

                    <div>
                        <label class="lp-form-label">Maximum Amount</label>
                        <input type="number" name="maximum_amount" id="edit_maximum_amount" class="lp-form-control" step="0.01" min="0">
                    </div>

                    <div>
                        <label class="lp-form-label">Processing Fee</label>
                        <input type="number" name="processing_fee" id="edit_processing_fee" class="lp-form-control" step="0.01" min="0">
                    </div>

                    <div>
                        <label class="lp-form-label">Penalty Rate (%)</label>
                        <input type="number" name="penalty_rate" id="edit_penalty_rate" class="lp-form-control" step="0.01" min="0">
                    </div>

                    <div>
                        <label class="lp-form-label">Repayment Frequency</label>
                        <select name="repayment_frequency" id="edit_repayment_frequency" class="lp-form-control">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>

                    <div>
                        <label class="lp-form-label">Interest Type</label>
                        <select name="interest_type" id="edit_interest_type" class="lp-form-control">
                            <option value="flat">Flat</option>
                            <option value="reducing">Reducing Balance</option>
                        </select>
                    </div>

                    <div>
                        <label class="lp-form-label">Status</label>
                        <select name="status" id="edit_status" class="lp-form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                </div>
            </div>

            <div class="lp-modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeProductModal('editProductModal')">
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Update Product
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openProductModal(id) {
    const modal = document.getElementById(id);

    if (!modal) return;

    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeProductModal(id) {
    const modal = document.getElementById(id);

    if (!modal) return;

    modal.classList.remove('open');
    document.body.style.overflow = '';
}

function setField(id, value) {
    const field = document.getElementById(id);

    if (field) {
        field.value = value ?? '';
    }
}

function editProductFromButton(button) {
    try {
        const raw = button.getAttribute('data-product');
        const row = JSON.parse(raw);

        setField('edit_id', row.id);
        setField('edit_product_code', row.product_code);
        setField('edit_product_name', row.product_name);
        setField('edit_description', row.description || '');
        setField('edit_loan_period_months', row.loan_period_months);
        setField('edit_interest_rate', row.interest_rate);
        setField('edit_minimum_amount', row.minimum_amount);
        setField('edit_maximum_amount', row.maximum_amount);
        setField('edit_processing_fee', row.processing_fee);
        setField('edit_penalty_rate', row.penalty_rate);
        setField('edit_repayment_frequency', row.repayment_frequency || 'monthly');
        setField('edit_interest_type', row.interest_type || 'flat');
        setField('edit_status', row.status || 'active');

        openProductModal('editProductModal');

    } catch (error) {
        console.error(error);
        alert('Failed to load product details.');
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.lp-modal-overlay.open').forEach(modal => {
            modal.classList.remove('open');
        });

        document.body.style.overflow = '';
    }
});

document.addEventListener('click', function(event) {
    const overlay = event.target.closest('.lp-modal-overlay');

    if (overlay && event.target === overlay) {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    }
});
</script>

<?php require 'footer.php'; ?>