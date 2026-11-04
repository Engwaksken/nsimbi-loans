<?php
require "db.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fname   = trim($_POST['fname'] ?? '');
    $lname   = trim($_POST['lname'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $admin   = intval($_POST['admin'] ?? 0);
    $role    = isset($_POST['role']) ? intval($_POST['role']) : 1;

    if (empty($fname) || empty($lname) || empty($email) || empty($password)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'All fields are required.'];
        header("Location: add_user.php");
        exit;
    }

    if ($admin !== 1) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Not authorized for this action.'];
        header("Location: users.php");
        exit;
    }

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert into database
    $stmt = $conn->prepare("INSERT INTO user (fname, lname, role, email, password, status) VALUES (?, ?, ?, ?, ?, 1)");
    if ($stmt) {
        $stmt->bind_param("ssiss", $fname, $lname, $role, $email, $hashed_password);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'User added successfully.'];
            header("Location: add_user.php");
            exit;
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to add user.'];
            header("Location: add_user.php");
            exit;
        }
    } else {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Database error.'];
        header("Location: add_user.php");
        exit;
    }

} else {
    header("Location: users.php");
    exit;
}
?>
