<?php
session_start();
require '../db.php';

require 'nav.php';
if (!isset($_SESSION['id'], $_SESSION['role']) || (int)$_SESSION['role'] !== 5) {
    $_SESSION['error'] = "Please login to continue.";
    header('Location: ../index.php');
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found.");
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function clean_input($value): string
{
    return htmlspecialchars(strip_tags(trim((string)$value)), ENT_QUOTES, 'UTF-8');
}

$user_id = (int)$_SESSION['id'];


$stmt = $conn->prepare("
    SELECT id, fname, lname, username, email, role, password, status
    FROM user
    WHERE id = ? AND role = 5
    LIMIT 1
");

if (!$stmt) {
    $_SESSION['error'] = "Failed to load account.";
    header('Location: dashboard.php');
    exit();
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$user) {
    $_SESSION['error'] = "Startup account not found.";
    header('Location: ../index.php');
    exit();
}

if ((int)($user['status'] ?? 0) !== 1) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['error'] = "Your account is inactive.";
    header('Location: ../index.php');
    exit();
}

/* ---------------------------
   HANDLE FORM SUBMISSION
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $_SESSION['error'] = "All password fields are required.";
        header('Location: change-password.php');
        exit();
    }

    if (!password_verify($current_password, (string)$user['password'])) {
        $_SESSION['error'] = "Current password is incorrect.";
        header('Location: change-password.php');
        exit();
    }

    if (strlen($new_password) < 6) {
        $_SESSION['error'] = "New password must be at least 6 characters.";
        header('Location: change-password.php');
        exit();
    }

    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = "New password and confirm password do not match.";
        header('Location: change-password.php');
        exit();
    }

    if ($current_password === $new_password) {
        $_SESSION['error'] = "New password must be different from current password.";
        header('Location: change-password.php');
        exit();
    }

    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    $update = $conn->prepare("
        UPDATE user
        SET password = ?
        WHERE id = ? AND role = 5
        LIMIT 1
    ");

    if (!$update) {
        $_SESSION['error'] = "Failed to prepare password update.";
        header('Location: change-password.php');
        exit();
    }

    $update->bind_param("si", $hashed_password, $user_id);

    if ($update->execute()) {
        $update->close();
        session_regenerate_id(true);
        $_SESSION['success'] = "Password changed successfully.";
        header('Location: change-password.php');
        exit();
    } else {
        $update->close();
        $_SESSION['error'] = "Failed to change password.";
        header('Location: change-password.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/all.min.css">
    <style>
        body {
            background: #f6f8fb;
        }
        .page-wrap {
            padding: 28px 0;
        }
        .password-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(0,0,0,.06);
        }
        .page-header {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            color: #fff;
            border-radius: 18px;
            padding: 26px;
            margin-bottom: 24px;
        }
        .icon-circle {
            width: 68px;
            height: 68px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,.14);
            border: 2px solid rgba(255,255,255,.20);
            font-size: 28px;
        }
    </style>
</head>
<body>

<div class="container page-wrap">

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col-md-8 d-flex align-items-center gap-3">
                <div class="icon-circle">
                    <i class="fas fa-key"></i>
                </div>
                <div>
                    <h2 class="mb-1">Change Password</h2>
                    <div>
                        Account: <strong><?= h($user['username'] ?? '') ?></strong>
                    </div>
                </div>
            </div>

            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="dashboard.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Dashboard
                </a>
                <a href="profile.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-user-edit me-1"></i> Profile
                </a>
            </div>
        </div>
    </div>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= h($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= h($_SESSION['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card password-card">
                <div class="card-body p-4">
                    <h5 class="mb-4">Update Login Password</h5>

                    <form method="post" action="">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <input type="password" class="form-control" name="current_password" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="new_password" required minlength="6">
                            <small class="text-muted">Use at least 6 characters.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" name="confirm_password" required minlength="6">
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Change Password
                            </button>
                            <a href="dashboard.php" class="btn btn-light">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card password-card mt-4">
                <div class="card-body p-4">
                    <h6 class="mb-3">Password Tips</h6>
                    <div class="text-muted small">
                        Use a password you do not use elsewhere, mix letters and numbers, and avoid obvious words like your company name or phone number.
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

require 'footer.php';
</body>
</html>