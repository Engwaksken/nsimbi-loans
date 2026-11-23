<?php
declare(strict_types=1);

/*
 * Staff HR > Monthly Submissions: for one month, who has submitted their
 * report (and invoice, for consultants), what is waiting for review, and who
 * can be paid. Admins only.
 */

$phead = 'Monthly Submissions';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/staff_hr.php';
hr_require_login();

$month = (string)($_GET['month'] ?? hr_current_period());
if (!hr_valid_period($month)) {
    $month = hr_current_period();
}
$monthStart = new DateTimeImmutable($month . '-01');
$prev = $monthStart->modify('-1 month')->format('Y-m');
$next = $monthStart->modify('+1 month')->format('Y-m');

$schemaError = '';
$rows = [];
$waiting = [];
$stats = ['staff' => 0, 'missing' => 0, 'review' => 0, 'payable' => 0, 'paid' => 0, 'paid_total' => 0.0];
try {
    foreach (hr_profiles_all($conn) as $p) {
        $pid = (int)$p['id'];
        $report = hr_report_for($conn, $pid, $month);
        $invoice = hr_invoice_for($conn, $pid, $month);
        $payment = hr_payment_for($conn, $pid, $month);
        // Inactive staff only show for months they have records in.
        if ($p['status'] !== 'active' && !$report && !$invoice && !$payment) {
            continue;
        }
        // Skip months before the contract started.
        if (!$report && !$invoice && !$payment && $p['contract_start'] && substr((string)$p['contract_start'], 0, 7) > $month) {
            continue;
        }
        $check = hr_pay_check($p, $report, $invoice);
        $stats['staff']++;
        if (!$report || ($p['staff_type'] === 'consultant' && !$invoice)) $stats['missing']++;
        if (($report['status'] ?? '') === 'submitted' || ($invoice['status'] ?? '') === 'submitted') $stats['review']++;
        if ($payment) {
            $stats['paid']++;
            $stats['paid_total'] += (float)$payment['amount'];
        } elseif ($check['ok']) {
            $stats['payable']++;
        }
        $rows[] = compact('p', 'report', 'invoice', 'payment', 'check');
    }

    // Anything waiting for review in other months too.
    $waiting = $conn->query("
        SELECT x.kind, x.profile_id, x.period, x.submitted_at, u.fname, u.lname FROM (
            SELECT 'Report' AS kind, profile_id, period, submitted_at FROM staff_monthly_reports WHERE status = 'submitted'
            UNION ALL
            SELECT 'Invoice', profile_id, period, submitted_at FROM staff_invoices WHERE status = 'submitted'
        ) x
        JOIN staff_profiles p ON p.id = x.profile_id
        JOIN user u ON u.id = p.user_id
        WHERE x.period <> '" . $conn->real_escape_string($month) . "'
        ORDER BY x.submitted_at
    ")->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    $schemaError = 'Could not load submissions: ' . $e->getMessage();
}

require 'nav-head.php';
echo hr_styles();
?>
<div class="hr-page">
    <?= hr_flash_html() ?>
    <?php if ($schemaError !== ''): ?><div class="hr-flash error"><?= hr_h($schemaError) ?></div><?php endif; ?>

    <div class="hr-card">
        <div class="hr-head">
            <div>
                <h5><i class="fas fa-calendar-check me-1"></i> <?= hr_h(hr_period_label($month)) ?></h5>
                <p class="hr-muted" style="margin:0">Staff can only be paid once their monthly report is approved. Consultants also need an approved invoice.</p>
            </div>
            <form method="get" class="hr-actions">
                <a class="btn btn-sm btn-secondary" href="?month=<?= $prev ?>" title="Previous month"><i class="fas fa-chevron-left"></i></a>
                <input class="hr-input" type="month" name="month" value="<?= hr_h($month) ?>" style="width:auto" onchange="this.form.submit()" aria-label="Month">
                <a class="btn btn-sm btn-secondary" href="?month=<?= $next ?>" title="Next month"><i class="fas fa-chevron-right"></i></a>
            </form>
        </div>
    </div>

    <div class="stats-grid stats-compact">
        <div class="stat-card c-red">
            <div class="stat-head">
                <div class="stat-ico c-red"><i class="fas fa-file-circle-exclamation"></i></div>
                <span class="stat-badge flat"><i class="fas fa-users"></i> <?= number_format($stats['staff']) ?> staff</span>
            </div>
            <div class="stat-value"><?= number_format($stats['missing']) ?></div>
            <div class="stat-label">Not yet submitted</div>
            <div class="stat-foot"><i class="fas fa-file-lines"></i> Report or invoice missing</div>
        </div>
        <div class="stat-card c-orange">
            <div class="stat-head">
                <div class="stat-ico c-orange"><i class="fas fa-hourglass-half"></i></div>
                <?php if ($waiting): ?><span class="stat-badge down"><i class="fas fa-clock"></i> +<?= count($waiting) ?> older</span><?php endif; ?>
            </div>
            <div class="stat-value"><?= number_format($stats['review']) ?></div>
            <div class="stat-label">Waiting for review</div>
            <div class="stat-foot"><i class="fas fa-clipboard-check"></i> Submitted, needs approval</div>
        </div>
        <div class="stat-card c-green">
            <div class="stat-head">
                <div class="stat-ico c-green"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="stat-value"><?= number_format($stats['payable']) ?></div>
            <div class="stat-label">Ready to pay</div>
            <div class="stat-foot"><i class="fas fa-thumbs-up"></i> All approvals in</div>
        </div>
        <div class="stat-card c-blue">
            <div class="stat-head">
                <div class="stat-ico c-blue"><i class="fas fa-money-bill-wave"></i></div>
            </div>
            <div class="stat-value"><?= number_format($stats['paid']) ?></div>
            <div class="stat-label">Paid</div>
            <div class="stat-foot"><i class="fas fa-coins"></i> <?= hr_ugx($stats['paid_total']) ?> this month</div>
        </div>
    </div>

    <?php if ($waiting): ?>
        <div class="hr-info">
            <strong>Also waiting for review from other months:</strong>
            <?php foreach ($waiting as $i => $w): ?>
                <?= $i ? ' · ' : '' ?><a href="hr_review.php?profile=<?= (int)$w['profile_id'] ?>&period=<?= hr_h($w['period']) ?>"><?= hr_h(trim($w['fname'] . ' ' . $w['lname'])) ?>, <?= hr_h($w['kind']) ?> <?= hr_h(hr_period_label($w['period'])) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="hr-card">
        <?php if (!$rows): ?>
            <p class="hr-muted" style="margin:0">No staff profiles for this month. <a href="hr_staff.php">Create staff profiles</a> first.</p>
        <?php else: ?>
            <div class="hr-table-wrap">
                <table class="hr-table">
                    <thead><tr><th>Staff member</th><th>Type</th><th>Monthly report</th><th>Invoice</th><th>Pay</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?= hr_h(hr_display_name($r['p'])) ?></strong><div class="hr-muted"><?= hr_h($r['p']['job_title']) ?></div></td>
                            <td><?= hr_type_badge($r['p']['staff_type']) ?></td>
                            <td><?= hr_status_badge($r['report']['status'] ?? null) ?></td>
                            <td>
                                <?php if ($r['p']['staff_type'] === 'consultant'): ?>
                                    <?= hr_status_badge($r['invoice']['status'] ?? null) ?>
                                    <?php if ($r['invoice']): ?><div class="hr-muted"><?= hr_ugx($r['invoice']['amount']) ?></div><?php endif; ?>
                                <?php else: ?><span class="hr-muted">Not needed</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['payment']): ?>
                                    <?= hr_status_badge('paid') ?><div class="hr-muted"><?= hr_ugx($r['payment']['amount']) ?></div>
                                <?php elseif ($r['check']['ok']): ?>
                                    <span class="hr-badge hr-b-ok">Ready to pay</span>
                                <?php else: ?>
                                    <span class="hr-badge hr-b-bad">On hold</span>
                                    <div class="hr-blocked"><?= hr_h(implode('; ', $r['check']['reasons'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><a class="btn btn-sm <?= (($r['report']['status'] ?? '') === 'submitted' || ($r['invoice']['status'] ?? '') === 'submitted') ? 'btn-primary' : 'btn-secondary' ?>" href="hr_review.php?profile=<?= (int)$r['p']['id'] ?>&period=<?= hr_h($month) ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require 'footer.php'; ?>
