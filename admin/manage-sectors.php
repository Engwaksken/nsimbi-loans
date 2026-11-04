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

function riskBadgeClass(float $score): string
{
    if ($score >= 8) return 'badge-danger';
    if ($score >= 5) return 'badge-warning';
    if ($score >= 1) return 'badge-primary';
    return 'badge-muted';
}

$sectors = [];

$stmt = $conn->prepare("
    SELECT id, name, score
    FROM sectorRiskScores
    ORDER BY name ASC
");

if (!$stmt) {
    die("Sector query failed: " . h($conn->error));
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $sectors[] = $row;
}

$stmt->close();

$totalSectors = count($sectors);
$totalScore = 0;
$highRisk = 0;
$mediumRisk = 0;
$lowRisk = 0;

foreach ($sectors as $sector) {
    $score = (float)($sector['score'] ?? 0);
    $totalScore += $score;

    if ($score >= 8) {
        $highRisk++;
    } elseif ($score >= 5) {
        $mediumRisk++;
    } else {
        $lowRisk++;
    }
}

$avgScore = $totalSectors > 0 ? $totalScore / $totalSectors : 0;
?>

<style>
.sector-page {
    padding: 1rem;
}

.sector-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.sector-stat-card {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e5e7eb;
    border-left: 5px solid #2563eb;
    padding: 1rem;
    box-shadow: 0 8px 24px rgba(15,23,42,.05);
}

.sector-stat-card.danger {
    border-left-color: #dc2626;
}

.sector-stat-card.warning {
    border-left-color: #f59e0b;
}

.sector-stat-card.success {
    border-left-color: #16a34a;
}

.sector-stat-flex {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
}

.sector-stat-label {
    margin: 0;
    color: #64748b;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.sector-stat-value {
    margin: .35rem 0 0;
    color: #111827;
    font-size: 1.35rem;
    font-weight: 900;
}

.sector-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: #2563eb;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.sector-stat-card.danger .sector-stat-icon {
    background: #dc2626;
}

.sector-stat-card.warning .sector-stat-icon {
    background: #f59e0b;
}

.sector-stat-card.success .sector-stat-icon {
    background: #16a34a;
}

.sector-card {
    background: #fff;
    border-radius: 18px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 8px 24px rgba(15,23,42,.06);
    overflow: hidden;
}

.sector-card-header {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.sector-card-title {
    margin: 0;
    font-weight: 900;
    color: #111827;
}

.sector-card-sub {
    margin: .2rem 0 0;
    color: #64748b;
    font-size: .85rem;
}

.sector-toolbar {
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
    align-items: center;
}

.sector-search {
    width: 260px;
    max-width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    padding: .55rem .75rem;
}

.sector-table-scroll {
    width: 100%;
    overflow-x: auto;
}

.sector-table {
    width: 100%;
    min-width: 760px;
    margin: 0;
    border-collapse: collapse;
}

.sector-table th {
    background: #f8fafc;
    color: #475569;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: .9rem 1rem;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
}

.sector-table td {
    padding: .9rem 1rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.sector-name {
    font-weight: 800;
    color: #111827;
}

.sector-score-bar {
    width: 150px;
    height: 8px;
    background: #e5e7eb;
    border-radius: 999px;
    overflow: hidden;
}

.sector-score-fill {
    height: 100%;
    background: #2563eb;
    border-radius: 999px;
}

.sector-score-fill.warning {
    background: #f59e0b;
}

.sector-score-fill.danger {
    background: #dc2626;
}

.sector-actions {
    display: flex;
    justify-content: center;
    gap: .4rem;
}

.sector-empty {
    padding: 2.5rem;
    text-align: center;
    color: #64748b;
}

/* Custom modal */
.sector-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(15, 23, 42, .55);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.sector-modal-overlay.show {
    display: flex;
}

.sector-modal-box {
    background: #fff;
    width: 100%;
    max-width: 520px;
    border-radius: 18px;
    box-shadow: 0 24px 60px rgba(15,23,42,.25);
    overflow: hidden;
}

.sector-modal-header {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.sector-modal-title {
    margin: 0;
    font-weight: 900;
    color: #111827;
}

.sector-modal-close {
    border: 0;
    background: #f3f4f6;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 1.2rem;
}

.sector-modal-body {
    padding: 1.2rem;
}

.sector-modal-footer {
    padding: 1rem 1.2rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: .5rem;
}

.sector-form-label {
    display: block;
    font-weight: 800;
    margin-bottom: .35rem;
    color: #111827;
}

.sector-form-control {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    padding: .7rem .8rem;
}

.sector-alert {
    display: none;
    padding: .75rem .9rem;
    border-radius: 10px;
    margin-bottom: 1rem;
}

.sector-alert.error {
    display: block;
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.sector-alert.success {
    display: block;
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.badge-muted {
    background: #f1f5f9;
    color: #475569;
}

@media (max-width: 992px) {
    .sector-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 576px) {
    .sector-stats-grid {
        grid-template-columns: 1fr;
    }

    .sector-toolbar {
        width: 100%;
    }

    .sector-search {
        width: 100%;
    }
}
</style>

<div class="container-fluid py-4 sector-page">

    <div class="page-header mb-4">
        <div>
            <div class="title">Manage Sectors</div>
            <div class="sub"><?= number_format($totalSectors) ?> sector<?= $totalSectors === 1 ? '' : 's' ?> configured</div>
        </div>

        <button class="btn btn-primary" type="button" id="addSectorBtn">
            <i class="fas fa-plus me-1"></i> Add Sector
        </button>
    </div>

    <div class="sector-stats-grid">
        <div class="sector-stat-card">
            <div class="sector-stat-flex">
                <div>
                    <p class="sector-stat-label">Total Sectors</p>
                    <h4 class="sector-stat-value"><?= number_format($totalSectors) ?></h4>
                </div>
                <div class="sector-stat-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
            </div>
        </div>

        <div class="sector-stat-card danger">
            <div class="sector-stat-flex">
                <div>
                    <p class="sector-stat-label">High Risk</p>
                    <h4 class="sector-stat-value"><?= number_format($highRisk) ?></h4>
                </div>
                <div class="sector-stat-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
        </div>

        <div class="sector-stat-card warning">
            <div class="sector-stat-flex">
                <div>
                    <p class="sector-stat-label">Medium Risk</p>
                    <h4 class="sector-stat-value"><?= number_format($mediumRisk) ?></h4>
                </div>
                <div class="sector-stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
        </div>

        <div class="sector-stat-card success">
            <div class="sector-stat-flex">
                <div>
                    <p class="sector-stat-label">Average Score</p>
                    <h4 class="sector-stat-value"><?= number_format($avgScore, 1) ?>/10</h4>
                </div>
                <div class="sector-stat-icon">
                    <i class="fas fa-calculator"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="sector-card">
        <div class="sector-card-header">
            <div>
                <h5 class="sector-card-title">Sector Risk Scores</h5>
                <p class="sector-card-sub">Create, edit, and delete sector scoring values.</p>
            </div>

            <div class="sector-toolbar">
                <input type="text" id="sectorSearch" class="sector-search" placeholder="Search sector...">

                <button type="button" class="btn btn-sm btn-outline-success" onclick="downloadSectorCSV()">
                    <i class="fas fa-file-csv me-1"></i> CSV
                </button>
            </div>
        </div>

        <div class="sector-table-scroll">
            <table class="sector-table" id="sectorTable">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Sector Name</th>
                    <th>Risk Score</th>
                    <th>Risk Level</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!empty($sectors)): ?>
                    <?php foreach ($sectors as $index => $sector): ?>
                        <?php
                        $id = (int)($sector['id'] ?? 0);
                        $name = (string)($sector['name'] ?? '');
                        $score = (float)($sector['score'] ?? 0);
                        $scorePercent = max(0, min(100, $score * 10));
                        $riskClass = riskBadgeClass($score);
                        $barClass = $score >= 8 ? 'danger' : ($score >= 5 ? 'warning' : '');
                        $riskLabel = $score >= 8 ? 'High' : ($score >= 5 ? 'Medium' : 'Low');
                        ?>
                        <tr>
                            <td><?= number_format($index + 1) ?></td>

                            <td>
                                <div class="sector-name"><?= h($name) ?></div>
                            </td>

                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <strong><?= number_format($score, 1) ?></strong>
                                    <div class="sector-score-bar">
                                        <div class="sector-score-fill <?= h($barClass) ?>"
                                             style="width: <?= h($scorePercent) ?>%;"></div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <span class="badge <?= h($riskClass) ?>">
                                    <?= h($riskLabel) ?> Risk
                                </span>
                            </td>

                            <td class="text-center">
                                <div class="sector-actions">
                                    <button type="button"
                                            class="btn btn-sm btn-info js-edit-sector"
                                            data-id="<?= $id ?>"
                                            data-name="<?= h($name) ?>"
                                            data-score="<?= h($score) ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <button type="button"
                                            class="btn btn-sm btn-danger js-delete-sector"
                                            data-id="<?= $id ?>"
                                            data-name="<?= h($name) ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">
                            <div class="sector-empty">
                                <i class="fas fa-layer-group fa-2x mb-2"></i>
                                <h6>No sectors found</h6>
                                <p class="mb-0">Add your first sector to begin.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Sector Modal -->
<div class="sector-modal-overlay" id="sectorModal">
    <div class="sector-modal-box">
        <div class="sector-modal-header">
            <h5 class="sector-modal-title" id="sectorModalTitle">Add Sector</h5>
            <button type="button" class="sector-modal-close js-close-sector-modal">&times;</button>
        </div>

        <div class="sector-modal-body">
            <div id="sectorFormAlert" class="sector-alert"></div>

            <form id="sectorForm">
                <input type="hidden" id="sectorId">

                <div class="mb-3">
                    <label class="sector-form-label">Sector Name</label>
                    <input type="text" class="sector-form-control" id="sectorName" required>
                </div>

                <div class="mb-3">
                    <label class="sector-form-label">Risk Score</label>
                    <input type="number"
                           class="sector-form-control"
                           id="sectorScore"
                           step="0.1"
                           min="0"
                           max="10"
                           required>
                    <small class="text-muted">Enter value from 0 to 10.</small>
                </div>
            </form>
        </div>

        <div class="sector-modal-footer">
            <button type="button" class="btn btn-light js-close-sector-modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="saveSector">
                <i class="fas fa-save me-1"></i> Save Sector
            </button>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="sector-modal-overlay" id="deleteModal">
    <div class="sector-modal-box">
        <div class="sector-modal-header">
            <h5 class="sector-modal-title">Delete Sector</h5>
            <button type="button" class="sector-modal-close js-close-sector-modal">&times;</button>
        </div>

        <div class="sector-modal-body">
            <div id="deleteFormAlert" class="sector-alert"></div>

            <p class="mb-0">
                Are you sure you want to delete this sector:
                <strong id="deleteSectorName"></strong>?
            </p>
        </div>

        <div class="sector-modal-footer">
            <button type="button" class="btn btn-light js-close-sector-modal">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDelete">
                <i class="fas fa-trash-alt me-1"></i> Delete
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const sectorModal = document.getElementById('sectorModal');
    const deleteModal = document.getElementById('deleteModal');

    const sectorId = document.getElementById('sectorId');
    const sectorName = document.getElementById('sectorName');
    const sectorScore = document.getElementById('sectorScore');
    const sectorModalTitle = document.getElementById('sectorModalTitle');
    const sectorFormAlert = document.getElementById('sectorFormAlert');

    const deleteSectorName = document.getElementById('deleteSectorName');
    const deleteFormAlert = document.getElementById('deleteFormAlert');
    const confirmDelete = document.getElementById('confirmDelete');

    function openModal(modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeModals() {
        document.querySelectorAll('.sector-modal-overlay').forEach(modal => {
            modal.classList.remove('show');
        });
        document.body.style.overflow = '';
    }

    function showAlert(el, message, type = 'error') {
        el.className = 'sector-alert ' + type;
        el.textContent = message;
    }

    function clearAlert(el) {
        el.className = 'sector-alert';
        el.textContent = '';
    }

    document.getElementById('addSectorBtn')?.addEventListener('click', function () {
        sectorModalTitle.textContent = 'Add Sector';
        sectorId.value = '';
        sectorName.value = '';
        sectorScore.value = '';
        clearAlert(sectorFormAlert);
        openModal(sectorModal);
    });

    document.querySelectorAll('.js-edit-sector').forEach(btn => {
        btn.addEventListener('click', function () {
            sectorModalTitle.textContent = 'Edit Sector';
            sectorId.value = this.dataset.id || '';
            sectorName.value = this.dataset.name || '';
            sectorScore.value = this.dataset.score || '';
            clearAlert(sectorFormAlert);
            openModal(sectorModal);
        });
    });

    document.querySelectorAll('.js-delete-sector').forEach(btn => {
        btn.addEventListener('click', function () {
            confirmDelete.dataset.id = this.dataset.id || '';
            deleteSectorName.textContent = this.dataset.name || '';
            clearAlert(deleteFormAlert);
            openModal(deleteModal);
        });
    });

    document.querySelectorAll('.js-close-sector-modal').forEach(btn => {
        btn.addEventListener('click', closeModals);
    });

    document.querySelectorAll('.sector-modal-overlay').forEach(modal => {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModals();
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModals();
        }
    });

    document.getElementById('saveSector')?.addEventListener('click', async function () {
        const id = sectorId.value.trim();
        const name = sectorName.value.trim();
        const score = parseFloat(sectorScore.value);

        clearAlert(sectorFormAlert);

        if (!name) {
            showAlert(sectorFormAlert, 'Sector name is required.');
            return;
        }

        if (Number.isNaN(score) || score < 0 || score > 10) {
            showAlert(sectorFormAlert, 'Risk score must be between 0 and 10.');
            return;
        }

        const btn = this;
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

        try {
            const response = await fetch('manage_sector.php', {
                method: id ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    id: id || null,
                    name: name,
                    score: score
                })
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Error saving sector.');
            }

            showAlert(sectorFormAlert, data.message || 'Sector saved successfully.', 'success');

            setTimeout(() => {
                window.location.reload();
            }, 600);

        } catch (error) {
            showAlert(sectorFormAlert, error.message || 'Error saving sector.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = oldHtml;
        }
    });

    confirmDelete?.addEventListener('click', async function () {
        const id = this.dataset.id || '';

        clearAlert(deleteFormAlert);

        if (!id) {
            showAlert(deleteFormAlert, 'Invalid sector selected.');
            return;
        }

        const btn = this;
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Deleting...';

        try {
            const response = await fetch('manage_sector.php?id=' + encodeURIComponent(id), {
                method: 'DELETE',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Error deleting sector.');
            }

            showAlert(deleteFormAlert, data.message || 'Sector deleted successfully.', 'success');

            setTimeout(() => {
                window.location.reload();
            }, 600);

        } catch (error) {
            showAlert(deleteFormAlert, error.message || 'Error deleting sector.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = oldHtml;
        }
    });

    document.getElementById('sectorSearch')?.addEventListener('keyup', function () {
        const value = this.value.toLowerCase();

        document.querySelectorAll('#sectorTable tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
        });
    });
});

function downloadSectorCSV() {
    const rows = document.querySelectorAll('#sectorTable tr');
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
    link.download = 'sectors.csv';
    link.click();
}
</script>

<?php require "footer.php"; ?>