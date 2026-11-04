<?php
declare(strict_types=1);

$phead = 'Pending Loan Top-Ups';
require "nav-head.php";
require "services_check.php";
require_once __DIR__ . "/loan_audit.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !$conn instanceof mysqli) {
    die("Database connection not established.");
}

$currentRole   = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$isReviewer    = ($currentRole === 1);
$isOfficer     = ($currentRole === 2);

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return 'UGX ' . number_format((float)($value ?? 0));
}

function topupStatusBadge(string $status): string
{
    return match ($status) {
        'PENDING'        => 'badge-warning',
        'INFO_REQUESTED' => 'badge-info',
        'INFO_PROVIDED'  => 'badge-purple',
        default          => 'badge-secondary',
    };
}

function topupStatusLabel(string $status): string
{
    return match ($status) {
        'PENDING'        => 'Pending',
        'INFO_REQUESTED' => 'Awaiting Officer',
        'INFO_PROVIDED'  => 'Ready for Review',
        default          => ucfirst(strtolower($status)),
    };
}

$sql = "
    SELECT
        t.*,
        l.amount AS current_amount,
        l.loan_balance AS current_balance,
        l.due_date AS current_due_date,
        u.fname, u.lname, u.email AS client_email, u.phone AS client_phone,
        officer.fname AS officer_fname, officer.lname AS officer_lname
    FROM loan_topup_requests t
    INNER JOIN loan l ON l.id = t.loan_id
    LEFT JOIN user u ON u.id = t.client_id
    LEFT JOIN user officer ON officer.id = t.assigned_officer_id
    WHERE t.status IN ('PENDING', 'INFO_PROVIDED')
    ORDER BY t.id DESC
";

$result = $conn->query($sql);
$topups = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $topups[] = $row;
    }
}

$auditTrailByTopup = getTopupAuditTrailBulk($conn, array_column($topups, 'id'));
$loanOfficers = getLoanOfficers($conn);

$stats = ['count' => 0, 'amount' => 0];
foreach ($topups as $t) {
    $stats['count']++;
    $stats['amount'] += (float)($t['amount'] ?? 0);
}
?>

<style>
.stats-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.stat-card { border: 0; border-radius: 16px; background: #fff; box-shadow: 0 6px 20px rgba(15,23,42,.06); overflow: hidden; position: relative; padding: 1rem; }
.stat-card::before { content: ""; position: absolute; left: 0; top: 0; width: 5px; height: 100%; }
.border-warning-band::before { background: #f59e0b; }
.border-primary-band::before { background: #0d6efd; }
.stat-flex { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.stat-icon { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; color: #fff; }
.stat-icon.bg-warning { background: #f59e0b; }
.stat-icon.bg-primary { background: #0d6efd; }
.stat-label { font-size: .72rem; font-weight: 700; color: #6b7280; margin-bottom: .35rem; letter-spacing: .05em; text-transform: uppercase; }
.stat-value { font-size: 1.15rem; font-weight: 800; color: #111827; margin: 0; }

.role-badge { display: inline-flex; align-items: center; gap: .4rem; font-size: .75rem; font-weight: 700; color: #374151; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 999px; padding: .3rem .75rem; }

.topup-card { background: #fff; border: 1px solid #e5e7eb; border-left: 5px solid #f59e0b; border-radius: 16px; padding: 1rem; margin-bottom: .9rem; box-shadow: 0 6px 18px rgba(15,23,42,.05); }
.topup-card.status-info_requested { border-left-color: #0ea5e9; }
.topup-card.status-info_provided { border-left-color: #7e22ce; }

.topup-card-top { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.cell-av { width: 42px; height: 42px; border-radius: 50%; background: #f59e0b; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; flex-shrink: 0; }
.topup-meta { display: flex; align-items: center; gap: 2rem; flex-wrap: wrap; }

.fs-sm { font-size: .85rem; }
.fs-xs { font-size: .72rem; }
.text-muted { color: #64748b !important; }

.badge-purple { background: #f3e8ff; color: #7e22ce; }
.badge-secondary { background: #e5e7eb; color: #374151; }
.badge-info { background: #e0f2fe; color: #0369a1; }

.workflow-note { margin-top: .6rem; padding: .6rem .8rem; border-radius: 10px; background: #f8fafc; border: 1px dashed #cbd5e1; font-size: .82rem; color: #334155; }
.workflow-note strong { color: #111827; }

.audit-entry { border-bottom: 1px solid #eef0f3; padding: .55rem 0; }
.audit-entry:last-child { border-bottom: 0; }
.audit-action { font-weight: 700; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
.audit-action.topup_approved { color: #16a34a; }
.audit-action.topup_rejected { color: #dc2626; }
.audit-action.topup_info_requested { color: #0369a1; }
.audit-action.topup_info_provided { color: #7e22ce; }
.audit-action.topup_requested { color: #6b7280; }

.review-panel { border: 1px solid #e5e7eb; border-radius: 14px; background: #fbfcfd; padding: 1rem; margin-top: .75rem; }
.review-tabs { display: flex; gap: .35rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem; flex-wrap: wrap; }
.review-tab-btn { border: none; background: transparent; padding: .5rem 1rem; font-size: .8rem; font-weight: 700; color: #64748b; border-bottom: 3px solid transparent; border-radius: 8px 8px 0 0; cursor: pointer; }
.review-tab-btn:hover { background: #f1f5f9; }
.review-tab-btn.tab-approve.active { color: #16a34a; border-bottom-color: #16a34a; background: #f0fdf4; }
.review-tab-btn.tab-reject.active { color: #dc2626; border-bottom-color: #dc2626; background: #fef2f2; }
.review-tab-btn.tab-info.active { color: #0369a1; border-bottom-color: #0369a1; background: #f0f9ff; }
.review-tab-content.d-none { display: none; }
.d-none { display: none; }

@media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-header mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <div class="title">Pending Loan Top-Ups</div>
        <div class="sub"><?= number_format($stats['count']) ?> top-up request<?= $stats['count'] === 1 ? '' : 's' ?> awaiting review</div>
    </div>
    <span class="role-badge"><i class="fas fa-user-shield"></i> Viewing as: <?= h($isReviewer ? 'Reviewer' : ($isOfficer ? 'Loan Officer' : 'Viewer (read-only)')) ?></span>
</div>

<div class="stats-grid">
    <div class="stat-card border-warning-band">
        <div class="stat-flex">
            <div>
                <div class="stat-label">Top-Ups Awaiting Review</div>
                <div class="stat-value"><?= number_format($stats['count']) ?></div>
            </div>
            <div class="stat-icon bg-warning"><i class="fas fa-layer-group"></i></div>
        </div>
    </div>
    <div class="stat-card border-primary-band">
        <div class="stat-flex">
            <div>
                <div class="stat-label">Total Requested</div>
                <div class="stat-value"><?= money($stats['amount']) ?></div>
            </div>
            <div class="stat-icon bg-primary"><i class="fas fa-coins"></i></div>
        </div>
    </div>
</div>

<?php if (empty($topups)): ?>

    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-check-circle" style="color:var(--secondary);opacity:.5"></i>
                <h3>All Clear!</h3>
                <p>No top-up requests are waiting on review.</p>
            </div>
        </div>
    </div>

<?php else: ?>

    <?php foreach ($topups as $topup): ?>
        <?php
        $topupId = (int)$topup['id'];
        $loanId = (int)$topup['loan_id'];
        $status = strtoupper(trim((string)($topup['status'] ?? 'PENDING')));

        $clientName = trim((string)($topup['fname'] ?? '') . ' ' . (string)($topup['lname'] ?? ''));
        $clientName = $clientName !== '' ? $clientName : ('Client #' . (int)$topup['client_id']);
        $initials = strtoupper(substr($clientName, 0, 1));

        $requestedDate = !empty($topup['created_at']) ? date('M j, Y', strtotime((string)$topup['created_at'])) : 'N/A';

        $officerName = trim((string)($topup['officer_fname'] ?? '') . ' ' . (string)($topup['officer_lname'] ?? ''));
        $officerResponse = trim((string)($topup['officer_response'] ?? ''));
        $infoRequestDetails = trim((string)($topup['info_request_details'] ?? ''));

        $trail = $auditTrailByTopup[$topupId] ?? [];
        ?>

        <div class="topup-card status-<?= h(strtolower($status)) ?>">
            <div class="topup-card-top">
                <div class="d-flex align-center gap-3">
                    <div class="cell-av"><?= h($initials ?: 'U') ?></div>
                    <div>
                        <div style="font-weight:700"><?= h($clientName) ?></div>
                        <div class="fs-sm text-muted">
                            Top-up on Loan #<?= $loanId ?> &middot; Requested <?= h($requestedDate) ?>
                        </div>
                        <div class="mt-1">
                            <span class="badge badge-primary">Top-Up</span>
                            <span class="badge <?= topupStatusBadge($status) ?>"><?= h(topupStatusLabel($status)) ?></span>
                        </div>
                    </div>
                </div>

                <div class="topup-meta">
                    <div>
                        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.06em">Top-Up Amount</div>
                        <div style="font-weight:800;color:var(--primary)"><?= money($topup['amount'] ?? 0) ?></div>
                    </div>
                    <div>
                        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.06em">Extra Period</div>
                        <div class="fs-sm text-muted"><?= (int)($topup['loan_period'] ?? 0) ?> month(s)</div>
                    </div>
                    <div>
                        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.06em">Current Balance</div>
                        <div class="fs-sm text-muted"><?= money($topup['current_balance'] ?? 0) ?></div>
                    </div>
                </div>
            </div>

            <?php if (!empty($topup['request_notes'])): ?>
                <div class="fs-xs text-muted mt-2">Requester notes: <?= nl2br(h($topup['request_notes'])) ?></div>
            <?php endif; ?>

            <?php if ($status === 'INFO_PROVIDED'): ?>
                <div class="workflow-note">
                    <strong><i class="fas fa-reply"></i> Loan officer response<?= $officerName !== '' ? ' from ' . h($officerName) : '' ?>:</strong>
                    <div class="mt-1"><?= nl2br(h($officerResponse !== '' ? $officerResponse : 'No response text was recorded.')) ?></div>
                    <?php if ($infoRequestDetails !== ''): ?>
                        <div class="mt-2 text-muted"><strong>Original request:</strong> <?= nl2br(h($infoRequestDetails)) ?></div>
                    <?php endif; ?>
                </div>
            <?php elseif ($status === 'INFO_REQUESTED'): ?>
                <div class="workflow-note">
                    <strong><i class="fas fa-user-clock"></i> Awaiting <?= $officerName !== '' ? h($officerName) : 'assigned officer' ?>:</strong>
                    <div class="mt-1"><?= nl2br(h($infoRequestDetails)) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($trail)): ?>
                <div class="mt-2">
                    <a class="fs-xs text-muted" href="#" onclick="var el=document.getElementById('taudit-<?= $topupId ?>'); el.classList.toggle('d-none'); return false;">
                        <i class="fas fa-clock-rotate-left"></i> View history (<?= count($trail) ?>)
                    </a>
                    <div class="d-none mt-2" id="taudit-<?= $topupId ?>">
                        <div class="p-2" style="background:#fafbfc;border-radius:10px;border:1px solid #eef0f3;">
                            <?php foreach ($trail as $entry): ?>
                                <?php
                                $entryActor = trim((string)($entry['fname'] ?? '') . ' ' . (string)($entry['lname'] ?? ''));
                                $entryActor = $entryActor !== '' ? $entryActor : 'User #' . (int)$entry['actor_id'];
                                $entryWhen = !empty($entry['created_at']) ? date('M j, Y g:i A', strtotime((string)$entry['created_at'])) : '';
                                ?>
                                <div class="audit-entry fs-sm">
                                    <span class="audit-action <?= h($entry['action']) ?>"><?= h(str_replace('_', ' ', $entry['action'])) ?></span>
                                    <span class="text-muted"> &middot; <?= h($entryActor) ?> &middot; <?= h($entryWhen) ?></span>
                                    <?php if (!empty($entry['reason'])): ?>
                                        <div><strong>Reason:</strong> <?= nl2br(h($entry['reason'])) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['comments'])): ?>
                                        <div><strong>Comment:</strong> <?= nl2br(h($entry['comments'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="d-flex gap-2 flex-wrap mt-3">
                <a href="view_loan.php?id=<?= urlencode((string)$loanId) ?>" class="btn btn-ghost btn-sm">
                    <i class="fas fa-eye"></i> View Loan
                </a>

                <?php if ($isReviewer): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm review-toggle-btn"
                            data-panel="topupReviewPanel-<?= $topupId ?>" aria-expanded="false">
                        <i class="fas fa-clipboard-check"></i> Review Top-Up
                        <i class="fas fa-chevron-down fs-xs"></i>
                    </button>
                <?php else: ?>
                    <span class="fs-xs text-muted align-self-center">Read-only for your role</span>
                <?php endif; ?>
            </div>

            <?php if ($isReviewer): ?>
                <div id="topupReviewPanel-<?= $topupId ?>" class="review-panel d-none">
                    <div class="review-tabs">
                        <button type="button" class="review-tab-btn tab-approve active" data-tab="tapprove-<?= $topupId ?>">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" class="review-tab-btn tab-reject" data-tab="treject-<?= $topupId ?>">
                            <i class="fas fa-times"></i> Reject
                        </button>
                        <button type="button" class="review-tab-btn tab-info" data-tab="tinfo-<?= $topupId ?>">
                            <i class="fas fa-circle-question"></i> Request More Info
                        </button>
                    </div>

                    <div class="review-tab-content" data-tab-content="tapprove-<?= $topupId ?>">
                        <form method="POST" action="review_topup_action.php">
                            <input type="hidden" name="topup_id" value="<?= $topupId ?>">
                            <input type="hidden" name="action" value="approve">
                            <label class="form-label fs-sm">Reviewer comments (optional)</label>
                            <textarea name="comments" class="form-control mb-3" rows="3" placeholder="Any notes for the audit trail..."></textarea>
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-success btn-sm"
                                        onclick="return confirm('Approve this top-up request?')">
                                    Confirm Approval
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="review-tab-content d-none" data-tab-content="treject-<?= $topupId ?>">
                        <form method="POST" action="review_topup_action.php"
                              onsubmit="return this.reason.value.trim().length > 0 || (alert('A rejection reason is required.') && false);">
                            <input type="hidden" name="topup_id" value="<?= $topupId ?>">
                            <input type="hidden" name="action" value="reject">
                            <label class="form-label fs-sm">Reason for rejection <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control mb-3" rows="2" required
                                      placeholder="Visible to the applicant and the assigned loan officer"></textarea>
                            <label class="form-label fs-sm">Additional comments (optional)</label>
                            <textarea name="comments" class="form-control mb-3" rows="2" placeholder="Internal note for the audit trail"></textarea>
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-danger btn-sm">Confirm Rejection</button>
                            </div>
                        </form>
                    </div>

                    <div class="review-tab-content d-none" data-tab-content="tinfo-<?= $topupId ?>">
                        <form method="POST" action="review_topup_action.php"
                              onsubmit="return this.info_details.value.trim().length > 0 || (alert('Please describe what information is needed.') && false);">
                            <input type="hidden" name="topup_id" value="<?= $topupId ?>">
                            <input type="hidden" name="action" value="request_info">
                            <label class="form-label fs-sm">What's needed from the applicant? <span class="text-danger">*</span></label>
                            <textarea name="info_details" class="form-control mb-3" rows="3" required
                                      placeholder="e.g. proof of continued income, updated collateral..."></textarea>
                            <label class="form-label fs-sm">Assign to loan officer <span class="text-danger">*</span></label>
                            <select name="officer_id" class="form-select mb-3" required>
                                <option value="">Select a loan officer...</option>
                                <?php foreach ($loanOfficers as $officer): ?>
                                    <option value="<?= (int)$officer['id'] ?>">
                                        <?= h(trim($officer['fname'] . ' ' . $officer['lname'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($loanOfficers)): ?>
                                <div class="text-danger fs-xs mb-3">No loan officers found. Add a user with role = 2 first.</div>
                            <?php endif; ?>
                            <label class="form-label fs-sm">Notes for the officer (optional)</label>
                            <textarea name="comments" class="form-control mb-3" rows="2" placeholder="Any extra context for the audit trail"></textarea>
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-info btn-sm">Send Request</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php endforeach; ?>

<?php endif; ?>

<script>
document.addEventListener('click', function (e) {
    var toggleBtn = e.target.closest('.review-toggle-btn');
    if (toggleBtn) {
        var panel = document.getElementById(toggleBtn.getAttribute('data-panel'));
        if (panel) {
            var nowHidden = panel.classList.toggle('d-none');
            toggleBtn.setAttribute('aria-expanded', nowHidden ? 'false' : 'true');
        }
        return;
    }

    var tabBtn = e.target.closest('.review-tab-btn');
    if (tabBtn) {
        var panelRoot = tabBtn.closest('.review-panel');
        if (!panelRoot) return;

        panelRoot.querySelectorAll('.review-tab-btn').forEach(function (btn) { btn.classList.remove('active'); });
        tabBtn.classList.add('active');

        panelRoot.querySelectorAll('.review-tab-content').forEach(function (content) { content.classList.add('d-none'); });

        var target = tabBtn.getAttribute('data-tab');
        var activeContent = panelRoot.querySelector('[data-tab-content="' + target + '"]');
        if (activeContent) activeContent.classList.remove('d-none');
    }
});
</script>

<?php require "footer.php"; ?>