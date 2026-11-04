<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../db.php';

function flashRedirect(string $message, string $type = 'error'): void
{
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    header("Location: ../profile.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flashRedirect('Invalid request method.');
}

if (!isset($_SESSION['logged_in'])) {
    flashRedirect('Access denied.');
}

if (!isset($conn) || !$conn instanceof mysqli) {
    flashRedirect('Database connection failed.');
}

$loggedInUserId = (int)$_SESSION['logged_in'];
$userId = (int)($_POST['user_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));

if ($userId <= 0 || $userId !== $loggedInUserId) {
    flashRedirect('Invalid user selected.');
}

if ($action === 'update_profile') {
    $fname = trim((string)($_POST['fname'] ?? ''));
    $lname = trim((string)($_POST['lname'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));

    if ($fname === '' || $lname === '' || $username === '' || $email === '') {
        flashRedirect('Please fill all required profile fields.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flashRedirect('Invalid email address.');
    }

    $checkStmt = $conn->prepare("
        SELECT id
        FROM user
        WHERE (username = ? OR email = ?)
        AND id <> ?
        LIMIT 1
    ");

    if (!$checkStmt) {
        flashRedirect('Validation failed.');
    }

    $checkStmt->bind_param('ssi', $username, $email, $userId);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($exists) {
        flashRedirect('Username or email is already used by another account.');
    }

    $stmt = $conn->prepare("
        UPDATE user
        SET fname = ?, lname = ?, username = ?, email = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        flashRedirect('Failed to prepare profile update.');
    }

    $stmt->bind_param('ssssi', $fname, $lname, $username, $email, $userId);

    if (!$stmt->execute()) {
        flashRedirect('Failed to update profile.');
    }

    $stmt->close();

    flashRedirect('Profile updated successfully.', 'success');
}

if ($action === 'update_password') {
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['con_password'] ?? '');

    if ($password === '' || $confirmPassword === '') {
        flashRedirect('Both password fields are required.');
    }

    if (strlen($password) < 6) {
        flashRedirect('Password must be at least 6 characters.');
    }

    if ($password !== $confirmPassword) {
        flashRedirect('Passwords do not match.');
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        UPDATE user
        SET password = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        flashRedirect('Failed to prepare password update.');
    }

    $stmt->bind_param('si', $hashedPassword, $userId);

    if (!$stmt->execute()) {
        flashRedirect('Failed to update password.');
    }

    $stmt->close();

    flashRedirect('Password updated successfully.', 'success');
}

flashRedirect('Unknown profile action.');