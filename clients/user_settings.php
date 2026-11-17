<?php
/**
 * Changes the logged-in client's password (Change Password modal on
 * profile.php). Requires the current password.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require "db.php";

function back(string $message, string $type = 'error'): void
{
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    $_SESSION['message_target'] = $type === 'success' ? '' : 'password';
    header('Location: profile.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);

if ($user_id <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php');
    exit;
}

$current = (string)($_POST['current_password'] ?? '');
$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['con_password'] ?? '');

if ($current === '' || $password === '' || $confirm === '') {
    back('Please fill in all password fields.');
}
if (strlen($password) < 8) {
    back('New password must be at least 8 characters.');
}
if ($password !== $confirm) {
    back('The new passwords do not match.');
}

try {
    $stmt = $conn->prepare("SELECT password FROM user WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current, (string)$row['password'])) {
        back('Your current password is incorrect.');
    }
    if (password_verify($password, (string)$row['password'])) {
        back('Your new password must be different from the current one.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE user SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hash, $user_id);
    $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log('Client password change failed for user ' . $user_id . ': ' . $e->getMessage());
    back('Your password could not be updated. Please try again.');
}

back('Password updated successfully.', 'success');
