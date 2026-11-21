<?php
declare(strict_types=1);

/*
 * My Staff Portal: where a staff member (loan officer or admin with a staff
 * profile) submits the monthly report, and, if a consultant, the monthly
 * invoice; sees their KPIs, appraisals, contract and pay status.
 */

$phead = 'My Staff Portal';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/staff_hr.php';
[$userId] = hr_require_login(true);

$profile = hr_profile_for_user($conn, $userId);
$tabs = ['month' => 'Monthly submission', 'history' => 'History & pay', 'kpis' => 'My KPIs', 'appraisals' => 'Appraisals', 'profile' => 'My profile'];
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'month');
if (!isset($tabs[$tab])) {
    $tab = 'month';
}

$periods = $profile ? hr_reportable_periods($profile) : [];
$period = (string)($_GET['period'] ?? $_POST['period'] ?? ($periods[0] ?? hr_current_period()));
if (!in_array($period, $periods, true)) {
    $period = $periods[0] ?? hr_current_period();
}
$self = 'my_staff_portal.php';

/* ---------- Actions ---------- */
if ($profile && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)$profile['id'];
    $back = $self . '?tab=' . $tab . '&period=' . $period;
    if (!hr_csrf_ok()) {
        hr_flash('Your session expired. Please try again.', 'error');
        hr_redirect($back);
    }
    $action = (string)($_POST['action'] ?? '');
    $name = hr_display_name($profile);
    $monthLabel = hr_period_label($period);
    $reviewLink = '<p>Open <strong>Staff HR &gt; Monthly Submissions</strong> in the admin portal to review it.</p>';
    try {
        if ($profile['status'] !== 'active' && in_array($action, ['save_report', 'save_invoice'], true)) {
            throw new DomainException('Your staff profile is inactive, so you cannot submit reports or invoices.');
        }
        if (in_array($action, ['save_report', 'save_invoice'], true) && hr_payment_for($conn, $pid, $period)) {
            throw new DomainException($monthLabel . ' is already paid and can no longer be changed.');
        }
        switch ($action) {
            case 'save_report':
                $existing = hr_report_for($conn, $pid, $period);
                if (!hr_is_editable($existing)) {
                    throw new DomainException('Your report for ' . $monthLabel . ' is approved and can no longer be changed.');
                }
                $summary = hr_text($_POST['work_summary'] ?? '', 8000);
                if (mb_strlen($summary) < 30) {
                    throw new DomainException('Describe the work you did this month (at least a few sentences).');
                }
                if (empty($_POST['confirm'])) {
                    throw new DomainException('Tick the box to confirm the report is accurate.');
                }
                $fields = [
                    'achievements'    => hr_text($_POST['achievements'] ?? '', 8000),
                    'challenges'      => hr_text($_POST['challenges'] ?? '', 8000),
                    'support_needed'  => hr_text($_POST['support_needed'] ?? '', 4000),
                    'next_month_plan' => hr_text($_POST['next_month_plan'] ?? '', 8000),
                ];
                $progress = [];
                $actuals = is_array($_POST['kpi_actual'] ?? null) ? $_POST['kpi_actual'] : [];
                $comments = is_array($_POST['kpi_comment'] ?? null) ? $_POST['kpi_comment'] : [];
                foreach (hr_kpis($conn, $pid, true) as $k) {
                    $kid = (int)$k['id'];
                    $actual = hr_text($actuals[$kid] ?? '', 100);
                    if ($actual === '' && $k['frequency'] === 'monthly') {
                        throw new DomainException('Enter what you achieved for the KPI "' . $k['title'] . '".');
                    }
                    if ($actual !== '' || trim((string)($comments[$kid] ?? '')) !== '') {
                        $progress[$kid] = ['actual' => $actual, 'comment' => hr_text($comments[$kid] ?? '', 500)];
                    }
                }
                $progressJson = $progress ? json_encode($progress) : null;
                $fileId = $existing['file_id'] ?? null;
                if (hr_has_upload($_FILES['file'] ?? null)) {
                    $fileId = hr_store_file($conn, $pid, 'report', 'Report attachment ' . $monthLabel, $_FILES['file'], $userId);
                }
                if ($existing) {
                    $s = $conn->prepare("UPDATE staff_monthly_reports SET work_summary = ?, achievements = ?, challenges = ?, support_needed = ?, next_month_plan = ?,
                                         kpi_progress = ?, file_id = ?, status = 'submitted', reviewed_by = NULL, reviewed_at = NULL
                                         WHERE id = ? AND status IN ('submitted','returned')");
                    $rid = (int)$existing['id'];
                    $s->bind_param('ssssssii', $summary, $fields['achievements'], $fields['challenges'], $fields['support_needed'], $fields['next_month_plan'], $progressJson, $fileId, $rid);
                } else {
                    $s = $conn->prepare('INSERT INTO staff_monthly_reports (profile_id, period, work_summary, achievements, challenges, support_needed, next_month_plan, kpi_progress, file_id)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $s->bind_param('isssssssi', $pid, $period, $summary, $fields['achievements'], $fields['challenges'], $fields['support_needed'], $fields['next_month_plan'], $progressJson, $fileId);
                }
                $s->execute();
                $s->close();
                $verb = $existing ? ($existing['status'] === 'returned' ? 'resubmitted' : 'updated') : 'submitted';
                hr_mail_admins($conn, $name . ' ' . $verb . ' the monthly report for ' . $monthLabel,
                    '<p>' . hr_h($name) . ' ' . $verb . ' their monthly report for <strong>' . hr_h($monthLabel) . '</strong>.</p>' . $reviewLink);
                hr_flash('Your report for ' . $monthLabel . ' was ' . $verb . '. You will get an email once it is reviewed.'
                    . ($profile['staff_type'] === 'consultant' && !hr_invoice_for($conn, $pid, $period) ? ' Remember to submit your invoice too.' : ''));
                break;

            case 'save_invoice':
                if ($profile['staff_type'] !== 'consultant') {
                    throw new DomainException('Only consultants submit invoices.');
                }
                $existing = hr_invoice_for($conn, $pid, $period);
                if (!hr_is_editable($existing)) {
                    throw new DomainException('Your invoice for ' . $monthLabel . ' is approved and can no longer be changed.');
                }
                $number = hr_text($_POST['invoice_number'] ?? '', 60);
                $date = hr_date($_POST['invoice_date'] ?? '');
                $amount = hr_money($_POST['amount'] ?? '');
                $desc = hr_text($_POST['description'] ?? '', 4000);
                if ($number === '' || !$date || $amount <= 0) {
                    throw new DomainException('Enter the invoice number, date and amount.');
                }
                $fileId = $existing['file_id'] ?? null;
                if (hr_has_upload($_FILES['file'] ?? null)) {
                    $fileId = hr_store_file($conn, $pid, 'invoice', 'Invoice ' . $number, $_FILES['file'], $userId);
                }
                if ($existing) {
                    $s = $conn->prepare("UPDATE staff_invoices SET invoice_number = ?, invoice_date = ?, amount = ?, description = ?, file_id = ?,
                                         status = 'submitted', reviewed_by = NULL, reviewed_at = NULL
                                         WHERE id = ? AND status IN ('submitted','returned')");
                    $iid = (int)$existing['id'];
                    $s->bind_param('ssdsii', $number, $date, $amount, $desc, $fileId, $iid);
                } else {
                    $s = $conn->prepare('INSERT INTO staff_invoices (profile_id, period, invoice_number, invoice_date, amount, description, file_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $s->bind_param('isssdsi', $pid, $period, $number, $date, $amount, $desc, $fileId);
                }
                $s->execute();
                $s->close();
                $verb = $existing ? ($existing['status'] === 'returned' ? 'resubmitted' : 'updated') : 'submitted';
                hr_mail_admins($conn, $name . ' ' . $verb . ' an invoice for ' . $monthLabel,
                    '<p>' . hr_h($name) . ' ' . $verb . ' invoice ' . hr_h($number) . ' of ' . hr_ugx($amount) . ' for <strong>' . hr_h($monthLabel) . '</strong>.</p>' . $reviewLink);
                hr_flash('Your invoice for ' . $monthLabel . ' was ' . $verb . '.'
                    . (!hr_report_for($conn, $pid, $period) ? ' Remember to submit your monthly report too; you cannot be paid without it.' : ''));
                break;

            case 'ack_appraisal':
                $aid = (int)($_POST['appraisal_id'] ?? 0);
                $comment = hr_text($_POST['staff_comments'] ?? '', 4000);
                $s = $conn->prepare('UPDATE staff_appraisals SET staff_comments = ?, acknowledged_at = NOW() WHERE id = ? AND profile_id = ? AND acknowledged_at IS NULL');
                $s->bind_param('sii', $comment, $aid, $pid);
                $s->execute();
                $ok = $s->affected_rows === 1;
                $s->close();
                hr_flash($ok ? 'Thank you. Your appraisal is acknowledged.' : 'This appraisal was already acknowledged.', $ok ? 'success' : 'error');
                break;

            case 'upload_my_file':
                $type = (string)($_POST['file_type'] ?? '');
                if (!in_array($type, ['national_id', 'cv', 'certificate', 'other'], true)) {
                    throw new DomainException('Choose what kind of document this is.');
                }
                $file = $_FILES['file'] ?? [];
                hr_store_file($conn, $pid, $type, (string)($_POST['title'] ?? ''), is_array($file) ? $file : [], $userId);
                hr_flash('Document uploaded.');
                break;
        }
    } catch (DomainException $e) {
        hr_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        error_log('My staff portal ' . $action . ': ' . $e->getMessage());
        hr_flash('Something went wrong. Please try again or tell an admin.', 'error');
    }
    hr_redirect($back);
}

require 'nav-head.php';
echo hr_styles();

if (!$profile): ?>
<div class="hr-page">
    <div class="hr-card">
        <h5><i class="fas fa-id-badge me-1"></i> My Staff Portal</h5>
        <p class="hr-muted" style="margin:0">You do not have a staff profile yet. Ask an administrator to create one for you under Staff HR &gt; Staff Profiles. Once it exists, you will submit your monthly report here.</p>
    </div>
</div>
<?php require 'footer.php'; return; endif;

$pid = (int)$profile['id'];
$isConsultant = $profile['staff_type'] === 'consultant';
$report = hr_report_for($conn, $pid, $period);
$invoice = $isConsultant ? hr_invoice_for($conn, $pid, $period) : null;
$payment = hr_payment_for($conn, $pid, $period);
$check = hr_pay_check($profile, $report, $invoice);
$kpis = hr_kpis($conn, $pid);
$activeKpis = array_values(array_filter($kpis, static fn($k) => $k['status'] === 'active'));
$kpiNames = array_column($kpis, 'title', 'id');
$appraisals = hr_appraisals($conn, $pid);
$unacked = count(array_filter($appraisals, static fn($a) => !$a['acknowledged_at']));
$dueNow = hr_my_due_count($conn, $profile);
$reportEditable = !$payment && hr_is_editable($report) && $profile['status'] === 'active';
$invoiceEditable = !$payment && hr_is_editable($invoice) && $profile['status'] === 'active';
$progress = hr_json_map($report['kpi_progress'] ?? null);
?>
<div class="hr-page">
    <?= hr_flash_html() ?>
    <div class="hr-card">
        <div class="hr-head">
            <div>
                <h5><?= hr_h(hr_display_name($profile)) ?> <?= hr_type_badge($profile['staff_type']) ?></h5>
                <div class="hr-muted"><?= hr_h($profile['job_title'] ?: 'Staff member') ?><?= $profile['contract_end'] ? ' · Contract ends ' . hr_h(date('d M Y', strtotime((string)$profile['contract_end']))) : '' ?></div>
            </div>
        </div>
        <p class="hr-muted" style="margin:.6rem 0 0">
            Each month you must submit your monthly report<?= $isConsultant ? ' and your invoice' : '' ?>.
            You are paid for a month only after <?= $isConsultant ? 'both are' : 'it is' ?> approved.
        </p>
    </div>

    <nav class="hr-tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="<?= hr_h($self . '?tab=' . $key) ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= hr_h($label) ?>
                <?php if ($key === 'month' && $dueNow): ?><span class="hr-count"><?= $dueNow ?></span><?php endif; ?>
                <?php if ($key === 'appraisals' && $unacked): ?><span class="hr-count"><?= $unacked ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

<?php if ($tab === 'month'): ?>
    <form method="get" class="hr-actions" style="margin-bottom:1rem">
        <input type="hidden" name="tab" value="month">
        <label class="hr-label" for="perSel" style="margin:0">Month</label>
        <select id="perSel" class="hr-input" name="period" style="width:auto" onchange="this.form.submit()">
            <?php foreach ($periods as $per): ?>
                <option value="<?= hr_h($per) ?>" <?= $per === $period ? 'selected' : '' ?>><?= hr_h(hr_period_label($per)) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm btn-secondary" type="submit">Go</button></noscript>
    </form>

    <?php if ($payment): ?>
        <div class="hr-flash success">Paid <?= hr_ugx($payment['amount']) ?> on <?= hr_h(date('d M Y', strtotime((string)$payment['paid_on']))) ?><?= $payment['reference'] ? ' (ref ' . hr_h($payment['reference']) . ')' : '' ?>.</div>
    <?php elseif (!$check['ok']): ?>
        <div class="hr-warn"><strong>Pay for <?= hr_h(hr_period_label($period)) ?> is on hold:</strong> <?= hr_h(implode('; ', $check['reasons'])) ?>.</div>
    <?php else: ?>
        <div class="hr-flash success">Everything for <?= hr_h(hr_period_label($period)) ?> is approved. Your payment will be processed.</div>
    <?php endif; ?>

    <div class="hr-card">
        <div class="hr-head">
            <h5><i class="fas fa-clipboard-list me-1"></i> Monthly report: <?= hr_h(hr_period_label($period)) ?></h5>
            <?= hr_status_badge($report['status'] ?? null) ?>
        </div>
        <?php if (!empty($report['review_note'])): ?>
            <div class="<?= $report['status'] === 'returned' ? 'hr-warn' : 'hr-info' ?>" style="margin:.6rem 0"><strong>Reviewer's comment:</strong> <?= nl2br(hr_h($report['review_note'])) ?></div>
        <?php endif; ?>

        <?php if ($reportEditable): ?>
            <form method="post" enctype="multipart/form-data" class="hr-form" style="margin-top:.6rem">
                <?= hr_csrf_field() ?>
                <input type="hidden" name="action" value="save_report">
                <input type="hidden" name="tab" value="month">
                <input type="hidden" name="period" value="<?= hr_h($period) ?>">
                <div class="wide">
                    <label class="hr-req" for="rSum">Work done this month</label>
                    <textarea id="rSum" name="work_summary" required minlength="30" rows="5" placeholder="Clients visited, loans processed, collections made, meetings held..."><?= hr_h($report['work_summary'] ?? '') ?></textarea>
                </div>
                <?php if ($activeKpis): ?>
                    <div class="hr-section-title">Progress on my KPIs</div>
                    <div class="wide">
                        <?php foreach ($activeKpis as $k): $kid = (int)$k['id']; ?>
                            <div class="hr-kpi-row">
                                <div>
                                    <strong><?= hr_h($k['title']) ?></strong>
                                    <div class="hr-muted">Target: <?= hr_h($k['target'] ?: '-') ?><?= $k['frequency'] !== 'monthly' ? ' (' . hr_h($k['frequency']) . ')' : '' ?></div>
                                </div>
                                <input class="hr-input" name="kpi_actual[<?= $kid ?>]" maxlength="100" value="<?= hr_h($progress[$kid]['actual'] ?? '') ?>"
                                       placeholder="Achieved" aria-label="Achieved for <?= hr_h($k['title']) ?>" <?= $k['frequency'] === 'monthly' ? 'required' : '' ?>>
                                <input class="hr-input" name="kpi_comment[<?= $kid ?>]" maxlength="500" value="<?= hr_h($progress[$kid]['comment'] ?? '') ?>"
                                       placeholder="Comment (optional)" aria-label="Comment on <?= hr_h($k['title']) ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="wide"><label for="rAch">Key achievements</label><textarea id="rAch" name="achievements"><?= hr_h($report['achievements'] ?? '') ?></textarea></div>
                <div class="wide"><label for="rCh">Challenges</label><textarea id="rCh" name="challenges"><?= hr_h($report['challenges'] ?? '') ?></textarea></div>
                <div class="wide"><label for="rSup">Support needed</label><textarea id="rSup" name="support_needed"><?= hr_h($report['support_needed'] ?? '') ?></textarea></div>
                <div class="wide"><label for="rPlan">Plan for next month</label><textarea id="rPlan" name="next_month_plan"><?= hr_h($report['next_month_plan'] ?? '') ?></textarea></div>
                <div class="wide">
                    <label for="rFile">Attachment (optional)</label>
                    <input id="rFile" type="file" name="file" accept=".pdf,image/jpeg,image/png,image/webp">
                    <small>PDF or image, up to 5 MB.<?php if (!empty($report['file_id']) && ($f = hr_file_find($conn, (int)$report['file_id']))): ?> Current: <?= hr_file_link($f) ?>. Choosing a new file replaces it.<?php endif; ?></small>
                </div>
                <div class="wide"><label style="font-weight:600"><input type="checkbox" name="confirm" value="1" required> I confirm this report is a true and accurate account of my work for <?= hr_h(hr_period_label($period)) ?>.</label></div>
                <div class="wide"><button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i> <?= $report ? ($report['status'] === 'returned' ? 'Resubmit report' : 'Update report') : 'Submit report' ?></button></div>
            </form>
        <?php elseif ($report): ?>
            <?php foreach (['work_summary' => 'Work done this month', 'achievements' => 'Key achievements', 'challenges' => 'Challenges', 'support_needed' => 'Support needed', 'next_month_plan' => 'Plan for next month'] as $col => $lbl): ?>
                <?php if (trim((string)$report[$col]) !== ''): ?><div class="hr-block-label" style="margin-top:.6rem"><?= hr_h($lbl) ?></div><div class="hr-text"><?= hr_h($report[$col]) ?></div><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($progress): ?>
                <div class="hr-block-label">KPI progress</div>
                <ul><?php foreach ($progress as $kid => $row): ?><li><?= hr_h($kpiNames[$kid] ?? 'KPI') ?>: <strong><?= hr_h($row['actual']) ?></strong> <?= hr_h($row['comment']) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        <?php else: ?>
            <p class="hr-muted" style="margin:.4rem 0 0">Your profile is inactive, so no report is due.</p>
        <?php endif; ?>
    </div>

    <?php if ($isConsultant): ?>
    <div class="hr-card">
        <div class="hr-head">
            <h5><i class="fas fa-file-invoice me-1"></i> Invoice: <?= hr_h(hr_period_label($period)) ?></h5>
            <?= hr_status_badge($invoice['status'] ?? null) ?>
        </div>
        <?php if (!empty($invoice['review_note'])): ?>
            <div class="<?= $invoice['status'] === 'returned' ? 'hr-warn' : 'hr-info' ?>" style="margin:.6rem 0"><strong>Reviewer's comment:</strong> <?= nl2br(hr_h($invoice['review_note'])) ?></div>
        <?php endif; ?>
        <?php if ($invoiceEditable): ?>
            <form method="post" enctype="multipart/form-data" class="hr-form" style="margin-top:.6rem">
                <?= hr_csrf_field() ?>
                <input type="hidden" name="action" value="save_invoice">
                <input type="hidden" name="tab" value="month">
                <input type="hidden" name="period" value="<?= hr_h($period) ?>">
                <div><label class="hr-req" for="iNum">Invoice number</label><input id="iNum" name="invoice_number" maxlength="60" required value="<?= hr_h($invoice['invoice_number'] ?? '') ?>"></div>
                <div><label class="hr-req" for="iDate">Invoice date</label><input id="iDate" type="date" name="invoice_date" required value="<?= hr_h($invoice['invoice_date'] ?? date('Y-m-d')) ?>"></div>
                <div>
                    <label class="hr-req" for="iAmt">Amount (UGX)</label>
                    <input id="iAmt" name="amount" inputmode="numeric" required value="<?= hr_h(number_format((float)($invoice['amount'] ?? $profile['monthly_pay']))) ?>">
                    <?php if ((float)$profile['monthly_pay'] > 0): ?><small>Agreed monthly fee: <?= hr_ugx($profile['monthly_pay']) ?></small><?php endif; ?>
                </div>
                <div class="wide"><label for="iDesc">Services provided</label><textarea id="iDesc" name="description"><?= hr_h($invoice['description'] ?? '') ?></textarea></div>
                <div class="wide">
                    <label for="iFile">Invoice document (optional)</label>
                    <input id="iFile" type="file" name="file" accept=".pdf,image/jpeg,image/png,image/webp">
                    <small>Your signed invoice as a PDF or photo, up to 5 MB.<?php if (!empty($invoice['file_id']) && ($f = hr_file_find($conn, (int)$invoice['file_id']))): ?> Current: <?= hr_file_link($f) ?>. Choosing a new file replaces it.<?php endif; ?></small>
                </div>
                <div class="wide"><button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i> <?= $invoice ? ($invoice['status'] === 'returned' ? 'Resubmit invoice' : 'Update invoice') : 'Submit invoice' ?></button></div>
            </form>
        <?php elseif ($invoice): ?>
            <dl class="hr-dl" style="margin-top:.6rem">
                <div><dt>Invoice number</dt><dd><?= hr_h($invoice['invoice_number']) ?></dd></div>
                <div><dt>Date</dt><dd><?= hr_h(date('d M Y', strtotime((string)$invoice['invoice_date']))) ?></dd></div>
                <div><dt>Amount</dt><dd><?= hr_ugx($invoice['amount']) ?></dd></div>
            </dl>
        <?php else: ?>
            <p class="hr-muted" style="margin:.4rem 0 0">Your profile is inactive, so no invoice is due.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php elseif ($tab === 'history'): ?>
    <?php
    $reports = array_column(hr_reports($conn, $pid), null, 'period');
    $invoices = array_column(hr_invoices($conn, $pid), null, 'period');
    $payments = hr_payments($conn, $pid);
    $all = array_unique(array_merge($periods, array_keys($reports), array_keys($invoices), array_keys($payments)));
    rsort($all);
    ?>
    <div class="hr-card">
        <h5>History and pay</h5>
        <div class="hr-table-wrap">
            <table class="hr-table">
                <thead><tr><th>Month</th><th>Report</th><?= $isConsultant ? '<th>Invoice</th>' : '' ?><th>Pay</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($all as $per): $r = $reports[$per] ?? null; $i = $invoices[$per] ?? null; $pay = $payments[$per] ?? null; ?>
                    <tr>
                        <td><strong><?= hr_h(hr_period_label($per)) ?></strong></td>
                        <td><?= hr_status_badge($r['status'] ?? null) ?></td>
                        <?php if ($isConsultant): ?><td><?= hr_status_badge($i['status'] ?? null) ?></td><?php endif; ?>
                        <td><?= $pay ? hr_status_badge('paid') . '<div class="hr-muted">' . hr_ugx($pay['amount']) . '</div>' : '<span class="hr-muted">Not paid</span>' ?></td>
                        <td><?php if (in_array($per, $periods, true)): ?><a class="btn btn-sm btn-secondary" href="<?= hr_h($self . '?tab=month&period=' . $per) ?>">Open</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'kpis'): ?>
    <div class="hr-card">
        <h5>My key performance indicators</h5>
        <?php if (!$activeKpis): ?>
            <p class="hr-muted" style="margin:0">No KPIs have been set for you yet.</p>
        <?php else: ?>
            <div class="hr-table-wrap">
                <table class="hr-table">
                    <thead><tr><th>KPI</th><th>Target</th><th>Measured</th><th>Weight</th></tr></thead>
                    <tbody>
                    <?php foreach ($activeKpis as $k): ?>
                        <tr>
                            <td><strong><?= hr_h($k['title']) ?></strong><?php if ($k['description'] !== ''): ?><div class="hr-muted"><?= hr_h($k['description']) ?></div><?php endif; ?></td>
                            <td><?= hr_h($k['target'] ?: '-') ?></td>
                            <td><?= hr_h(ucfirst($k['frequency'])) ?></td>
                            <td><?= (int)$k['weight'] ? (int)$k['weight'] . '%' : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'appraisals'): ?>
    <div class="hr-card">
        <h5>My performance appraisals</h5>
        <?php if (!$appraisals): ?><p class="hr-muted" style="margin:0">No appraisals yet.</p><?php endif; ?>
        <?php foreach ($appraisals as $a): $ratings = hr_json_map($a['kpi_ratings']); ?>
            <details class="hr-details" style="border-top:1px solid #f1f5f9;padding:.7rem 0" <?= !$a['acknowledged_at'] ? 'open' : '' ?>>
                <summary>
                    <?= hr_h($a['period_label']) ?> ·
                    <span class="hr-stars"><?= str_repeat('★', (int)$a['overall_rating']) . str_repeat('☆', 5 - (int)$a['overall_rating']) ?></span>
                    <?= hr_h(hr_rating_label((int)$a['overall_rating'])) ?>
                    <?= $a['acknowledged_at'] ? '<span class="hr-badge hr-b-ok">Acknowledged</span>' : '<span class="hr-badge hr-b-wait">Please read and acknowledge</span>' ?>
                </summary>
                <div style="margin-top:.6rem">
                    <div class="hr-muted">Reviewed <?= hr_h(date('d M Y', strtotime((string)$a['review_date']))) ?> by <?= hr_h($a['reviewer_name'] ?: 'an admin') ?></div>
                    <?php if ($ratings): ?>
                        <table class="hr-table" style="margin:.6rem 0">
                            <thead><tr><th>KPI</th><th>Rating</th><th>Comment</th></tr></thead>
                            <tbody><?php foreach ($ratings as $kid => $r): ?><tr><td><?= hr_h($kpiNames[$kid] ?? 'KPI') ?></td><td><?= (int)$r['rating'] ?>/5</td><td><?= hr_h($r['comment'] ?? '') ?></td></tr><?php endforeach; ?></tbody>
                        </table>
                    <?php endif; ?>
                    <?php foreach (['strengths' => 'Strengths', 'improvements' => 'Areas to improve', 'goals' => 'Goals for next period', 'reviewer_comments' => 'Reviewer comments', 'staff_comments' => 'My comments'] as $col => $lbl): ?>
                        <?php if (trim((string)$a[$col]) !== ''): ?><div class="hr-block-label"><?= hr_h($lbl) ?></div><div class="hr-text"><?= hr_h($a[$col]) ?></div><?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($a['file_id'] && ($f = hr_file_find($conn, (int)$a['file_id']))): ?><p><?= hr_file_link($f, 'Appraisal form') ?></p><?php endif; ?>
                    <?php if (!$a['acknowledged_at']): ?>
                        <form method="post" class="hr-form">
                            <?= hr_csrf_field() ?>
                            <input type="hidden" name="action" value="ack_appraisal">
                            <input type="hidden" name="tab" value="appraisals">
                            <input type="hidden" name="appraisal_id" value="<?= (int)$a['id'] ?>">
                            <div class="wide"><label for="ack<?= (int)$a['id'] ?>">My comments (optional)</label><textarea id="ack<?= (int)$a['id'] ?>" name="staff_comments"></textarea></div>
                            <div class="wide"><button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> I have read this appraisal</button></div>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>

<?php else: /* profile */ ?>
    <?php $files = hr_profile_files($conn, $pid); ?>
    <div class="hr-card">
        <h5>My details</h5>
        <p class="hr-muted">If anything here is wrong, ask an administrator to correct it.</p>
        <dl class="hr-dl">
            <div><dt>Engagement</dt><dd><?= hr_h(HR_STAFF_TYPES[$profile['staff_type']] ?? '') ?></dd></div>
            <div><dt>Job title</dt><dd><?= hr_h($profile['job_title'] ?: '-') ?></dd></div>
            <div><dt>Department</dt><dd><?= hr_h($profile['department'] ?: '-') ?></dd></div>
            <div><dt>Contract</dt><dd><?= $profile['contract_start'] ? hr_h(date('d M Y', strtotime((string)$profile['contract_start']))) : '-' ?> to <?= $profile['contract_end'] ? hr_h(date('d M Y', strtotime((string)$profile['contract_end']))) : '-' ?></dd></div>
            <div><dt>National ID (NIN)</dt><dd><?= hr_h($profile['national_id_number'] ?: '-') ?></dd></div>
            <div><dt>Phone</dt><dd><?= hr_h($profile['phone'] ?: '-') ?></dd></div>
            <div><dt>Next of kin</dt><dd><?= hr_h($profile['nok_name'] ?: '-') ?><?= $profile['nok_relationship'] ? ' (' . hr_h($profile['nok_relationship']) . ')' : '' ?></dd></div>
            <div><dt>Next of kin phone</dt><dd><?= hr_h($profile['nok_phone'] ?: '-') ?></dd></div>
            <div><dt>Bank</dt><dd><?= hr_h(trim($profile['bank_name'] . ' ' . $profile['bank_account']) ?: '-') ?></dd></div>
            <div><dt>Mobile money</dt><dd><?= hr_h($profile['mobile_money'] ?: '-') ?></dd></div>
        </dl>
    </div>
    <div class="hr-card">
        <h5>My documents</h5>
        <?php if (!$files): ?><p class="hr-muted">No documents yet.</p><?php else: ?>
            <ul style="margin:.4rem 0 1rem;padding-left:1.1rem">
                <?php foreach ($files as $f): ?><li><?= hr_h(hr_file_types()[$f['file_type']]['label'] ?? $f['file_type']) ?>: <?= hr_file_link($f) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="hr-form">
            <?= hr_csrf_field() ?>
            <input type="hidden" name="action" value="upload_my_file">
            <input type="hidden" name="tab" value="profile">
            <div>
                <label class="hr-req" for="mType">Add a document</label>
                <select id="mType" name="file_type" required>
                    <option value="national_id">National ID</option>
                    <option value="cv">CV</option>
                    <option value="certificate">Certificate</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div><label for="mTitle">Title</label><input id="mTitle" name="title" maxlength="150"></div>
            <div><label class="hr-req" for="mFile">File</label><input id="mFile" type="file" name="file" required accept=".pdf,image/jpeg,image/png,image/webp"></div>
            <div class="wide"><button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i> Upload</button></div>
        </form>
    </div>
<?php endif; ?>
</div>
<?php require 'footer.php'; ?>
