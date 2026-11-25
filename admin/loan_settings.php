<?php
declare(strict_types=1);

/*
 * Loans > Loan Settings: the processing fee taken from each payout, the loan
 * officer district rules, and a read-only view of the repayment terms and
 * interest rates the app offers. One tab each; changes are made in modals.
 * Reviewers (role 1) only.
 */

$phead = 'Loan Settings';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/loan_offers.php';
require_once dirname(__DIR__) . '/includes/officer_assignment.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    header('Location: dashboard.php');
    exit();
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['loan_settings_csrf'])) {
    $_SESSION['loan_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['loan_settings_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['Your session expired. Please try again.', 'error'];
    $tab = ($_POST['form'] ?? '') === 'officers' ? 'officers' : 'fee';
    if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? '')) && ($_POST['form'] ?? '') === 'officers') {
        $officers = officer_list($conn);
        $rules = ['districts' => [], 'default' => (int)($_POST['default_officer'] ?? 0)];
        $flash = null;
        if (!isset($officers[$rules['default']])) {
            $flash = ['Choose the loan officer for all other districts.', 'error'];
        }
        foreach ((array)($_POST['rule_district'] ?? []) as $i => $district) {
            $district = location_district((string)$district);
            $officerId = (int)($_POST['rule_officer'][$i] ?? 0);
            if ($district === '' && $officerId === 0) {
                continue;
            }
            if ($district === '' || !isset($officers[$officerId])) {
                $flash = ['Each district rule needs a district and a loan officer.', 'error'];
                break;
            }
            $rules['districts'][$district] = $officerId;
        }
        if ($flash === null) {
            officer_assignment_ensure_schema($conn);
            officer_rules_save($conn, $rules, (int)($_SESSION['user_id'] ?? 0));
            app_setting_set($conn, 'officer_assignment_bootstrap', 'rules set ' . date('Y-m-d H:i'), (int)($_SESSION['user_id'] ?? 0));
            if (($_POST['reassign'] ?? '') === '1') {
                $counts = officer_sync($conn, true);
                $flash = ["Officer rules saved. Reassigned {$counts['clients']} client(s) and {$counts['loans']} loan(s).", 'success'];
            } else {
                officer_sync($conn);
                $flash = ['Officer rules saved. They apply to new clients and loans.', 'success'];
            }
        }
    } elseif (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $raw = preg_replace('/[^0-9.]/', '', (string)($_POST['processing_fee'] ?? ''));
        if ($raw === '' || !is_numeric($raw) || (float)$raw < 0 || (float)$raw > 1000000) {
            $flash = ['Enter a fee between UGX 0 and UGX 1,000,000.', 'error'];
        } else {
            app_setting_set($conn, 'loan_processing_fee', (string)round((float)$raw), (int)($_SESSION['user_id'] ?? 0));
            $flash = ['Processing fee saved. It applies to offers sent from now on.', 'success'];
        }
    }
    // An error reopens the form it came from.
    $flash[2] = $flash[1] === 'error' ? ($tab === 'officers' ? 'lsOfficerModal' : 'lsFeeModal') : '';
    $_SESSION['loan_settings_flash'] = $flash;
    header('Location: loan_settings.php?tab=' . $tab, true, 303);
    exit;
}

$fee = loan_processing_fee($conn);
officer_assignment_ensure_schema($conn);
$officerRules = officer_rules($conn);
$officerOptions = officer_list($conn);
$bootstrapState = app_setting_get($conn, 'officer_assignment_bootstrap', '');
$officerCounts = [];
foreach ($officerOptions as $officerId => $officerName) {
    $officerCounts[$officerId] = [
        'clients' => (int)$conn->query('SELECT COUNT(*) FROM customers WHERE assigned_officer_id = ' . (int)$officerId)->fetch_row()[0],
        'loans'   => (int)$conn->query('SELECT COUNT(*) FROM loan WHERE assigned_officer_id = ' . (int)$officerId)->fetch_row()[0],
    ];
}
// Rule rows to show: saved rules plus one empty row for adding another.
$ruleRows = [];
foreach ($officerRules['districts'] as $district => $officerId) {
    $ruleRows[] = [$district, $officerId];
}
$ruleRows[] = ['', 0];
[$flashMsg, $flashType, $reopenModal] = ($_SESSION['loan_settings_flash'] ?? []) + ['', '', ''];
unset($_SESSION['loan_settings_flash']);

$tabs = [
    'fee'      => ['fa-receipt', 'Processing fee'],
    'officers' => ['fa-user-tie', 'Loan officers'],
    'terms'    => ['fa-calendar-alt', 'Repayment terms'],
];
$activeTab = isset($tabs[$_GET['tab'] ?? '']) ? (string)$_GET['tab'] : 'fee';

require "nav-head.php";
?>

<style>
.ls-page { padding: 1rem; max-width: 900px; }
.ls-pane-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: .75rem; }
.ls-pane-head h5 { font-weight: 800; margin: 0 0 .25rem; }
.ls-big { font-size: 1.6rem; font-weight: 800; color: #0f172a; }
.ls-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
.ls-table th, .ls-table td { padding: .5rem .55rem; border-bottom: 1px solid #f0f1f3; text-align: left; vertical-align: middle; }
.ls-table th { font-size: .74rem; text-transform: uppercase; color: #64748b; background: #f8fafc; }
.ls-table td.num, .ls-table th.num { text-align: right; }
.ls-section-title { font-size: .8rem; font-weight: 700; text-transform: uppercase; color: #64748b; margin: 1.25rem 0 .5rem; }
</style>

<div class="ls-page">
    <div class="mb-3">
        <h4 class="mb-0 fw-bold">Loan Settings</h4>
        <div class="text-muted fs-sm">Processing fee, loan officer assignment and the repayment terms offered in the app.</div>
    </div>

    <?php if ($flashMsg !== '' && $reopenModal === ''): ?>
        <div class="alert <?= $flashType === 'error' ? 'alert-danger' : 'alert-success' ?>"><?= h($flashMsg) ?></div>
    <?php endif; ?>

    <div class="profile-tab-card">
        <div class="profile-tabs-horizontal" role="tablist">
            <?php foreach ($tabs as $key => [$icon, $label]): ?>
                <button type="button" class="profile-tab-btn<?= $activeTab === $key ? ' active' : '' ?>" data-tab="<?= h($key) ?>"
                        role="tab" aria-selected="<?= $activeTab === $key ? 'true' : 'false' ?>" aria-controls="tab-<?= h($key) ?>">
                    <i class="fas <?= h($icon) ?>"></i> <?= h($label) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="profile-tab-content">
            <!-- Processing fee -->
            <div class="profile-tab-pane<?= $activeTab === 'fee' ? ' active' : '' ?>" id="tab-fee" role="tabpanel">
                <div class="ls-pane-head">
                    <div>
                        <h5>Loan processing fee</h5>
                        <div class="ls-big">UGX <?= number_format((int)$fee) ?></div>
                    </div>
                    <button type="button" class="btn btn-primary" data-ls-open="lsFeeModal"><i class="fas fa-edit me-1"></i> Change fee</button>
                </div>
                <p class="fs-sm text-muted mb-0">
                    Taken from the payout when a client accepts an offer. Example: an approved loan of UGX 300,000
                    with a UGX <?= number_format((int)$fee) ?> fee sends UGX <?= number_format(max(0, 300000 - (int)$fee)) ?> to the client,
                    who still repays UGX 300,000 plus interest.
                </p>
            </div>

            <!-- Loan officers -->
            <div class="profile-tab-pane<?= $activeTab === 'officers' ? ' active' : '' ?>" id="tab-officers" role="tabpanel">
                <div class="ls-pane-head">
                    <div>
                        <h5>Loan officers by district</h5>
                        <p class="fs-sm text-muted mb-0">
                            Each client goes to the loan officer for their district (from their district, or a district named in their address),
                            and their loans go to the same officer. Loan officers only see their own clients and loans.
                        </p>
                    </div>
                    <?php if ($officerOptions): ?>
                        <button type="button" class="btn btn-primary" data-ls-open="lsOfficerModal"><i class="fas fa-edit me-1"></i> Edit rules</button>
                    <?php endif; ?>
                </div>

                <?php if ($bootstrapState === 'officers-not-found'): ?>
                    <div class="alert alert-warning fs-sm">Ruth Musiimenta or Esther Kayondo was not found as an active loan officer, so nothing was assigned automatically. Click "Edit rules", set the officers and tick "Reassign".</div>
                <?php elseif ($bootstrapState !== ''): ?>
                    <div class="fs-xs text-muted mb-2">Last change: <?= h($bootstrapState) ?></div>
                <?php endif; ?>

                <?php if (!$officerOptions): ?>
                    <div class="alert alert-warning fs-sm mb-0">There are no active loan officers (users with the Loan Officer role).</div>
                <?php else: ?>
                    <div class="ls-section-title">Rules</div>
                    <table class="ls-table">
                        <thead><tr><th>District</th><th>Loan officer</th></tr></thead>
                        <tbody>
                        <?php foreach ($officerRules['districts'] as $ruleDistrict => $ruleOfficer): ?>
                            <tr>
                                <td><?= h($ruleDistrict) ?></td>
                                <td><?= h($officerOptions[$ruleOfficer] ?? 'Officer #' . $ruleOfficer . ' (not active)') ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr>
                                <td><strong>All other districts</strong></td>
                                <td><?= $officerRules['default'] > 0
                                    ? h($officerOptions[$officerRules['default']] ?? 'Officer #' . $officerRules['default'] . ' (not active)')
                                    : '<span class="text-muted">Not set</span>' ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="ls-section-title">Clients and loans per officer</div>
                    <table class="ls-table">
                        <thead><tr><th>Loan officer</th><th class="num">Clients</th><th class="num">Loans</th></tr></thead>
                        <tbody>
                        <?php foreach ($officerOptions as $officerId => $officerName): ?>
                            <tr>
                                <td><?= h($officerName) ?></td>
                                <td class="num"><?= number_format($officerCounts[$officerId]['clients'] ?? 0) ?></td>
                                <td class="num"><?= number_format($officerCounts[$officerId]['loans'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Repayment terms -->
            <div class="profile-tab-pane<?= $activeTab === 'terms' ? ' active' : '' ?>" id="tab-terms" role="tabpanel">
                <div class="ls-pane-head">
                    <div>
                        <h5>Repayment terms in the app</h5>
                        <p class="fs-sm text-muted mb-0">
                            Interest is a flat rate on the approved amount for the whole term
                            (includes/loan_offers.php, <code>loan_interest_rate()</code>).
                        </p>
                    </div>
                </div>
                <table class="ls-table">
                    <thead><tr><th>Term</th><th>Interest</th><th>On UGX 300,000 you repay</th></tr></thead>
                    <tbody>
                    <?php foreach (loan_term_options() as $opt):
                        $rate = loan_interest_rate($opt['count'], $opt['unit']); ?>
                        <tr>
                            <td><?= h($opt['label']) ?></td>
                            <td><?= h(rtrim(rtrim(number_format($rate, 2), '0'), '.')) ?>%</td>
                            <td><?= h(loan_money(300000 + 300000 * $rate / 100)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Change processing fee -->
<div class="modal-overlay ls-modal" id="lsFeeModal" aria-hidden="true">
    <div class="modal-box sm" role="dialog" aria-modal="true" aria-labelledby="lsFeeTitle">
        <form method="POST">
            <div class="modal-header">
                <div class="modal-title"><div class="modal-ico"><i class="fas fa-receipt"></i></div>
                    <span class="modal-title-text" id="lsFeeTitle">Change processing fee</span></div>
                <button type="button" class="modal-close" data-ls-close aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <?php if ($reopenModal === 'lsFeeModal'): ?>
                    <div class="alert alert-danger"><?= h($flashMsg) ?></div>
                <?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <label class="form-label fs-sm" for="processing_fee">Fee (UGX)</label>
                <input type="number" min="0" max="1000000" step="500" id="processing_fee" name="processing_fee"
                       class="form-control" value="<?= h((string)(int)$fee) ?>" required>
                <div class="fs-xs text-muted mt-2">Applies to offers sent from now on.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-ls-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save fee</button>
            </div>
        </form>
    </div>
</div>

<?php if ($officerOptions): ?>
<!-- Edit loan officer rules -->
<div class="modal-overlay ls-modal" id="lsOfficerModal" aria-hidden="true">
    <div class="modal-box lg" role="dialog" aria-modal="true" aria-labelledby="lsOfficerTitle">
        <form method="POST">
            <div class="modal-header">
                <div class="modal-title"><div class="modal-ico"><i class="fas fa-user-tie"></i></div>
                    <span class="modal-title-text" id="lsOfficerTitle">Loan officers by district</span></div>
                <button type="button" class="modal-close" data-ls-close aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <?php if ($reopenModal === 'lsOfficerModal'): ?>
                    <div class="alert alert-danger"><?= h($flashMsg) ?></div>
                <?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="form" value="officers">
                <table class="ls-table mb-2">
                    <thead><tr><th>District</th><th>Loan officer</th></tr></thead>
                    <tbody id="lsRuleRows">
                    <?php foreach ($ruleRows as [$ruleDistrict, $ruleOfficer]): ?>
                        <tr class="ls-rule-row">
                            <td>
                                <select name="rule_district[]" class="form-select form-select-sm" aria-label="District">
                                    <option value="">Add a district...</option>
                                    <?php foreach (UGANDA_DISTRICTS as $districtName): ?>
                                        <option value="<?= h($districtName) ?>" <?= $districtName === $ruleDistrict ? 'selected' : '' ?>><?= h($districtName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="rule_officer[]" class="form-select form-select-sm" aria-label="Loan officer">
                                    <option value="0">Choose officer</option>
                                    <?php foreach ($officerOptions as $officerId => $officerName): ?>
                                        <option value="<?= (int)$officerId ?>" <?= $officerId === $ruleOfficer ? 'selected' : '' ?>><?= h($officerName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tbody>
                        <tr>
                            <td><strong>All other districts</strong></td>
                            <td>
                                <select name="default_officer" class="form-select form-select-sm" aria-label="Officer for all other districts" required>
                                    <option value="0">Choose officer</option>
                                    <?php foreach ($officerOptions as $officerId => $officerName): ?>
                                        <option value="<?= (int)$officerId ?>" <?= $officerId === $officerRules['default'] ? 'selected' : '' ?>><?= h($officerName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <button type="button" class="btn btn-sm btn-light mb-2" id="lsAddRule"><i class="fas fa-plus me-1"></i> Add another district</button>
                <div class="fs-xs text-muted mb-2">To remove a district rule, set its district back to "Add a district...".</div>
                <label class="fs-sm d-block">
                    <input type="checkbox" name="reassign" value="1">
                    Reassign all existing clients and their loans to match these rules (otherwise only new clients and loans follow them)
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-ls-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save officer rules</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    function openModal(id) {
        var m = document.getElementById(id);
        if (!m) return;
        if (window.Modal) { Modal.open(id); } else { m.classList.add('open'); }
        m.setAttribute('aria-hidden', 'false');
    }
    function closeModal(m) {
        if (window.Modal) { Modal.close(m.id); } else { m.classList.remove('open'); }
        m.setAttribute('aria-hidden', 'true');
    }

    // Tabs: switch in place and keep the choice in the address (?tab=).
    document.querySelectorAll('.profile-tab-btn[data-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('.profile-tab-btn').forEach(function (b) {
                b.classList.toggle('active', b === button);
                b.setAttribute('aria-selected', b === button ? 'true' : 'false');
            });
            document.querySelectorAll('.profile-tab-pane').forEach(function (p) {
                p.classList.toggle('active', p.id === 'tab-' + button.dataset.tab);
            });
            var url = new URL(window.location.href);
            url.searchParams.set('tab', button.dataset.tab);
            window.history.replaceState({}, '', url);
        });
    });

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-ls-open]');
        if (t) { openModal(t.getAttribute('data-ls-open')); return; }
        var modal = e.target.closest('.ls-modal');
        if (modal && (e.target === modal || e.target.closest('[data-ls-close]'))) closeModal(modal);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.ls-modal.open').forEach(closeModal);
    });

    // Another empty district row, copied from the last one.
    var addRule = document.getElementById('lsAddRule');
    if (addRule) {
        addRule.addEventListener('click', function () {
            var rows = document.querySelectorAll('#lsRuleRows .ls-rule-row');
            var copy = rows[rows.length - 1].cloneNode(true);
            copy.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
            document.getElementById('lsRuleRows').appendChild(copy);
            copy.querySelector('select').focus();
        });
    }

    <?php if ($reopenModal !== ''): ?>
    // A save that failed reopens its form with the message.
    openModal(<?= json_encode($reopenModal) ?>);
    <?php endif; ?>
})();
</script>

<?php require "footer.php"; ?>
