<?php
declare(strict_types=1);

session_start();

$phead = 'My Loan Info Requests';
require "nav-head.php";
require "services_check.php";
require_once __DIR__ . "/loan_audit.php";

if (!isset($conn) || !$conn instanceof mysqli) {
    die("Database connection not established.");
}

// Assumption: role = 2 is "Loan Officer". Adjust if your role numbering differs.
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

// --------------------------------------------------------------
// Handle "mark as complete" submission
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    $loanId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $response = trim((string)($_POST['response'] ?? ''));

    if (!$loanId) {
        $error = "Invalid loan id.";
    } elseif ($response === '') {
        $error = "Please describe what you found out before marking this complete.";
    } else {
        $stmtCheck = $conn->prepare("
            SELECT id, status FROM loan
            WHERE id = ? AND assigned_officer_id = ? AND status = 'INFO_REQUESTED'
            LIMIT 1
        ");
        $stmtCheck->bind_param("ii", $loanId, $userId);
        $stmtCheck->execute();
        $loanRow = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();

        if (!$loanRow) {
            $error = "This request is not assigned to you or is no longer open.";
        } else {
            $newStatus = 'INFO_PROVIDED';

            $stmtUpdate = $conn->prepare("
                UPDATE loan
                SET status = ?, officer_response = ?
                WHERE id = ? AND assigned_officer_id = ? AND status = 'INFO_REQUESTED'
            ");
            $stmtUpdate->bind_param("ssii", $newStatus, $response, $loanId, $userId);

            if ($stmtUpdate->execute()) {
                logLoanAudit(
                    $conn,
                    $loanId,
                    $userId,
                    $role,
                    'info_provided',
                    $response,
                    null,
                    'INFO_REQUESTED',
                    $newStatus
                );

                $success = "Marked as complete and returned to the reviewer.";
            } else {
                $error = "Could not update this loan. Please try again.";
            }

            $stmtUpdate->close();
        }
    }
}

// --------------------------------------------------------------
// Load the officer's open and recently-completed requests
// --------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT l.*, u.fname, u.lname
    FROM loan l
    LEFT JOIN user u ON u.id = l.client_id
    WHERE l.assigned_officer_id = ?
      AND l.status IN ('INFO_REQUESTED', 'INFO_PROVIDED')
    ORDER BY l.status = 'INFO_REQUESTED' DESC, l.id DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

$requests = [];
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}
$stmt->close();
?>

<style>
.request-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-left: 5px solid #0ea5e9;
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: .9rem;
    box-shadow: 0 6px 18px rgba(15,23,42,.05);
}
.request-card.done { border-left-color: #7e22ce; opacity: .85; }
.fs-sm { font-size: .85rem; }
.fs-xs { font-size: .72rem; }
.text-muted { color: #64748b !important; }
</style>

<div class="page-header mb-4">
    <div class="title"><?= h($phead) ?></div>
    <div class="sub">Requests a reviewer has assigned to you</div>
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
                <p>You have no open information requests right now.</p>
            </div>
        </div>
    </div>
<?php else: ?>

    <?php foreach ($requests as $loan): ?>
        <?php
        $loanId = (int)$loan['id'];
        $isDone = ($loan['status'] === 'INFO_PROVIDED');
        $clientName = trim((string)($loan['fname'] ?? '') . ' ' . (string)($loan['lname'] ?? ''));
        $clientName = $clientName !== '' ? $clientName : ('Client #' . (int)$loan['client_id']);
        ?>
        <div class="request-card <?= $isDone ? 'done' : '' ?>">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <div>
                    <div style="font-weight:700"><?= h($clientName) ?> &mdash; Loan #<?= $loanId ?></div>
                    <div class="fs-sm text-muted"><?= money($loan['amount'] ?? 0) ?> requested</div>
                </div>
                <span class="badge <?= $isDone ? 'badge-purple' : 'badge-info' ?>">
                    <?= $isDone ? 'Sent back to reviewer' : 'Awaiting your response' ?>
                </span>
            </div>

            <div class="mt-2 fs-sm">
                <strong>Reviewer requested:</strong>
                <?= nl2br(h($loan['info_request_details'] ?? '')) ?>
            </div>

            <?php if ($isDone): ?>
                <div class="mt-2 fs-sm text-muted">
                    <strong>Your response:</strong>
                    <?= nl2br(h($loan['officer_response'] ?? '')) ?>
                </div>
            <?php else: ?>
                <form method="POST" class="mt-3">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="id" value="<?= $loanId ?>">
                    <label class="form-label fs-sm">What did you find out / obtain from the applicant?</label>
                    <textarea name="response" class="form-control mb-2" rows="3" required
                              placeholder="Summarize the information gathered so the reviewer can finish their assessment..."></textarea>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="fas fa-check"></i> Mark Complete &amp; Return to Reviewer
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

<?php endif; ?>

<?php require "footer.php"; ?>