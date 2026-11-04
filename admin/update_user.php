<?php
session_start();
require "db.php";

// Must be POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: users.php?msg=Invalid request&type=error");
    exit;
}

// Check logged-in role (ONLY Admin can update users)
$loggedRole = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
if ($loggedRole !== 1) {
    $user = (int)($_POST['user'] ?? 0);
    header("Location: view_user.php?user={$user}&msg=Not Authorized for this Action&type=error");
    exit;
}

/* ---------- INPUT ---------- */
$userId = (int)($_POST['user'] ?? 0);
$email  = trim($_POST['email'] ?? '');
$role   = isset($_POST['role']) ? (int)$_POST['role'] : 0;
$pass   = trim($_POST['pass'] ?? '');

if ($userId <= 0) {
    header("Location: users.php?msg=Invalid user&type=error");
    exit;
}

/* ---------- VALIDATE ---------- */
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: view_user.php?user={$userId}&msg=Invalid email address&type=error");
    exit;
}

if ($role !== 0 && !in_array($role, [1, 2], true)) {
    header("Location: view_user.php?user={$userId}&msg=Invalid role selected&type=error");
    exit;
}

/* ---------- BUILD UPDATE ---------- */
$fields = [];
$params = [];
$types  = "";

// Update email if provided
if ($email !== '') {
    // Optional: prevent duplicate email (uncomment if needed)
    /*
    $chk = $conn->prepare("SELECT id FROM user WHERE email = ? AND id <> ? LIMIT 1");
    $chk->bind_param("si", $email, $userId);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        header("Location: view_user.php?user={$userId}&msg=Email already in use&type=error");
        exit;
    }
    $chk->close();
    */
    $fields[] = "email = ?";
    $params[] = $email;
    $types   .= "s";
}

// Update role if provided
if ($role !== 0) {
    $fields[] = "role = ?";
    $params[] = $role;
    $types   .= "i";
}

// Update password if provided
if ($pass !== '') {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $fields[] = "password = ?";
    $params[] = $hash;
    $types   .= "s";
}

if (empty($fields)) {
    header("Location: view_user.php?user={$userId}&msg=No changes provided&type=error");
    exit;
}

$params[] = $userId;
$types   .= "i";

$sql = "UPDATE user SET " . implode(", ", $fields) . " WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    header("Location: view_user.php?user={$userId}&msg=User updated successfully&type=success");
} else {
    header("Location: view_user.php?user={$userId}&msg=Failed to update user&type=error");
}
exit;
