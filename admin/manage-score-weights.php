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

$components = [
    'esoRecommendation'        => 'ESO Recommendation',
    'sectorRisk'               => 'Business Sector',
    'mobileMoneyTransactions'  => 'Mobile Money Transactions',
    'personalRecords'          => 'Personal Records',
    'businessRecords'          => 'Business Records'
];

$weights = [];

$stmt = $conn->prepare("
    SELECT id, name, weight
    FROM scoreWeights
    ORDER BY id ASC
");

if (!$stmt) {
    die("Score weights query failed: " . h($conn->error));
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $weights[] = $row;
}

$stmt->close();

$totalWeight = 0;
foreach ($weights as $weight) {
    $totalWeight += (float)($weight['weight'] ?? 0);
}

$weightPercent = $totalWeight * 100;
$isBalanced = abs($totalWeight - 1.0) < 0.001;
?>

<style>
.weight-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.weight-stat-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-left: 5px solid #2563eb;
    border-radius: 16px;
    padding: 1rem;
    box-shadow: 0 8px 24px rgba(15,23,42,.05);
}

.weight-stat-card.success { border-left-color: #16a34a; }
.weight-stat-card.warning { border-left-color: #f59e0b; }
.weight-stat-card.danger { border-left-color: #dc2626; }

.weight-stat-flex {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.weight-stat-label {
    margin: 0;
    font-size: .72rem;
    font-weight: 800;
    color: #64748b;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.weight-stat-value {
    margin: .35rem 0 0;
    font-size: 1.3rem;
    font-weight: 900;
    color: #111827;
}

.weight-stat-icon {
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

.weight-stat-card.success .weight-stat-icon { background: #16a34a; }
.weight-stat-card.warning .weight-stat-icon { background: #f59e0b; }
.weight-stat-card.danger .weight-stat-icon { background: #dc2626; }

.weight-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(15,23,42,.06);
    overflow: hidden;
}

.weight-card-header {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.weight-card-title {
    margin: 0;
    font-weight: 900;
    color: #111827;
}

.weight-card-sub {
    margin: .2rem 0 0;
    color: #64748b;
    font-size: .85rem;
}

.weight-table-scroll {
    width: 100%;
    overflow-x: auto;
}

.weight-table {
    width: 100%;
    min-width: 760px;
    margin: 0;
    border-collapse: collapse;
}

.weight-table th {
    background: #f8fafc;
    color: #475569;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: .95rem 1rem;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
}

.weight-table td {
    padding: .95rem 1rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.weight-component {
    font-weight: 800;
    color: #111827;
}

.weight-key {
    color: #64748b;
    font-size: .78rem;
}

.weight-bar {
    width: 180px;
    height: 9px;
    background: #e5e7eb;
    border-radius: 999px;
    overflow: hidden;
}

.weight-fill {
    height: 100%;
    border-radius: 999px;
    background: #2563eb;
}

.weight-fill.warning { background: #f59e0b; }
.weight-fill.danger { background: #dc2626; }

.weight-alert {
    padding: .85rem 1rem;
    border-radius: 12px;
    margin-bottom: 1rem;
    font-size: .9rem;
}

.weight-alert.success {
    background: #dcfce7;
    color: #166534;
}

.weight-alert.warning {
    background: #fffbeb;
    color: #92400e;
}

.weight-empty {
    padding: 2.5rem;
    text-align: center;
    color: #64748b;
}

/* Modal */
.weight-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(15, 23, 42, .55);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.weight-modal-overlay.show {
    display: flex;
}

.weight-modal-box {
    width: 100%;
    max-width: 520px;
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 24px 60px rgba(15,23,42,.25);
    overflow: hidden;
}

.weight-modal-header {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.weight-modal-title {
    margin: 0;
    font-weight: 900;
    color: #111827;
}

.weight-modal-close {
    border: 0;
    background: #f3f4f6;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 1.2rem;
}

.weight-modal-body {
    padding: 1.2rem;
}

.weight-modal-footer {
    padding: 1rem 1.2rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: .5rem;
}

.weight-form-label {
    display: block;
    font-weight: 800;
    margin-bottom: .35rem;
    color: #111827;
}

.weight-form-control {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    padding: .7rem .8rem;
}

.weight-form-alert {
    display: none;
    padding: .75rem .9rem;
    border-radius: 10px;
    margin-bottom: 1rem;
}

.weight-form-alert.error {
    display: block;
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.weight-form-alert.success {
    display: block;
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

@media (max-width: 992px) {
    .weight-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 576px) {
    .weight-stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container-fluid py-4">

    <div class="page-header mb-4">
        <div>
            <div class="title">Manage Score Weights</div>
            <div class="sub">Configure how each credit scoring component contributes to the final score.</div>
        </div>
    </div>

    <?php if ($isBalanced): ?>
        <div class="weight-alert success">
            <i class="fas fa-check-circle me-1"></i>
            Total weight is balanced at 1.00.
        </div>
    <?php else: ?>
        <div class="weight-alert warning">
            <i class="fas fa-exclamation-triangle me-1"></i>
            Total weight is currently <?= number_format($totalWeight, 2) ?>. Recommended total is 1.00.
        </div>
    <?php endif; ?>

    <div class="weight-stats-grid">
        <div class="weight-stat-card">
            <div class="weight-stat-flex">
                <div>
                    <p class="weight-stat-label">Components</p>
                    <h4 class="weight-stat-value"><?= number_format(count($weights)) ?></h4>
                </div>
                <div class="weight-stat-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>
        </div>

        <div class="weight-stat-card <?= $isBalanced ? 'success' : 'warning' ?>">
            <div class="weight-stat-flex">
                <div>
                    <p class="weight-stat-label">Total Weight</p>
                    <h4 class="weight-stat-value" id="totalWeight"><?= number_format($totalWeight, 2) ?></h4>
                </div>
                <div class="weight-stat-icon">
                    <i class="fas fa-balance-scale"></i>
                </div>
            </div>
        </div>

        <div class="weight-stat-card">
            <div class="weight-stat-flex">
                <div>
                    <p class="weight-stat-label">Percentage</p>
                    <h4 class="weight-stat-value" id="totalPercentage"><?= number_format($weightPercent, 0) ?>%</h4>
                </div>
                <div class="weight-stat-icon">
                    <i class="fas fa-percentage"></i>
                </div>
            </div>
        </div>

        <div class="weight-stat-card <?= $isBalanced ? 'success' : 'danger' ?>">
            <div class="weight-stat-flex">
                <div>
                    <p class="weight-stat-label">Status</p>
                    <h4 class="weight-stat-value" id="weightStatus"><?= $isBalanced ? 'Balanced' : 'Review' ?></h4>
                </div>
                <div class="weight-stat-icon">
                    <i class="fas <?= $isBalanced ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="weight-card">
        <div class="weight-card-header">
            <div>
                <h5 class="weight-card-title">Score Components</h5>
                <p class="weight-card-sub">Each weight must be between 0 and 1. The total should equal 1.00.</p>
            </div>

            <button type="button" class="btn btn-sm btn-outline-success" onclick="downloadWeightsCSV()">
                <i class="fas fa-file-csv me-1"></i> CSV
            </button>
        </div>

        <div class="weight-table-scroll">
            <table class="weight-table" id="weightsTable">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Component</th>
                    <th>Weight</th>
                    <th>Contribution</th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!empty($weights)): ?>
                    <?php foreach ($weights as $index => $weight): ?>
                        <?php
                        $id = (int)($weight['id'] ?? 0);
                        $name = (string)($weight['name'] ?? '');
                        $value = (float)($weight['weight'] ?? 0);
                        $label = $components[$name] ?? $name;
                        $percent = max(0, min(100, $value * 100));
                        $fillClass = $value >= .4 ? 'danger' : ($value >= .2 ? 'warning' : '');
                        ?>
                        <tr>
                            <td><?= number_format($index + 1) ?></td>

                            <td>
                                <div class="weight-component"><?= h($label) ?></div>
                                <div class="weight-key"><?= h($name) ?></div>
                            </td>

                            <td>
                                <strong class="weight-value" data-component="<?= h($name) ?>">
                                    <?= number_format($value, 2) ?>
                                </strong>
                            </td>

                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span><?= number_format($percent, 0) ?>%</span>
                                    <div class="weight-bar">
                                        <div class="weight-fill <?= h($fillClass) ?>"
                                             style="width: <?= h($percent) ?>%;"></div>
                                    </div>
                                </div>
                            </td>

                            <td class="text-center">
                                <button type="button"
                                        class="btn btn-sm btn-info js-edit-weight"
                                        data-component="<?= h($name) ?>"
                                        data-label="<?= h($label) ?>"
                                        data-weight="<?= h($value) ?>"
                                        data-id="<?= h($id) ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">
                            <div class="weight-empty">
                                <i class="fas fa-balance-scale fa-2x mb-2"></i>
                                <h6>No score weights found</h6>
                                <p class="mb-0">Add default weights into the scoreWeights table.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Weight Modal -->
<div class="weight-modal-overlay" id="editWeightModal">
    <div class="weight-modal-box">
        <div class="weight-modal-header">
            <h5 class="weight-modal-title">Edit Weight</h5>
            <button type="button" class="weight-modal-close js-close-weight-modal">&times;</button>
        </div>

        <div class="weight-modal-body">
            <div id="weightFormAlert" class="weight-form-alert"></div>

            <form id="weightForm">
                <input type="hidden" id="componentName">
                <input type="hidden" id="tableId">

                <div class="mb-3">
                    <label class="weight-form-label">Component</label>
                    <input type="text" id="componentLabel" class="weight-form-control" readonly>
                </div>

                <div class="mb-3">
                    <label class="weight-form-label">Weight Value</label>
                    <input type="number"
                           class="weight-form-control"
                           id="weightValue"
                           step="0.01"
                           min="0"
                           max="1"
                           required>
                    <small class="text-muted">Enter a value from 0 to 1.</small>
                </div>
            </form>
        </div>

        <div class="weight-modal-footer">
            <button type="button" class="btn btn-light js-close-weight-modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="saveWeight">
                <i class="fas fa-save me-1"></i> Save Changes
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('editWeightModal');
    const formAlert = document.getElementById('weightFormAlert');

    const componentName = document.getElementById('componentName');
    const componentLabel = document.getElementById('componentLabel');
    const tableId = document.getElementById('tableId');
    const weightValue = document.getElementById('weightValue');

    function openModal() {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }

    function showAlert(message, type = 'error') {
        formAlert.className = 'weight-form-alert ' + type;
        formAlert.textContent = message;
    }

    function clearAlert() {
        formAlert.className = 'weight-form-alert';
        formAlert.textContent = '';
    }

    function updateTotalWeight() {
        let total = 0;

        document.querySelectorAll('.weight-value').forEach(el => {
            total += parseFloat(el.textContent) || 0;
        });

        const totalEl = document.getElementById('totalWeight');
        const percentEl = document.getElementById('totalPercentage');
        const statusEl = document.getElementById('weightStatus');

        if (totalEl) totalEl.textContent = total.toFixed(2);
        if (percentEl) percentEl.textContent = Math.round(total * 100) + '%';

        if (statusEl) {
            statusEl.textContent = Math.abs(total - 1) < 0.001 ? 'Balanced' : 'Review';
        }

        return total;
    }

    document.querySelectorAll('.js-edit-weight').forEach(btn => {
        btn.addEventListener('click', function () {
            componentName.value = this.dataset.component || '';
            componentLabel.value = this.dataset.label || '';
            tableId.value = this.dataset.id || '';
            weightValue.value = this.dataset.weight || 0;

            clearAlert();
            openModal();
        });
    });

    document.querySelectorAll('.js-close-weight-modal').forEach(btn => {
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

    document.getElementById('saveWeight')?.addEventListener('click', async function () {
        const component = componentName.value.trim();
        const id = tableId.value.trim();
        const weight = parseFloat(weightValue.value);

        clearAlert();

        if (!component || !id) {
            showAlert('Invalid score component selected.');
            return;
        }

        if (Number.isNaN(weight) || weight < 0 || weight > 1) {
            showAlert('Weight must be between 0 and 1.');
            return;
        }

        const btn = this;
        const oldHtml = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

        try {
            const response = await fetch('update_weight.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    name: component,
                    weight: weight,
                    id: id
                })
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Error updating weight.');
            }

            const valueEl = document.querySelector('.weight-value[data-component="' + component + '"]');

            if (valueEl) {
                valueEl.textContent = weight.toFixed(2);
            }

            showAlert(data.message || 'Weight updated successfully.', 'success');
            updateTotalWeight();

            setTimeout(() => {
                window.location.reload();
            }, 600);

        } catch (error) {
            showAlert(error.message || 'Error updating weight.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = oldHtml;
        }
    });

    updateTotalWeight();
});

function downloadWeightsCSV() {
    const rows = document.querySelectorAll('#weightsTable tr');
    let csv = [];

    rows.forEach(row => {
        const cols = row.querySelectorAll('th,td');
        let rowData = [];

        cols.forEach(col => {
            rowData.push('"' + col.innerText.replace(/"/g, '""') + '"');
        });

        csv.push(rowData.join(','));
    });

    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const link = document.createElement('a');

    link.href = URL.createObjectURL(blob);
    link.download = 'score_weights.csv';
    link.click();
}
</script>

<?php require "footer.php"; ?>