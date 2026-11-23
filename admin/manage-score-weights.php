<?php
declare(strict_types=1);

/*
 * System > Manage Score Weights: how much each component counts in a
 * client's credit score. The weights always total 1.00 (100%). Reviewers
 * (role 1) edit them; saving is refused unless they add up to 100%.
 * Reading and tidying the table: includes/score_weights.php.
 */

$phead = 'Manage Score Weights';
require "nav-head.php";
require_once dirname(__DIR__) . '/includes/score_weights.php';

if (!isset($conn)) {
    die("Database connection not established.");
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$loadError = '';
try {
    $state = score_weights_state($conn);
} catch (Throwable $e) {
    error_log('Manage score weights: ' . $e->getMessage());
    $state = ['weights' => array_fill_keys(array_keys(CREDIT_COMPONENTS), 0.0), 'ids' => [], 'total' => 0.0,
        'duplicates' => 0, 'unused' => [], 'missing' => [], 'raw_total' => 0.0];
    $loadError = 'Could not load the score weights. Check the server log.';
}

$weights = $state['weights'];
$total = (float)$state['total'];
$isBalanced = abs($total - 1.0) < 0.001;
$isOk = $loadError === '' && score_weights_ok($state);
$canEdit = (int)($_SESSION['role'] ?? 0) === 1;

arsort($weights);
$largestName = (string)array_key_first($weights);
$largest = $weights[$largestName] ?? 0.0;
// Show in the scoring order, not by size.
$weights = array_replace(array_fill_keys(array_keys(CREDIT_COMPONENTS), 0.0), $weights);

// What is wrong, in plain words.
$problems = [];
if (!$isBalanced) {
    $problems[] = sprintf('The weights add up to %.2f instead of 1.00.', $total);
}
if ($state['duplicates'] > 0) {
    $problems[] = $state['duplicates'] . ' component row(s) are stored twice.';
}
if ($state['unused']) {
    $problems[] = 'Rows not used by scoring: ' . implode(', ', $state['unused']) . '.';
}
if ($state['missing']) {
    $problems[] = 'No row yet for: ' . implode(', ', array_map(static fn($n) => CREDIT_COMPONENTS[$n]['label'], $state['missing'])) . '.';
}
?>
<style>
.sw-table td { vertical-align: middle; }
.sw-name { font-weight: 700; color: var(--text-dark); }
.sw-key { font-size: .7rem; color: #94a3b8; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
.sw-help { font-size: .76rem; color: #64748b; white-space: normal; max-width: 420px; }
.sw-bar { height: 7px; border-radius: 99px; background: #eef2f7; overflow: hidden; min-width: 120px; }
.sw-bar span { display: block; height: 100%; background: var(--primary, #2563eb); border-radius: 99px; }
.sw-weight { font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 1rem; color: var(--text-dark); }
.sw-alert { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; border-radius: 12px; padding: .85rem 1rem; margin-bottom: 1rem; font-size: .88rem; border: 1px solid; }
.sw-alert.ok { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
.sw-alert.warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.sw-alert ul { margin: .3rem 0 0; padding-left: 1.1rem; }
.sw-edit-row { display: grid; grid-template-columns: minmax(0, 1fr) 110px; gap: .75rem; align-items: center; padding: .55rem 0; border-bottom: 1px solid #f1f5f9; }
.sw-edit-row:last-child { border-bottom: 0; }
.sw-edit-row label { font-weight: 700; font-size: .85rem; margin: 0; }
.sw-edit-row .sw-help { max-width: none; }
.sw-input { position: relative; }
.sw-input input { width: 100%; text-align: right; padding: .45rem 1.7rem .45rem .6rem; border: 1px solid #d1d5db; border-radius: 9px; font-weight: 700; }
.sw-input span { position: absolute; right: .6rem; top: 50%; transform: translateY(-50%); color: #64748b; font-weight: 700; pointer-events: none; }
.sw-total { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .7rem .9rem; border-radius: 10px; margin-top: .8rem; font-weight: 800; }
.sw-total.ok { background: #f0fdf4; color: #166534; }
.sw-total.bad { background: #fef2f2; color: #b91c1c; }
.sw-form-error { color: #b91c1c; font-size: .82rem; margin-top: .5rem; }
@media (max-width: 576px) { .sw-edit-row { grid-template-columns: minmax(0, 1fr) 92px; } }
</style>

<div class="page-header mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <div class="title">Manage Score Weights</div>
        <div class="sub">How much each component counts in a client's credit score (0 to 10). The weights always add up to 100%.</div>
    </div>
    <?php if ($canEdit && $loadError === ''): ?>
        <button type="button" class="btn btn-primary" data-sw-open><i class="fas fa-sliders-h"></i> Edit weights</button>
    <?php endif; ?>
</div>

<?php if ($loadError !== ''): ?>
    <div class="alert alert-danger"><?= h($loadError) ?></div>
<?php elseif ($isOk): ?>
    <div class="sw-alert ok"><div><i class="fas fa-check-circle me-1"></i> The weights add up to 1.00 (100%) and each component is stored once.</div></div>
<?php else: ?>
    <div class="sw-alert warn">
        <div>
            <strong><i class="fas fa-exclamation-triangle me-1"></i> The score weights need fixing.</strong>
            <ul><?php foreach ($problems as $p): ?><li><?= h($p) ?></li><?php endforeach; ?></ul>
            <div class="mt-1">Fixing scales the weights below to total 1.00, keeping each component's share, and removes the extra rows. Scores already use these shares, so no client's score changes.</div>
        </div>
        <?php if ($canEdit): ?>
            <button type="button" class="btn btn-warning btn-sm" id="normalizeWeights"><i class="fas fa-balance-scale me-1"></i> Fix: balance to 1.00</button>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="stats-grid stats-compact">
    <div class="stat-card c-blue">
        <div class="stat-head"><div class="stat-ico c-blue"><i class="fas fa-layer-group"></i></div></div>
        <div class="stat-value"><?= count(CREDIT_COMPONENTS) ?></div>
        <div class="stat-label">Score components</div>
        <div class="stat-foot"><i class="fas fa-calculator"></i> Score = sum of score &times; weight</div>
    </div>
    <div class="stat-card <?= $isBalanced ? 'c-green' : 'c-orange' ?>">
        <div class="stat-head">
            <div class="stat-ico <?= $isBalanced ? 'c-green' : 'c-orange' ?>"><i class="fas fa-balance-scale"></i></div>
            <span class="stat-badge <?= $isBalanced ? 'up' : 'down' ?>"><?= round($total * 100) ?>%</span>
        </div>
        <div class="stat-value"><?= number_format($total, 2) ?></div>
        <div class="stat-label">Total weight</div>
        <div class="stat-foot"><i class="fas fa-bullseye"></i> Must be 1.00</div>
    </div>
    <div class="stat-card <?= $isOk ? 'c-green' : 'c-red' ?>">
        <div class="stat-head"><div class="stat-ico <?= $isOk ? 'c-green' : 'c-red' ?>"><i class="fas <?= $isOk ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i></div></div>
        <div class="stat-value"><?= $isOk ? 'Balanced' : 'Needs fixing' ?></div>
        <div class="stat-label">Status</div>
        <div class="stat-foot"><i class="fas fa-database"></i> <?= $state['duplicates'] + count($state['unused']) ?> extra row(s)</div>
    </div>
    <div class="stat-card c-purple">
        <div class="stat-head"><div class="stat-ico c-purple"><i class="fas fa-crown"></i></div></div>
        <div class="stat-value sm"><?= $total > 0 ? round($largest / $total * 100) : 0 ?>%</div>
        <div class="stat-label">Largest share</div>
        <div class="stat-foot"><i class="fas fa-tag"></i> <?= h(CREDIT_COMPONENTS[$largestName]['label'] ?? '-') ?></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h5 class="mb-0">Score components</h5>
            <small class="text-muted">Document components only raise a score: without a document they count at the business-type score. A loan overdue more than 30 days caps the score at 4 (more than 90 days at 2).</small>
        </div>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="downloadWeightsCSV()"><i class="fas fa-file-csv me-1"></i> CSV</button>
    </div>
    <div class="table-wrap">
        <table class="table sw-table" id="weightsTable">
            <thead>
                <tr><th>#</th><th>Component</th><th>Weight</th><th>Share of the score</th></tr>
            </thead>
            <tbody>
            <?php $i = 0; foreach ($weights as $name => $w): $i++; $share = $total > 0 ? $w / $total : 0; ?>
                <tr>
                    <td><?= $i ?></td>
                    <td>
                        <div class="sw-name"><?= h(CREDIT_COMPONENTS[$name]['label']) ?></div>
                        <div class="sw-help"><?= h(SCORE_WEIGHT_HELP[$name] ?? '') ?></div>
                        <div class="sw-key"><?= h($name) ?></div>
                    </td>
                    <td><span class="sw-weight"><?= number_format($w, 2) ?></span></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <strong style="min-width:3ch"><?= round($share * 100) ?>%</strong>
                            <div class="sw-bar flex-grow-1"><span style="width:<?= min(100, round($share * 100, 1)) ?>%"></span></div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canEdit && $loadError === ''): ?>
<div class="modal-overlay" id="swModal" aria-hidden="true">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="swModalTitle">
        <div class="modal-header">
            <div class="modal-title"><div class="modal-ico"><i class="fas fa-sliders-h"></i></div><span class="modal-title-text" id="swModalTitle">Edit score weights</span></div>
            <button type="button" class="modal-close" data-sw-close aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <form id="swForm" novalidate>
            <div class="modal-body">
                <p class="text-muted fs-sm mt-0">Give each component a share of the score in whole percent. They must add up to 100%. <strong>Balance</strong> scales what you typed to 100% and keeps the proportions.</p>
                <?php foreach ($weights as $name => $w): ?>
                    <div class="sw-edit-row">
                        <div>
                            <label for="sw-<?= h($name) ?>"><?= h(CREDIT_COMPONENTS[$name]['label']) ?></label>
                            <div class="sw-help"><?= h(SCORE_WEIGHT_HELP[$name] ?? '') ?></div>
                        </div>
                        <div class="sw-input">
                            <input type="number" id="sw-<?= h($name) ?>" data-sw-name="<?= h($name) ?>" min="0" max="100" step="1" inputmode="numeric"
                                   value="<?= $total > 0 ? round($w / $total * 100) : 0 ?>">
                            <span>%</span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="sw-total" id="swTotal"><span>Total</span><span id="swTotalValue">100%</span></div>
                <div class="sw-form-error" id="swError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-sw-close>Cancel</button>
                <button type="button" class="btn btn-secondary" id="swBalance"><i class="fas fa-balance-scale me-1"></i> Balance to 100%</button>
                <button type="submit" class="btn btn-primary" id="swSave"><i class="fas fa-save me-1"></i> Save</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    async function post(body) {
        const response = await fetch('update_weight.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        });
        let data = {};
        try { data = await response.json(); } catch (e) { /* not JSON */ }
        if (!data.success) throw new Error(data.message || 'Could not save the weights.');
        return data;
    }
    function busy(btn, text) {
        const old = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> ' + text;
        return function () { btn.disabled = false; btn.innerHTML = old; };
    }
    function notify(message) {
        return window.Popup ? Popup.alert(message) : Promise.resolve(window.alert(message));
    }

    // One-click fix from the warning.
    const fixBtn = document.getElementById('normalizeWeights');
    fixBtn?.addEventListener('click', async function () {
        const ok = window.Popup
            ? await Popup.confirm('Scale all weights so they add up to 1.00 and remove duplicate or unused rows? Each component keeps the same share.')
            : window.confirm('Scale all weights so they add up to 1.00?');
        if (!ok) return;
        const done = busy(fixBtn, 'Balancing...');
        try {
            const data = await post({ action: 'normalize' });
            await notify(data.message);
            window.location.reload();
        } catch (error) {
            done();
            notify(error.message);
        }
    });

    // Edit modal.
    const modal = document.getElementById('swModal');
    if (!modal) return;
    const form = document.getElementById('swForm');
    const inputs = Array.from(form.querySelectorAll('[data-sw-name]'));
    const totalBox = document.getElementById('swTotal');
    const totalValue = document.getElementById('swTotalValue');
    const errorBox = document.getElementById('swError');
    const saveBtn = document.getElementById('swSave');
    const initial = inputs.map(i => i.value);

    function percent(input) {
        const v = Math.round(parseFloat(input.value));
        return Number.isFinite(v) ? v : 0;
    }
    function refresh() {
        const total = inputs.reduce((sum, i) => sum + percent(i), 0);
        const bad = inputs.some(i => percent(i) < 0 || percent(i) > 100);
        totalValue.textContent = total + '%' + (total === 100 ? '' : (total > 100 ? ' (' + (total - 100) + '% too much)' : ' (' + (100 - total) + '% short)'));
        totalBox.className = 'sw-total ' + (total === 100 && !bad ? 'ok' : 'bad');
        saveBtn.disabled = total !== 100 || bad;
        errorBox.hidden = true;
        return total;
    }
    function open() {
        inputs.forEach((i, n) => { i.value = initial[n]; });
        refresh();
        if (window.Modal) { Modal.open('swModal'); } else { modal.classList.add('open'); }
        modal.setAttribute('aria-hidden', 'false');
        setTimeout(() => inputs[0]?.focus(), 80);
    }
    function close() {
        if (window.Modal) { Modal.close('swModal'); } else { modal.classList.remove('open'); }
        modal.setAttribute('aria-hidden', 'true');
    }

    // Same rule as the server: whole percents, leftovers to the largest remainders.
    document.getElementById('swBalance').addEventListener('click', function () {
        const values = inputs.map(i => Math.max(0, percent(i)));
        const sum = values.reduce((a, b) => a + b, 0);
        const exact = values.map(v => sum > 0 ? v / sum * 100 : 100 / values.length);
        const floors = exact.map(Math.floor);
        let left = 100 - floors.reduce((a, b) => a + b, 0);
        exact.map((e, n) => [e - floors[n], n]).sort((a, b) => b[0] - a[0]).forEach(([, n]) => {
            if (left > 0) { floors[n]++; left--; }
        });
        inputs.forEach((i, n) => { i.value = floors[n]; });
        refresh();
    });

    inputs.forEach(i => i.addEventListener('input', refresh));
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-sw-open]')) { open(); return; }
        if (e.target.closest('[data-sw-close]') || e.target === modal) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) close();
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        if (refresh() !== 100) return;
        const weights = {};
        inputs.forEach(i => { weights[i.dataset.swName] = percent(i) / 100; });
        const done = busy(saveBtn, 'Saving...');
        try {
            const data = await post({ action: 'save', weights: weights });
            close();
            await notify(data.message);
            window.location.reload();
        } catch (error) {
            done();
            errorBox.textContent = error.message;
            errorBox.hidden = false;
        }
    });
});

function downloadWeightsCSV() {
    const rows = document.querySelectorAll('#weightsTable tr');
    const csv = [];
    rows.forEach(row => {
        const data = [];
        row.querySelectorAll('th,td').forEach(col => data.push('"' + col.innerText.replace(/\s+/g, ' ').trim().replace(/"/g, '""') + '"'));
        csv.push(data.join(','));
    });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv.join('\n')], { type: 'text/csv' }));
    link.download = 'score_weights.csv';
    link.click();
}
</script>
<?php require 'footer.php'; ?>
