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
    header('Location: business_types.php', true, 303);
    exit;
}

$data = bt_unscored($conn);
$groups = $data['groups'];
$blank = $data['blank'];
$total = array_sum(array_column($groups, 'count'));
[$flash, $flashType] = $_SESSION['bt_flash'] ?? ['', ''];
unset($_SESSION['bt_flash']);

$recent = [];
try {
    bt_ensure_schema($conn);
    $recent = $conn->query("SELECT b.*, u.fname, u.lname, a.fname AS by_fname
        FROM business_type_corrections b
        LEFT JOIN user u ON u.id = b.user_id
        LEFT JOIN user a ON a.id = b.corrected_by
        ORDER BY b.id DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    error_log('Business type corrections log: ' . $e->getMessage());
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
.bt-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.bt-table th { text-align: left; font-size: .74rem; text-transform: uppercase; letter-spacing: .03em; color: #64748b; padding: .5rem .6rem; border-bottom: 1px solid #e5e7eb; }
.bt-table td { padding: .55rem .6rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.bt-wrap { overflow-x: auto; }
.bt-select { border: 1px solid #d1d5db; border-radius: 8px; padding: .35rem .5rem; max-width: 260px; width: 100%; }
.bt-tag { font-size: .72rem; font-weight: 700; border-radius: 999px; padding: .12rem .55rem; white-space: nowrap; }
.bt-tag.spelling { background: #dcfce7; color: #166534; }
.bt-tag.keyword { background: #fef3c7; color: #92400e; }
.bt-tag.none { background: #f1f5f9; color: #475569; }
</style>

<div class="bt-page">
    <?php if ($flash !== ''): ?><div class="bt-flash <?= h($flashType) ?>" role="status"><?= h($flash) ?></div><?php endif; ?>

    <div class="bt-card">
        <h5><i class="fas fa-tags me-2"></i>Business types not in the scoring table</h5>
        <p class="bt-muted" style="margin:0">
            A business type that is not in the scoring table scores <?= (int)LOAN_ASSESSMENT_DEFAULT_SCORE ?>, the lowest.
            <?= number_format($total) ?> client<?= $total === 1 ? '' : 's' ?> in <?= count($groups) ?> group<?= count($groups) === 1 ? '' : 's' ?> below, plus <?= count($blank) ?> with no business type.
            Tick the corrections that are right and apply them. Each client's credit score is recalculated; loan limits are not changed.
            <strong>Green</strong> suggestions are spelling fixes and are ticked for you. <strong>Amber</strong> ones are guesses from a word in the text: check them before ticking.
        </p>
    </div>

    <div class="bt-card">
        <?php if (!$groups): ?>
            <p class="bt-muted" style="margin:0">Every business type that was given is in the scoring table.</p>
        <?php else: ?>
            <form method="post" onsubmit="return confirm('Correct the business type of every client in the ticked rows?');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="groups">
                <div class="bt-wrap">
                    <table class="bt-table">
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
                <?php if ($canApply): ?>
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.8rem"><i class="fas fa-check me-1"></i>Apply ticked corrections</button>
                <?php else: ?>
                    <p class="bt-muted" style="margin:.8rem 0 0">Only reviewers can apply corrections.</p>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($blank): ?>
    <div class="bt-card">
        <h5>No business type given (<?= count($blank) ?>)</h5>
        <p class="bt-muted">These score <?= (int)LOAN_ASSESSMENT_DEFAULT_SCORE ?>. Each business is different, so set them one at a time.</p>
        <div class="bt-wrap">
            <table class="bt-table">
                <thead><tr><th>Client</th><th>Business type</th><th></th></tr></thead>
                <tbody>
                <?php foreach (array_slice($blank, 0, 100) as $b): $uid = (int)$b['user_id']; ?>
                    <tr>
                        <td><a href="view_client.php?user_id=<?= $uid ?>"><?= h(trim($b['fname'] . ' ' . $b['lname'])) ?></a> <span class="bt-muted">#<?= $uid ?></span></td>
                        <td colspan="2">
                            <form method="post" style="display:flex;gap:.4rem;align-items:center;margin:0">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="one">
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
        <?php if (count($blank) > 100): ?><p class="bt-muted" style="margin:.6rem 0 0">Showing the newest 100.</p><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($recent): ?>
    <div class="bt-card">
        <h5>Recent corrections</h5>
        <div class="bt-wrap">
            <table class="bt-table">
                <thead><tr><th>When</th><th>Client</th><th>From</th><th>To</th><th>Score</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?= h(date('d M Y H:i', strtotime((string)$r['created_at']))) ?></td>
                        <td><a href="view_client.php?user_id=<?= (int)$r['user_id'] ?>"><?= h(trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''))) ?></a></td>
                        <td><?= h($r['old_type'] !== '' ? $r['old_type'] : '(none)') ?></td>
                        <td><?= h($r['new_type']) ?></td>
                        <td><?= $r['old_score'] !== null ? h(rtrim(rtrim((string)$r['old_score'], '0'), '.')) : '-' ?> &rarr; <?= $r['new_score'] !== null ? h(rtrim(rtrim((string)$r['new_score'], '0'), '.')) : '-' ?></td>
                        <td><?= h($r['by_fname'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require 'footer.php'; ?>
