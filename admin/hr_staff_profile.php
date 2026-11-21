<?php
declare(strict_types=1);

/*
 * Staff HR > one staff profile: personal details, contract, National ID,
 * next of kin, pay details, documents, KPIs, appraisals and the month by
 * month record of reports, invoices and payments. Admins only.
 */

$phead = 'Staff Profile';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/staff_hr.php';
[$actorId] = hr_require_login();

$profileId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$profile = $profileId > 0 ? hr_profile_find($conn, $profileId) : null;
if (!$profile) {
    hr_flash('Staff profile not found.', 'error');
    hr_redirect('hr_staff.php');
}
$tabs = ['details' => 'Details', 'documents' => 'Documents', 'kpis' => 'KPIs', 'appraisals' => 'Appraisals', 'pay' => 'Reports & Pay'];
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'details');
if (!isset($tabs[$tab])) {
    $tab = 'details';
}
$self = 'hr_staff_profile.php?id=' . $profileId;

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hr_csrf_ok()) {
        hr_flash('Your session expired. Please try again.', 'error');
        hr_redirect($self . '&tab=' . $tab);
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'save_profile':
                $before = $profile['staff_type'];
                $data = hr_profile_input($_POST);
                hr_profile_update($conn, $profileId, $data);
                hr_flash($before !== $data['staff_type']
                    ? 'Saved. ' . hr_display_name($profile) . ' is now a ' . strtolower(HR_STAFF_TYPES[$data['staff_type']])
                      . ($data['staff_type'] === 'consultant' ? ' and must submit an invoice each month.' : ' and only submits the monthly report.')
                    : 'Profile saved.');
                break;

            case 'upload_file':
                $file = $_FILES['file'] ?? [];
                hr_store_file($conn, $profileId, (string)($_POST['file_type'] ?? ''), (string)($_POST['title'] ?? ''), is_array($file) ? $file : [], $actorId);
                hr_flash('Document uploaded.');
                break;

            case 'delete_file':
                $file = hr_file_find($conn, (int)($_POST['file_id'] ?? 0));
                if (!$file || (int)$file['profile_id'] !== $profileId || in_array($file['file_type'], ['report', 'invoice'], true)) {
                    throw new DomainException('Document not found.');
                }
                hr_file_delete($conn, $file);
                hr_flash('Document deleted.');
                break;

            case 'add_kpi':
                $title = hr_text($_POST['title'] ?? '', 150);
                if ($title === '') {
                    throw new DomainException('Give the KPI a name.');
                }
                $desc = hr_text($_POST['description'] ?? '', 500);
                $target = hr_text($_POST['target'] ?? '', 100);
                $freq = in_array($_POST['frequency'] ?? '', ['monthly', 'quarterly', 'annual'], true) ? $_POST['frequency'] : 'monthly';
                $weight = max(0, min(100, (int)($_POST['weight'] ?? 0)));
                $kpiId = (int)($_POST['kpi_id'] ?? 0);
                if ($kpiId > 0) {
                    $s = $conn->prepare('UPDATE staff_kpis SET title = ?, description = ?, target = ?, frequency = ?, weight = ? WHERE id = ? AND profile_id = ?');
                    $s->bind_param('ssssiii', $title, $desc, $target, $freq, $weight, $kpiId, $profileId);
                    $msg = 'KPI updated.';
                } else {
                    $s = $conn->prepare('INSERT INTO staff_kpis (profile_id, title, description, target, frequency, weight, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $s->bind_param('issssii', $profileId, $title, $desc, $target, $freq, $weight, $actorId);
                    $msg = 'KPI added. It now appears on the monthly report form.';
                }
                $s->execute();
                $s->close();
                hr_flash($msg);
                break;

            case 'kpi_status':
                $kpiId = (int)($_POST['kpi_id'] ?? 0);
                $status = ($_POST['status'] ?? '') === 'archived' ? 'archived' : 'active';
                $s = $conn->prepare('UPDATE staff_kpis SET status = ? WHERE id = ? AND profile_id = ?');
                $s->bind_param('sii', $status, $kpiId, $profileId);
                $s->execute();
                $s->close();
                hr_flash($status === 'archived' ? 'KPI archived. It no longer appears on new reports.' : 'KPI restored.');
                break;

            case 'add_appraisal':
                $label = hr_text($_POST['period_label'] ?? '', 60);
                $date = hr_date($_POST['review_date'] ?? '');
                $rating = (int)($_POST['overall_rating'] ?? 0);
                if ($label === '' || !$date || $rating < 1 || $rating > 5) {
                    throw new DomainException('An appraisal needs the period, the review date and an overall rating.');
                }
                $ownKpis = array_column(hr_kpis($conn, $profileId), 'id', 'id');
                $kpiComments = is_array($_POST['kpi_comment'] ?? null) ? $_POST['kpi_comment'] : [];
                $kpiRatings = [];
                foreach ((is_array($_POST['kpi_rating'] ?? null) ? $_POST['kpi_rating'] : []) as $kid => $val) {
                    $val = (int)$val;
                    if (isset($ownKpis[(int)$kid]) && $val >= 1 && $val <= 5) {
                        $kpiRatings[(int)$kid] = ['rating' => $val, 'comment' => hr_text($kpiComments[$kid] ?? '', 500)];
                    }
                }
                $fileId = null;
                if (hr_has_upload($_FILES['file'] ?? null)) {
                    $fileId = hr_store_file($conn, $profileId, 'appraisal', 'Appraisal ' . $label, $_FILES['file'], $actorId);
                }
                $json = $kpiRatings ? json_encode($kpiRatings) : null;
                $strengths = hr_text($_POST['strengths'] ?? '');
                $improve = hr_text($_POST['improvements'] ?? '');
                $goals = hr_text($_POST['goals'] ?? '');
                $comments = hr_text($_POST['reviewer_comments'] ?? '');
                $s = $conn->prepare('INSERT INTO staff_appraisals (profile_id, period_label, review_date, overall_rating, kpi_ratings, strengths, improvements, goals, reviewer_comments, file_id, reviewed_by)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $s->bind_param('ississsssii', $profileId, $label, $date, $rating, $json, $strengths, $improve, $goals, $comments, $fileId, $actorId);
                $s->execute();
                $s->close();
                hr_mail((string)$profile['email'], 'Your performance appraisal for ' . $label,
                    '<p>Dear ' . hr_h($profile['fname']) . ',</p><p>Your performance appraisal for <strong>' . hr_h($label) . '</strong> has been recorded with an overall rating of '
                    . $rating . '/5 (' . hr_h(hr_rating_label($rating)) . ').</p><p>Please sign in and open <strong>My Staff Portal &gt; Appraisals</strong> to read it, add your comments and acknowledge it.</p>');
                hr_flash('Appraisal saved and ' . hr_display_name($profile) . ' was emailed to read and acknowledge it.');
                break;

            case 'delete_appraisal':
                $aid = (int)($_POST['appraisal_id'] ?? 0);
                $s = $conn->prepare('DELETE FROM staff_appraisals WHERE id = ? AND profile_id = ? AND acknowledged_at IS NULL');
                $s->bind_param('ii', $aid, $profileId);
                $s->execute();
                $deleted = $s->affected_rows;
                $s->close();
                hr_flash($deleted ? 'Appraisal deleted.' : 'An appraisal the staff member has acknowledged cannot be deleted.', $deleted ? 'success' : 'error');
                break;
        }
    } catch (DomainException $e) {
        hr_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        error_log('Staff profile action ' . $action . ': ' . $e->getMessage());
        hr_flash('Something went wrong. Check the server log.', 'error');
    }
    hr_redirect($self . '&tab=' . $tab);
}

/* ---------- View ---------- */
$p = $profile;
$name = hr_display_name($p);
$files = hr_profile_files($conn, $profileId);
$kpis = hr_kpis($conn, $profileId);
$activeKpis = array_values(array_filter($kpis, static fn($k) => $k['status'] === 'active'));
$appraisals = hr_appraisals($conn, $profileId);
$editKpi = null;
foreach ($kpis as $k) {
    if ((int)$k['id'] === (int)($_GET['edit_kpi'] ?? 0)) {
        $editKpi = $k;
    }
}
$kpiNames = [];
foreach ($kpis as $k) {
    $kpiNames[(int)$k['id']] = $k['title'];
}
$hasContract = (bool)array_filter($files, static fn($f) => $f['file_type'] === 'contract');
$hasId = (bool)array_filter($files, static fn($f) => $f['file_type'] === 'national_id');

$phead = 'Staff Profile: ' . $name;
require 'nav-head.php';
echo hr_styles();
?>
<div class="hr-page">
    <?= hr_flash_html() ?>
    <div class="hr-card">
        <div class="hr-head">
            <div>
                <h5><?= hr_h($name) ?> <?= hr_type_badge($p['staff_type']) ?>
                    <?php if ($p['status'] !== 'active'): ?><span class="hr-badge hr-b-none">Inactive</span><?php endif; ?></h5>
                <div class="hr-muted"><?= hr_h($p['job_title'] ?: 'No job title') ?> · <?= hr_h($p['email']) ?> · <?= (int)$p['role'] === 1 ? 'Admin' : 'Loan Officer' ?> login</div>
            </div>
            <div class="hr-actions">
                <a class="btn btn-sm btn-secondary" href="hr_staff.php"><i class="fas fa-arrow-left"></i> All staff</a>
            </div>
        </div>
        <?php if (!$hasContract || !$hasId || $p['nok_name'] === ''): ?>
            <div class="hr-warn" style="margin:.8rem 0 0">
                Still missing:
                <?= implode(', ', array_filter([!$hasContract ? 'signed contract' : '', !$hasId ? 'National ID copy' : '', $p['nok_name'] === '' ? 'next of kin' : ''])) ?>.
            </div>
        <?php endif; ?>
    </div>

    <nav class="hr-tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="<?= hr_h($self . '&tab=' . $key) ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= hr_h($label) ?></a>
        <?php endforeach; ?>
    </nav>

<?php if ($tab === 'details'): ?>
    <div class="hr-card">
        <form method="post" class="hr-form">
            <?= hr_csrf_field() ?>
            <input type="hidden" name="action" value="save_profile">
            <input type="hidden" name="id" value="<?= $profileId ?>">
            <input type="hidden" name="tab" value="details">

            <div class="hr-section-title">Engagement</div>
            <div>
                <label class="hr-req" for="fType">Employee or consultant</label>
                <select id="fType" name="staff_type" required>
                    <?php foreach (HR_STAFF_TYPES as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $p['staff_type'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Consultants must also submit an invoice each month.</small>
            </div>
            <div><label for="fTitle">Job title</label><input id="fTitle" name="job_title" maxlength="120" value="<?= hr_h($p['job_title']) ?>"></div>
            <div><label for="fDept">Department</label><input id="fDept" name="department" maxlength="120" value="<?= hr_h($p['department']) ?>"></div>
            <div>
                <label for="fStatus">Status</label>
                <select id="fStatus" name="status">
                    <option value="active" <?= $p['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $p['status'] === 'inactive' ? 'selected' : '' ?>>Inactive (left, no reports due)</option>
                </select>
            </div>
            <div><label for="fCs">Contract start</label><input id="fCs" type="date" name="contract_start" value="<?= hr_h($p['contract_start']) ?>"></div>
            <div><label for="fCe">Contract end</label><input id="fCe" type="date" name="contract_end" value="<?= hr_h($p['contract_end']) ?>"></div>
            <div><label for="fPay">Monthly pay or fee (UGX)</label><input id="fPay" name="monthly_pay" inputmode="numeric" value="<?= hr_h(number_format((float)$p['monthly_pay'])) ?>"></div>

            <div class="hr-section-title">Personal details</div>
            <div><label for="fNin">National ID number (NIN)</label><input id="fNin" name="national_id_number" maxlength="40" value="<?= hr_h($p['national_id_number']) ?>"></div>
            <div><label for="fDob">Date of birth</label><input id="fDob" type="date" name="date_of_birth" value="<?= hr_h($p['date_of_birth']) ?>"></div>
            <div>
                <label for="fGender">Gender</label>
                <select id="fGender" name="gender">
                    <option value=""></option>
                    <?php foreach (['Male', 'Female'] as $g): ?><option <?= $p['gender'] === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?>
                </select>
            </div>
            <div><label for="fPhone">Phone</label><input id="fPhone" name="phone" maxlength="40" value="<?= hr_h($p['phone']) ?>"></div>
            <div class="wide"><label for="fAddr">Home address</label><input id="fAddr" name="address" maxlength="255" value="<?= hr_h($p['address']) ?>"></div>

            <div class="hr-section-title">Next of kin</div>
            <div><label for="fNk">Full name</label><input id="fNk" name="nok_name" maxlength="150" value="<?= hr_h($p['nok_name']) ?>"></div>
            <div><label for="fNkr">Relationship</label><input id="fNkr" name="nok_relationship" maxlength="60" value="<?= hr_h($p['nok_relationship']) ?>" placeholder="e.g. Spouse, Parent"></div>
            <div><label for="fNkp">Phone</label><input id="fNkp" name="nok_phone" maxlength="40" value="<?= hr_h($p['nok_phone']) ?>"></div>
            <div><label for="fNka">Address</label><input id="fNka" name="nok_address" maxlength="255" value="<?= hr_h($p['nok_address']) ?>"></div>

            <div class="hr-section-title">Payment details</div>
            <div><label for="fBank">Bank</label><input id="fBank" name="bank_name" maxlength="120" value="<?= hr_h($p['bank_name']) ?>"></div>
            <div><label for="fAcc">Account number</label><input id="fAcc" name="bank_account" maxlength="60" value="<?= hr_h($p['bank_account']) ?>"></div>
            <div><label for="fMm">Mobile money number</label><input id="fMm" name="mobile_money" maxlength="40" value="<?= hr_h($p['mobile_money']) ?>"></div>
            <div><label for="fTin">TIN</label><input id="fTin" name="tin" maxlength="40" value="<?= hr_h($p['tin']) ?>"></div>

            <div class="wide"><label for="fNotes">Notes (admins only)</label><textarea id="fNotes" name="notes"><?= hr_h($p['notes']) ?></textarea></div>
            <div class="wide"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save profile</button></div>
        </form>
    </div>

<?php elseif ($tab === 'documents'): ?>
    <div class="hr-card">
        <h5>Documents</h5>
        <?php if (!$files): ?>
            <p class="hr-muted">No documents yet. Upload the signed contract and a copy of the National ID.</p>
        <?php else: ?>
            <div class="hr-table-wrap">
                <table class="hr-table">
                    <thead><tr><th>Type</th><th>Title</th><th>Uploaded</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($files as $f): $meta = hr_file_types()[$f['file_type']] ?? ['label' => $f['file_type'], 'icon' => 'fa-file']; ?>
                        <tr>
                            <td><i class="fas <?= hr_h($meta['icon']) ?>"></i> <?= hr_h($meta['label']) ?></td>
                            <td><?= hr_file_link($f) ?></td>
                            <td><?= hr_h(date('d M Y', strtotime((string)$f['created_at']))) ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Delete this document? The file cannot be recovered.');">
                                    <?= hr_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_file">
                                    <input type="hidden" name="id" value="<?= $profileId ?>">
                                    <input type="hidden" name="tab" value="documents">
                                    <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="hr-card">
        <h5>Upload a document</h5>
        <form method="post" enctype="multipart/form-data" class="hr-form">
            <?= hr_csrf_field() ?>
            <input type="hidden" name="action" value="upload_file">
            <input type="hidden" name="id" value="<?= $profileId ?>">
            <input type="hidden" name="tab" value="documents">
            <div>
                <label class="hr-req" for="uType">Type</label>
                <select id="uType" name="file_type" required>
                    <?php foreach (hr_file_types() as $k => $meta): if (in_array($k, ['report', 'invoice'], true)) continue; ?>
                        <option value="<?= $k ?>"><?= hr_h($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label for="uTitle">Title</label><input id="uTitle" name="title" maxlength="150" placeholder="e.g. Contract 2026-2027"></div>
            <div><label class="hr-req" for="uFile">File</label><input id="uFile" type="file" name="file" accept=".pdf,image/jpeg,image/png,image/webp" required><small>PDF, JPG, PNG or WebP, up to 5 MB.</small></div>
            <div class="wide"><button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i> Upload</button></div>
        </form>
    </div>

<?php elseif ($tab === 'kpis'): ?>
    <div class="hr-card">
        <h5>Key performance indicators</h5>
        <p class="hr-muted">Active KPIs appear on <?= hr_h($p['fname']) ?>'s monthly report form, where they report progress against each target. You rate them in appraisals.</p>
        <?php if (!$kpis): ?>
            <p class="hr-muted">No KPIs yet.</p>
        <?php else: ?>
            <div class="hr-table-wrap">
                <table class="hr-table">
                    <thead><tr><th>KPI</th><th>Target</th><th>Measured</th><th>Weight</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($kpis as $k): ?>
                        <tr>
                            <td><strong><?= hr_h($k['title']) ?></strong><?php if ($k['description'] !== ''): ?><div class="hr-muted"><?= hr_h($k['description']) ?></div><?php endif; ?></td>
                            <td><?= hr_h($k['target'] ?: '-') ?></td>
                            <td><?= hr_h(ucfirst($k['frequency'])) ?></td>
                            <td><?= (int)$k['weight'] ? (int)$k['weight'] . '%' : '-' ?></td>
                            <td><?= $k['status'] === 'active' ? '<span class="hr-badge hr-b-ok">Active</span>' : '<span class="hr-badge hr-b-none">Archived</span>' ?></td>
                            <td>
                                <div class="hr-actions">
                                    <a class="btn btn-sm btn-secondary" href="<?= hr_h($self . '&tab=kpis&edit_kpi=' . (int)$k['id']) ?>#kpiForm">Edit</a>
                                    <form method="post">
                                        <?= hr_csrf_field() ?>
                                        <input type="hidden" name="action" value="kpi_status">
                                        <input type="hidden" name="id" value="<?= $profileId ?>">
                                        <input type="hidden" name="tab" value="kpis">
                                        <input type="hidden" name="kpi_id" value="<?= (int)$k['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $k['status'] === 'active' ? 'archived' : 'active' ?>">
                                        <button class="btn btn-sm btn-outline" type="submit"><?= $k['status'] === 'active' ? 'Archive' : 'Restore' ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php $totalWeight = array_sum(array_map(static fn($k) => (int)$k['weight'], $activeKpis)); ?>
            <?php if ($totalWeight > 0 && $totalWeight !== 100): ?>
                <p class="hr-muted" style="margin:.6rem 0 0">Active KPI weights add up to <?= $totalWeight ?>%, not 100%.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="hr-card" id="kpiForm">
        <h5><?= $editKpi ? 'Edit KPI' : 'Add a KPI' ?></h5>
        <form method="post" class="hr-form">
            <?= hr_csrf_field() ?>
            <input type="hidden" name="action" value="add_kpi">
            <input type="hidden" name="id" value="<?= $profileId ?>">
            <input type="hidden" name="tab" value="kpis">
            <input type="hidden" name="kpi_id" value="<?= (int)($editKpi['id'] ?? 0) ?>">
            <div><label class="hr-req" for="kTitle">KPI</label><input id="kTitle" name="title" maxlength="150" required value="<?= hr_h($editKpi['title'] ?? '') ?>" placeholder="e.g. New loans disbursed"></div>
            <div><label for="kTarget">Target</label><input id="kTarget" name="target" maxlength="100" value="<?= hr_h($editKpi['target'] ?? '') ?>" placeholder="e.g. 20 loans a month"></div>
            <div>
                <label for="kFreq">Measured</label>
                <select id="kFreq" name="frequency">
                    <?php foreach (['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annual' => 'Annually'] as $fk => $fv): ?>
                        <option value="<?= $fk ?>" <?= ($editKpi['frequency'] ?? 'monthly') === $fk ? 'selected' : '' ?>><?= $fv ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label for="kWeight">Weight (%)</label><input id="kWeight" name="weight" type="number" min="0" max="100" value="<?= (int)($editKpi['weight'] ?? 0) ?>"></div>
            <div class="wide"><label for="kDesc">How it is measured</label><input id="kDesc" name="description" maxlength="500" value="<?= hr_h($editKpi['description'] ?? '') ?>" placeholder="e.g. Count of loans approved and disbursed for clients the officer onboarded"></div>
            <div class="wide hr-actions">
                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> <?= $editKpi ? 'Save KPI' : 'Add KPI' ?></button>
                <?php if ($editKpi): ?><a class="btn btn-secondary" href="<?= hr_h($self . '&tab=kpis') ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>

<?php elseif ($tab === 'appraisals'): ?>
    <div class="hr-card">
        <h5>Performance appraisals</h5>
        <?php if (!$appraisals): ?>
            <p class="hr-muted">No appraisals yet.</p>
        <?php endif; ?>
        <?php foreach ($appraisals as $a): ?>
            <?php $ratings = hr_json_map($a['kpi_ratings']); $file = $a['file_id'] ? hr_file_find($conn, (int)$a['file_id']) : null; ?>
            <details class="hr-details" style="border-top:1px solid #f1f5f9;padding:.7rem 0">
                <summary>
                    <?= hr_h($a['period_label']) ?> ·
                    <span class="hr-stars"><?= str_repeat('★', (int)$a['overall_rating']) . str_repeat('☆', 5 - (int)$a['overall_rating']) ?></span>
                    <?= hr_h(hr_rating_label((int)$a['overall_rating'])) ?>
                    <?= $a['acknowledged_at'] ? '<span class="hr-badge hr-b-ok">Acknowledged</span>' : '<span class="hr-badge hr-b-wait">Not yet acknowledged</span>' ?>
                </summary>
                <div style="margin-top:.6rem">
                    <div class="hr-muted">Reviewed <?= hr_h(date('d M Y', strtotime((string)$a['review_date']))) ?> by <?= hr_h($a['reviewer_name'] ?: 'an admin') ?></div>
                    <?php if ($ratings): ?>
                        <table class="hr-table" style="margin:.6rem 0">
                            <thead><tr><th>KPI</th><th>Rating</th><th>Comment</th></tr></thead>
                            <tbody>
                            <?php foreach ($ratings as $kid => $r): ?>
                                <tr><td><?= hr_h($kpiNames[(int)$kid] ?? 'KPI #' . (int)$kid) ?></td><td><?= (int)$r['rating'] ?>/5</td><td><?= hr_h($r['comment'] ?? '') ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php foreach (['strengths' => 'Strengths', 'improvements' => 'Areas to improve', 'goals' => 'Goals for next period', 'reviewer_comments' => 'Reviewer comments', 'staff_comments' => 'Staff member\'s comments'] as $col => $lbl): ?>
                        <?php if (trim((string)$a[$col]) !== ''): ?>
                            <div class="hr-block-label"><?= hr_h($lbl) ?></div><div class="hr-text"><?= hr_h($a[$col]) ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($file): ?><p><?= hr_file_link($file, 'Signed appraisal form') ?></p><?php endif; ?>
                    <?php if (!$a['acknowledged_at']): ?>
                        <form method="post" onsubmit="return confirm('Delete this appraisal?');">
                            <?= hr_csrf_field() ?>
                            <input type="hidden" name="action" value="delete_appraisal">
                            <input type="hidden" name="id" value="<?= $profileId ?>">
                            <input type="hidden" name="tab" value="appraisals">
                            <input type="hidden" name="appraisal_id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit"><i class="fas fa-trash"></i> Delete</button>
                        </form>
                    <?php else: ?>
                        <div class="hr-muted">Acknowledged <?= hr_h(date('d M Y H:i', strtotime((string)$a['acknowledged_at']))) ?></div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
    <div class="hr-card">
        <h5>Record an appraisal</h5>
        <form method="post" enctype="multipart/form-data" class="hr-form">
            <?= hr_csrf_field() ?>
            <input type="hidden" name="action" value="add_appraisal">
            <input type="hidden" name="id" value="<?= $profileId ?>">
            <input type="hidden" name="tab" value="appraisals">
            <div><label class="hr-req" for="aPeriod">Period</label><input id="aPeriod" name="period_label" maxlength="60" required placeholder="e.g. January - June 2026"></div>
            <div><label class="hr-req" for="aDate">Review date</label><input id="aDate" type="date" name="review_date" required value="<?= date('Y-m-d') ?>"></div>
            <div>
                <label class="hr-req" for="aRating">Overall rating</label>
                <select id="aRating" name="overall_rating" required>
                    <option value="">Choose...</option>
                    <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> - <?= hr_rating_label($i) ?></option><?php endfor; ?>
                </select>
            </div>
            <?php if ($activeKpis): ?>
                <div class="hr-section-title">Rating per KPI</div>
                <div class="wide">
                    <?php foreach ($activeKpis as $k): ?>
                        <div class="hr-kpi-row">
                            <div><strong><?= hr_h($k['title']) ?></strong><div class="hr-muted">Target: <?= hr_h($k['target'] ?: '-') ?></div></div>
                            <select class="hr-input" name="kpi_rating[<?= (int)$k['id'] ?>]" aria-label="Rating for <?= hr_h($k['title']) ?>">
                                <option value="">Not rated</option>
                                <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> - <?= hr_rating_label($i) ?></option><?php endfor; ?>
                            </select>
                            <input class="hr-input" name="kpi_comment[<?= (int)$k['id'] ?>]" maxlength="500" placeholder="Comment" aria-label="Comment on <?= hr_h($k['title']) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="wide"><label for="aStr">Strengths</label><textarea id="aStr" name="strengths"></textarea></div>
            <div class="wide"><label for="aImp">Areas to improve</label><textarea id="aImp" name="improvements"></textarea></div>
            <div class="wide"><label for="aGoals">Goals for next period</label><textarea id="aGoals" name="goals"></textarea></div>
            <div class="wide"><label for="aCom">Reviewer comments</label><textarea id="aCom" name="reviewer_comments"></textarea></div>
            <div class="wide"><label for="aFile">Signed appraisal form (optional)</label><input id="aFile" type="file" name="file" accept=".pdf,image/jpeg,image/png,image/webp"></div>
            <div class="wide"><button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save appraisal</button></div>
        </form>
    </div>

<?php else: /* pay */ ?>
    <?php
    $reports = [];
    foreach (hr_reports($conn, $profileId) as $r) $reports[$r['period']] = $r;
    $invoices = [];
    foreach (hr_invoices($conn, $profileId) as $i) $invoices[$i['period']] = $i;
    $payments = hr_payments($conn, $profileId);
    $periods = array_unique(array_merge(hr_reportable_periods($p), array_keys($reports), array_keys($invoices), array_keys($payments)));
    rsort($periods);
    ?>
    <div class="hr-card">
        <h5>Reports, invoices and pay by month</h5>
        <p class="hr-muted">A month can be paid only after the monthly report<?= $p['staff_type'] === 'consultant' ? ' and the invoice are' : ' is' ?> approved.</p>
        <div class="hr-table-wrap">
            <table class="hr-table">
                <thead><tr><th>Month</th><th>Report</th><?= $p['staff_type'] === 'consultant' ? '<th>Invoice</th>' : '' ?><th>Pay</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($periods as $per): ?>
                    <?php $r = $reports[$per] ?? null; $i = $invoices[$per] ?? null; $pay = $payments[$per] ?? null; ?>
                    <tr>
                        <td><strong><?= hr_h(hr_period_label($per)) ?></strong></td>
                        <td><?= hr_status_badge($r['status'] ?? null) ?></td>
                        <?php if ($p['staff_type'] === 'consultant'): ?><td><?= hr_status_badge($i['status'] ?? null) ?><?= $i ? '<div class="hr-muted">' . hr_ugx($i['amount']) . '</div>' : '' ?></td><?php endif; ?>
                        <td><?= $pay ? hr_status_badge('paid') . '<div class="hr-muted">' . hr_ugx($pay['amount']) . ' on ' . hr_h(date('d M Y', strtotime((string)$pay['paid_on']))) . '</div>' : '<span class="hr-muted">Not paid</span>' ?></td>
                        <td><a class="btn btn-sm btn-secondary" href="hr_review.php?profile=<?= $profileId ?>&period=<?= hr_h($per) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</div>
<?php require 'footer.php'; ?>
