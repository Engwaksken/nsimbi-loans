<?php
require "db.php";            
session_start();

if (!isset($_POST['submit']) || !isset($_SESSION['logged_in'])) {
    $_SESSION['message'] = "Invalid request.";
    $_SESSION['message_type'] = "danger";
    header("Location: settings.php");
    exit;
}

$user_id = (int) $_SESSION['logged_in'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $password = $_POST['password'] ?? '';
    $con_password = $_POST['con_password'] ?? '';

    if (empty($password) || empty($con_password)) {
        $_SESSION['message'] = "Please fill in all fields.";
        $_SESSION['message_type'] = "danger";
        header("Location: settings.php");
        exit;
    }

    if ($password !== $con_password) {
        $_SESSION['message'] = "Passwords do not match.";
        $_SESSION['message_type'] = "danger";
        header("Location: settings.php");
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE user SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hashedPassword, $user_id);

    if ($stmt->execute()) {
        $_SESSION['message'] = "Password updated successfully.";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Error updating password.";
        $_SESSION['message_type'] = "danger";
    }

    $stmt->close();
    header("Location: settings.php");
    exit;
} else {
    $_SESSION['message'] = "Invalid request.";
    $_SESSION['message_type'] = "danger";
    header("Location: settings.php");
    exit;
}
