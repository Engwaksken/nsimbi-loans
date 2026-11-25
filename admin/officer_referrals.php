<?php
declare(strict_types=1);

/*
 * Loans > Officer Referrals: which loan officers bring in the best clients.
 * Clients name their referring officer on the /apply sign-up form
 * (customers.referred_by). Officers are ranked on what those clients went on
 * to do in the chosen sign-up period. Admins only.
 */

$phead = 'Officer Referrals';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/officer_referrals.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    header('Location: ../login.php');
    exit;
}

// "Assign referred clients": each client who named a referring officer
// (and their loans) goes to that officer (includes/officer_assignment.php).
require_once dirname(__DIR__) . '/includes/officer_assignment.php';
if (empty($_SESSION['referral_assign_csrf'])) {
    $_SESSION['referral_assign_csrf'] = bin2hex(random_bytes(16));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_referrals') {
    if (hash_equals($_SESSION['referral_assign_csrf'], (string)($_POST['csrf'] ?? ''))) {
        officer_assignment_ensure_schema($conn);
        $done = officer_apply_referrals($conn);
        $_SESSION['referral_assign_flash'] = "Assigned {$done['clients']} referred client(s) and {$done['loans']} loan(s) to their referring officers.";
    } else {
        $_SESSION['referral_assign_flash'] = 'Your session expired. Please try again.';
    }
    header('Location: officer_referrals.php', true, 303);
    exit;
}
$referralAssignFlash = (string)($_SESSION['referral_assign_flash'] ?? '');
unset($_SESSION['referral_assign_flash']);
$referralAssignLast = app_setting_get($conn, 'officer_referral_assignment', '');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('money')) {
    function money($value): string
    {
        return 'UGX ' . number_format((float)($value ?? 0));
    }
}
$pct = static fn(float $f): string => round($f * 100) . '%';

// ---- Period (by the date the client signed up) ----
$periods = [
    'all'        => 'All time',
    'this_month' => 'This month',
    'last_month' => 'Last month',
    'last_90'    => 'Last 90 days',
    'this_year'  => 'This year',
    'custom'     => 'Custom dates',
];
$period = (string)($_GET['period'] ?? 'all');
if (!isset($periods[$period])) {
    $period = 'all';
}
$validDate = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false ? $d : null;
[$from, $to] = match ($period) {
    'this_month' => [date('Y-m-01'), date('Y-m-d')],
    'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'last_90'    => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
    'this_year'  => [date('Y-01-01'), date('Y-m-d')],
    'custom'     => [$validDate($_GET['from'] ?? null), $validDate($_GET['to'] ?? null)],
    default      => [null, null],
};
if ($from !== null && $to !== null && $from > $to) {
    [$from, $to] = [$to, $from];
}

// ---- Ranking ----
$sorts = [
    'disbursed'  => 'Amount paid out',
    'clients'    => 'Clients referred',
    'conversion' => 'Conversion rate',
    'quality'    => 'Lowest overdue rate',
];
$sort = (string)($_GET['sort'] ?? 'disbursed');
if (!isset($sorts[$sort])) {
    $sort = 'disbursed';
}

$error = '';
$rows = [];
$unreferred = 0;
try {
    $rows = referral_leaderboard($conn, $from, $to);
    $unreferred = referral_unreferred_count($conn, $from, $to);
} catch (Throwable $e) {
    error_log('Officer referrals: ' . $e->getMessage());
    $error = 'Could not load referral figures. Check the server log.';
}

usort($rows, static function (array $a, array $b) use ($sort): int {
    $key = match ($sort) {
        'clients'    => [$b['clients'], $a['clients']],
        'conversion' => [$b['conversion'], $a['conversion']],
        // Officers with no paid-out loans go last: nothing to judge yet.
        'quality'    => [
            [$a['loans_disbursed'] > 0 ? 0 : 1, $a['overdue_rate'], -$a['loans_disbursed']],
            [$b['loans_disbursed'] > 0 ? 0 : 1, $b['overdue_rate'], -$b['loans_disbursed']],
        ],
        default      => [$b['amount_disbursed'], $a['amount_disbursed']],
    };
    return ($key[0] <=> $key[1])
        ?: ($b['amount_disbursed'] <=> $a['amount_disbursed'])
        ?: ($b['clients'] <=> $a['clients'])
        ?: strcasecmp($a['fname'] . $a['lname'], $b['fname'] . $b['lname']);
});

$totals = ['clients' => 0, 'funded' => 0, 'disbursed' => 0.0, 'overdue' => 0, 'loans' => 0];
foreach ($rows as $r) {
    $totals['clients'] += $r['clients'];
    $totals['funded'] += $r['clients_funded'];
    $totals['disbursed'] += $r['amount_disbursed'];
    $totals['overdue'] += $r['loans_overdue'];
    $totals['loans'] += $r['loans_disbursed'];
}
$allSignups = $totals['clients'] + $unreferred;

// Client lists for the officers that have referrals.
$clientLists = [];
if ($error === '') {
    foreach ($rows as $r) {
        if ($r['clients'] > 0) {
            try {
                $clientLists[(int)$r['id']] = referral_clients_of($conn, (int)$r['id'], $from, $to);
            } catch (Throwable $e) {
                error_log('Officer referrals clients: ' . $e->getMessage());
            }
        }
    }
}

$periodLabel = $period === 'custom'
    ? (($from ? date('j M Y', strtotime($from)) : 'Start') . ' - ' . ($to ? date('j M Y', strtotime($to)) : 'today'))
    : $periods[$period];

require 'nav-head.php';
?>
<style>
.ref-filters { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
.ref-filters .form-select, .ref-filters .form-control { width: auto; min-width: 150px; }
.ref-rank { width: 30px; height: 30px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: .8rem; background: #f1f5f9; color: #475569; }
.ref-rank.r1 { background: #fef3c7; color: #92400e; }
.ref-rank.r2 { background: #e5e7eb; color: #374151; }
.ref-rank.r3 { background: #ffedd5; color: #9a3412; }
.ref-name { font-weight: 700; color: var(--text-dark); }
.ref-bar { height: 5px; border-radius: 99px; background: #eef2f7; overflow: hidden; margin-top: .3rem; min-width: 70px; }
.ref-bar span { display: block; height: 100%; background: var(--secondary, #16a34a); }
.ref-bar.bad span { background: #dc2626; }
.ref-link { border: 0; background: none; padding: 0; color: #1d4ed8; font-size: .72rem; font-weight: 700; cursor: pointer; }
.ref-link:hover { text-decoration: underline; }
.ref-help { font-size: .8rem; color: #64748b; }
.ref-table td, .ref-table th { white-space: nowrap; }
.badge-secondary { background: #e5e7eb; color: #374151; }
</style>

<?php if ($referralAssignFlash !== ''): ?>
    <div class="alert alert-success"><?= h($referralAssignFlash) ?></div>
<?php endif; ?>
<div class="card mb-3" style="padding:.9rem 1rem">
    <form method="post" class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-0">
        <div class="fs-sm">
            <strong>Referred clients belong to their referring officer.</strong>
            New sign-ups are assigned automatically.
            <?php if ($referralAssignLast !== ''): ?><span class="text-muted">Last assigned: <?= h($referralAssignLast) ?>.</span><?php endif; ?>
        </div>
        <input type="hidden" name="action" value="assign_referrals">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['referral_assign_csrf']) ?>">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-user-check"></i> Assign referred clients to their officers</button>
    </form>
</div>

<div class="page-header mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <div class="title">Officer Referrals</div>
        <div class="sub">Clients pick the loan officer who referred them when they sign up on /apply. See whose clients sign up, get loans and repay.</div>
    </div>
</div>

<?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="stats-grid stats-compact">
    <div class="stat-card c-blue">
        <div class="stat-head">
            <div class="stat-ico c-blue"><i class="fas fa-user-plus"></i></div>
            <span class="stat-badge flat"><i class="fas fa-calendar"></i> <?= h($periodLabel) ?></span>
        </div>
        <div class="stat-value"><?= number_format($totals['clients']) ?></div>
        <div class="stat-label">Referred clients</div>
        <div class="stat-foot"><i class="fas fa-chart-pie"></i> <?= $allSignups > 0 ? $pct($totals['clients'] / $allSignups) : '0%' ?> of <?= number_format($allSignups) ?> sign-ups</div>
    </div>
    <div class="stat-card c-green">
        <div class="stat-head">
            <div class="stat-ico c-green"><i class="fas fa-hand-holding-dollar"></i></div>
        </div>
        <div class="stat-value"><?= number_format($totals['funded']) ?></div>
        <div class="stat-label">Got a loan paid out</div>
        <div class="stat-foot"><i class="fas fa-arrow-trend-up"></i> <?= $totals['clients'] > 0 ? $pct($totals['funded'] / $totals['clients']) : '0%' ?> conversion</div>
    </div>
    <div class="stat-card c-purple">
        <div class="stat-head">
            <div class="stat-ico c-purple"><i class="fas fa-coins"></i></div>
        </div>
        <div class="stat-value sm"><?= money($totals['disbursed']) ?></div>
        <div class="stat-label">Paid out to referred clients</div>
        <div class="stat-foot"><i class="fas fa-file-invoice-dollar"></i> <?= number_format($totals['loans']) ?> loan(s)</div>
    </div>
    <div class="stat-card c-red">
        <div class="stat-head">
            <div class="stat-ico c-red"><i class="fas fa-clock"></i></div>
        </div>
        <div class="stat-value"><?= number_format($totals['overdue']) ?></div>
        <div class="stat-label">Overdue loans</div>
        <div class="stat-foot"><i class="fas fa-percent"></i> <?= $totals['loans'] > 0 ? $pct($totals['overdue'] / $totals['loans']) : '0%' ?> of paid-out loans</div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="mb-0">Loan officer ranking</h5>
            <small class="text-muted">Counts clients who signed up in the period, and every loan they have taken since.</small>
        </div>
        <form method="get" class="ref-filters">
            <select name="period" class="form-select form-select-sm" aria-label="Sign-up period" onchange="this.form.querySelector('.ref-custom').hidden = this.value !== 'custom'; if (this.value !== 'custom') this.form.submit();">
                <?php foreach ($periods as $key => $label): ?>
                    <option value="<?= h($key) ?>"<?= $period === $key ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="ref-custom ref-filters"<?= $period === 'custom' ? '' : ' hidden' ?>>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= h($from ?? '') ?>" aria-label="From">
                <input type="date" name="to" class="form-control form-control-sm" value="<?= h($to ?? '') ?>" aria-label="To">
            </span>
            <select name="sort" class="form-select form-select-sm" aria-label="Rank by" onchange="this.form.submit()">
                <?php foreach ($sorts as $key => $label): ?>
                    <option value="<?= h($key) ?>"<?= $sort === $key ? ' selected' : '' ?>>Rank by: <?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i> Apply</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table ref-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Loan officer</th>
                    <th>Clients referred</th>
                    <th>Applied</th>
                    <th>Got a loan</th>
                    <th>Paid out</th>
                    <th>Cleared</th>
                    <th>Overdue</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="9"><div class="empty-state"><i class="fas fa-user-tie"></i><h3>No loan officers yet</h3><p>Add users with the Loan Officer role under People &gt; Users.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $i => $r): ?>
                <?php
                $rank = $i + 1;
                $name = trim($r['fname'] . ' ' . $r['lname']) ?: 'User #' . (int)$r['id'];
                $scored = $sort === 'quality' ? $r['loans_disbursed'] > 0 : ($sort === 'clients' || $sort === 'conversion' ? $r['clients'] > 0 : $r['amount_disbursed'] > 0);
                ?>
                <tr>
                    <td><span class="ref-rank<?= $scored && $rank <= 3 ? ' r' . $rank : '' ?>"><?= $rank ?></span></td>
                    <td>
                        <div class="ref-name"><?= h($name) ?></div>
                        <div class="fs-xs text-muted">
                            <?= (int)$r['role'] === 2 ? 'Loan officer' : 'Not a loan officer' ?><?= (int)$r['status'] !== 1 ? ' · inactive' : '' ?>
                        </div>
                    </td>
                    <td><strong><?= number_format($r['clients']) ?></strong></td>
                    <td><?= number_format($r['clients_applied']) ?></td>
                    <td>
                        <?= number_format($r['clients_funded']) ?> <span class="text-muted">(<?= $pct($r['conversion']) ?>)</span>
                        <div class="ref-bar"><span style="width:<?= min(100, round($r['conversion'] * 100)) ?>%"></span></div>
                    </td>
                    <td>
                        <strong><?= money($r['amount_disbursed']) ?></strong>
                        <div class="fs-xs text-muted"><?= number_format($r['loans_disbursed']) ?> loan(s)</div>
                    </td>
                    <td><?= number_format($r['loans_cleared']) ?></td>
                    <td>
                        <?= number_format($r['loans_overdue']) ?>
                        <?php if ($r['loans_disbursed'] > 0): ?><span class="text-muted">(<?= $pct($r['overdue_rate']) ?>)</span><?php endif; ?>
                        <?php if ($r['overdue_balance'] > 0): ?><div class="fs-xs text-danger"><?= money($r['overdue_balance']) ?> owed</div><?php endif; ?>
                        <?php if ($r['loans_disbursed'] > 0): ?><div class="ref-bar bad"><span style="width:<?= min(100, round($r['overdue_rate'] * 100)) ?>%"></span></div><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-2 align-items-center">
                            <?php if ((int)$r['role'] === 2 && (int)$r['status'] === 1): ?>
                                <button type="button" class="ref-link" data-copy-ref="<?= (int)$r['id'] ?>" title="Copy this officer's sign-up link">
                                    <i class="fas fa-link"></i> Copy link
                                </button>
                            <?php endif; ?>
                            <?php if ($r['clients'] > 0): ?>
                                <button type="button" class="btn btn-ghost btn-sm" data-ref-open="refModal-<?= (int)$r['id'] ?>">
                                    <i class="fas fa-users"></i> Clients
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($rows as $r): ?>
    <?php if (empty($clientLists[(int)$r['id']])) continue; ?>
    <?php $name = trim($r['fname'] . ' ' . $r['lname']) ?: 'User #' . (int)$r['id']; ?>
    <div class="modal-overlay ref-modal" id="refModal-<?= (int)$r['id'] ?>" aria-hidden="true">
        <div class="modal-box md" role="dialog" aria-modal="true" aria-labelledby="refModalTitle-<?= (int)$r['id'] ?>">
            <div class="modal-header">
                <div class="modal-title"><div class="modal-ico"><i class="fas fa-users"></i></div>
                    <span class="modal-title-text" id="refModalTitle-<?= (int)$r['id'] ?>">Clients referred by <?= h($name) ?></span></div>
                <button type="button" class="modal-close" data-ref-close aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p class="ref-help mt-0"><?= h($periodLabel) ?>: <?= number_format($r['clients']) ?> client(s), <?= money($r['amount_disbursed']) ?> paid out.</p>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Client</th><th>Signed up</th><th>Assigned to</th><th>Loans</th><th>Paid out</th><th>Latest loan</th></tr></thead>
                        <tbody>
                        <?php foreach ($clientLists[(int)$r['id']] as $c): ?>
                            <tr>
                                <td>
                                    <a href="view_client.php?id=<?= (int)$c['user_id'] ?>" class="ref-name"><?= h(trim($c['fname'] . ' ' . $c['lname'])) ?></a>
                                    <div class="fs-xs text-muted"><?= h(trim(($c['business_name'] ?? '') . ' · ' . ($c['phone'] ?? ''), ' ·')) ?></div>
                                </td>
                                <td><?= !empty($c['created_at']) ? h(date('j M Y', strtotime((string)$c['created_at']))) : '-' ?></td>
                                <td>
                                    <?php $assignedId = (int)($c['assigned_officer_id'] ?? 0); ?>
                                    <?= h($assignedId > 0 ? ($c['assigned_officer_name'] ?: 'Officer #' . $assignedId) : 'Not assigned') ?>
                                    <?php if ($assignedId !== (int)$r['id']): ?><div class="fs-xs text-warning">Not with referrer</div><?php endif; ?>
                                </td>
                                <td><?= number_format((int)$c['loans']) ?></td>
                                <td><?= (float)$c['disbursed'] > 0 ? money($c['disbursed']) : '<span class="text-muted">-</span>' ?></td>
                                <td>
                                    <?php if ((int)$c['loans'] === 0): ?>
                                        <span class="text-muted">Not applied yet</span>
                                    <?php else: ?>
                                        <span class="badge <?= (int)$c['has_overdue'] ? 'badge-danger' : 'badge-secondary' ?>"><?= h(ucwords(strtolower(str_replace('_', ' ', (string)$c['latest_status'])))) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-ref-close>Close</button>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
(function () {
    function openRef(id) {
        var m = document.getElementById(id);
        if (!m) return;
        if (window.Modal) { Modal.open(id); } else { m.classList.add('open'); }
        m.setAttribute('aria-hidden', 'false');
    }
    function closeRef(m) {
        if (window.Modal) { Modal.close(m.id); } else { m.classList.remove('open'); }
        m.setAttribute('aria-hidden', 'true');
    }
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-ref-open]');
        if (opener) { openRef(opener.getAttribute('data-ref-open')); return; }
        var modal = e.target.closest('.ref-modal');
        if (modal && (e.target === modal || e.target.closest('[data-ref-close]'))) { closeRef(modal); return; }
        var copy = e.target.closest('[data-copy-ref]');
        if (copy) {
            var link = window.location.origin + '/apply?ref=' + copy.getAttribute('data-copy-ref');
            var done = function () {
                var old = copy.innerHTML;
                copy.innerHTML = '<i class="fas fa-check"></i> Copied';
                setTimeout(function () { copy.innerHTML = old; }, 1600);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(link).then(done, function () { window.prompt('Copy this link:', link); });
            } else {
                window.prompt('Copy this link:', link);
            }
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.ref-modal.open').forEach(closeRef);
    });
})();
</script>
<?php require 'footer.php'; ?>
