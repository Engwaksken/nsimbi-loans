<?php
session_start();
require 'db.php';
require_once 'mail-function.php';

function generateToken() {
    return bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $_SESSION['status'] = "Email is required.";
        header('Location: ../forgot-password.php');
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM user WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        $token = generateToken();
        $expires = date("Y-m-d H:i:s", strtotime("+1 hour"));

        $conn->query("DELETE FROM password_resets WHERE email = '$email'");
        $stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $email, $token, $expires);
        $stmt->execute();

        $resetLink = "https://nsimbiimpact.com/loans/reset-password.php?token=$token";
        sendPasswordResetEmail($email, $resetLink);
        $_SESSION['status'] = "Reset link sent to your email: $email";
        header('Location: ../forgot-password.php');
        exit;
    } else {
        $_SESSION['status'] = "No account found with that email.";
        header('Location: ../forgot-password.php');
        exit;
    }
}
?>
