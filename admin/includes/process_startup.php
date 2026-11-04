<?php
session_start();
require "../db.php";

if (!isset($_SESSION['role']) || !in_array((int)$_SESSION['role'], [1, 2], true)) {
    $_SESSION['message'] = "Not authorized.";
    $_SESSION['message_type'] = "danger";
    header("Location: ../startups.php");
    exit;
}

function backNow()
{
    header("Location: ../startups.php");
    exit;
}

function uploadLogo(string $field = 'company_logo'): ?string
{
    if (empty($_FILES[$field]['name'])) {
        return null;
    }

    $dir = "uploads/startups/";
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $allowed, true)) {
        throw new Exception("Invalid logo file type.");
    }

    $name = 'startup_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $path = $dir . $name;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $path)) {
        throw new Exception("Failed to upload logo.");
    }

    return $path;
}

$action = trim($_POST['action'] ?? '');

if ($action === 'create') {
    $company_name      = trim($_POST['company_name'] ?? '');
    $ceo_name          = trim($_POST['ceo_name'] ?? '');
    $phone             = trim($_POST['phone'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $bank_name         = trim($_POST['bank_name'] ?? '');
    $account_name      = trim($_POST['account_name'] ?? '');
    $account_number    = trim($_POST['account_number'] ?? '');
    $bank_branch       = trim($_POST['bank_branch'] ?? '');
    $company_location  = trim($_POST['company_location'] ?? '');
    $startup_status    = trim($_POST['startup_status'] ?? 'active');
    $username          = trim($_POST['username'] ?? '');
    $password          = (string)($_POST['password'] ?? '');
    $user_status       = isset($_POST['user_status']) ? (int)$_POST['user_status'] : 1;

    if ($company_name === '' || $username === '' || $password === '') {
        $_SESSION['message'] = "Company name, username and password are required.";
        $_SESSION['message_type'] = "danger";
        backNow();
    }

    mysqli_begin_transaction($conn);

    try {
        $check = $conn->prepare("SELECT id FROM user WHERE username = ? LIMIT 1");
        $check->bind_param("s", $username);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            throw new Exception("Username already exists.");
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $role = 5;

        $fname = $company_name;
        $lname = 'Startup';

        $u = $conn->prepare("
            INSERT INTO user (fname, lname, username, role, password, email, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $u->bind_param("sssissi", $fname, $lname, $username, $role, $hashed, $email, $user_status);
        if (!$u->execute()) {
            throw new Exception("Failed to create startup user account.");
        }

        $startupUserId = (int)$u->insert_id;
        $u->close();

        $logoPath = uploadLogo();

        $s = $conn->prepare("
            INSERT INTO startups (
                startup_id, company_name, company_logo, ceo_name, phone, email, 
                bank_name, account_name, account_number, bank_branch, company_location, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $s->bind_param(
            "isssssssssss",
            $startupUserId,
            $company_name,
            $logoPath,
            $ceo_name,
            $phone,
            $email,
            $bank_name,
            $account_name,
            $account_number,
            $bank_branch,
            $company_location,
            $startup_status
        );

        if (!$s->execute()) {
            throw new Exception("Failed to create startup profile.");
        }
        $s->close();

        mysqli_commit($conn);
        $_SESSION['message'] = "Startup created successfully.";
        $_SESSION['message_type'] = "success";
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['message'] = $e->getMessage();
        $_SESSION['message_type'] = "danger";
    }

    backNow();
}

if ($action === 'update') {
    $id                = (int)($_POST['id'] ?? 0);
    $company_name      = trim($_POST['company_name'] ?? '');
    $ceo_name          = trim($_POST['ceo_name'] ?? '');
    $phone             = trim($_POST['phone'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $bank_name         = trim($_POST['bank_name'] ?? '');
    $account_name      = trim($_POST['account_name'] ?? '');
    $account_number    = trim($_POST['account_number'] ?? '');
    $bank_branch       = trim($_POST['bank_branch'] ?? '');
    $company_location  = trim($_POST['company_location'] ?? '');
    $startup_status    = trim($_POST['startup_status'] ?? 'active');
    $username          = trim($_POST['username'] ?? '');
    $password          = (string)($_POST['password'] ?? '');
    $user_status       = isset($_POST['user_status']) ? (int)$_POST['user_status'] : 1;

    if ($id <= 0 || $company_name === '' || $username === '') {
        $_SESSION['message'] = "Invalid startup details.";
        $_SESSION['message_type'] = "danger";
        backNow();
    }

    mysqli_begin_transaction($conn);

    try {
        $get = $conn->prepare("SELECT startup_id, company_logo FROM startups WHERE id = ? LIMIT 1");
        $get->bind_param("i", $id);
        $get->execute();
        $old = $get->get_result()->fetch_assoc();
        $get->close();

        if (!$old) {
            throw new Exception("Startup not found.");
        }

        $startupUserId = (int)$old['startup_id'];
        $logoPath = $old['company_logo'];

        $check = $conn->prepare("SELECT id FROM user WHERE username = ? AND id <> ? LIMIT 1");
        $check->bind_param("si", $username, $startupUserId);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            throw new Exception("Username already exists.");
        }

        $newLogo = uploadLogo();
        if ($newLogo !== null) {
            $logoPath = $newLogo;
        }

        if ($password !== '') {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $u = $conn->prepare("
                UPDATE user
                SET fname = ?, username = ?, email = ?, phone = ?, status = ?, password = ?
                WHERE id = ? AND role = 5
            ");
            $fname = $company_name;
            $u->bind_param("ssssisi", $fname, $username, $email, $phone, $user_status, $hashed, $startupUserId);
        } else {
            $u = $conn->prepare("
                UPDATE user
                SET fname = ?, username = ?, email = ?, status = ?
                WHERE id = ? AND role = 5
            ");
            $fname = $company_name;
            $u->bind_param("sssii", $fname, $username, $email, $user_status, $startupUserId);
        }

        if (!$u->execute()) {
            throw new Exception("Failed to update startup user account.");
        }
        $u->close();

        $s = $conn->prepare("
            UPDATE startups
            SET company_name = ?, company_logo = ?, ceo_name = ?, phone = ?, email = ?, 
                bank_name = ?, account_name = ?, account_number = ?, bank_branch = ?, 
                company_location = ?, status = ?
            WHERE id = ?
        ");

        $s->bind_param(
            "sssssssssssi",
            $company_name,
            $logoPath,
            $ceo_name,
            $phone,
            $email,
            $bank_name,
            $account_name,
            $account_number,
            $bank_branch,
            $company_location,
            $startup_status,
            $id
        );

        if (!$s->execute()) {
            throw new Exception("Failed to update startup profile.");
        }
        $s->close();

        mysqli_commit($conn);
        $_SESSION['message'] = "Startup updated successfully.";
        $_SESSION['message_type'] = "success";
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['message'] = $e->getMessage();
        $_SESSION['message_type'] = "danger";
    }

    backNow();
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $startupUserId = (int)($_POST['startup_user_id'] ?? 0);

    if ($id <= 0 || $startupUserId <= 0) {
        $_SESSION['message'] = "Invalid startup selected.";
        $_SESSION['message_type'] = "danger";
        backNow();
    }

    mysqli_begin_transaction($conn);

    try {
        $u = $conn->prepare("DELETE FROM user WHERE id = ? AND role = 5 LIMIT 1");
        $u->bind_param("i", $startupUserId);

        if (!$u->execute()) {
            throw new Exception("Failed to delete startup user.");
        }
        $u->close();

        mysqli_commit($conn);
        $_SESSION['message'] = "Startup deleted successfully.";
        $_SESSION['message_type'] = "success";
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['message'] = $e->getMessage();
        $_SESSION['message_type'] = "danger";
    }

    backNow();
}

$_SESSION['message'] = "Unknown action.";
$_SESSION['message_type'] = "danger";
backNow();