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

    <div class="hr-stats">
        <div class="hr-stat"><div class="n"><?= $counts['employee'] ?></div><div class="l">Employees</div></div>
        <div class="hr-stat"><div class="n"><?= $counts['consultant'] ?></div><div class="l">Consultants</div></div>
        <div class="hr-stat"><div class="n"><?= $counts['due'] ?></div><div class="l"><?= hr_h(hr_period_label($period)) ?> reports not in</div></div>
        <div class="hr-stat"><div class="n"><?= $counts['inactive'] ?></div><div class="l">Inactive</div></div>
    </div>

    <div class="hr-card">
        <div class="hr-head">
            <div>
                <h5><i class="fas fa-id-badge me-1"></i> Staff profiles</h5>
                <p class="hr-muted" style="margin:0">Employees submit a monthly report. Consultants submit a monthly report and an invoice. Nobody can be paid for a month until these are approved.</p>
            </div>
            <a class="btn btn-primary btn-sm" href="hr_submissions.php"><i class="fas fa-calendar-check"></i> Monthly submissions</a>
        </div>
        <?php if (!$rows): ?>
            <p class="hr-muted" style="margin:1rem 0 0">No staff profiles yet. Create the first one below.</p>
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

    <div class="hr-card" id="create">
        <h5><i class="fas fa-user-plus me-1"></i> Create a staff profile</h5>
        <?php if (!$candidates): ?>
            <p class="hr-muted" style="margin:0">Every admin and loan officer account already has a profile. To add someone new, first create their login under People &gt; Users, then come back here.</p>
        <?php else: ?>
            <p class="hr-muted">Choose the person's login account and whether they are an employee or a consultant. You can add the contract, National ID, next of kin and KPIs on the next page.</p>
            <form method="post" class="hr-form">
                <?= hr_csrf_field() ?>
                <div>
                    <label class="hr-req" for="hrUser">Login account</label>
                    <select id="hrUser" name="user_id" required>
                        <option value="">Choose...</option>
                        <?php foreach ($candidates as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= hr_h(trim($c['fname'] . ' ' . $c['lname'])) ?> (<?= (int)$c['role'] === 1 ? 'Admin' : 'Loan Officer' ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
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
                <div class="wide">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create profile</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require 'footer.php'; ?>
