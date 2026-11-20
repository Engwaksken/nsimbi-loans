<?php
declare(strict_types=1);

/*
 * Recalculate customer loan limits with the shared loan assessment
 * (includes/loan_assessment.php), the same formula used by the app, the
 * website, admin add client, import and the AI recommended amount.
 *
 * Lists every customer whose saved limit or score differs from the formula.
 * Reviewers tick the rows to correct and apply. Clients without turnover are
 * never changed: a 0 limit means "no limit" on the website.
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
        $calc = loan_assessment_limit(
            (string)($r['business_type'] ?? ''),
            (string)($r['amount_last'] ?? ''),
            (string)($r['amount_this'] ?? '')
        );
        $r['calc'] = $calc;
        $r['saved_limit'] = (float)($r['loan_limit'] ?? 0);
        $r['saved_score'] = is_numeric($r['credit_score'] ?? null) ? (float)$r['credit_score'] : null;
        $rows[] = $r;
    }
    return $rows;
}

function rl_differs(array $r): bool
{
    $limitDiff = abs($r['saved_limit'] - $r['calc']['limit']) > max(1000, $r['calc']['limit'] * 0.01);
    $scoreDiff = $r['saved_score'] === null || abs($r['saved_score'] - $r['calc']['score']) >= 0.5;
    return $limitDiff || $scoreDiff;
}

/* ---------- Apply ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!$canApply) {
        $_SESSION['message'] = 'Only reviewers can change loan limits.';
        $_SESSION['message_type'] = 'error';
    } elseif (!hash_equals($csrf, $postedCsrf)) {
        $_SESSION['message'] = 'Your session expired. Please try again.';
        $_SESSION['message_type'] = 'error';
    } else {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        $updated = 0;
        $stmt = $conn->prepare("UPDATE customers SET loan_limit = ?, credit_score = ? WHERE user_id = ?");
        foreach (rl_load($conn, $ids) as $r) {
            if (!$r['calc']['has_turnover']) {
                continue; // never write a 0 limit
            }
            $limit = (float)$r['calc']['limit'];
            $score = (int)$r['calc']['score'];
            $uid = (int)$r['user_id'];
            $stmt->bind_param('dii', $limit, $score, $uid);
            if ($stmt->execute()) {
                $updated++;
            }
        }
        $stmt->close();
        $_SESSION['message'] = "Updated {$updated} client" . ($updated === 1 ? '' : 's') . ' to the formula limit.';
        $_SESSION['message_type'] = 'success';
    }
    header('Location: recalculate_limits.php' . (isset($_GET['q']) ? '?q=' . urlencode((string)$_GET['q']) : ''));
    exit;
}

/* ---------- List ---------- */
require "nav-head.php";

$q = trim((string)($_GET['q'] ?? ''));
$all = rl_load($conn);
if ($q !== '') {
    $all = array_values(array_filter($all, static function (array $r) use ($q): bool {
        $hay = strtolower(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? '') . ' ' . ($r['email'] ?? '') . ' ' . ($r['phone'] ?? ''));
        return str_contains($hay, strtolower($q));
    }));
}

$mismatched = [];
$noTurnover = [];
foreach ($all as $r) {
    if (!$r['calc']['has_turnover']) {
        if ($r['saved_limit'] > 0) {
            $noTurnover[] = $r;
        }
        continue;
    }
    if (rl_differs($r)) {
        $mismatched[] = $r;
    }
}
$flash = $_SESSION['message'] ?? '';
$flashType = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);
?>

<style>
.rl-page { padding: 1rem; }
.rl-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.1rem; margin-bottom: 1rem; }
.rl-card h5 { font-weight: 800; margin: 0 0 .25rem; }
.rl-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.rl-table th { background: #f8fafc; color: #475569; font-size: .74rem; text-transform: uppercase; padding: .6rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
.rl-table td { padding: .6rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.rl-up { color: #b91c1c; font-weight: 700; }
.rl-down { color: #92400e; font-weight: 700; }
.rl-new { color: #1d4ed8; font-weight: 800; }
.rl-flash { padding: .7rem 1rem; border-radius: 10px; margin-bottom: 1rem; }
.rl-flash.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.rl-flash.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.rl-search { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .75rem; }
.rl-search input { flex: 1; min-width: 220px; border: 1px solid #d1d5db; border-radius: 10px; padding: .55rem .8rem; }
</style>

<div class="container-fluid rl-page">

    <?php if ($flash !== ''): ?>
        <div class="rl-flash <?= h($flashType) ?>"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="rl-card">
        <h5><i class="fas fa-calculator text-primary me-2"></i>Recalculate Loan Limits</h5>
        <p class="text-muted mb-0">
            One formula for app, website and admin: average turnover + average &times; (business score &divide; 10).
            Business types not in the scoring table score 3. Clients below have a saved limit or score that does
            not match it. Rows where the saved limit is higher are ticked.
        </p>
        <form method="get" class="rl-search">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by name, email or phone">
            <button class="btn btn-secondary btn-sm" type="submit">Search</button>
            <?php if ($q !== ''): ?><a class="btn btn-light btn-sm" href="recalculate_limits.php">Clear</a><?php endif; ?>
        </form>
    </div>

    <div class="rl-card">
        <h5><?= count($mismatched) ?> client<?= count($mismatched) === 1 ? '' : 's' ?> not matching the formula</h5>

        <?php if (!$mismatched): ?>
            <p class="text-muted mb-0">Every client<?= $q !== '' ? ' matching your search' : '' ?> already matches the formula.</p>
        <?php else: ?>
            <form method="post" action="recalculate_limits.php<?= $q !== '' ? '?q=' . h(urlencode($q)) : '' ?>"
                  onsubmit="return this.querySelectorAll('input[name=&quot;ids[]&quot;]:checked').length > 0;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <div class="table-responsive">
                    <table class="rl-table" data-no-paginate>
                        <thead>
                        <tr>
                            <th><input type="checkbox" id="rlAll" aria-label="Select all"></th>
                            <th>Client</th>
                            <th>Business type</th>
                            <th>Turnover (last / this)</th>
                            <th>Score (saved / formula)</th>
                            <th>Saved limit</th>
                            <th>Formula limit</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($mismatched as $r): ?>
                            <?php
                            $name = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''));
                            $higher = $r['saved_limit'] > $r['calc']['limit'];
                            ?>
                            <tr>
                                <td><input type="checkbox" name="ids[]" value="<?= (int)$r['user_id'] ?>" <?= $higher ? 'checked' : '' ?> <?= $canApply ? '' : 'disabled' ?>></td>
                                <td><strong><?= h($name !== '' ? $name : 'Client #' . $r['user_id']) ?></strong><div class="text-muted"><?= h($r['email'] ?? '') ?></div></td>
                                <td><?= h(($r['business_type'] ?? '') !== '' ? $r['business_type'] : 'Not given') ?></td>
                                <td><?= h(loan_assessment_turnover((string)$r['amount_last'])) ?><br><?= h(loan_assessment_turnover((string)$r['amount_this'])) ?></td>
                                <td><?= $r['saved_score'] !== null ? h(loan_assessment_num($r['saved_score'])) : 'None' ?> / <?= (int)$r['calc']['score'] ?></td>
                                <td class="<?= $higher ? 'rl-up' : 'rl-down' ?>"><?= rl_money($r['saved_limit']) ?></td>
                                <td class="rl-new"><?= rl_money((float)$r['calc']['limit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($canApply): ?>
                    <button type="submit" class="btn btn-primary mt-3">
                        <i class="fas fa-check me-2"></i>Set ticked clients to the formula limit
                    </button>
                <?php else: ?>
                    <p class="text-muted mt-3 mb-0">Only reviewers can apply changes.</p>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($noTurnover): ?>
        <div class="rl-card">
            <h5><?= count($noTurnover) ?> client<?= count($noTurnover) === 1 ? '' : 's' ?> with a limit but no turnover</h5>
            <p class="text-muted">The formula cannot be applied, so these are not changed here. Add their turnover on the client profile first.</p>
            <div class="table-responsive">
                <table class="rl-table" data-no-paginate>
                    <thead><tr><th>Client</th><th>Business type</th><th>Saved limit</th></tr></thead>
                    <tbody>
                    <?php foreach ($noTurnover as $r): ?>
                        <tr>
                            <td><strong><?= h(trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''))) ?></strong><div class="text-muted"><?= h($r['email'] ?? '') ?></div></td>
                            <td><?= h(($r['business_type'] ?? '') !== '' ? $r['business_type'] : 'Not given') ?></td>
                            <td><?= rl_money($r['saved_limit']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('rlAll')?.addEventListener('change', function () {
    document.querySelectorAll('input[name="ids[]"]:not(:disabled)').forEach(cb => { cb.checked = this.checked; });
});
</script>

<?php require "footer.php"; ?>
