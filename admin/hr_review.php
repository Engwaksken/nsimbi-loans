<?php
declare(strict_types=1);

/*
 * Staff HR > review one staff member's month: read the monthly report (and a
 * consultant's invoice), approve or return them, then record the payment.
 * Payment is refused until hr_pay_check() passes. Admins only.
 */

$phead = 'Review Monthly Submission';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/staff_hr.php';
[$actorId] = hr_require_login();

$profileId = (int)($_GET['profile'] ?? $_POST['profile'] ?? 0);
$period = (string)($_GET['period'] ?? $_POST['period'] ?? '');
$profile = $profileId > 0 ? hr_profile_find($conn, $profileId) : null;
if (!$profile || !hr_valid_period($period)) {
    hr_flash('Submission not found.', 'error');
    hr_redirect('hr_submissions.php');
}
$self = 'hr_review.php?profile=' . $profileId . '&period=' . $period;
$monthLabel = hr_period_label($period);
$isConsultant = $profile['staff_type'] === 'consultant';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hr_csrf_ok()) {
        hr_flash('Your session expired. Please try again.', 'error');
        hr_redirect($self);
    }
    $action = (string)($_POST['action'] ?? '');
    $note = hr_text($_POST['note'] ?? '', 1000);
    $paid = hr_payment_for($conn, $profileId, $period);
    $greeting = '<p>Dear ' . hr_h($profile['fname']) . ',</p>';
    try {
        if (in_array($action, ['approve_report', 'return_report', 'approve_invoice', 'return_invoice'], true)) {
            if ($paid) {
                throw new DomainException('This month is already paid. Remove the payment first to change the report or invoice.');
            }
            [$verb, $what] = explode('_', $action);
            $table = $what === 'report' ? 'staff_monthly_reports' : 'staff_invoices';
            $label = $what === 'report' ? 'monthly report' : 'invoice';
            if ($verb === 'return' && $note === '') {
                throw new DomainException('Say what needs to change so the staff member can fix it.');
            }
            $newStatus = $verb === 'approve' ? 'approved' : 'returned';
            $fromStatuses = $verb === 'approve' ? "('submitted')" : "('submitted','approved')";
            $s = $conn->prepare("UPDATE {$table} SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
                                 WHERE profile_id = ? AND period = ? AND status IN {$fromStatuses}");
            $noteOrNull = $note !== '' ? $note : null;
            $s->bind_param('ssiis', $newStatus, $noteOrNull, $actorId, $profileId, $period);
            $s->execute();
            $changed = $s->affected_rows;
            $s->close();
            if ($changed !== 1) {
                throw new DomainException('The ' . $label . ' has changed since you opened it. Check it again.');
            }
            if ($verb === 'approve') {
                hr_mail((string)$profile['email'], 'Your ' . $label . ' for ' . $monthLabel . ' was approved',
                    $greeting . '<p>Your ' . $label . ' for <strong>' . hr_h($monthLabel) . '</strong> has been approved.</p>'
                    . ($note !== '' ? '<p>Comment: ' . nl2br(hr_h($note)) . '</p>' : ''));
                hr_flash(ucfirst($label) . ' approved.');
            } else {
                hr_mail((string)$profile['email'], 'Action needed: your ' . $label . ' for ' . $monthLabel . ' was returned',
                    $greeting . '<p>Your ' . $label . ' for <strong>' . hr_h($monthLabel) . '</strong> was returned for changes:</p><p>'
                    . nl2br(hr_h($note)) . '</p><p>Please sign in, open <strong>My Staff Portal</strong>, update it and submit again. You cannot be paid for this month until it is approved.</p>');
                hr_flash(ucfirst($label) . ' returned. ' . hr_display_name($profile) . ' was emailed what to change.');
            }
        } elseif ($action === 'mark_paid') {
            if ($paid) {
                throw new DomainException('This month is already marked paid.');
            }
            $check = hr_pay_check($profile, hr_report_for($conn, $profileId, $period), hr_invoice_for($conn, $profileId, $period));
            if (!$check['ok']) {
                throw new DomainException('Cannot pay yet: ' . implode('; ', $check['reasons']) . '.');
            }
            $amount = hr_money($_POST['amount'] ?? '');
            $paidOn = hr_date($_POST['paid_on'] ?? '') ?? date('Y-m-d');
            $ref = hr_text($_POST['reference'] ?? '', 120);
            if ($amount <= 0) {
                throw new DomainException('Enter the amount paid.');
            }
            $s = $conn->prepare('INSERT INTO staff_payments (profile_id, period, amount, reference, paid_on, paid_by) VALUES (?, ?, ?, ?, ?, ?)');
            $s->bind_param('isdssi', $profileId, $period, $amount, $ref, $paidOn, $actorId);
            $s->execute();
            $s->close();
            hr_mail((string)$profile['email'], 'Payment for ' . $monthLabel,
                $greeting . '<p>Your payment of <strong>' . hr_ugx($amount) . '</strong> for ' . hr_h($monthLabel) . ' was made on '
                . hr_h(date('d M Y', strtotime($paidOn))) . ($ref !== '' ? ' (reference ' . hr_h($ref) . ')' : '') . '.</p>');
            hr_flash('Payment recorded for ' . $monthLabel . '.');
        } elseif ($action === 'undo_payment') {
            $s = $conn->prepare('DELETE FROM staff_payments WHERE profile_id = ? AND period = ?');
            $s->bind_param('is', $profileId, $period);
            $s->execute();
            $s->close();
            hr_flash('Payment record removed.');
        }
    } catch (DomainException $e) {
        hr_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        error_log('Staff HR review ' . $action . ': ' . $e->getMessage());
        hr_flash('Something went wrong. Check the server log.', 'error');
    }
    hr_redirect($self);
}

$report = hr_report_for($conn, $profileId, $period);
$invoice = hr_invoice_for($conn, $profileId, $period);
$payment = hr_payment_for($conn, $profileId, $period);
$check = hr_pay_check($profile, $report, $invoice);
$kpiNames = [];
$kpiTargets = [];
foreach (hr_kpis($conn, $profileId) as $k) {
    $kpiNames[(int)$k['id']] = $k['title'];
    $kpiTargets[(int)$k['id']] = $k['target'];
}
$name = hr_display_name($profile);

/** Approve / return buttons for a report or invoice. */
function hr_review_buttons(string $what, array $row, bool $locked): string
{
    if ($locked || !in_array($row['status'], ['submitted', 'approved'], true)) {
        return '';
    }
    ob_start(); ?>
    <form method="post" class="hr-form" style="margin-top:.8rem;border-top:1px solid #f1f5f9;padding-top:.8rem">
        <?= hr_csrf_field() ?>
        <div class="wide">
            <label for="note_<?= $what ?>">Comment<?= $row['status'] === 'submitted' ? ' (required when returning)' : ' (required)' ?></label>
            <textarea id="note_<?= $what ?>" name="note" rows="2" maxlength="1000"></textarea>
        </div>
        <div class="wide hr-actions">
            <?php if ($row['status'] === 'submitted'): ?>
                <button class="btn btn-success" name="action" value="approve_<?= $what ?>" type="submit"><i class="fas fa-check"></i> Approve</button>
            <?php endif; ?>
            <button class="btn btn-warning" name="action" value="return_<?= $what ?>" type="submit"><i class="fas fa-undo"></i> Return for changes</button>
        </div>
    </form>
    <?php
    return (string)ob_get_clean();
}

$phead = $name . ': ' . $monthLabel;
require 'nav-head.php';
echo hr_styles();
?>
<div class="hr-page">
    <?= hr_flash_html() ?>
    <div class="hr-card">
        <div class="hr-head">
            <div>
                <h5><?= hr_h($name) ?> <?= hr_type_badge($profile['staff_type']) ?></h5>
                <div class="hr-muted"><?= hr_h($monthLabel) ?> · <?= hr_h($profile['job_title'] ?: 'No job title') ?></div>
            </div>
            <div class="hr-actions">
                <a class="btn btn-sm btn-secondary" href="hr_submissions.php?month=<?= hr_h($period) ?>"><i class="fas fa-arrow-left"></i> <?= hr_h($monthLabel) ?> submissions</a>
                <a class="btn btn-sm btn-secondary" href="hr_staff_profile.php?id=<?= $profileId ?>&tab=pay">Profile</a>
            </div>
        </div>
    </div>

    <div class="hr-card">
        <div class="hr-head">
            <h5><i class="fas fa-clipboard-list me-1"></i> Monthly report</h5>
            <?= hr_status_badge($report['status'] ?? null) ?>
        </div>
        <?php if (!$report): ?>
            <p class="hr-muted" style="margin:.4rem 0 0"><?= hr_h($profile['fname']) ?> has not submitted a report for <?= hr_h($monthLabel) ?>.</p>
        <?php else: ?>
            <div class="hr-muted" style="margin-bottom:.8rem">Submitted <?= hr_h(date('d M Y H:i', strtotime((string)$report['submitted_at']))) ?><?= $report['updated_at'] !== $report['submitted_at'] ? ', last changed ' . hr_h(date('d M Y H:i', strtotime((string)$report['updated_at']))) : '' ?></div>
            <?php foreach (['work_summary' => 'Work done this month', 'achievements' => 'Achievements', 'challenges' => 'Challenges', 'support_needed' => 'Support needed', 'next_month_plan' => 'Plan for next month'] as $col => $lbl): ?>
                <?php if (trim((string)$report[$col]) !== ''): ?>
                    <div class="hr-block-label"><?= hr_h($lbl) ?></div><div class="hr-text"><?= hr_h($report[$col]) ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php $progress = hr_json_map($report['kpi_progress']); ?>
            <?php if ($progress): ?>
                <div class="hr-block-label">KPI progress</div>
                <table class="hr-table" style="margin:.3rem 0 .8rem">
                    <thead><tr><th>KPI</th><th>Target</th><th>Achieved</th><th>Comment</th></tr></thead>
                    <tbody>
                    <?php foreach ($progress as $kid => $row): ?>
                        <tr>
                            <td><?= hr_h($kpiNames[(int)$kid] ?? 'KPI #' . (int)$kid) ?></td>
                            <td><?= hr_h($kpiTargets[(int)$kid] ?? '-') ?></td>
                            <td><strong><?= hr_h($row['actual'] ?? '') ?></strong></td>
                            <td><?= hr_h($row['comment'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php if ($report['file_id'] && ($f = hr_file_find($conn, (int)$report['file_id']))): ?><p><?= hr_file_link($f, 'Attachment') ?></p><?php endif; ?>
            <?php if ($report['review_note']): ?>
                <div class="hr-info" style="margin:0"><strong>Review comment:</strong> <?= nl2br(hr_h($report['review_note'])) ?></div>
            <?php endif; ?>
            <?= hr_review_buttons('report', $report, (bool)$payment) ?>
        <?php endif; ?>
    </div>

    <?php if ($isConsultant || $invoice): ?>
    <div class="hr-card">
        <div class="hr-head">
            <h5><i class="fas fa-file-invoice me-1"></i> Invoice</h5>
            <?= hr_status_badge($invoice['status'] ?? null) ?>
        </div>
        <?php if (!$invoice): ?>
            <p class="hr-muted" style="margin:.4rem 0 0"><?= hr_h($profile['fname']) ?> has not submitted an invoice for <?= hr_h($monthLabel) ?>.</p>
        <?php else: ?>
            <dl class="hr-dl" style="margin:.6rem 0">
                <div><dt>Invoice number</dt><dd><?= hr_h($invoice['invoice_number']) ?></dd></div>
                <div><dt>Invoice date</dt><dd><?= hr_h(date('d M Y', strtotime((string)$invoice['invoice_date']))) ?></dd></div>
                <div><dt>Amount</dt><dd><?= hr_ugx($invoice['amount']) ?></dd></div>
                <div><dt>Agreed monthly fee</dt><dd><?= hr_ugx($profile['monthly_pay']) ?></dd></div>
            </dl>
            <?php if ((float)$profile['monthly_pay'] > 0 && abs((float)$invoice['amount'] - (float)$profile['monthly_pay']) >= 1): ?>
                <div class="hr-warn">The invoice amount is different from the agreed monthly fee.</div>
            <?php endif; ?>
            <?php if (trim((string)$invoice['description']) !== ''): ?>
                <div class="hr-block-label">Description</div><div class="hr-text"><?= hr_h($invoice['description']) ?></div>
            <?php endif; ?>
            <?php if ($invoice['file_id'] && ($f = hr_file_find($conn, (int)$invoice['file_id']))): ?><p><?= hr_file_link($f, 'Invoice document') ?></p><?php endif; ?>
            <?php if ($invoice['review_note']): ?>
                <div class="hr-info" style="margin:0"><strong>Review comment:</strong> <?= nl2br(hr_h($invoice['review_note'])) ?></div>
            <?php endif; ?>
            <?= hr_review_buttons('invoice', $invoice, (bool)$payment) ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="hr-card">
        <div class="hr-head">
            <h5><i class="fas fa-money-check-alt me-1"></i> Payment</h5>
            <?= $payment ? hr_status_badge('paid') : '' ?>
        </div>
        <?php if ($payment): ?>
            <dl class="hr-dl" style="margin:.6rem 0">
                <div><dt>Amount</dt><dd><?= hr_ugx($payment['amount']) ?></dd></div>
                <div><dt>Paid on</dt><dd><?= hr_h(date('d M Y', strtotime((string)$payment['paid_on']))) ?></dd></div>
                <div><dt>Reference</dt><dd><?= hr_h($payment['reference'] ?: '-') ?></dd></div>
            </dl>
            <form method="post" onsubmit="return confirm('Remove this payment record? Use this only if it was recorded by mistake.');">
                <?= hr_csrf_field() ?>
                <button class="btn btn-sm btn-danger" name="action" value="undo_payment" type="submit">Remove payment record</button>
            </form>
        <?php elseif (!$check['ok']): ?>
            <div class="hr-warn" style="margin:.6rem 0 0">
                <strong>Cannot be paid yet.</strong> <?= hr_h(implode('; ', $check['reasons'])) ?>.
            </div>
        <?php else: ?>
            <form method="post" class="hr-form" style="margin-top:.6rem">
                <?= hr_csrf_field() ?>
                <div><label class="hr-req" for="pAmt">Amount paid (UGX)</label><input id="pAmt" name="amount" inputmode="numeric" required value="<?= hr_h(number_format(hr_pay_amount($profile, $invoice))) ?>"></div>
                <div><label class="hr-req" for="pDate">Paid on</label><input id="pDate" type="date" name="paid_on" required value="<?= date('Y-m-d') ?>"></div>
                <div><label for="pRef">Reference</label><input id="pRef" name="reference" maxlength="120" placeholder="Bank or mobile money reference"></div>
                <div class="wide"><button class="btn btn-primary" name="action" value="mark_paid" type="submit"><i class="fas fa-check-circle"></i> Record payment</button></div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require 'footer.php'; ?>
