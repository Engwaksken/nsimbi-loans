<?php
declare(strict_types=1);

session_start();

$phead = 'Top-Up Reviews';
require "nav-head.php";
require "services_check.php";
require_once __DIR__ . "/loan_audit.php";
require_once __DIR__ . '/../php/mail-function.php';
require_once __DIR__ . '/../includes/topup_notify.php';

if (!isset($conn) || !$conn instanceof mysqli) {
    die("Database connection not established.");
}

$role   = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if ($role !== 2 || $userId <= 0) {
    die("Not authorized. This page is for loan officers only.");
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return 'UGX ' . number_format((float)($value ?? 0));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    $topupId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $response = trim((string)($_POST['response'] ?? ''));

    if (!$topupId) {
        $error = "Invalid top-up request id.";
    } elseif ($response === '') {
        $error = "Please describe what you found out before marking this complete.";
    } else {
        $stmtCheck = $conn->prepare("
            SELECT t.id, t.loan_id, t.status FROM loan_topup_requests t
            WHERE t.id = ? AND " . TOPUP_OPEN_FOR_OFFICER_SQL . "
            LIMIT 1
        ");
        $stmtCheck->bind_param("ii", $topupId, $userId);
        $stmtCheck->execute();
        $topupRow = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();

        if (!$topupRow) {
            $error = "This request is not assigned to you or is no longer open.";
        } else {
            $newStatus = 'INFO_PROVIDED';

            $oldStatus = (string)$topupRow['status'];
            // Whoever forwards an unassigned staff request becomes its officer.
            $stmtUpdate = $conn->prepare("
                UPDATE loan_topup_requests
                SET status = ?, officer_response = ?, assigned_officer_id = ?, updated_at = NOW()
                WHERE id = ? AND status = ?
            ");
            $stmtUpdate->bind_param("ssiis", $newStatus, $response, $userId, $topupId, $oldStatus);

            if ($stmtUpdate->execute()) {
                logTopupAudit(
                    $conn,
                    (int)$topupRow['loan_id'],
                    $topupId,
                    $userId,
                    $role,
                    'topup_info_provided',
                    $response,
                    null,
                    $oldStatus,
                    $newStatus
                );

                $success = "Forwarded to the administrator for approval.";

                // Tell the admins it is ready for approval.
                $officerName = trim((string)($_SESSION['fname'] ?? '') . ' ' . (string)($_SESSION['lname'] ?? '')) ?: 'The loan officer';
                $loanRef = 'LOAN-' . (int)$topupRow['loan_id'];
                $note = "{$officerName} reviewed the top-up on {$loanRef} and forwarded it for approval: {$response}";
                foreach (topup_admin_ids($conn) as $adminId) {
                    topup_notify_bell($conn, $adminId, (int)$topupRow['loan_id'], 'Top-Up Ready for Approval', $note);
                    topup_notify_email(
                        $conn,
                        $adminId,
                        'Loan Top-Up Ready for Approval - Nsimbi Impact',
                        'A loan officer has reviewed a top-up request and forwarded it to you for approval.',
                        ['Loan' => $loanRef, 'Reviewed by' => $officerName, 'Officer review' => $response],
                        'pending_topups.php',
                        'Approve or reject'
                    );
                }
            } else {
                $error = "Could not update this request. Please try again.";
            }

            $stmtUpdate->close();
        }
    }
}

$stmt = $conn->prepare("
    SELECT t.*, u.fname, u.lname
    FROM loan_topup_requests t
    LEFT JOIN user u ON u.id = t.client_id
    WHERE " . TOPUP_OPEN_FOR_OFFICER_SQL . "
       OR (t.assigned_officer_id = ? AND t.status = 'INFO_PROVIDED')
    ORDER BY t.status = 'INFO_PROVIDED' ASC, t.id DESC
");
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();

$requests = [];
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}
$stmt->close();
?>

<style>
.request-card { background: #fff; border: 1px solid #e5e7eb; border-left: 5px solid #0ea5e9; border-radius: 16px; padding: 1rem; margin-bottom: .9rem; box-shadow: 0 6px 18px rgba(15,23,42,.05); }
.request-card.done { border-left-color: #7e22ce; opacity: .85; }
.fs-sm { font-size: .85rem; }
.fs-xs { font-size: .72rem; }
.text-muted { color: #64748b !important; }
</style>

<div class="page-header mb-4">
    <div class="title"><?= h($phead) ?></div>
    <div class="sub">Top-up requests waiting for your review. Forward each one to the administrator with your recommendation.</div>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if (empty($requests)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-check-circle" style="color:var(--secondary);opacity:.5"></i>
                <h3>Nothing assigned</h3>
                <p>You have no top-up requests to review right now.</p>
            </div>
        </div>
    </div>
<?php else: ?>

    <?php foreach ($requests as $topup): ?>
        <?php
        $topupId = (int)$topup['id'];
        $isDone = ($topup['status'] === 'INFO_PROVIDED');
        $clientName = trim((string)($topup['fname'] ?? '') . ' ' . (string)($topup['lname'] ?? ''));
        $clientName = $clientName !== '' ? $clientName : ('Client #' . (int)$topup['client_id']);
        ?>
        <div class="request-card <?= $isDone ? 'done' : '' ?>">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <div>
                    <div style="font-weight:700"><?= h($clientName) ?> - Top-Up on Loan #<?= (int)$topup['loan_id'] ?></div>
                    <div class="fs-sm text-muted"><?= money($topup['amount'] ?? 0) ?> requested over <?= (int)($topup['loan_period'] ?? 0) ?> months</div>
                </div>
                <span class="badge <?= $isDone ? 'badge-purple' : 'badge-info' ?>">
                    <?= $isDone ? 'Forwarded to admin' : 'Awaiting your review' ?>
                </span>
            </div>

            <?php if (!empty($topup['request_notes'])): ?>
                <div class="mt-2 fs-sm"><strong>Reason given:</strong> <?= nl2br(h($topup['request_notes'])) ?></div>
            <?php endif; ?>

            <div class="mt-2 fs-sm">
                <strong>To do:</strong>
                <?= nl2br(h(trim((string)($topup['info_request_details'] ?? '')) !== ''
                    ? $topup['info_request_details']
                    : 'Check the request and the loan, then forward it to the administrator with your recommendation.')) ?>
            </div>

            <?php if ($isDone): ?>
                <div class="mt-2 fs-sm text-muted">
                    <strong>Your review:</strong>
                    <?= nl2br(h($topup['officer_response'] ?? '')) ?>
                </div>
            <?php else: ?>
                <form method="POST" class="mt-3">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="id" value="<?= $topupId ?>">
                    <label class="form-label fs-sm">Your review and recommendation</label>
                    <textarea name="response" class="form-control mb-2" rows="3" required
                              placeholder="What you checked, and whether you recommend approving this top-up..."></textarea>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="fas fa-paper-plane"></i> Forward to Admin for Approval
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

<?php endif; ?>

<?php require "footer.php"; ?>