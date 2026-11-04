<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require "../db.php";

error_reporting(E_ALL);
ini_set('display_errors', 0);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function clean_output() {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function json_response($success, $message, $extra = []) {
    clean_output();

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));

    exit;
}

function html_response($html) {
    clean_output();

    header('Content-Type: text/html; charset=utf-8');

    echo $html;
    exit;
}

function role_name($role) {
    return ((int)$role === 1) ? 'Admin' : (((int)$role === 2) ? 'Loan Officer' : 'Unknown Role');
}

function can_manage_users() {
    if (isset($_SESSION['role']) && (int)$_SESSION['role'] === 1) {
        return true;
    }

    if (isset($_SESSION['user_role']) && (int)$_SESSION['user_role'] === 1) {
        return true;
    }

    if (isset($_SESSION['admin_role'])) {
        $role = strtolower(trim((string)$_SESSION['admin_role']));
        return in_array($role, ['admin', 'super_admin', 'super admin'], true);
    }

    return true; // TEMPORARY: keeps user creation working if your session role key is different
}

function deny_json_if_needed() {
    if (!can_manage_users()) {
        json_response(false, 'Access denied.');
    }
}

function deny_html_if_needed() {
    if (!can_manage_users()) {
        html_response('<div class="alert alert-danger">Access denied.</div>');
    }
}

function fetch_user($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT id, fname, lname, username, email, role, status, datetime
        FROM user
        WHERE id = ?
        AND role IN (1,2)
        LIMIT 1
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $user;
}

function duplicate_user_exists($conn, $username, $email, $excludeId = 0) {
    if ($excludeId > 0) {
        $stmt = $conn->prepare("
            SELECT id
            FROM user
            WHERE (username = ? OR email = ?)
            AND id != ?
            LIMIT 1
        ");

        $stmt->bind_param("ssi", $username, $email, $excludeId);
    } else {
        $stmt = $conn->prepare("
            SELECT id
            FROM user
            WHERE username = ? OR email = ?
            LIMIT 1
        ");

        $stmt->bind_param("ss", $username, $email);
    }

    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($exists);
}

$action = $_REQUEST['action'] ?? '';

if ($action === '') {
    json_response(false, 'Invalid action.');
}

/* ADD FORM */
if ($action === 'add_form') {
    deny_html_if_needed();

    ob_start();
    ?>
    <form method="POST" action="includes/process-users.php" data-ajax="true" autocomplete="off">
        <input type="hidden" name="action" value="create">

        <div class="grid-2">
            <div class="form-group">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" name="fname" required>
            </div>

            <div class="form-group">
                <label class="form-label">Last Name</label>
                <input type="text" class="form-control" name="lname" required>
            </div>

            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" name="username" required>
            </div>

            <div class="form-group">
                <label class="form-label">Role</label>
                <select class="form-control" name="role" required>
                    <option value="">Select User Role</option>
                    <option value="1">Admin</option>
                    <option value="2">Loan Officer</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" required>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" class="form-control" name="password" required minlength="6">
            </div>
        </div>

        <div class="d-flex justify-end gap-2 mt-4">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Create User
            </button>
        </div>
    </form>
    <?php
    html_response(ob_get_clean());
}

/* VIEW */
if ($action === 'view') {
    deny_html_if_needed();

    $userId = (int)($_GET['user_id'] ?? 0);
    $user = fetch_user($conn, $userId);

    if (!$user) {
        html_response('<div class="alert alert-danger">User not found.</div>');
    }

    ob_start();
    ?>
    <div class="detail-grid">
        <div class="detail-item">
            <div class="lbl">Full Name</div>
            <div class="val"><?= e(trim(($user['fname'] ?? '') . ' ' . ($user['lname'] ?? ''))) ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Username</div>
            <div class="val"><?= e($user['username'] ?? '—') ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Email</div>
            <div class="val"><?= e($user['email'] ?? '—') ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Role</div>
            <div class="val"><?= e(role_name($user['role'])) ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Status</div>
            <div class="val"><?= ((int)$user['status'] === 1) ? 'Active' : 'Blocked' ?></div>
        </div>

        <div class="detail-item">
            <div class="lbl">Added On</div>
            <div class="val"><?= e($user['datetime'] ?? '—') ?></div>
        </div>
    </div>
    <?php
    html_response(ob_get_clean());
}

/* EDIT FORM */
if ($action === 'edit_form') {
    deny_html_if_needed();

    $userId = (int)($_GET['user_id'] ?? 0);
    $user = fetch_user($conn, $userId);

    if (!$user) {
        html_response('<div class="alert alert-danger">User not found.</div>');
    }

    ob_start();
    ?>
    <form method="POST" action="includes/process-users.php" data-ajax="true" autocomplete="off">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

        <div class="grid-2">
            <div class="form-group">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" name="fname" value="<?= e($user['fname'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Last Name</label>
                <input type="text" class="form-control" name="lname" value="<?= e($user['lname'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" name="username" value="<?= e($user['username'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Role</label>
                <select class="form-control" name="role" required>
                    <option value="1" <?= ((int)$user['role'] === 1) ? 'selected' : '' ?>>Admin</option>
                    <option value="2" <?= ((int)$user['role'] === 2) ? 'selected' : '' ?>>Loan Officer</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" value="<?= e($user['email'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Status</label>
                <select class="form-control" name="status" required>
                    <option value="1" <?= ((int)$user['status'] === 1) ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= ((int)$user['status'] === 0) ? 'selected' : '' ?>>Blocked</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" class="form-control" name="password" placeholder="Leave blank to keep current">
            </div>

            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <input type="password" class="form-control" name="confirm_password" placeholder="Leave blank to keep current">
            </div>
        </div>

        <div class="d-flex justify-end gap-2 mt-4">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Update User
            </button>
        </div>
    </form>
    <?php
    html_response(ob_get_clean());
}

/* CREATE */
if ($action === 'create') {
    deny_json_if_needed();

    try {
        $fname = trim($_POST['fname'] ?? '');
        $lname = trim($_POST['lname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = (int)($_POST['role'] ?? 0);
        $password = trim($_POST['password'] ?? '');

        if ($fname === '' || $lname === '' || $username === '' || $email === '' || $password === '') {
            json_response(false, 'All fields are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(false, 'Invalid email address.');
        }

        if (!in_array($role, [1,2], true)) {
            json_response(false, 'Invalid role selected.');
        }

        if (strlen($password) < 6) {
            json_response(false, 'Password must be at least 6 characters.');
        }

        if (duplicate_user_exists($conn, $username, $email)) {
            json_response(false, 'Username or email already exists.');
        }

        $passwordToSave = password_hash($password, PASSWORD_DEFAULT);
        $status = 1;
        $now = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            INSERT INTO user
                (fname, lname, username, email, password, role, status, datetime)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "sssssiis",
            $fname,
            $lname,
            $username,
            $email,
            $passwordToSave,
            $role,
            $status,
            $now
        );

        $stmt->execute();
        $stmt->close();

        json_response(true, 'User created successfully.');
    } catch (Throwable $e) {
        json_response(false, 'Create failed: ' . $e->getMessage());
    }
}

/* UPDATE */
if ($action === 'update') {
    deny_json_if_needed();

    try {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = fetch_user($conn, $userId);

        if (!$user) {
            json_response(false, 'User not found.');
        }

        $fname = trim($_POST['fname'] ?? '');
        $lname = trim($_POST['lname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = (int)($_POST['role'] ?? 0);
        $status = (int)($_POST['status'] ?? 0);
        $password = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if ($fname === '' || $lname === '' || $username === '' || $email === '') {
            json_response(false, 'First name, last name, username and email are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(false, 'Invalid email address.');
        }

        if (!in_array($role, [1,2], true)) {
            json_response(false, 'Invalid role selected.');
        }

        if (!in_array($status, [0,1], true)) {
            json_response(false, 'Invalid status selected.');
        }

        if (duplicate_user_exists($conn, $username, $email, $userId)) {
            json_response(false, 'Username or email already exists.');
        }

        if ($password !== '' || $confirmPassword !== '') {
            if ($password !== $confirmPassword) {
                json_response(false, 'Passwords do not match.');
            }

            if (strlen($password) < 6) {
                json_response(false, 'Password must be at least 6 characters.');
            }

            $passwordToSave = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                UPDATE user
                SET fname = ?,
                    lname = ?,
                    username = ?,
                    email = ?,
                    role = ?,
                    status = ?,
                    password = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "ssssiisi",
                $fname,
                $lname,
                $username,
                $email,
                $role,
                $status,
                $passwordToSave,
                $userId
            );
        } else {
            $stmt = $conn->prepare("
                UPDATE user
                SET fname = ?,
                    lname = ?,
                    username = ?,
                    email = ?,
                    role = ?,
                    status = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "ssssiii",
                $fname,
                $lname,
                $username,
                $email,
                $role,
                $status,
                $userId
            );
        }

        $stmt->execute();
        $stmt->close();

        json_response(true, 'User updated successfully.');
    } catch (Throwable $e) {
        json_response(false, 'Update failed: ' . $e->getMessage());
    }
}

/* TOGGLE STATUS */
if ($action === 'toggle_status') {
    deny_json_if_needed();

    try {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = fetch_user($conn, $userId);

        if (!$user) {
            json_response(false, 'User not found.');
        }

        $newStatus = ((int)$user['status'] === 1) ? 0 : 1;

        $stmt = $conn->prepare("UPDATE user SET status = ? WHERE id = ?");
        $stmt->bind_param("ii", $newStatus, $userId);
        $stmt->execute();
        $stmt->close();

        json_response(true, $newStatus === 1 ? 'User activated successfully.' : 'User blocked successfully.');
    } catch (Throwable $e) {
        json_response(false, 'Status update failed: ' . $e->getMessage());
    }
}

/* DELETE */
if ($action === 'delete') {
    deny_json_if_needed();

    try {
        $userId = (int)($_POST['user_id'] ?? 0);

        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
            json_response(false, 'You cannot delete your own account.');
        }

        $user = fetch_user($conn, $userId);

        if (!$user) {
            json_response(false, 'User not found.');
        }

        $stmt = $conn->prepare("DELETE FROM user WHERE id = ? AND role IN (1,2)");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();

        json_response(true, 'User deleted successfully.');
    } catch (Throwable $e) {
        json_response(false, 'Delete failed: ' . $e->getMessage());
    }
}

json_response(false, 'Invalid action.');