<?php
declare(strict_types=1);

/*
 * Loans > Payment Slips: repayments clients sent with a payment slip.
 * A loan officer checks the slip, then approves (the loan balance goes down
 * automatically, and the loan is cleared when fully paid) or rejects with a
 * reason. Reviewer (1), loan officer (2) and accountant (5); officers see the
 * loans assigned to them and unassigned ones.
 */

$phead = 'Payment Slips';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/payment_slips.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$role = (int)($_SESSION['role'] ?? 0);
$actorId = (int)($_SESSION['user_id'] ?? 0);
if (!isset($_SESSION['logged_in']) || !in_array($role, PAYMENT_SLIP_REVIEW_ROLES, true) || $actorId <= 0) {
    header('Location: dashboard.php');
    exit();
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

payment_slips_ensure_schema($conn);

if (empty($_SESSION['payment_slips_csrf'])) {
    $_SESSION['payment_slips_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['payment_slips_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['Your session expired. Please try again.', 'error'];
    if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $decision = (string)($_POST['decision'] ?? '');
        $raw = preg_replace('/[^0-9.]/', '', (string)($_POST['approved_amount'] ?? ''));
        try {
            $r = payment_slip_review(
                $conn,
                (int)($_POST['payment_id'] ?? 0),
                $actorId,
                $role,
                $decision,
                (string)($_POST['note'] ?? ''),
                $raw !== '' && is_numeric($raw) ? (float)$raw : null
            );
            $flash = $r['status'] === 'SUCCESSFUL'
                ? ['Payment approved. Loan #' . $r['loan_id'] . ' balance: ' . loan_money($r['old_balance']) . ' → ' . loan_money($r['new_balance'])
                    . ($r['new_balance'] <= 0 ? ' (loan cleared).' : '.'), 'success']
                : ['Payment rejected. The client has been told why.', 'success'];
        } catch (DomainException $e) {
            $flash = [$e->getMessage(), 'error'];
        } catch (Throwable $e) {
            error_log('payment slip review: ' . $e->getMessage());
            $flash = ['The payment could not be saved. Please try again.', 'error'];
        }
    }
    $_SESSION['payment_slips_flash'] = $flash;
    $tab = (string)($_GET['tab'] ?? 'pending');
    header('Location: payment_slips.php?tab=' . urlencode($tab));
    exit;
}

$tab = ($_GET['tab'] ?? 'pending') === 'reviewed' ? 'reviewed' : 'pending';
$where = $tab === 'pending'
    ? "UPPER(p.status) = 'PENDING'"
    : "UPPER(p.status) IN ('SUCCESSFUL', 'REJECTED') AND p.reviewed_at IS NOT NULL";
$params = [];
$types = '';
if ($role === 2) {
    $where .= ' AND (l.assigned_officer_id IS NULL OR l.assigned_officer_id = 0 OR l.assigned_officer_id = ?)';
    $params[] = $actorId;
    $types .= 'i';
}
$order = $tab === 'pending' ? 'p.id ASC' : 'p.reviewed_at DESC';
$limit = $tab === 'pending' ? 200 : 100;

$sql = "
    SELECT p.*, l.loan_balance, l.status AS loan_status, l.amount AS loan_amount, l.due_date,
           l.assigned_officer_id,
           TRIM(CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, ''))) AS client_name,
           c.phone AS client_phone,
           TRIM(CONCAT(COALESCE(r.fname, ''), ' ', COALESCE(r.lname, ''))) AS reviewer_name,
           (SELECT COALESCE(SUM(p2.amount), 0) FROM payments p2
             WHERE p2.loan_id = p.loan_id AND UPPER(p2.status) = 'PENDING' AND p2.id < p.id) AS pending_before
    FROM payments p
    INNER JOIN loan l ON l.id = p.loan_id
    LEFT JOIN user u ON u.id = COALESCE(p.user_id, l.client_id)
    LEFT JOIN customers c ON c.user_id = u.id
    LEFT JOIN user r ON r.id = p.reviewed_by
    WHERE {$where}
    ORDER BY {$order}
    LIMIT {$limit}
";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

[$flashMsg, $flashType] = $_SESSION['payment_slips_flash'] ?? ['', ''];
unset($_SESSION['payment_slips_flash']);

require "nav-head.php";
?>

<style>
.ps-page { padding: 1rem; }
.ps-tabs { display: flex; gap: .5rem; margin-bottom: 1rem; }
.ps-tabs a { padding: .45rem 1rem; border-radius: 999px; border: 1px solid #e5e7eb; font-weight: 700; font-size: .85rem; text-decoration: none; color: #374151; background: #fff; }
.ps-tabs a.active { background: #12329A; color: #fff; border-color: #12329A; }
.ps-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 1rem; }
.ps-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1rem; display: flex; flex-direction: column; gap: .6rem; }
.ps-slip { display: block; height: 180px; border-radius: 10px; background: #f3f7fb; overflow: hidden; text-align: center; }
.ps-slip img { width: 100%; height: 100%; object-fit: cover; }
.ps-slip .ps-noslip { display: flex; height: 100%; align-items: center; justify-content: center; flex-direction: column; color: #6b7280; font-size: .85rem; gap: .3rem; }
.ps-row { display: flex; justify-content: space-between; font-size: .85rem; gap: .5rem; }
.ps-row span:first-child { color: #6b7280; }
.ps-amount { font-size: 1.35rem; font-weight: 800; color: #12329A; }
.ps-actions form { display: flex; flex-direction: column; gap: .4rem; }
.ps-badge { display: inline-block; padding: .2rem .6rem; border-radius: 999px; font-size: .72rem; font-weight: 800; }
.ps-ok { background: #dcfce7; color: #166534; }
.ps-no { background: #fee2e2; color: #991b1b; }
.ps-warn { background: #fff8e6; color: #92400e; font-size: .8rem; padding: .4rem .6rem; border-radius: 8px; }
</style>

<div class="ps-page">
    <?php if ($flashMsg !== ''): ?>
        <div class="alert <?= $flashType === 'error' ? 'alert-danger' : 'alert-success' ?>"><?= h($flashMsg) ?></div>
    <?php endif; ?>

    <div class="ps-tabs">
        <a href="payment_slips.php?tab=pending" class="<?= $tab === 'pending' ? 'active' : '' ?>">Waiting for review</a>
        <a href="payment_slips.php?tab=reviewed" class="<?= $tab === 'reviewed' ? 'active' : '' ?>">Reviewed</a>
    </div>

    <?php if (!$rows): ?>
        <div class="ps-card"><?= $tab === 'pending' ? 'No payments are waiting for review.' : 'Nothing reviewed yet.' ?></div>
    <?php endif; ?>

    <div class="ps-grid">
    <?php foreach ($rows as $p):
        $id = (int)$p['id'];
        $isImage = str_starts_with((string)($p['slip_mime'] ?? ''), 'image/');
        $hasSlip = !empty($p['slip_path']);
        $balance = (float)$p['loan_balance'];
        $afterApproval = max(0, $balance - (float)$p['amount']);
        $status = strtoupper((string)$p['status']);
        ?>
        <div class="ps-card">
            <a class="ps-slip" href="<?= $hasSlip ? 'payment_slip_file.php?id=' . $id : '#' ?>" target="_blank" rel="noopener">
                <?php if ($hasSlip && $isImage): ?>
                    <img src="payment_slip_file.php?id=<?= $id ?>" alt="Payment slip" loading="lazy">
                <?php elseif ($hasSlip): ?>
                    <span class="ps-noslip"><i class="fas fa-file-pdf fa-2x"></i>Open PDF slip</span>
                <?php else: ?>
                    <span class="ps-noslip"><i class="fas fa-exclamation-triangle fa-2x"></i>No slip attached<br>(older app version)</span>
                <?php endif; ?>
            </a>

            <div>
                <strong><?= h($p['client_name'] ?: 'Client') ?></strong>
                <div class="fs-sm text-muted"><?= h($p['client_phone'] ?? '') ?> · Loan #<?= (int)$p['loan_id'] ?></div>
            </div>

            <div class="ps-amount"><?= h(loan_money((float)$p['amount'])) ?></div>
            <?php if ($status !== 'PENDING' && $p['claimed_amount'] !== null && abs((float)$p['claimed_amount'] - (float)$p['amount']) > 0.009): ?>
                <div class="fs-xs text-muted">Client entered <?= h(loan_money((float)$p['claimed_amount'])) ?></div>
            <?php endif; ?>

            <div class="ps-row"><span>Method</span><span><?= h(PAYMENT_METHODS[$p['payment_method'] ?? ''] ?? ($p['payment_method'] ?: '-')) ?></span></div>
            <div class="ps-row"><span>Transaction ID</span><span><?= h($p['transaction_reference'] ?: '-') ?></span></div>
            <div class="ps-row"><span>Sent</span><span><?= h($p['datetime'] ? date('j M Y, g:i A', strtotime((string)$p['datetime'])) : '-') ?></span></div>

            <?php if ($status === 'PENDING'): ?>
                <div class="ps-row"><span>Loan balance now</span><span><?= h(loan_money($balance)) ?></span></div>
                <div class="ps-row"><span>After approval</span><strong><?= h(loan_money($afterApproval)) ?><?= $afterApproval <= 0 ? ' (cleared)' : '' ?></strong></div>
                <?php if ((float)$p['pending_before'] > 0): ?>
                    <div class="ps-warn">Another payment of <?= h(loan_money((float)$p['pending_before'])) ?> on this loan is also waiting. Check they are not the same slip.</div>
                <?php endif; ?>

                <div class="ps-actions">
                    <form method="POST" action="payment_slips.php?tab=pending"
                          onsubmit="return confirm('Approve this payment and reduce the loan balance?')">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="payment_id" value="<?= $id ?>">
                        <input type="hidden" name="decision" value="approve">
                        <label class="fs-xs text-muted">Amount on the slip (change it if it differs)</label>
                        <input type="number" name="approved_amount" class="form-control form-control-sm" min="1" step="1"
                               max="<?= (int)ceil($balance) ?>" value="<?= (int)round((float)$p['amount']) ?>" required>
                        <input type="text" name="note" class="form-control form-control-sm" maxlength="500" placeholder="Note (optional)">
                        <button type="submit" class="btn btn-success btn-sm">Approve payment</button>
                    </form>
                    <form method="POST" action="payment_slips.php?tab=pending" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="payment_id" value="<?= $id ?>">
                        <input type="hidden" name="decision" value="reject">
                        <input type="text" name="note" class="form-control form-control-sm" maxlength="500" required
                               placeholder="Reason for rejecting (sent to the client)">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Reject</button>
                    </form>
                </div>
            <?php else: ?>
                <div>
                    <span class="ps-badge <?= $status === 'SUCCESSFUL' ? 'ps-ok' : 'ps-no' ?>"><?= $status === 'SUCCESSFUL' ? 'APPROVED' : 'REJECTED' ?></span>
                    <span class="fs-xs text-muted">by <?= h($p['reviewer_name'] ?: 'staff') ?>, <?= h(date('j M Y, g:i A', strtotime((string)$p['reviewed_at']))) ?></span>
                </div>
                <?php if (!empty($p['review_note'])): ?>
                    <div class="fs-sm"><?= h($p['review_note']) ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<?php require "footer.php"; ?>
