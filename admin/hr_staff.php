<?php
declare(strict_types=1);

/*
 * Staff HR > Staff Profiles: every admin or loan officer who works for the
 * organisation gets a profile, marked Employee (monthly report) or
 * Consultant (monthly report and invoice). Admins only.
 */

$phead = 'Staff Profiles';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/staff_hr.php';
[$actorId] = hr_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hr_csrf_ok()) {
        hr_flash('Your session expired. Please try again.', 'error');
        hr_redirect('hr_staff.php');
    }
    try {
        $data = hr_profile_input($_POST);
        $newId = hr_profile_create($conn, (int)($_POST['user_id'] ?? 0), $data, $actorId);
        hr_flash('Profile created. Add the contract, ID and next of kin details below.');
        hr_redirect('hr_staff_profile.php?id=' . $newId);
    } catch (DomainException $e) {
        hr_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        error_log('Create staff profile: ' . $e->getMessage());
        hr_flash('Could not create the profile. Check the server log.', 'error');
    }
    hr_redirect('hr_staff.php');
}

$period = hr_current_period();
$schemaError = '';
try {
    $profiles = hr_profiles_all($conn);
    $candidates = hr_users_without_profile($conn);
} catch (Throwable $e) {
    $profiles = $candidates = [];
    $schemaError = 'Could not load staff profiles: ' . $e->getMessage();
}

$counts = ['employee' => 0, 'consultant' => 0, 'inactive' => 0, 'due' => 0];
$rows = [];
foreach ($profiles as $p) {
    if ($p['status'] !== 'active') {
        $counts['inactive']++;
    } else {
        $counts[$p['staff_type']] = ($counts[$p['staff_type']] ?? 0) + 1;
    }
    $report = hr_report_for($conn, (int)$p['id'], $period);
    $invoice = $p['staff_type'] === 'consultant' ? hr_invoice_for($conn, (int)$p['id'], $period) : null;
    if ($p['status'] === 'active' && !$report) {
        $counts['due']++;
    }
    $rows[] = [$p, $report, $invoice];
}

require 'nav-head.php';
echo hr_styles();
?>
<div class="hr-page">
    <?= hr_flash_html() ?>
    <?php if ($schemaError !== ''): ?><div class="hr-flash error"><?= hr_h($schemaError) ?></div><?php endif; ?>

    <div class="stats-grid stats-compact">
        <div class="stat-card c-blue">
            <div class="stat-head">
                <div class="stat-ico c-blue"><i class="fas fa-user-tie"></i></div>
                <span class="stat-badge flat"><i class="fas fa-circle-check"></i> Active</span>
            </div>
            <div class="stat-value"><?= number_format($counts['employee']) ?></div>
            <div class="stat-label">Employees</div>
            <div class="stat-foot"><i class="fas fa-file-lines"></i> Monthly report only</div>
        </div>
        <div class="stat-card c-purple">
            <div class="stat-head">
                <div class="stat-ico c-purple"><i class="fas fa-briefcase"></i></div>
                <span class="stat-badge flat"><i class="fas fa-circle-check"></i> Active</span>
            </div>
            <div class="stat-value"><?= number_format($counts['consultant']) ?></div>
            <div class="stat-label">Consultants</div>
            <div class="stat-foot"><i class="fas fa-file-invoice"></i> Monthly report and invoice</div>
        </div>
        <a href="hr_submissions.php" class="stat-card c-orange">
            <div class="stat-head">
                <div class="stat-ico c-orange"><i class="fas fa-hourglass-half"></i></div>
                <span class="stat-badge <?= $counts['due'] > 0 ? 'down' : 'up' ?>"><i class="fas fa-calendar"></i> <?= hr_h(hr_period_label($period)) ?></span>
            </div>
            <div class="stat-value"><?= number_format($counts['due']) ?></div>
            <div class="stat-label">Reports not in</div>
            <div class="stat-foot"><i class="fas fa-calendar-check"></i> Open monthly submissions</div>
        </a>
        <div class="stat-card c-red">
            <div class="stat-head">
                <div class="stat-ico c-red"><i class="fas fa-user-slash"></i></div>
            </div>
            <div class="stat-value"><?= number_format($counts['inactive']) ?></div>
            <div class="stat-label">Inactive</div>
            <div class="stat-foot"><i class="fas fa-users"></i> <?= number_format(count($profiles)) ?> profile(s) in total</div>
        </div>
    </div>

    <div class="hr-card">
        <div class="hr-head">
            <div>
                <h5><i class="fas fa-id-badge me-1"></i> Staff profiles</h5>
                <p class="hr-muted" style="margin:0">Employees submit a monthly report. Consultants submit a monthly report and an invoice. Nobody can be paid for a month until these are approved.</p>
            </div>
            <div class="hr-actions">
                <a class="btn btn-secondary btn-sm" href="hr_submissions.php"><i class="fas fa-calendar-check"></i> Monthly submissions</a>
                <button type="button" class="btn btn-primary btn-sm" data-open-create><i class="fas fa-user-plus"></i> Create staff profile</button>
            </div>
        </div>
        <?php if (!$rows): ?>
            <p class="hr-muted" style="margin:1rem 0 0">No staff profiles yet. Use <strong>Create staff profile</strong> to add the first one.</p>
        <?php else: ?>
            <div class="hr-table-wrap" style="margin-top:.8rem">
                <table class="hr-table">
                    <thead><tr>
                        <th>Name</th><th>Type</th><th>Job title</th><th>Contract ends</th>
                        <th><?= hr_h(hr_period_label($period)) ?> report</th><th>Invoice</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as [$p, $report, $invoice]): ?>
                        <?php
                        $ends = (string)($p['contract_end'] ?? '');
                        $endsSoon = $ends !== '' && $ends <= date('Y-m-d', strtotime('+30 days'));
                        ?>
                        <tr>
                            <td>
                                <strong><?= hr_h(hr_display_name($p)) ?></strong>
                                <?php if ($p['status'] !== 'active'): ?> <span class="hr-badge hr-b-none">Inactive</span><?php endif; ?>
                                <div class="hr-muted"><?= hr_h($p['email']) ?> · <?= (int)$p['role'] === 1 ? 'Admin' : 'Loan Officer' ?></div>
                            </td>
                            <td><?= hr_type_badge($p['staff_type']) ?></td>
                            <td><?= hr_h($p['job_title'] ?: '-') ?></td>
                            <td<?= $endsSoon ? ' style="color:#b91c1c;font-weight:700"' : '' ?>><?= $ends !== '' ? hr_h(date('d M Y', strtotime($ends))) : '-' ?></td>
                            <td><?= hr_status_badge($report['status'] ?? null) ?></td>
                            <td><?= $p['staff_type'] === 'consultant' ? hr_status_badge($invoice['status'] ?? null) : '<span class="hr-muted">-</span>' ?></td>
                            <td><a class="btn btn-sm btn-secondary" href="hr_staff_profile.php?id=<?= (int)$p['id'] ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="createStaffModal" aria-hidden="true">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="createStaffTitle">
        <div class="modal-header">
            <div class="modal-title"><div class="modal-ico"><i class="fas fa-user-plus"></i></div><span class="modal-title-text" id="createStaffTitle">Create a staff profile</span></div>
            <button type="button" class="modal-close" data-close-create aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <?php if (!$candidates): ?>
            <div class="modal-body">
                <p class="hr-muted" style="margin:0">Every admin and loan officer account already has a profile. To add someone new, first create their login under People &gt; Users, then come back here.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-close-create>Close</button>
                <a class="btn btn-primary" href="users.php"><i class="fas fa-users"></i> Go to Users</a>
            </div>
        <?php else: ?>
            <form method="post">
                <div class="modal-body">
                    <p class="hr-muted">Choose the person's login account and whether they are an employee or a consultant. You can add the contract, National ID, next of kin and KPIs on the next page.</p>
                    <?= hr_csrf_field() ?>
                    <div class="hr-form">
                        <div class="wide">
                            <label class="hr-req" for="hrUser">Login account</label>
                            <select id="hrUser" name="user_id" required>
                                <option value="">Choose...</option>
                                <?php foreach ($candidates as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= hr_h(trim($c['fname'] . ' ' . $c['lname'])) ?> (<?= (int)$c['role'] === 1 ? 'Admin' : 'Loan Officer' ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="wide">
                            <label class="hr-req" for="hrType">Engagement</label>
                            <select id="hrType" name="staff_type" required>
                                <option value="">Choose...</option>
                                <option value="employee">Employee: monthly report only</option>
                                <option value="consultant">Consultant: monthly report and invoice</option>
                            </select>
                        </div>
                        <div>
                            <label for="hrTitle">Job title</label>
                            <input id="hrTitle" name="job_title" maxlength="120" placeholder="e.g. Loan Officer">
                        </div>
                        <div>
                            <label for="hrPay">Monthly pay or fee (UGX)</label>
                            <input id="hrPay" name="monthly_pay" inputmode="numeric" placeholder="0">
                        </div>
                        <div>
                            <label for="hrStart">Contract start</label>
                            <input id="hrStart" name="contract_start" type="date">
                        </div>
                        <div>
                            <label for="hrEnd">Contract end</label>
                            <input id="hrEnd" name="contract_end" type="date">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-close-create>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create profile</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var id = 'createStaffModal';
    var modal = document.getElementById(id);
    if (!modal) return;
    function openCreate() {
        if (window.Modal) { Modal.open(id); } else { modal.classList.add('open'); }
        modal.setAttribute('aria-hidden', 'false');
        var first = modal.querySelector('select, input:not([type=hidden])');
        if (first) setTimeout(function () { first.focus(); }, 80);
    }
    function closeCreate() {
        if (window.Modal) { Modal.close(id); } else { modal.classList.remove('open'); }
        modal.setAttribute('aria-hidden', 'true');
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-open-create]')) { openCreate(); return; }
        if (e.target.closest('[data-close-create]') || e.target === modal) closeCreate();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeCreate();
    });
    // Old links to hr_staff.php#create still open the form.
    if (location.hash === '#create') openCreate();
})();
</script>
<?php require 'footer.php'; ?>
