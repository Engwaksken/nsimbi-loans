<?php
session_start();
require "../db.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function json_response($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}

function clean_money($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $clean = str_replace([',', ' '], '', $value);

    return is_numeric($clean) ? (float)$clean : false;
}

function can_manage_staff() {
    $role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
    return in_array($role, [1, 2], true);
}

function fetch_staff($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT 
            user.id AS user_table_id,
            user.fname,
            user.lname,
            user.email,
            user.status,
            members.*
        FROM members
        INNER JOIN user ON members.user_id = user.id
        WHERE members.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $staff;
}

$action = $_REQUEST['action'] ?? '';
$userId = (int)($_REQUEST['user_id'] ?? $_REQUEST['staff'] ?? 0);

if ($action === '') {
    json_response(false, 'Invalid action.');
}

if ($userId <= 0 && !in_array($action, ['update'], true)) {
    json_response(false, 'Invalid staff selected.');
}

if (in_array($action, ['edit', 'update', 'delete'], true) && !can_manage_staff()) {
    json_response(false, 'Not authorized for this action.');
}

/* VIEW STAFF */
if ($action === 'view') {
    $staff = fetch_staff($conn, $userId);

    if (!$staff) {
        echo '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Staff not found.</div>';
        exit;
    }
    ?>
    <div class="detail-grid">
        <div class="detail-item">
            <div class="lbl">Full Name</div>
            <div class="val"><?= e(($staff['fname'] ?? '') . ' ' . ($staff['lname'] ?? '')) ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Email</div>
            <div class="val"><?= e($staff['email'] ?? '—') ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Phone</div>
            <div class="val"><?= e($staff['phone'] ?? '—') ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Home Address</div>
            <div class="val"><?= e($staff['home_address'] ?? '—') ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Employer</div>
            <div class="val"><?= e($staff['employer'] ?? '—') ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Employment Status</div>
            <div class="val"><?= e($staff['employment_status'] ?? '—') ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Bank Name</div>
            <div class="val"><?= e($staff['bank_name'] ?? '—') ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Bank Account</div>
            <div class="val"><?= e($staff['bank_account'] ?? '—') ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Gross Salary</div>
            <div class="val">UGX <?= number_format((float)($staff['gross_salary'] ?? 0)) ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Net Salary / Loan Limit</div>
            <div class="val">UGX <?= number_format((float)($staff['loan_limit'] ?? 0)) ?></div>
        </div>
    </div>
    <?php
    exit;
}

/* EDIT FORM */
if ($action === 'edit') {
    $staff = fetch_staff($conn, $userId);

    if (!$staff) {
        echo '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Staff not found.</div>';
        exit;
    }
    ?>
    <form action="ajax/process-staff.php" method="POST" data-ajax="true">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="staff" value="<?= e($staff['user_id']) ?>">

        <div class="grid-2">
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= e($staff['phone'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Home Address</label>
                <input type="text" name="home_address" class="form-control" value="<?= e($staff['home_address'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Gross Salary</label>
                <input type="text" name="gross_salary" class="form-control" value="<?= e(number_format((float)($staff['gross_salary'] ?? 0))) ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Net Salary / Loan Limit</label>
                <input type="text" name="net_salary" class="form-control" value="<?= e(number_format((float)($staff['net_salary'] ?? 0))) ?>">
            </div>
        </div>

        <div class="mt-4">
            <div class="form-section-title">
                <i class="fas fa-key"></i>
                Reset Password
            </div>
            <p class="text-muted fs-sm mb-3">
                Leave these fields blank if you do not want to change this staff member's password.
            </p>

            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <div class="input-group">
                        <input
                            type="password"
                            name="new_password"
                            id="staff-new-password"
                            class="form-control"
                            minlength="8"
                            autocomplete="new-password"
                            placeholder="Minimum 8 characters"
                        >
                        <button
                            type="button"
                            class="btn btn-light"
                            data-password-toggle="staff-new-password"
                            title="Show or hide password"
                            aria-label="Show or hide password"
                        >
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <div class="input-group">
                        <input
                            type="password"
                            name="confirm_password"
                            id="staff-confirm-password"
                            class="form-control"
                            minlength="8"
                            autocomplete="new-password"
                            placeholder="Repeat new password"
                        >
                        <button
                            type="button"
                            class="btn btn-light"
                            data-password-toggle="staff-confirm-password"
                            title="Show or hide password"
                            aria-label="Show or hide password"
                        >
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
<div class="d-flex justify-end gap-2 mt-4">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </div>
    </form>
    <?php
    exit;
}

/* LOAN HISTORY */
if ($action === 'loans') {
    $staff = fetch_staff($conn, $userId);

    if (!$staff) {
        echo '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Staff not found.</div>';
        exit;
    }

    $loanStmt = $conn->prepare("
        SELECT *
        FROM loan
        WHERE client_id = ?
        ORDER BY id DESC
    ");
    $loanStmt->bind_param("i", $userId);
    $loanStmt->execute();
    $loans = $loanStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $loanStmt->close();
    ?>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Loan No</th>
                    <th>Amount</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!empty($loans)): ?>
                    <?php $i = 1; foreach ($loans as $loan): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= e($loan['loan_no'] ?? $loan['id']) ?></td>
                            <td>UGX <?= number_format((float)($loan['loan_amount'] ?? $loan['amount'] ?? 0)) ?></td>
                            <td>UGX <?= number_format((float)($loan['loan_balance'] ?? 0)) ?></td>
                            <td>
                                <span class="badge badge-primary">
                                    <?= e($loan['status'] ?? '—') ?>
                                </span>
                            </td>
                            <td><?= e($loan['created_at'] ?? $loan['date_created'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="fas fa-file-invoice-dollar"></i>
                                <h3>No loan history found</h3>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
    exit;
}

/* UPDATE STAFF */
if ($action === 'update') {
    if (!can_manage_staff()) {
        json_response(false, 'Not authorized for this action.');
    }

    $staffId = (int)($_POST['staff'] ?? 0);

    if ($staffId <= 0) {
        json_response(false, 'Invalid staff ID.');
    }

    $staff = fetch_staff($conn, $staffId);

    if (!$staff) {
        json_response(false, 'Staff not found.');
    }

    $grossSalary     = clean_money($_POST['gross_salary'] ?? '');
    $netSalary       = clean_money($_POST['net_salary'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $homeAddress     = trim($_POST['home_address'] ?? '');
    $newPassword     = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($grossSalary === false) {
        json_response(false, 'Invalid gross salary format.');
    }

    if ($netSalary === false) {
        json_response(false, 'Invalid net salary format.');
    }

    if ($newPassword !== '' || $confirmPassword !== '') {
        if ($newPassword === '' || $confirmPassword === '') {
            json_response(false, 'Enter and confirm the new password.');
        }

        if (strlen($newPassword) < 8) {
            json_response(false, 'New password must be at least 8 characters.');
        }

        if (!hash_equals($newPassword, $confirmPassword)) {
            json_response(false, 'New password and confirmation do not match.');
        }
    }

    $grossSalary = ($grossSalary === null) ? (float)$staff['gross_salary'] : $grossSalary;
    $netSalary   = ($netSalary === null) ? (float)$staff['net_salary'] : $netSalary;
    $phone       = ($phone !== '') ? $phone : $staff['phone'];
    $homeAddress = ($homeAddress !== '') ? $homeAddress : $staff['home_address'];

    $conn->begin_transaction();

    try {
        $update = $conn->prepare("
            UPDATE members
            SET gross_salary = ?,
                net_salary = ?,
                phone = ?,
                loan_limit = ?,
                home_address = ?
            WHERE user_id = ?
        ");

        $update->bind_param(
            "ddsdsi",
            $grossSalary,
            $netSalary,
            $phone,
            $netSalary,
            $homeAddress,
            $staffId
        );

        if (!$update->execute()) {
            throw new RuntimeException('Failed to update staff details.');
        }

        $update->close();

        $passwordChanged = false;

        if ($newPassword !== '') {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            if ($passwordHash === false) {
                throw new RuntimeException('Failed to secure the new password.');
            }

            $passwordUpdate = $conn->prepare("
                UPDATE user
                SET password = ?
                WHERE id = ?
            ");

            $passwordUpdate->bind_param("si", $passwordHash, $staffId);

            if (!$passwordUpdate->execute()) {
                throw new RuntimeException('Failed to reset staff password.');
            }

            $passwordUpdate->close();
            $passwordChanged = true;
        }

        $conn->commit();

        json_response(
            true,
            $passwordChanged
                ? 'Staff details updated and password reset successfully.'
                : 'Staff successfully updated.'
        );
    } catch (Throwable $e) {
        $conn->rollback();
        json_response(false, $e->getMessage());
    }
}

/* DELETE STAFF */
if ($action === 'delete') {
    if (!can_manage_staff()) {
        json_response(false, 'Not authorized for this action.');
    }

    $staff = fetch_staff($conn, $userId);

    if (!$staff) {
        json_response(false, 'Staff not found.');
    }

    $loanCheck = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM loan
        WHERE client_id = ?
        AND status NOT IN ('CLEARED', 'CANCELLED', 'REJECTED')
    ");
    $loanCheck->bind_param("i", $userId);
    $loanCheck->execute();
    $activeLoans = (int)$loanCheck->get_result()->fetch_assoc()['total'];
    $loanCheck->close();

    if ($activeLoans > 0) {
        json_response(false, 'This staff member has active loans and cannot be deleted.');
    }

    $conn->begin_transaction();

    try {
        $deleteMember = $conn->prepare("DELETE FROM members WHERE user_id = ?");
        $deleteMember->bind_param("i", $userId);
        $deleteMember->execute();
        $deleteMember->close();

        $deleteUser = $conn->prepare("DELETE FROM user WHERE id = ?");
        $deleteUser->bind_param("i", $userId);
        $deleteUser->execute();
        $deleteUser->close();

        $conn->commit();

        json_response(true, 'Staff deleted successfully.');
    } catch (Throwable $e) {
        $conn->rollback();
        json_response(false, 'Delete failed: ' . $e->getMessage());
    }
}

json_response(false, 'Invalid action.');