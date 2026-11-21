<?php
declare(strict_types=1);

/*
 * System > Business Types: clients whose business type is not in the scoring
 * table (they score the lowest, 3), grouped by what was typed, with a
 * suggested match. Reviewers (role 1) confirm corrections; nothing changes on
 * its own. Each change is logged and only the credit score is recalculated,
 * never the loan limit.
 */

$phead = 'Business Types';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/business_types.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    header('Location: ../login.php');
    exit;
}
$canApply = (int)($_SESSION['role'] ?? 0) === 1;
$actorId = (int)($_SESSION['user_id'] ?? 0);

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (empty($_SESSION['bt_csrf'])) {
    $_SESSION['bt_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['bt_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = ['', 'success'];
    if (!$canApply) {
        $msg = ['Only reviewers can correct business types.', 'error'];
    } elseif (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $msg = ['Your session expired. Please try again.', 'error'];
    } else {
        try {
            $changed = 0;
            $groups = 0;
            if (($_POST['action'] ?? '') === 'one') {
                // One client with no business type.
                $uid = (int)($_POST['user_id'] ?? 0);
                $type = (string)($_POST['type'][$uid] ?? '');
                if ($uid > 0 && $type !== '' && bt_set_type($conn, $uid, $type, $actorId) !== null) {
                    $changed = 1;
                    $groups = 1;
                }
            } else {
                $picked = is_array($_POST['apply'] ?? null) ? $_POST['apply'] : [];
                $types = is_array($_POST['type'] ?? null) ? $_POST['type'] : [];
                // Check every ticked row before changing anything.
                $todo = [];
                foreach ($picked as $i => $key) {
                    $type = is_string($types[$i] ?? null) ? $types[$i] : '';
                    if (!is_string($key) || $key === '') {
                        continue;
                    }
                    if (!isset(loan_assessment_scores()[$type])) {
                        throw new DomainException('Choose a business type for every ticked row ("' . $key . '" has none). Nothing was changed.');
                    }
                    $todo[$key] = $type;
                }
                foreach ($todo as $key => $type) {
                    $changed += bt_apply_group($conn, $key, $type, $actorId);
                    $groups++;
                }
            }
            $msg = $changed > 0
                ? ["Corrected {$changed} client" . ($changed === 1 ? '' : 's') . " ({$groups} business type" . ($groups === 1 ? '' : 's') . '). Their credit scores were recalculated; loan limits were not changed.', 'success']
                : ['Tick at least one row and choose a business type for it.', 'error'];
        } catch (DomainException $e) {
            $msg = [$e->getMessage(), 'error'];
        } catch (Throwable $e) {
            error_log('Business type correction: ' . $e->getMessage());
            $msg = ['Something went wrong. Check the server log.', 'error'];
        }
    }
    $_SESSION['bt_flash'] = $msg;
    $backTab = in_array($_POST['tab'] ?? '', ['fix', 'blank', 'history'], true) ? $_POST['tab'] : 'fix';
    header('Location: business_types.php?tab=' . $backTab, true, 303);
    exit;
}


$data = bt_unscored($conn);
$groups = $data['groups'];
$blank = $data['blank'];
$total = array_sum(array_column($groups, 'count'));
[$flash, $flashType] = $_SESSION['bt_flash'] ?? ['', ''];
unset($_SESSION['bt_flash']);

$recent = [];
$historyTotal = 0;
try {
    bt_ensure_schema($conn);
    $historyTotal = (int)($conn->query('SELECT COUNT(*) AS c FROM business_type_corrections')->fetch_assoc()['c'] ?? 0);
    $recent = $conn->query("SELECT b.*, u.fname, u.lname, a.fname AS by_fname
        FROM business_type_corrections b
        LEFT JOIN user u ON u.id = b.user_id
        LEFT JOIN user a ON a.id = b.corrected_by
        ORDER BY b.id DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    error_log('Business type corrections log: ' . $e->getMessage());
}

$tabs = [
    'fix'     => ['To correct', 'fa-spell-check', $total],
    'blank'   => ['No business type', 'fa-question-circle', count($blank)],
    'history' => ['History', 'fa-history', $historyTotal],
];
$tab = (string)($_GET['tab'] ?? 'fix');
if (!isset($tabs[$tab])) {
    $tab = 'fix';
}

/** Dropdown of scoring table types. */
function bt_select(string $name, ?string $selected, bool $disabled): string
{
    $html = '<select name="' . h($name) . '" class="bt-select"' . ($disabled ? ' disabled' : '') . '><option value="">Choose...</option>';
    foreach (loan_assessment_scores() as $type => $score) {
        $html .= '<option value="' . h($type) . '"' . ($type === $selected ? ' selected' : '') . '>' . h($type) . ' (score ' . h(bt_fmt((float)$score)) . ')</option>';
    }
    return $html . '</select>';
}

require 'nav-head.php';
?>
<style>
.bt-page { padding: 1rem; }
.bt-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.1rem; margin-bottom: 1rem; }
.bt-card h5 { font-weight: 800; margin: 0 0 .35rem; }
.bt-muted { color: #64748b; font-size: .86rem; }
.bt-flash { padding: .7rem 1rem; border-radius: 10px; margin-bottom: 1rem; }
.bt-flash.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.bt-flash.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.bt-tabs { display: flex; gap: .25rem; flex-wrap: wrap; border-bottom: 1px solid #e5e7eb; margin: -.2rem 0 1rem; }
.bt-tab { display: inline-flex; align-items: center; gap: .45rem; padding: .65rem .9rem; color: #475569; font-weight: 700; font-size: .88rem; text-decoration: none; border-bottom: 3px solid transparent; border-radius: 10px 10px 0 0; }
.bt-tab:hover { background: #f8fafc; color: #0f172a; }
.bt-tab.active { color: var(--primary, #123299); border-bottom-color: var(--primary, #123299); background: #f1f5ff; }
.bt-count { background: #e2e8f0; color: #334155; border-radius: 999px; font-size: .72rem; padding: .05rem .5rem; }
.bt-tab.active .bt-count { background: var(--primary, #123299); color: #fff; }
.bt-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.bt-table th { text-align: left; font-size: .74rem; text-transform: uppercase; letter-spacing: .03em; color: #64748b; padding: .5rem .6rem; border-bottom: 1px solid #e5e7eb; }
.bt-table td { padding: .55rem .6rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.bt-wrap { overflow-x: auto; }
.bt-select { border: 1px solid #d1d5db; border-radius: 8px; padding: .35rem .5rem; max-width: 260px; width: 100%; }
.bt-tag { font-size: .72rem; font-weight: 700; border-radius: 999px; padding: .12rem .55rem; white-space: nowrap; }
.bt-tag.spelling { background: #dcfce7; color: #166534; }
.bt-tag.keyword { background: #fef3c7; color: #92400e; }
.bt-tag.none { background: #f1f5f9; color: #475569; }
.bt-toolbar { display: flex; gap: .6rem; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: .6rem; }
.bt-search { border: 1px solid #d1d5db; border-radius: 8px; padding: .4rem .65rem; min-width: 220px; }
.bt-empty { text-align: center; padding: 2rem 1rem; color: #64748b; }
.bt-empty i { display: block; font-size: 1.8rem; margin-bottom: .5rem; color: #16a34a; }
</style>

<div class="bt-page">
    <?php if ($flash !== ''): ?><div class="bt-flash <?= h($flashType) ?>" role="status"><?= h($flash) ?></div><?php endif; ?>

    <div class="bt-card">
        <h5><i class="fas fa-tags me-2"></i>Business types</h5>
        <p class="bt-muted" style="margin:0">
            A business type that is not in the scoring table (System &gt; Manage Sectors) scores <?= (int)LOAN_ASSESSMENT_DEFAULT_SCORE ?>, the lowest.
            Correct them here. Each client's credit score is recalculated; loan limits are not changed, and every change is kept in History.
        </p>
    </div>

    <div class="bt-card">
        <nav class="bt-tabs" aria-label="Business type groups">
            <?php foreach ($tabs as $key => [$label, $icon, $count]): ?>
                <a class="bt-tab<?= $key === $tab ? ' active' : '' ?>" href="business_types.php?tab=<?= h($key) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>>
                    <i class="fas <?= h($icon) ?>"></i><?= h($label) ?> <span class="bt-count"><?= number_format($count) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

<?php if ($tab === 'fix'): ?>
        <?php if (!$groups): ?>
            <div class="bt-empty"><i class="fas fa-check-circle"></i>Every business type that was given is in the scoring table.</div>
        <?php else: ?>
            <p class="bt-muted">
                <?= number_format($total) ?> client<?= $total === 1 ? '' : 's' ?> in <?= count($groups) ?> group<?= count($groups) === 1 ? '' : 's' ?>.
                <span class="bt-tag spelling">Spelling</span> fixes are ticked for you.
                <span class="bt-tag keyword">Guess</span> matches come from a word in the text: check them before ticking.
            </p>
            <form method="post" data-ns-confirm="Correct the business type of every client in the ticked rows? Their credit scores will be recalculated.">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="groups">
                <input type="hidden" name="tab" value="fix">
                <div class="bt-toolbar">
                    <input type="search" class="bt-search" data-bt-filter="btFix" placeholder="Search typed business types..." aria-label="Search business types">
                    <?php if ($canApply): ?>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Apply ticked corrections</button>
                    <?php endif; ?>
                </div>
                <div class="bt-wrap">
                    <table class="bt-table" id="btFix">
                        <thead><tr><th></th><th>Typed as</th><th>Clients</th><th>Suggestion</th><th>Correct to</th></tr></thead>
                        <tbody>
                        <?php foreach ($groups as $i => $g): $s = $g['suggestion']; ?>
                            <tr>
                                <td><input type="checkbox" name="apply[<?= $i ?>]" value="<?= h($g['key']) ?>" aria-label="Correct <?= h($g['label']) ?>"
                                    <?= ($s && $s['how'] === 'spelling') ? 'checked' : '' ?> <?= $canApply ? '' : 'disabled' ?>></td>
                                <td><strong><?= h($g['label']) ?></strong></td>
                                <td><?= (int)$g['count'] ?></td>
                                <td>
                                    <?php if ($s): ?>
                                        <span class="bt-tag <?= h($s['how']) ?>"><?= $s['how'] === 'spelling' ? 'Spelling' : 'Guess' ?></span>
                                        <?= h($s['name']) ?> (score <?= h(bt_fmt((float)$s['score'])) ?>)
                                    <?php else: ?>
                                        <span class="bt-tag none">No match</span> choose one
                                    <?php endif; ?>
                                </td>
                                <td><?= bt_select("type[$i]", $s['name'] ?? null, !$canApply) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$canApply): ?>
                    <p class="bt-muted" style="margin:.8rem 0 0">Only reviewers can apply corrections.</p>
                <?php endif; ?>
            </form>
        <?php endif; ?>

<?php elseif ($tab === 'blank'): ?>
        <?php if (!$blank): ?>
            <div class="bt-empty"><i class="fas fa-check-circle"></i>Every client has a business type.</div>
        <?php else: ?>
            <p class="bt-muted">These clients score <?= (int)LOAN_ASSESSMENT_DEFAULT_SCORE ?>. Each business is different, so set them one at a time.</p>
            <div class="bt-toolbar">
                <input type="search" class="bt-search" data-bt-filter="btBlank" placeholder="Search clients..." aria-label="Search clients">
            </div>
            <div class="bt-wrap">
                <table class="bt-table" id="btBlank">
                    <thead><tr><th>Client</th><th>Business type</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($blank, 0, 200) as $b): $uid = (int)$b['user_id']; ?>
                        <tr>
                            <td><a href="view_client.php?user_id=<?= $uid ?>"><?= h(trim($b['fname'] . ' ' . $b['lname'])) ?></a> <span class="bt-muted">#<?= $uid ?></span></td>
                            <td>
                                <form method="post" style="display:flex;gap:.4rem;align-items:center;margin:0">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="one">
                                    <input type="hidden" name="tab" value="blank">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <?= bt_select("type[$uid]", null, !$canApply) ?>
                                    <?php if ($canApply): ?><button type="submit" class="btn btn-secondary btn-sm">Save</button><?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($blank) > 200): ?><p class="bt-muted" style="margin:.6rem 0 0">Showing the newest 200 of <?= number_format(count($blank)) ?>.</p><?php endif; ?>
        <?php endif; ?>

<?php else: ?>
        <?php if (!$recent): ?>
            <div class="bt-empty"><i class="fas fa-history" style="color:#94a3b8"></i>No corrections yet.</div>
        <?php else: ?>
            <div class="bt-toolbar">
                <input type="search" class="bt-search" data-bt-filter="btHistory" placeholder="Search history..." aria-label="Search history">
                <?php if ($historyTotal > count($recent)): ?><span class="bt-muted">Newest <?= count($recent) ?> of <?= number_format($historyTotal) ?></span><?php endif; ?>
            </div>
            <div class="bt-wrap">
                <table class="bt-table" id="btHistory">
                    <thead><tr><th>When</th><th>Client</th><th>From</th><th>To</th><th>Score</th><th>By</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td><?= h(date('d M Y H:i', strtotime((string)$r['created_at']))) ?></td>
                            <td><a href="view_client.php?user_id=<?= (int)$r['user_id'] ?>"><?= h(trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''))) ?></a></td>
                            <td><?= h($r['old_type'] !== '' ? $r['old_type'] : '(none)') ?></td>
                            <td><?= h($r['new_type']) ?></td>
                            <td><?= $r['old_score'] !== null ? h(bt_fmt((float)$r['old_score'])) : '-' ?> &rarr; <?= $r['new_score'] !== null ? h(bt_fmt((float)$r['new_score'])) : '-' ?></td>
                            <td><?= h($r['by_fname'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
<?php endif; ?>
    </div>
</div>
<script>
document.querySelectorAll('[data-bt-filter]').forEach(function (input) {
    var table = document.getElementById(input.getAttribute('data-bt-filter'));
    if (!table) return;
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        table.querySelectorAll('tbody tr').forEach(function (row) {
            row.style.display = !q || row.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
        });
    });
});
// Apply needs a choice in every ticked row. Checked on window so it runs
// before the confirmation popup (which listens on document).
window.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('form[data-ns-confirm]') || !form.querySelector('input[name^="apply["]')) return;
    (function () {
        var ticked = form.querySelectorAll('input[name^="apply["]:checked');
        var missing = Array.prototype.some.call(ticked, function (box) {
            var sel = box.closest('tr').querySelector('select');
            return !sel || !sel.value;
        });
        if (!ticked.length || missing) {
            e.preventDefault();
            e.stopImmediatePropagation();
            Popup.alert(!ticked.length ? 'Tick at least one row to correct.' : 'Choose a business type for every ticked row.');
        }
    })();
}, true);
</script>
<?php require 'footer.php'; ?>
