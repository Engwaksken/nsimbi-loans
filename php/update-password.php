<?php
session_start();
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$token || !$password || !$confirm_password) {
        $_SESSION['status'] = "All fields are required.";
        header("Location: ../reset-password.php?token=$token");
        exit;
    }

    if ($password !== $confirm_password) {
        $_SESSION['status'] = "Passwords do not match.";
        header("Location: ../reset-password.php?token=$token");
        exit;
    }

    $stmt = $conn->prepare("SELECT email, expires_at FROM password_resets WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($reset = $result->fetch_assoc()) {
        if (strtotime($reset['expires_at']) < time()) {
            $_SESSION['status'] = "Token has expired.";
            header("Location: ../forgot-password.php");
            exit;
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $email = $reset['email'];

        $stmt = $conn->prepare("UPDATE user SET password = ? WHERE email = ?");
        $stmt->bind_param("ss", $hashed_password, $email);
        $stmt->execute();

        $conn->query("DELETE FROM password_resets WHERE email = '$email'");

        $_SESSION['status'] = "Password successfully updated. You can now log in.";
        header("Location: ../login.php");
        exit;
    } else {
        $_SESSION['status'] = "Invalid or expired token.";
        header("Location: ../forgot-password.php");
        exit;
    }
}
?>
