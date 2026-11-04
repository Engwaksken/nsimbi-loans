<?php
require "nav-head.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    exit("Database connection not established.");
}

if (!isset($_SESSION['user_id'])) {
    exit("User not logged in.");
}

$currentUserId = (int)$_SESSION['user_id'];
$currentRole   = strtolower(trim((string)($_SESSION['role'] ?? '')));

$allowedRoles = ['admin', 'administrator', 'loan officer', 'loan_officer', 'accountant'];
if (!in_array($currentRole, $allowedRoles, true)) {
    exit("Access denied.");
}

$conn->set_charset('utf8mb4');

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return number_format((float)$value, 2);
}

$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$sql = "
    SELECT r.*,
           l.status AS loan_status,
           l.effective_balance,
           m.first_name,
           m.last_name,
           m.telephone1
    FROM loan_repayment_requests r
    LEFT JOIN loan l ON l.id = r.loan_id
    LEFT JOIN members m ON m.user_id = r.client_id
    ORDER BY
        CASE r.status
            WHEN 'PENDING' THEN 0
            WHEN 'REJECTED' THEN 1
            WHEN 'APPROVED' THEN 2
            ELSE 3
        END,
        r.id DESC
";
$result = $conn->query($sql);
?>

<div class="container-fluid py-4">
    <?php if ($success !== ''): ?>
        <div class="alert alert-success text-white"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger text-white"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header pb-0">
            <h6>MANAGE LOAN REPAYMENT REQUESTS</h6>
        </div>

        <div class="card-body px-0 pt-0 pb-2">
            <div class="table-responsive p-0">
                <table class="table align-items-center mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Client</th>
                            <th>Loan</th>
                            <th>Amount</th>
                            <th>Balance Before</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Receipt</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): $i = 1; ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <?php
                                $status = strtoupper((string)$row['status']);
                                $badge = match ($status) {
                                    'APPROVED' => 'success',
                                    'REJECTED' => 'danger',
                                    'PENDING'  => 'warning',
                                    default    => 'secondary',
                                };
                                $clientName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                                ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td>
                                        <strong><?= e($clientName !== '' ? $clientName : 'Unknown Client') ?></strong><br>
                                        <small><?= e($row['telephone1'] ?? '') ?></small>
                                    </td>
                                    <td>#<?= (int)$row['loan_id'] ?></td>
                                    <td><?= money($row['requested_amount']) ?></td>
                                    <td><?= money($row['effective_balance'] ?? 0) ?></td>
                                    <td><?= e($row['payment_method']) ?></td>
                                    <td><?= e($row['reference_number']) ?></td>
                                    <td>
                                        <?php if (!empty($row['receipt_file'])): ?>
                                            <a target="_blank" href="<?= e($row['receipt_file']) ?>" class="badge bg-gradient-info">View</a>
                                        <?php else: ?>
                                            <span class="text-muted">No file</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-gradient-<?= $badge ?>"><?= e($row['status']) ?></span></td>
                                    <td><?= e($row['created_at']) ?></td>
                                    <td class="text-center">
                                        <?php if ($status === 'PENDING'): ?>
                                            <form action="approve_loan_repayment.php" method="POST" class="d-inline">
                                                <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="action_type" value="approve">
                                                <button type="submit" class="btn btn-sm bg-gradient-success mb-0">Approve</button>
                                            </form>

                                            <button
                                                type="button"
                                                class="btn btn-sm bg-gradient-danger mb-0"
                                                data-bs-toggle="modal"
                                                data-bs-target="#rejectModal<?= (int)$row['id'] ?>"
                                            >
                                                Reject
                                            </button>

                                            <div class="modal fade" id="rejectModal<?= (int)$row['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <form class="modal-content" action="approve_loan_repayment.php" method="POST">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Reject Repayment Request</h5>
                                                            <button type="button" class="btn-close text-dark" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
                                                            <input type="hidden" name="action_type" value="reject">

                                                            <label class="form-label">Reason</label>
                                                            <textarea name="review_notes" class="form-control" rows="4" required></textarea>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn bg-gradient-secondary" data-bs-dismiss="modal">Close</button>
                                                            <button type="submit" class="btn bg-gradient-danger">Reject</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">Reviewed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="11" class="text-center text-muted">No repayment requests found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require "footer.php"; ?>