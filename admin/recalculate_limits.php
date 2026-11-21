<?php
declare(strict_types=1);

/*
 * Recalculate customer loan limits with the shared loan assessment
 * (includes/loan_assessment.php), the same formula used by the app, the
 * website, admin add client, import and the AI recommended amount.
 *
 * Tabs:
 *   higher      saved limit is above the formula  (the risky ones)
 *   lower       saved limit is below the formula
 *   score       limit matches but the saved score does not
 *   noturnover  has a limit but no turnover, so the formula cannot be applied
 *
 * Reviewers (role 1) can update the ticked rows, or every row in a tab
 * (respecting the search). Values are always recalculated on the server.
 * Clients without turnover are never changed: a 0 limit means "no limit"
 * on the website.
 */

$phead = 'Recalculate Loan Limits';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/loan_assessment.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? null, ['1', '2', 1, 2], true)) {
    header('Location: ../login.php');
    exit();
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$canApply = ((int)($_SESSION['role'] ?? 0) === 1);
if (empty($_SESSION['limit_recalc_csrf'])) {
    $_SESSION['limit_recalc_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['limit_recalc_csrf'];

const RL_TABS = [
    'higher'     => ['Limit too high', 'fa-arrow-up', 'Saved limit is above the formula. These clients can borrow more than their turnover supports.'],
    'lower'      => ['Limit too low', 'fa-arrow-down', 'Saved limit is below the formula. Some may be deliberate, so check before updating.'],
    'score'      => ['Score only', 'fa-star-half-alt', 'Limit is right but the saved score is missing, 0 or on a different scale.'],
    'noturnover' => ['No turnover', 'fa-ban', 'These clients have a limit but no turnover on file, so the formula cannot be applied. Add their turnover on the client profile first.'],
];
const RL_PER_PAGE = [10, 25, 50, 100];

function rl_money(float $v): string
{
    return 'UGX ' . number_format($v);
}

function rl_load(mysqli $conn, ?array $onlyIds = null): array
{
    $sql = "
        SELECT c.user_id, u.fname, u.lname, u.email, c.phone,
               c.business_type, c.amount_last, c.amount_this,
               c.credit_score, c.loan_limit
        FROM customers c
        INNER JOIN user u ON u.id = c.user_id
    ";
    if ($onlyIds !== null) {
        if (!$onlyIds) {
            return [];
        }
        $sql .= ' WHERE c.user_id IN (' . implode(',', array_map('intval', $onlyIds)) . ')';
    }
    $sql .= ' ORDER BY c.user_id DESC';

    $rows = [];
    $res = $conn->query($sql);
    while ($res && ($r = $res->fetch_assoc())) {
        // Includes approved credit documents (verified income, document scores).
        $r['calc'] = loan_assessment_limit(
            (string)($r['business_type'] ?? ''),
            (string)($r['amount_last'] ?? ''),
            (string)($r['amount_this'] ?? ''),
            (int)$r['user_id']
        );
        $r['saved_limit'] = (float)($r['loan_limit'] ?? 0);
        $r['saved_score'] = is_numeric($r['credit_score'] ?? null) ? (float)$r['credit_score'] : null;
        $r['tab'] = rl_tab($r);
        $rows[] = $r;
    }
    return $rows;
}

/** Which tab a client belongs in, or null when everything matches. */
function rl_tab(array $r): ?string
{
    if (!$r['calc']['has_turnover']) {
        return $r['saved_limit'] > 0 ? 'noturnover' : null;
    }
    $formula = (float)$r['calc']['limit'];
    $tolerance = max(1000, $formula * 0.01);
    if ($r['saved_limit'] - $formula > $tolerance) {
        return 'higher';
    }
    if ($formula - $r['saved_limit'] > $tolerance) {
        return 'lower';
    }
    if ($r['saved_score'] === null || abs($r['saved_score'] - $r['calc']['score']) >= 0.5) {
        return 'score';
    }
    return null;
}

function rl_search(array $rows, string $search): array
{
    if ($search === '') {
        return $rows;
    }
    $needle = strtolower($search);
    return array_values(array_filter($rows, static function (array $r) use ($needle): bool {
        $hay = strtolower(implode(' ', [
            $r['fname'] ?? '', $r['lname'] ?? '', $r['email'] ?? '',
            $r['phone'] ?? '', $r['business_type'] ?? '', (string)$r['user_id'],
        ]));
        return str_contains($hay, $needle);
    }));
}

function rl_url(array $params): string
{
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return 'recalculate_limits.php' . ($params ? '?' . http_build_query($params) : '');
}

/* ---------- Read the view state ---------- */
$search = trim((string)($_GET['q'] ?? ''));
$tab = (string)($_GET['tab'] ?? 'higher');
if (!isset(RL_TABS[$tab])) {
    $tab = 'higher';
}
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, RL_PER_PAGE, true)) {
    $perPage = 25;
}
$page = max(1, (int)($_GET['page'] ?? 1));

/* ---------- Apply ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!$canApply) {
        $_SESSION['message'] = 'Only reviewers can change loan limits.';
        $_SESSION['message_type'] = 'error';
    } elseif (!hash_equals($csrf, $postedCsrf)) {
        $_SESSION['message'] = 'Your session expired. Please try again.';
        $_SESSION['message_type'] = 'error';
    } elseif (($_POST['action'] ?? '') === 'fix_scores') {
        // Only the score: hand-set loan limits stay as they are.
        $fixed = 0;
        $stmt = $conn->prepare('UPDATE customers SET credit_score = ? WHERE user_id = ? AND (credit_score IS NULL OR credit_score = 0)');
        foreach (rl_load($conn) as $r) {
            if ($r['saved_score'] !== null && $r['saved_score'] > 0) {
                continue;
            }
            $score = round((float)$r['calc']['score'], 1);
            $uid = (int)$r['user_id'];
            if ($score <= 0) {
                continue;
            }
            $stmt->bind_param('di', $score, $uid);
            $stmt->execute();
            $fixed += $stmt->affected_rows;
        }
        $stmt->close();
        $_SESSION['message'] = $fixed > 0
            ? "Saved a credit score for {$fixed} client" . ($fixed === 1 ? '' : 's') . '. Their loan limits were not changed.'
            : 'Every client already has a credit score.';
        $_SESSION['message_type'] = $fixed > 0 ? 'success' : 'error';
    } elseif ($tab === 'noturnover') {
        $_SESSION['message'] = 'Clients without turnover cannot be recalculated. Add their turnover first.';
        $_SESSION['message_type'] = 'error';
    } else {
        if (($_POST['scope'] ?? '') === 'all') {
            // Every client in this tab that matches the current search.
            $targets = array_filter(rl_search(rl_load($conn), $search), static fn($r) => $r['tab'] === $tab);
        } else {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
            $targets = rl_load($conn, $ids);
        }

        $updated = 0;
        $stmt = $conn->prepare("UPDATE customers SET loan_limit = ?, credit_score = ? WHERE user_id = ?");
        foreach ($targets as $r) {
            if (!$r['calc']['has_turnover']) {
                continue; // never write a 0 limit
            }
            $limit = (float)$r['calc']['limit'];
            $score = round((float)$r['calc']['score'], 1);
            $uid = (int)$r['user_id'];
            $stmt->bind_param('ddi', $limit, $score, $uid);
            if ($stmt->execute()) {
                $updated++;
            }
        }
        $stmt->close();

        $_SESSION['message'] = $updated > 0
            ? "Updated {$updated} client" . ($updated === 1 ? '' : 's') . ' to the formula limit.'
            : 'No clients were selected.';
        $_SESSION['message_type'] = $updated > 0 ? 'success' : 'error';
    }
    header('Location: ' . rl_url(['tab' => $tab, 'q' => $search, 'per_page' => $perPage]));
    exit;
}

/* ---------- Build the list ---------- */
$rows = rl_search(rl_load($conn), $search);
$counts = array_fill_keys(array_keys(RL_TABS), 0);
foreach ($rows as $r) {
    if ($r['tab'] !== null) {
        $counts[$r['tab']]++;
    }
}
$missingScores = count(array_filter(rl_load($conn), static fn($r) => $r['saved_score'] === null || $r['saved_score'] <= 0));
$tabRows = array_values(array_filter($rows, static fn($r) => $r['tab'] === $tab));
$total = count($tabRows);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$pageRows = array_slice($tabRows, ($page - 1) * $perPage, $perPage);
$from = $total ? ($page - 1) * $perPage + 1 : 0;
$to = min($total, $page * $perPage);
$canUpdateTab = $canApply && $tab !== 'noturnover';

$flash = $_SESSION['message'] ?? '';
$flashType = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);

require "nav-head.php";
?>

<style>
.rl-page { padding: 1rem; }
.rl-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.1rem; margin-bottom: 1rem; }
.rl-card h5 { font-weight: 800; margin: 0 0 .25rem; }
.rl-tabs { display: flex; gap: .5rem; flex-wrap: wrap; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem; }
.rl-tab { display: inline-flex; align-items: center; gap: .45rem; padding: .65rem .9rem; border-radius: 10px 10px 0 0; color: #475569; font-weight: 700; text-decoration: none; border-bottom: 3px solid transparent; }
.rl-tab:hover { background: #f8fafc; color: #1e293b; }
.rl-tab.active { color: #1d4ed8; border-bottom-color: #2563eb; background: #eff6ff; }
.rl-count { background: #e2e8f0; color: #334155; border-radius: 999px; padding: 0 .5rem; font-size: .75rem; }
.rl-tab.active .rl-count { background: #2563eb; color: #fff; }
.rl-tab.tab-higher .rl-count { background: #fee2e2; color: #b91c1c; }
.rl-tab.tab-higher.active .rl-count { background: #dc2626; color: #fff; }
.rl-toolbar { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; justify-content: space-between; margin-bottom: .75rem; }
.rl-search { display: flex; gap: .5rem; flex-wrap: wrap; flex: 1; min-width: 260px; }
.rl-search input { flex: 1; min-width: 200px; border: 1px solid #d1d5db; border-radius: 10px; padding: .55rem .8rem; }
.rl-search select { border: 1px solid #d1d5db; border-radius: 10px; padding: .45rem .6rem; }
.rl-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.rl-table th { background: #f8fafc; color: #475569; font-size: .74rem; text-transform: uppercase; padding: .6rem; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
.rl-table td { padding: .6rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.rl-up { color: #b91c1c; font-weight: 700; }
.rl-down { color: #92400e; font-weight: 700; }
.rl-new { color: #1d4ed8; font-weight: 800; }
.rl-flash { padding: .7rem 1rem; border-radius: 10px; margin-bottom: 1rem; }
.rl-flash.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.rl-flash.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.rl-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
.rl-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .75rem; margin-top: 1rem; }
.rl-pages { display: flex; gap: .3rem; flex-wrap: wrap; list-style: none; margin: 0; padding: 0; }
.rl-pages a, .rl-pages span { display: inline-block; min-width: 2.1rem; text-align: center; padding: .35rem .6rem; border: 1px solid #e2e8f0; border-radius: 8px; color: #334155; text-decoration: none; font-size: .85rem; }
.rl-pages a:hover { background: #f1f5f9; }
.rl-pages .current { background: #2563eb; border-color: #2563eb; color: #fff; font-weight: 700; }
.rl-pages .disabled { color: #cbd5e1; }
.rl-empty { padding: 2rem 1rem; text-align: center; color: #64748b; }
</style>

<div class="container-fluid rl-page">

    <?php if ($flash !== ''): ?>
        <div class="rl-flash <?= h($flashType) ?>"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="rl-card">
        <h5><i class="fas fa-calculator text-primary me-2"></i>Recalculate Loan Limits</h5>
        <p class="text-muted mb-0">
            One formula for app, website and admin: average turnover + average &times; (business score &divide; 10).
            Business types not in the scoring table score 3.
        </p>
    </div>

    <?php if ($missingScores > 0): ?>
        <div class="rl-card" style="border-color:#fde68a;background:#fffbeb">
            <h5><i class="fas fa-exclamation-triangle me-2" style="color:#b45309"></i><?= number_format($missingScores) ?> client<?= $missingScores === 1 ? ' has' : 's have' ?> no credit score saved (it shows as 0/10)</h5>
            <p class="text-muted">This saves each one's current score from their business type, documents and repayment history. Their loan limits are not changed.</p>
            <?php if ($canApply): ?>
                <form method="post" onsubmit="return confirm('Save a credit score for <?= (int)$missingScores ?> client(s)? Loan limits will not change.');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="fix_scores">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-wrench me-1"></i>Fix missing scores</button>
                </form>
            <?php else: ?>
                <p class="text-muted mb-0">Only reviewers can apply changes.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="rl-card">
        <nav class="rl-tabs" aria-label="Limit groups">
            <?php foreach (RL_TABS as $key => [$label, $icon]): ?>
                <a class="rl-tab tab-<?= h($key) ?><?= $key === $tab ? ' active' : '' ?>"
                   href="<?= h(rl_url(['tab' => $key, 'q' => $search, 'per_page' => $perPage])) ?>">
                    <i class="fas <?= h($icon) ?>"></i> <?= h($label) ?>
                    <span class="rl-count"><?= number_format($counts[$key]) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <p class="text-muted"><?= h(RL_TABS[$tab][2]) ?></p>

        <div class="rl-toolbar">
            <form method="get" class="rl-search">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search name, email, phone, business type or client ID">
                <select name="per_page" aria-label="Rows per page" onchange="this.form.submit()">
                    <?php foreach (RL_PER_PAGE as $n): ?>
                        <option value="<?= $n ?>" <?= $n === $perPage ? 'selected' : '' ?>><?= $n ?> per page</option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-secondary btn-sm" type="submit"><i class="fas fa-search me-1"></i>Search</button>
                <?php if ($search !== ''): ?>
                    <a class="btn btn-light btn-sm" href="<?= h(rl_url(['tab' => $tab, 'per_page' => $perPage])) ?>">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($search !== ''): ?>
            <p class="text-muted fs-sm">
                Results for "<?= h($search) ?>". Tab counts include only matching clients.
            </p>
        <?php endif; ?>

        <?php if (!$pageRows): ?>
            <div class="rl-empty">
                <i class="fas fa-check-circle fa-2x mb-2" style="color:#16a34a"></i>
                <div>No clients in this tab<?= $search !== '' ? ' match your search' : '' ?>.</div>
            </div>
        <?php else: ?>
            <form method="post" id="rlForm"
                  action="<?= h(rl_url(['tab' => $tab, 'q' => $search, 'per_page' => $perPage])) ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="scope" id="rlScope" value="selected">

                <div class="table-responsive">
                    <table class="rl-table" data-no-paginate>
                        <thead>
                        <tr>
                            <?php if ($canUpdateTab): ?>
                                <th><input type="checkbox" id="rlAll" aria-label="Select all on this page"></th>
                            <?php endif; ?>
                            <th>Client</th>
                            <th>Business type</th>
                            <th>Turnover (last / this)</th>
                            <th>Score (saved / formula)</th>
                            <th>Saved limit</th>
                            <?php if ($tab !== 'noturnover'): ?>
                                <th>Formula limit</th>
                            <?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pageRows as $r): ?>
                            <?php $name = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? '')); ?>
                            <tr>
                                <?php if ($canUpdateTab): ?>
                                    <td><input type="checkbox" class="rl-pick" name="ids[]" value="<?= (int)$r['user_id'] ?>" <?= $tab === 'higher' ? 'checked' : '' ?>></td>
                                <?php endif; ?>
                                <td>
                                    <strong><?= h($name !== '' ? $name : 'Client #' . $r['user_id']) ?></strong>
                                    <div class="text-muted"><?= h($r['email'] ?? '') ?></div>
                                    <a class="fs-xs" href="view_client.php?user_id=<?= (int)$r['user_id'] ?>">Open profile</a>
                                </td>
                                <td><?= h(($r['business_type'] ?? '') !== '' ? $r['business_type'] : 'Not given') ?></td>
                                <td><?= h(loan_assessment_turnover((string)$r['amount_last'])) ?><br><?= h(loan_assessment_turnover((string)$r['amount_this'])) ?></td>
                                <td><?= $r['saved_score'] !== null ? h(loan_assessment_num($r['saved_score'])) : 'None' ?> / <?= (int)$r['calc']['score'] ?></td>
                                <td class="<?= $tab === 'higher' ? 'rl-up' : ($tab === 'lower' ? 'rl-down' : '') ?>"><?= rl_money($r['saved_limit']) ?></td>
                                <?php if ($tab !== 'noturnover'): ?>
                                    <td class="rl-new"><?= rl_money((float)$r['calc']['limit']) ?>
                                        <?php if (!empty($r['calc']['limit_note'])): ?><div style="font-size:.75rem;font-weight:500;color:#b45309;max-width:220px"><?= h($r['calc']['limit_note']) ?></div><?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($canUpdateTab): ?>
                    <div class="rl-actions">
                        <button type="submit" class="btn btn-primary" data-scope="selected">
                            <i class="fas fa-check me-2"></i>Update ticked clients
                        </button>
                        <button type="submit" class="btn btn-outline-primary" data-scope="all"
                                data-confirm="Set all <?= number_format($total) ?> client<?= $total === 1 ? '' : 's' ?> in &quot;<?= h(RL_TABS[$tab][0]) ?>&quot;<?= $search !== '' ? ' matching your search' : '' ?> to the formula limit?">
                            <i class="fas fa-layer-group me-2"></i>Update all <?= number_format($total) ?> in this tab
                        </button>
                    </div>
                <?php elseif (!$canApply && $tab !== 'noturnover'): ?>
                    <p class="text-muted mt-3 mb-0">Only reviewers can apply changes.</p>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($total > 0): ?>
            <div class="rl-footer">
                <div class="text-muted fs-sm">Showing <?= number_format($from) ?>-<?= number_format($to) ?> of <?= number_format($total) ?></div>
                <?php if ($totalPages > 1): ?>
                    <ul class="rl-pages" aria-label="Pages">
                        <?php $base = ['tab' => $tab, 'q' => $search, 'per_page' => $perPage]; ?>
                        <li><?php if ($page > 1): ?><a href="<?= h(rl_url($base + ['page' => $page - 1])) ?>">Prev</a><?php else: ?><span class="disabled">Prev</span><?php endif; ?></li>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        if ($start > 1): ?>
                            <li><a href="<?= h(rl_url($base + ['page' => 1])) ?>">1</a></li>
                            <?php if ($start > 2): ?><li><span class="disabled">...</span></li><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($p = $start; $p <= $end; $p++): ?>
                            <li><?php if ($p === $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="<?= h(rl_url($base + ['page' => $p])) ?>"><?= $p ?></a><?php endif; ?></li>
                        <?php endfor; ?>
                        <?php if ($end < $totalPages): ?>
                            <?php if ($end < $totalPages - 1): ?><li><span class="disabled">...</span></li><?php endif; ?>
                            <li><a href="<?= h(rl_url($base + ['page' => $totalPages])) ?>"><?= $totalPages ?></a></li>
                        <?php endif; ?>
                        <li><?php if ($page < $totalPages): ?><a href="<?= h(rl_url($base + ['page' => $page + 1])) ?>">Next</a><?php else: ?><span class="disabled">Next</span><?php endif; ?></li>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('rlForm');
    if (!form) return;
    var all = document.getElementById('rlAll');
    if (all) {
        all.addEventListener('change', function () {
            form.querySelectorAll('.rl-pick').forEach(function (cb) { cb.checked = all.checked; });
        });
    }
    form.querySelectorAll('button[data-scope]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            var scope = btn.getAttribute('data-scope');
            if (scope === 'selected' && !form.querySelector('.rl-pick:checked')) {
                e.preventDefault();
                return;
            }
            if (scope === 'all' && btn.dataset.ok !== '1') {
                e.preventDefault();
                Popup.confirm(btn.getAttribute('data-confirm')).then(function (ok) {
                    if (!ok) return;
                    btn.dataset.ok = '1';
                    btn.click();
                });
                return;
            }
            delete btn.dataset.ok;
            document.getElementById('rlScope').value = scope;
        });
    });
})();
</script>

<?php require "footer.php"; ?>
