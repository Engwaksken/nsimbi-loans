<?php
declare(strict_types=1);

/*
 * Loans > Credit Documents: review what the AI read from clients' mobile money
 * statements, utility bills and business records, then approve (the client's
 * credit score and loan limit are recalculated) or reject (the client is told
 * why). Reviewers (role 1) decide; loan officers can view.
 */

$phead = 'Credit Documents';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/credit_scoring.php';

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

$canDecide = ((int)($_SESSION['role'] ?? 0) === 1);
$reviewerId = (int)($_SESSION['user_id'] ?? 0);
if (empty($_SESSION['credit_ev_csrf'])) {
    $_SESSION['credit_ev_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['credit_ev_csrf'];

const CE_TABS = [
    'review'   => ['Waiting for review', 'fa-hourglass-half', ['analyzed']],
    'failed'   => ['Could not be read', 'fa-exclamation-triangle', ['failed', 'processing']],
    'approved' => ['Approved', 'fa-check-circle', ['approved', 'superseded']],
    'rejected' => ['Rejected', 'fa-times-circle', ['rejected', 'ignored']],
];
const CE_PER_PAGE = 10;

function ce_money($v): string
{
    return 'UGX ' . number_format((float)$v);
}

function ce_url(array $p): string
{
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return 'credit_evidence.php' . ($p ? '?' . http_build_query($p) : '');
}

credit_cleanup_maybe_run($conn);
credit_busy_retry_in_background($conn);

$tab = (string)($_GET['tab'] ?? 'review');
if (!isset(CE_TABS[$tab])) {
    $tab = 'review';
}
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = ce_url(['tab' => $tab, 'q' => $search, 'page' => $page]);
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new DomainException('Your session expired. Please try again.');
        }
        if (!$canDecide) {
            throw new DomainException('Only reviewers can approve or reject credit documents.');
        }
        $id = (int)($_POST['id'] ?? 0);
        $note = (string)($_POST['note'] ?? '');
        switch ((string)($_POST['action'] ?? '')) {
            case 'retry_busy':
                [$read, $stillBusy] = credit_busy_retry_all($conn);
                $_SESSION['ce_flash'] = $read > 0
                    ? ["Read {$read} document(s)." . ($stillBusy ? ' The AI is still busy for the rest; they will be tried again automatically.' : ''), 'success']
                    : ['The AI service is still busy. The documents will be tried again automatically.', 'error'];
                break;
            case 'approve':
                $profile = credit_evidence_approve($conn, $id, $reviewerId, $note);
                $_SESSION['ce_flash'] = ['Approved. Credit score is now ' . number_format($profile['score'], 1) . '/10'
                    . ($profile['has_turnover'] ? ' and the loan limit is ' . ce_money($profile['limit']) : '') . '.', 'success'];
                break;
            case 'reject':
                credit_evidence_reject($conn, $id, $reviewerId, $note);
                $_SESSION['ce_flash'] = ['Rejected. The client has been told why.', 'success'];
                break;
            case 'rerun':
                $row = credit_evidence_find($conn, $id);
                if (!$row || !in_array($row['status'], ['analyzed', 'failed', 'processing'], true)) {
                    throw new DomainException('This document cannot be read again.');
                }
                credit_evidence_analyze($conn, $id);
                $row = credit_evidence_find($conn, $id);
                $_SESSION['ce_flash'] = $row && $row['status'] === 'analyzed'
                    ? ['The AI read the document again. It is now waiting for review.', 'success']
                    : ['The AI still could not read it: ' . ($row['error'] ?? 'unknown error'), 'error'];
                break;
        }
    } catch (DomainException $e) {
        $_SESSION['ce_flash'] = [$e->getMessage(), 'error'];
    } catch (Throwable $e) {
        error_log('credit_evidence action: ' . $e->getMessage());
        $_SESSION['ce_flash'] = ['Something went wrong. Please try again.', 'error'];
    }
    header('Location: ' . $back);
    exit;
}

/* ---------- Data ---------- */
credit_ensure_schema($conn);
$where = '1=1';
$types = '';
$params = [];
if ($search !== '') {
    $where .= " AND (CONCAT_WS(' ', u.fname, u.lname) LIKE ? OR u.email LIKE ? OR c.phone LIKE ? OR c.business_name LIKE ?)";
    $like = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

// Counts per tab (respecting search).
$counts = array_fill_keys(array_keys(CE_TABS), 0);
$stmt = $conn->prepare("SELECT e.status, COUNT(*) AS n FROM credit_evidence e INNER JOIN user u ON u.id = e.user_id LEFT JOIN customers c ON c.user_id = e.user_id WHERE {$where} GROUP BY e.status");
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    foreach (CE_TABS as $key => [, , $statuses]) {
        if (in_array($r['status'], $statuses, true)) {
            $counts[$key] += (int)$r['n'];
        }
    }
}
$stmt->close();

$statuses = CE_TABS[$tab][2];
$in = implode(',', array_fill(0, count($statuses), '?'));
$total = $counts[$tab];
$totalPages = max(1, (int)ceil($total / CE_PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * CE_PER_PAGE;
$limit = CE_PER_PAGE;

$sql = "SELECT e.*, u.fname, u.lname, u.email, c.phone, c.business_name, c.business_type, c.credit_score, c.loan_limit,
               r.fname AS reviewer_fname, r.lname AS reviewer_lname
        FROM credit_evidence e
        INNER JOIN user u ON u.id = e.user_id
        LEFT JOIN customers c ON c.user_id = e.user_id
        LEFT JOIN user r ON r.id = e.reviewed_by
        WHERE {$where} AND e.status IN ({$in})
        ORDER BY " . ($tab === 'review' ? 'e.created_at ASC' : 'e.id DESC') . "
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$allTypes = $types . str_repeat('s', count($statuses)) . 'ii';
$allParams = array_merge($params, $statuses, [$limit, $offset]);
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

[$flashMsg, $flashType] = $_SESSION['ce_flash'] ?? ['', ''];
unset($_SESSION['ce_flash']);

require "nav-head.php";
?>

<style>
.ce-page { padding: 1rem; }
.ce-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.1rem; margin-bottom: 1rem; }
.ce-card h5 { font-weight: 800; margin: 0 0 .25rem; }
.ce-tabs { display: flex; gap: .5rem; flex-wrap: wrap; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem; }
.ce-tab { display: inline-flex; align-items: center; gap: .45rem; padding: .65rem .9rem; border-radius: 10px 10px 0 0; color: #475569; font-weight: 700; text-decoration: none; border-bottom: 3px solid transparent; }
.ce-tab:hover { background: #f8fafc; color: #1e293b; }
.ce-tab.active { color: #1d4ed8; border-bottom-color: #2563eb; background: #eff6ff; }
.ce-count { background: #e2e8f0; color: #334155; border-radius: 999px; padding: 0 .5rem; font-size: .75rem; }
.ce-tab.active .ce-count { background: #2563eb; color: #fff; }
.ce-search { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem; }
.ce-search input { flex: 1; min-width: 220px; border: 1px solid #d1d5db; border-radius: 10px; padding: .55rem .8rem; }
.ce-item { border: 1px solid #e5e7eb; border-radius: 14px; padding: 1rem; margin-bottom: 1rem; }
.ce-head { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.ce-head .who { font-weight: 800; }
.ce-sub { color: #64748b; font-size: .85rem; }
.ce-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: .6rem; margin: .8rem 0; }
.ce-stat { background: #f8fafc; border: 1px solid #eef2f7; border-radius: 10px; padding: .55rem .7rem; }
.ce-stat .l { font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 800; }
.ce-stat .v { font-weight: 800; color: #0f172a; }
.ce-stat .v.up { color: #15803d; }
.ce-summary { background: #f8fafc; border-radius: 10px; padding: .7rem .9rem; font-size: .9rem; }
.ce-list { margin: .4rem 0 0; padding-left: 1.1rem; font-size: .88rem; }
.ce-busy { display: flex; gap: 1rem; align-items: center; justify-content: space-between; flex-wrap: wrap; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; border-radius: 10px; padding: .7rem .9rem; margin-bottom: 1rem; font-size: .88rem; }
.ce-busy form { margin: 0; }
.ce-wait { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; border-radius: 10px; padding: .6rem .8rem; margin-top: .6rem; font-size: .88rem; }
.ce-concern { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: .6rem .8rem; margin-top: .6rem; font-size: .88rem; }
.ce-actions { display: flex; gap: .5rem; flex-wrap: wrap; align-items: flex-start; margin-top: .8rem; }
.ce-actions form { display: flex; gap: .4rem; flex-wrap: wrap; align-items: center; margin: 0; }
.ce-actions input[type=text] { border: 1px solid #d1d5db; border-radius: 8px; padding: .4rem .6rem; min-width: 220px; }
.ce-flash { padding: .7rem 1rem; border-radius: 10px; margin-bottom: 1rem; }
.ce-flash.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.ce-flash.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.ce-pages { display: flex; gap: .3rem; flex-wrap: wrap; list-style: none; margin: 0; padding: 0; }
.ce-pages a, .ce-pages span { display: inline-block; min-width: 2.1rem; text-align: center; padding: .35rem .6rem; border: 1px solid #e2e8f0; border-radius: 8px; color: #334155; text-decoration: none; font-size: .85rem; }
.ce-pages .current { background: #2563eb; border-color: #2563eb; color: #fff; font-weight: 700; }
.ce-pages .disabled { color: #cbd5e1; }
.ce-empty { padding: 2rem 1rem; text-align: center; color: #64748b; }
</style>

<div class="container-fluid ce-page">
    <?php if ($flashMsg !== ''): ?>
        <div class="ce-flash <?= h($flashType) ?>"><?= h($flashMsg) ?></div>
    <?php endif; ?>

    <div class="ce-card">
        <h5><i class="fas fa-file-invoice-dollar text-primary me-2"></i>Credit Documents</h5>
        <p class="text-muted mb-0">
            Clients upload mobile money statements, utility bills and business records. The AI reads each one and suggests a score;
            approving it recalculates the client's credit score (using Manage Score Weights) and their loan limit from the verified income.
            Always open the document and check it before approving.
        </p>
    </div>

    <div class="ce-card">
        <nav class="ce-tabs">
            <?php foreach (CE_TABS as $key => [$label, $icon]): ?>
                <a class="ce-tab<?= $key === $tab ? ' active' : '' ?>" href="<?= h(ce_url(['tab' => $key, 'q' => $search])) ?>">
                    <i class="fas <?= h($icon) ?>"></i> <?= h($label) ?> <span class="ce-count"><?= number_format($counts[$key]) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php $busyWaiting = credit_busy_waiting($conn); ?>
        <?php if ($tab === 'failed' && $busyWaiting > 0): ?>
            <div class="ce-busy">
                <div>
                    <strong><i class="fas fa-hourglass-half me-1"></i><?= number_format($busyWaiting) ?> document<?= $busyWaiting === 1 ? ' is' : 's are' ?> waiting because the AI service was busy.</strong>
                    They are read again automatically after 5, 15, 30 and 60 minutes, then 2 and 4 hours. You can also try now.
                </div>
                <?php if ($canDecide): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <button class="btn btn-primary btn-sm" name="action" value="retry_busy" type="submit"><i class="fas fa-redo me-1"></i>Read them now</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="get" class="ce-search">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search client name, email, phone or business">
            <button class="btn btn-secondary btn-sm" type="submit"><i class="fas fa-search me-1"></i>Search</button>
            <?php if ($search !== ''): ?><a class="btn btn-light btn-sm" href="<?= h(ce_url(['tab' => $tab])) ?>">Clear</a><?php endif; ?>
        </form>

        <?php if (!$rows): ?>
            <div class="ce-empty"><i class="fas fa-inbox fa-2x mb-2"></i><div>Nothing here<?= $search !== '' ? ' for your search' : '' ?>.</div></div>
        <?php endif; ?>

        <?php foreach ($rows as $r): ?>
            <?php
            $data = json_decode((string)($r['extracted_json'] ?? ''), true) ?: [];
            $typeMeta = CREDIT_EVIDENCE_TYPES[$r['doc_type']] ?? ['label' => 'Registration document', 'icon' => 'fa-file', 'income' => false];
            [$statusText, $statusClass] = credit_evidence_status_label((string)$r['status']);
            $preview = null;
            if ($r['status'] === 'analyzed') {
                $preview = credit_profile_for_user($conn, (int)$r['user_id'], $r);
            }
            $name = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''));
            ?>
            <div class="ce-item">
                <div class="ce-head">
                    <div>
                        <div class="who"><i class="fas <?= h($typeMeta['icon']) ?> text-primary me-1"></i><?= h($typeMeta['label']) ?>: <?= h($name !== '' ? $name : 'Client #' . $r['user_id']) ?></div>
                        <div class="ce-sub">
                            <?= h($r['business_name'] ?? '') ?><?= !empty($r['business_type']) ? ' (' . h($r['business_type']) . ')' : '' ?>
                            | <?= h($r['email'] ?? '') ?> | <?= $r['source'] === 'reg' ? 'Registration file sent to be read' : 'Uploaded' ?> <?= h(date('d M Y H:i', strtotime((string)$r['created_at']))) ?><?= $r['source'] === 'reg' ? '' : ' from the ' . ($r['source'] === 'app' ? 'app' : 'website') ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="badge <?= h($statusClass) ?>"><?= h($statusText) ?></span>
                        <div class="mt-1">
                            <?php if (!empty($r['file_deleted_at'])): ?>
                                <span class="btn btn-sm btn-light disabled" title="Deleted by the clean-up rule for rejected documents"><i class="fas fa-trash-alt me-1"></i>File deleted <?= h(date('d M Y', strtotime((string)$r['file_deleted_at']))) ?></span>
                            <?php else: ?>
                                <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="credit_evidence_file.php?id=<?= (int)$r['id'] ?>"><i class="fas fa-eye me-1"></i>Open document</a>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-light" href="view_client.php?user_id=<?= (int)$r['user_id'] ?>">Profile</a>
                        </div>
                    </div>
                </div>

                <?php if ($r['status'] === 'failed' && !empty($r['retry_at'])): ?>
                    <div class="ce-wait"><strong><i class="fas fa-hourglass-half me-1"></i>Not read yet: the AI service was busy.</strong>
                        Next automatic try around <?= h(date('H:i', strtotime((string)$r['retry_at']))) ?>
                        (attempt <?= (int)$r['retry_count'] + 1 ?> of <?= count(CREDIT_BUSY_RETRY_MINUTES) + 1 ?>). Or use Read again.</div>
                <?php elseif ($r['status'] === 'failed' || $r['status'] === 'processing'): ?>
                    <div class="ce-concern"><strong>The AI could not read this document.</strong> <?= h($r['error'] ?? 'It may still be processing.') ?></div>
                <?php else: ?>
                    <div class="ce-grid">
                        <div class="ce-stat"><div class="l">AI score</div><div class="v"><?= $r['ai_score'] !== null ? h(rtrim(rtrim(number_format((float)$r['ai_score'], 1), '0'), '.')) . '/10' : 'N/A' ?></div></div>
                        <?php if ($typeMeta['income']): ?>
                            <div class="ce-stat"><div class="l">Verified monthly income</div><div class="v"><?= (float)$r['monthly_income'] > 0 ? ce_money($r['monthly_income']) : 'Not shown' ?></div></div>
                        <?php endif; ?>
                        <div class="ce-stat"><div class="l">Period</div><div class="v"><?= h(trim(($data['period_start'] ?? '') . ' to ' . ($data['period_end'] ?? ''), ' to') ?: 'Not shown') ?></div></div>
                        <div class="ce-stat"><div class="l">Name on document</div><div class="v"><?= h(($data['account_holder_name'] ?? '') ?: 'Not shown') ?> <span class="ce-sub">(match: <?= h($data['name_matches_client'] ?? 'unclear') ?>)</span></div></div>
                        <?php if ($typeMeta['income']): ?>
                            <div class="ce-stat"><div class="l">Money in / out</div><div class="v"><?= ce_money($data['total_money_in'] ?? 0) ?> / <?= ce_money($data['total_money_out'] ?? 0) ?></div></div>
                        <?php else: ?>
                            <div class="ce-stat"><div class="l">Bills paid on time</div><div class="v"><?= h(str_replace('_', ' ', (string)($data['bills_paid_on_time'] ?? 'unclear'))) ?></div></div>
                        <?php endif; ?>
                        <div class="ce-stat"><div class="l">Read by</div><div class="v"><?= h(AI_PROVIDERS[$r['ai_provider']]['label'] ?? (string)$r['ai_provider']) ?></div><div class="ce-sub"><?= h($r['ai_model']) ?></div></div>
                    </div>

                    <?php if ($preview): ?>
                        <div class="ce-grid">
                            <div class="ce-stat"><div class="l">Credit score now / if approved</div>
                                <div class="v"><?= h(rtrim(rtrim(number_format((float)$r['credit_score'], 1), '0'), '.')) ?> &gt; <span class="<?= $preview['score'] > (float)$r['credit_score'] ? 'up' : '' ?>"><?= number_format($preview['score'], 1) ?>/10</span></div></div>
                            <div class="ce-stat"><div class="l">Loan limit now / if approved</div>
                                <div class="v"><?= ce_money($r['loan_limit']) ?> &gt; <?php if ($preview['has_turnover']): ?><span class="<?= $preview['limit'] > (float)$r['loan_limit'] ? 'up' : '' ?>"><?= ce_money($preview['limit']) ?></span><?php else: ?>unchanged<?php endif; ?></div></div>
                            <div class="ce-stat"><div class="l">Income used for limit</div><div class="v"><?= ce_money($preview['income_used']) ?></div><div class="ce-sub"><?= $preview['verified_income'] > 0 ? 'verified from documents' : 'declared at sign-up' ?></div></div>
                        </div>
                    <?php elseif (in_array($r['status'], ['approved', 'superseded'], true) && $r['score_before'] !== null): ?>
                        <div class="ce-sub">Before approval: score <?= h(rtrim(rtrim(number_format((float)$r['score_before'], 1), '0'), '.')) ?>, limit <?= ce_money($r['limit_before']) ?></div>
                    <?php endif; ?>

                    <?php if ($r['status'] === 'analyzed' && !empty($r['auto_note'])): ?>
                        <div class="ce-concern"><strong>Held for review:</strong> <?= h($r['auto_note']) ?></div>
                    <?php endif; ?>
                    <?php $ruleNotes = credit_evidence_rule_notes($r, $conn); ?>
                    <?php if ($ruleNotes): ?>
                        <div class="ce-summary"><strong>What counts for the score:</strong>
                            <ul class="ce-list"><?php foreach ($ruleNotes as $rn): ?><li><?= h($rn) ?></li><?php endforeach; ?></ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($r['summary'])): ?>
                        <div class="ce-summary"><?= nl2br(h($r['summary'])) ?>
                            <?php if (!empty($data['score_reasons'])): ?>
                                <ul class="ce-list"><?php foreach ((array)$data['score_reasons'] as $reason): ?><li><?= h($reason) ?></li><?php endforeach; ?></ul>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (empty($data['matches_expected_type']) && $data): ?>
                        <div class="ce-concern"><strong>This does not look like a <?= h($typeMeta['label']) ?>.</strong> The AI saw: <?= h($data['document_kind'] ?? 'unknown') ?></div>
                    <?php endif; ?>
                    <?php if (!empty($data['authenticity_concerns'])): ?>
                        <div class="ce-concern"><strong>Possible editing or forgery:</strong>
                            <ul class="ce-list"><?php foreach ((array)$data['authenticity_concerns'] as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (in_array($r['status'], ['approved', 'rejected', 'superseded'], true)): ?>
                    <div class="ce-sub mt-2">
                        <?= h(ucfirst((string)$r['status'])) ?> by <?= (int)($r['reviewed_by'] ?? 0) === 0 ? 'the automatic check' : h(trim(($r['reviewer_fname'] ?? '') . ' ' . ($r['reviewer_lname'] ?? '')) ?: 'staff') ?>
                        on <?= h(date('d M Y H:i', strtotime((string)$r['reviewed_at']))) ?><?= !empty($r['review_note']) ? ': ' . h($r['review_note']) : '' ?>
                    </div>
                <?php endif; ?>

                <?php if ($canDecide && in_array($r['status'], ['analyzed', 'failed', 'processing'], true)): ?>
                    <div class="ce-actions">
                        <?php if ($r['status'] === 'analyzed'): ?>
                            <form method="post" action="<?= h(ce_url(['tab' => $tab, 'q' => $search, 'page' => $page])) ?>">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="text" name="note" maxlength="500" placeholder="Note (optional)">
                                <button class="btn btn-sm btn-success" name="action" value="approve" type="submit"
                                        onclick="return confirm('Approve this document and update the client\'s credit score and loan limit?');">
                                    <i class="fas fa-check me-1"></i>Approve</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= h(ce_url(['tab' => $tab, 'q' => $search, 'page' => $page])) ?>">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="text" name="note" maxlength="500" placeholder="Reason for the client (required)" required>
                            <button class="btn btn-sm btn-outline-danger" name="action" value="reject" type="submit"><i class="fas fa-times me-1"></i>Reject</button>
                        </form>
                        <form method="post" action="<?= h(ce_url(['tab' => $tab, 'q' => $search, 'page' => $page])) ?>">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-light" name="action" value="rerun" type="submit"><i class="fas fa-redo me-1"></i>Read again</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($total > 0): ?>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="ce-sub">Showing <?= number_format($offset + 1) ?>-<?= number_format(min($total, $offset + CE_PER_PAGE)) ?> of <?= number_format($total) ?></div>
                <?php if ($totalPages > 1): ?>
                    <ul class="ce-pages">
                        <?php $base = ['tab' => $tab, 'q' => $search]; ?>
                        <li><?php if ($page > 1): ?><a href="<?= h(ce_url($base + ['page' => $page - 1])) ?>">Prev</a><?php else: ?><span class="disabled">Prev</span><?php endif; ?></li>
                        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                            <li><?php if ($p === $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="<?= h(ce_url($base + ['page' => $p])) ?>"><?= $p ?></a><?php endif; ?></li>
                        <?php endfor; ?>
                        <li><?php if ($page < $totalPages): ?><a href="<?= h(ce_url($base + ['page' => $page + 1])) ?>">Next</a><?php else: ?><span class="disabled">Next</span><?php endif; ?></li>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require "footer.php"; ?>
