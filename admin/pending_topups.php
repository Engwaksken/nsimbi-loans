<?php
declare(strict_types=1);

$phead = 'Pending Loan Top-Ups';
require "nav-head.php";
require "services_check.php";
require_once __DIR__ . "/loan_audit.php";
require_once __DIR__ . "/../includes/loan_assessment.php";

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
        u.fname, u.lname, u.email AS client_email,
        c.phone AS client_phone,
        c.business_type AS assess_business_type,
        c.amount_last AS assess_amount_last,
        c.amount_this AS assess_amount_this,
        officer.fname AS officer_fname, officer.lname AS officer_lname
    FROM loan_topup_requests t
    INNER JOIN loan l ON l.id = t.loan_id
    LEFT JOIN user u ON u.id = t.client_id
    LEFT JOIN customers c ON c.user_id = t.client_id
    LEFT JOIN user officer ON officer.id = t.assigned_officer_id
    WHERE t.status IN ('PENDING', 'INFO_PROVIDED')" . (officer_scope_id() > 0
        // Officers see their own loans plus requests sent to them (e.g. staff top-ups).
        ? ' AND (l.assigned_officer_id = ' . officer_scope_id() . ' OR t.assigned_officer_id = ' . officer_scope_id() . ')'
        : '') . "
    ORDER BY t.id DESC
";

// The database throws on errors (mysqli strict mode), so a schema difference
// must not take the whole page down: show the problem instead of a blank page.
$topups = [];
$loadError = '';
try {
    $result = $conn->query($sql);
    while ($result && ($row = $result->fetch_assoc())) {
        $topups[] = $row;
    }
} catch (Throwable $e) {
    error_log('pending_topups: ' . $e->getMessage());
    $loadError = $e->getMessage();
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

.pending-table-card { overflow: hidden; }
.pending-table { margin: 0; }
.pending-table thead th { white-space: nowrap; }
.pending-table tr.topup-row td:first-child { box-shadow: inset 4px 0 0 #f59e0b; }
.pending-table tr.topup-row.status-info_requested td:first-child { box-shadow: inset 4px 0 0 #0ea5e9; }
.pending-table tr.topup-row.status-info_provided td:first-child { box-shadow: inset 4px 0 0 #7e22ce; }
.pending-table .applicant-name { font-weight: 700; color: var(--text-dark); font-size: .88rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 240px; }
.pending-table .amount-main { font-weight: 800; color: var(--primary); font-family: 'Outfit', sans-serif; }
.pending-table .ai-amount { font-weight: 700; color: #1d4ed8; }
.pending-table .row-actions { display: inline-flex; gap: .35rem; flex-wrap: nowrap; justify-content: flex-end; }

.cell-av { width: 34px; height: 34px; font-size: .85rem; border-radius: 50%; background: #f59e0b; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; flex-shrink: 0; }

.review-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .9rem 1.2rem;
    padding: .9rem 1rem; border: 1px solid #e5e7eb; border-radius: 12px; background: #f8fafc; }
.review-summary .rs-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
.review-summary .rs-value { font-weight: 700; color: #111827; overflow-wrap: anywhere; }
.review-summary .amount-main { font-weight: 800; color: var(--primary); font-family: 'Outfit', sans-serif; }
.review-modal .review-panel { margin-top: 1rem; }

.fs-sm { font-size: .85rem; }
.fs-xs { font-size: .72rem; }
.text-muted { color: #64748b !important; }

.badge-purple { background: #f3e8ff; color: #7e22ce; }
.badge-secondary { background: #e5e7eb; color: #374151; }
.badge-info { background: #e0f2fe; color: #0369a1; }

.workflow-note { margin-top: .8rem; padding: .6rem .8rem; border-radius: 10px; background: #f8fafc; border: 1px dashed #cbd5e1; font-size: .82rem; color: #334155; }
.workflow-note strong { color: #111827; }

.audit-entry { border-bottom: 1px solid #eef0f3; padding: .55rem 0; }
.audit-entry:last-child { border-bottom: 0; }
.audit-action { font-weight: 700; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
.audit-action.topup_approved { color: #16a34a; }
.audit-action.topup_rejected { color: #dc2626; }
.audit-action.topup_info_requested { color: #0369a1; }
.audit-action.topup_info_provided { color: #7e22ce; }
.audit-action.topup_requested { color: #6b7280; }

.review-panel { border: 1px solid #e5e7eb; border-radius: 14px; background: #fbfcfd; padding: 1rem; }
.review-tabs { display: flex; gap: .35rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem; flex-wrap: wrap; }
.review-tab-btn { border: none; background: transparent; padding: .5rem 1rem; font-size: .8rem; font-weight: 700; color: #64748b; border-bottom: 3px solid transparent; border-radius: 8px 8px 0 0; cursor: pointer; }
.review-tab-btn:hover { background: #f1f5f9; }
.review-tab-btn.tab-approve.active { color: #16a34a; border-bottom-color: #16a34a; background: #f0fdf4; }
.review-tab-btn.tab-reject.active { color: #dc2626; border-bottom-color: #dc2626; background: #fef2f2; }
.review-tab-btn.tab-info.active { color: #0369a1; border-bottom-color: #0369a1; background: #f0f9ff; }
.review-tab-content.d-none { display: none; }
.d-none { display: none; }

.pending-table .col-pick { width: 44px; padding-right: 0; }
.pending-table tr.topup-row.selected-topup td { background: #eff6ff; }
.bulk-bar { display: flex; align-items: center; flex-wrap: wrap; gap: .75rem; padding: 1rem;
    border: 1px solid #cbd5e1; border-left: 5px solid #16a34a; background: #f0fdf4; border-radius: 14px; margin-bottom: 1rem; }
.bulk-bar .selection-count { font-weight: 800; color: #166534; }
.bulk-bar .bulk-comment { flex: 1 1 220px; max-width: 360px; }
.topup-pick { width: 19px; height: 19px; accent-color: #16a34a; cursor: pointer; flex-shrink: 0; }

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

<?php if ($loadError !== ''): ?>
    <div class="alert alert-danger">Could not load top-up requests: <?= h($loadError) ?></div>
<?php endif; ?>

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

    <?php if ($isReviewer): ?>
    <form method="POST" action="review_topup_action.php" id="bulkTopupForm">
        <input type="hidden" name="action" value="bulk_approve">
        <div class="bulk-bar" aria-label="Bulk top-up approval">
            <label class="d-flex align-items-center gap-2 mb-0">
                <input class="topup-pick" type="checkbox" id="selectAllTopups" aria-label="Select all top-up requests">
                <strong>Select all</strong>
            </label>
            <span class="selection-count" id="topupSelectionCount" aria-live="polite">0 selected</span>
            <button class="btn btn-sm btn-outline-secondary" id="clearTopupSelection" type="button">Clear</button>
            <input type="text" name="comments" class="form-control form-control-sm bulk-comment"
                   placeholder="Reviewer comment for all selected (optional)" aria-label="Reviewer comment">
            <button class="btn btn-success btn-sm ms-auto" type="submit" id="submitBulkTopups" disabled>
                <i class="fas fa-check-double"></i> Approve selected
            </button>
        </div>
    </form>
    <?php endif; ?>

    <div class="card mb-3 pending-table-card">
        <div class="table-wrap">
            <table class="table pending-table">
                <thead>
                    <tr>
                        <?php if ($isReviewer): ?><th class="col-pick"></th><?php endif; ?>
                        <th>Applicant</th>
                        <th>Status</th>
                        <th>Top-up</th>
                        <th>Current loan</th>
                        <th>AI recommended</th>
                        <th>Requested</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
    <?php $reviewModals = []; ?>
    <?php foreach ($topups as $topup): ?>
        <?php
        $topupId = (int)$topup['id'];
        $loanId = (int)$topup['loan_id'];
        $status = strtoupper(trim((string)($topup['status'] ?? 'PENDING')));

        $clientName = trim((string)($topup['fname'] ?? '') . ' ' . (string)($topup['lname'] ?? ''));
        $clientName = $clientName !== '' ? $clientName : ('Client #' . (int)$topup['client_id']);
        $initials = strtoupper(substr($clientName, 0, 1));
        $phone = trim((string)($topup['client_phone'] ?? ''));
        $contact = $phone !== '' ? $phone : 'Client ID: ' . (int)$topup['client_id'];

        $requestedDate = !empty($topup['created_at']) ? date('M j, Y', strtotime((string)$topup['created_at'])) : 'N/A';
        $period = (int)($topup['loan_period'] ?? 0);
        $periodLabel = '+' . $period . ' month' . ($period === 1 ? '' : 's');

        $officerName = trim((string)($topup['officer_fname'] ?? '') . ' ' . (string)($topup['officer_lname'] ?? ''));
        $officerResponse = trim((string)($topup['officer_response'] ?? ''));
        $infoRequestDetails = trim((string)($topup['info_request_details'] ?? ''));

        $trail = $auditTrailByTopup[$topupId] ?? [];

        try {
            $aiAmount = ai_recommended_amount($topup);
        } catch (Throwable $e) {
            error_log('pending_topups AI amount: ' . $e->getMessage());
            $aiAmount = 0;
        }
        ?>
                    <tr class="topup-row status-<?= h(strtolower($status)) ?>">
                        <?php if ($isReviewer): ?>
                            <td class="col-pick">
                                <input type="checkbox" class="topup-pick topup-row-check" form="bulkTopupForm"
                                       name="ids[]" value="<?= $topupId ?>" aria-label="Select top-up for <?= h($clientName) ?>">
                            </td>
                        <?php endif; ?>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="cell-av"><?= h($initials ?: 'U') ?></div>
                                <div style="min-width:0">
                                    <div class="applicant-name"><?= h($clientName) ?></div>
                                    <div class="fs-xs text-muted"><?= h($contact) ?></div>
                                    <div class="fs-xs text-muted">Loan #<?= $loanId ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= topupStatusBadge($status) ?>"><?= h(topupStatusLabel($status)) ?></span>
                            <?php if ($status === 'INFO_PROVIDED'): ?>
                                <div class="fs-xs text-muted mt-1"><i class="fas fa-reply"></i> <?= $officerName !== '' ? h($officerName) : 'Officer replied' ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <div class="amount-main"><?= money($topup['amount'] ?? 0) ?></div>
                            <div class="fs-xs text-muted"><?= h($periodLabel) ?></div>
                        </td>
                        <td class="text-nowrap">
                            <div><?= money($topup['current_amount'] ?? 0) ?></div>
                            <div class="fs-xs text-muted">Balance <?= money($topup['current_balance'] ?? 0) ?></div>
                        </td>
                        <td class="text-nowrap">
                            <?php if ($aiAmount > 0): ?>
                                <span class="ai-amount"><?= money($aiAmount) ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap"><?= h($requestedDate) ?></td>
                        <td class="text-end">
                            <div class="row-actions">
                                <a href="view_loan.php?id=<?= urlencode((string)$loanId) ?>" class="btn btn-ghost btn-sm" title="View loan">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <button type="button" class="btn <?= $isReviewer ? 'btn-outline-primary' : 'btn-ghost' ?> btn-sm"
                                        data-review-open="topupModal-<?= $topupId ?>" aria-haspopup="dialog">
                                    <i class="fas fa-<?= $isReviewer ? 'clipboard-check' : 'circle-info' ?>"></i> <?= $isReviewer ? 'Review' : 'Details' ?>
                                </button>
                            </div>
                            <?php if (!$isReviewer): ?>
                                <div class="fs-xs text-muted mt-1">Read-only for your role</div>
                            <?php endif; ?>
                        </td>
                    </tr>
        <?php ob_start(); ?>
<div class="modal-overlay review-modal" id="topupModal-<?= $topupId ?>" aria-hidden="true">
    <div class="modal-box md" role="dialog" aria-modal="true" aria-labelledby="topupModalTitle-<?= $topupId ?>">
        <div class="modal-header">
            <div class="modal-title">
                <div class="modal-ico"><i class="fas fa-<?= $isReviewer ? 'clipboard-check' : 'circle-info' ?>"></i></div>
                <span class="modal-title-text" id="topupModalTitle-<?= $topupId ?>"><?= $isReviewer ? 'Review top-up' : 'Top-up details' ?>: <?= h($clientName) ?></span>
            </div>
            <button type="button" class="modal-close" data-review-close aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="review-summary">
                <div>
                    <div class="rs-label">Applicant</div>
                    <div class="rs-value"><?= h($clientName) ?></div>
                    <div class="fs-xs text-muted"><?= h($contact) ?></div>
                </div>
                <div>
                    <div class="rs-label">Top-up</div>
                    <div class="rs-value amount-main"><?= money($topup['amount'] ?? 0) ?></div>
                    <div class="fs-xs text-muted"><?= h($periodLabel) ?></div>
                </div>
                <div>
                    <div class="rs-label">Current loan #<?= $loanId ?></div>
                    <div class="rs-value"><?= money($topup['current_amount'] ?? 0) ?></div>
                    <div class="fs-xs text-muted">Balance <?= money($topup['current_balance'] ?? 0) ?></div>
                </div>
                <div>
                    <div class="rs-label">AI recommended</div>
                    <div class="rs-value"><?= $aiAmount > 0 ? money($aiAmount) : '-' ?></div>
                </div>
                <div>
                    <div class="rs-label">Requested</div>
                    <div class="rs-value"><?= h($requestedDate) ?></div>
                    <div class="mt-1"><span class="badge <?= topupStatusBadge($status) ?>"><?= h(topupStatusLabel($status)) ?></span></div>
                </div>
            </div>

            <?php if (!empty($topup['request_notes'])): ?>
                <div class="workflow-note">
                    <strong><i class="fas fa-note-sticky"></i> Requester notes:</strong>
                    <div class="mt-1"><?= nl2br(h($topup['request_notes'])) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($status === 'INFO_PROVIDED'): ?>
                <div class="workflow-note">
                    <strong><i class="fas fa-reply"></i> Loan officer response<?= $officerName !== '' ? ' from ' . h($officerName) : '' ?>:</strong>
                    <div class="mt-1"><?= nl2br(h($officerResponse !== '' ? $officerResponse : 'No response text was recorded.')) ?></div>
                    <?php if ($infoRequestDetails !== ''): ?>
                        <div class="mt-2 text-muted"><strong>Original request:</strong> <?= nl2br(h($infoRequestDetails)) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($trail)): ?>
                <div class="mt-2">
                    <a class="fs-xs text-muted" href="#" onclick="document.getElementById('taudit-<?= $topupId ?>').classList.toggle('d-none'); return false;">
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
                                    <span class="text-muted"> | <?= h($entryActor) ?> | <?= h($entryWhen) ?></span>
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

            <?php if ($isReviewer): ?>
                <div class="review-panel">
                    <div class="review-tabs" role="tablist">
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
                        <form method="POST" action="review_topup_action.php">
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
                        <form method="POST" action="review_topup_action.php">
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
        <div class="modal-footer">
            <a href="view_loan.php?id=<?= urlencode((string)$loanId) ?>" class="btn btn-ghost"><i class="fas fa-eye"></i> Full loan details</a>
            <button type="button" class="btn btn-secondary" data-review-close>Close</button>
        </div>
    </div>
</div>
        <?php $reviewModals[] = ob_get_clean(); ?>
    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?= implode('', $reviewModals) ?>

<?php endif; ?>

<script>
function openReviewModal(id) {
    var modal = document.getElementById(id);
    if (!modal) return;
    if (window.Modal) { Modal.open(id); } else { modal.classList.add('open'); }
    modal.setAttribute('aria-hidden', 'false');
}
function closeReviewModal(id) {
    var modal = document.getElementById(id);
    if (!modal) return;
    if (window.Modal) { Modal.close(id); } else { modal.classList.remove('open'); }
    modal.setAttribute('aria-hidden', 'true');
}
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelectorAll('.review-modal.open');
    if (open.length) closeReviewModal(open[open.length - 1].id);
});
document.addEventListener('click', function (e) {
    var openBtn = e.target.closest('[data-review-open]');
    if (openBtn) {
        openReviewModal(openBtn.getAttribute('data-review-open'));
        return;
    }
    var openModal = e.target.closest('.review-modal');
    if (openModal && (e.target === openModal || e.target.closest('[data-review-close]'))) {
        closeReviewModal(openModal.id);
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

// Multi-select: tick top-ups and approve them together.
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('bulkTopupForm');
    if (!form) return;
    var checks = Array.prototype.slice.call(document.querySelectorAll('.topup-row-check'));
    var selectAll = document.getElementById('selectAllTopups');
    var counter = document.getElementById('topupSelectionCount');
    var submit = document.getElementById('submitBulkTopups');

    function selectedCount() {
        return checks.filter(function (x) { return x.checked; }).length;
    }
    function update() {
        var count = selectedCount();
        counter.textContent = count + ' selected';
        selectAll.checked = checks.length > 0 && count === checks.length;
        selectAll.indeterminate = count > 0 && count < checks.length;
        checks.forEach(function (x) { x.closest('tr').classList.toggle('selected-topup', x.checked); });
        submit.disabled = count === 0;
    }

    checks.forEach(function (x) { x.addEventListener('change', update); });
    selectAll.addEventListener('change', function () {
        checks.forEach(function (x) { x.checked = selectAll.checked; });
        update();
    });
    document.getElementById('clearTopupSelection').addEventListener('click', function () {
        checks.forEach(function (x) { x.checked = false; });
        update();
    });

    form.addEventListener('submit', function (e) {
        if (form.dataset.bulkOk === '1') { delete form.dataset.bulkOk; return; }
        e.preventDefault();
        var count = selectedCount();
        if (!count) return;
        var question = 'Approve ' + count + ' selected top-up request' + (count === 1 ? '' : 's') + '?';
        var ask = window.Popup ? Popup.confirm(question) : Promise.resolve(window.confirm(question));
        ask.then(function (ok) {
            if (!ok) return;
            form.dataset.bulkOk = '1';
            form.requestSubmit ? form.requestSubmit() : form.submit();
        });
    });

    update();
});
</script>

<?php require "footer.php"; ?>
