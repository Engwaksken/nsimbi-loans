<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../db.php';

/* ============================================================
   AUTH
============================================================ */
$sessionRole = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;

if (!in_array($sessionRole, [1, 2], true)) {
    $_SESSION['message'] = "Not Authorized for this Action";
    $_SESSION['message_type'] = "error";
    header("Location: ../dashboard.php");
    exit;
}

/* ============================================================
   HELPERS
============================================================ */
function redirectToClient(int $userId): void
{
    header("Location: ../view_client.php?user_id=" . urlencode((string)$userId));
    exit;
}

function redirectToClients(): void
{
    header("Location: ../clients.php");
    exit;
}

function flash(string $message, string $type = 'error'): void
{
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

function failAndRedirect(int $userId, string $message): void
{
    flash($message, 'error');
    redirectToClient($userId);
}

function cleanMoney($value): float
{
    $clean = str_replace([',', ' '], '', (string)$value);

    if ($clean === '') {
        return 0.0;
    }

    if (!is_numeric($clean)) {
        throw new RuntimeException("Invalid loan limit amount.");
    }

    return (float)$clean;
}

/* ============================================================
   REQUEST CHECK
============================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash("Invalid request method.", "error");
    redirectToClients();
}

if (!isset($conn) || !$conn instanceof mysqli) {
    flash("Database connection not found.", "error");
    redirectToClients();
}

$action  = trim((string)($_POST['action'] ?? ''));
$user_id = (int)($_POST['user_id'] ?? 0);

if ($user_id <= 0) {
    flash("Invalid client selected.", "error");
    redirectToClients();
}

/* ============================================================
   VERIFY CUSTOMER EXISTS
============================================================ */
$existsStmt = $conn->prepare("
    SELECT 
        u.id,
        u.role,
        c.id AS customer_id
    FROM user u
    INNER JOIN customers c ON c.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");

if (!$existsStmt) {
    failAndRedirect($user_id, "Failed to verify client.");
}

$existsStmt->bind_param("i", $user_id);
$existsStmt->execute();
$exists = $existsStmt->get_result()->fetch_assoc();
$existsStmt->close();

if (!$exists) {
    failAndRedirect($user_id, "Client record was not found.");
}

/* ============================================================
   UPDATE CLIENT
============================================================ */
if ($action === 'update_client') {
    $fname         = trim((string)($_POST['fname'] ?? ''));
    $lname         = trim((string)($_POST['lname'] ?? ''));
    $username      = trim((string)($_POST['username'] ?? ''));
    $email         = trim((string)($_POST['email'] ?? ''));
    $phone         = trim((string)($_POST['phone'] ?? ''));
    $nin           = trim((string)($_POST['nin'] ?? ''));
    $gender        = trim((string)($_POST['gender'] ?? ''));
    $dob           = trim((string)($_POST['dob'] ?? ''));
    $bank_name     = trim((string)($_POST['bank_name'] ?? ''));
    $bank_account  = trim((string)($_POST['bank_account'] ?? ''));
    $business_name = trim((string)($_POST['business_name'] ?? ''));
    $business_type = trim((string)($_POST['business_type'] ?? ''));
    $business_period = trim((string)($_POST['business_period'] ?? ''));
    $amount_last   = trim((string)($_POST['amount_last'] ?? ''));
    $amount_this   = trim((string)($_POST['amount_this'] ?? ''));
    $district      = trim((string)($_POST['district'] ?? ''));
    $location      = trim((string)($_POST['location'] ?? ''));
    $nok_name      = trim((string)($_POST['nok_name'] ?? ''));
    $nok_tel       = trim((string)($_POST['nok_tel'] ?? ''));
    $nok_rel       = trim((string)($_POST['nok_rel'] ?? ''));
    $stock         = trim((string)($_POST['stock'] ?? ''));
    $license       = trim((string)($_POST['license'] ?? ''));
    $loanLimitRaw  = trim((string)($_POST['loan_limit'] ?? ''));
    $creditScoreRaw = trim((string)($_POST['credit_score'] ?? ''));
    $active        = isset($_POST['active']) ? (int)$_POST['active'] : 0;

    if ($fname === '' || $lname === '' || $username === '') {
        failAndRedirect($user_id, "First name, last name, and username are required.");
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        failAndRedirect($user_id, "Invalid email address.");
    }

    if ($dob !== '') {
        $dobCheck = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dobCheck || $dobCheck->format('Y-m-d') !== $dob) {
            failAndRedirect($user_id, "Invalid date of birth.");
        }
    } else {
        $dob = null;
    }

    try {
        $loan_limit = cleanMoney($loanLimitRaw);
        $amount_last_value = cleanMoney($amount_last);
        $amount_this_value = cleanMoney($amount_this);
    } catch (Throwable $e) {
        failAndRedirect($user_id, $e->getMessage());
    }

    $credit_score = 0;
    if ($creditScoreRaw !== '') {
        if (!is_numeric($creditScoreRaw)) {
            failAndRedirect($user_id, "Invalid credit score.");
        }

        $credit_score = max(0, min(100, (int)$creditScoreRaw));
    }

    $active = $active === 1 ? 1 : 0;

    mysqli_begin_transaction($conn);

    try {
        $checkUsername = $conn->prepare("
            SELECT id
            FROM user
            WHERE username = ? 
            AND id <> ?
            LIMIT 1
        ");

        if (!$checkUsername) {
            throw new RuntimeException("Failed to prepare username check.");
        }

        $checkUsername->bind_param("si", $username, $user_id);
        $checkUsername->execute();
        $usernameExists = $checkUsername->get_result()->fetch_assoc();
        $checkUsername->close();

        if ($usernameExists) {
            throw new RuntimeException("Username already exists.");
        }

        if ($email !== '') {
            $checkEmail = $conn->prepare("
                SELECT id
                FROM user
                WHERE email = ? 
                AND id <> ?
                LIMIT 1
            ");

            if (!$checkEmail) {
                throw new RuntimeException("Failed to prepare email check.");
            }

            $checkEmail->bind_param("si", $email, $user_id);
            $checkEmail->execute();
            $emailExists = $checkEmail->get_result()->fetch_assoc();
            $checkEmail->close();

            if ($emailExists) {
                throw new RuntimeException("Email address already exists.");
            }
        }

        $userStmt = $conn->prepare("
            UPDATE user
            SET 
                fname = ?, 
                lname = ?, 
                username = ?, 
                email = ?, 
                status = ?
            WHERE id = ?
            LIMIT 1
        ");

        if (!$userStmt) {
            throw new RuntimeException("Failed to prepare user update.");
        }

        $userStmt->bind_param(
            "ssssii",
            $fname,
            $lname,
            $username,
            $email,
            $active,
            $user_id
        );

        if (!$userStmt->execute()) {
            throw new RuntimeException("Failed to update user details.");
        }

        $userStmt->close();

        $customerStmt = $conn->prepare("
            UPDATE customers
            SET 
                gender = ?,
                dob = ?,
                phone = ?,
                bank_name = ?,
                bank_account = ?,
                email = ?,
                business_name = ?,
                business_type = ?,
                business_period = ?,
                amount_last = ?,
                amount_this = ?,
                district = ?,
                location = ?,
                nok_name = ?,
                nok_tel = ?,
                nok_rel = ?,
                nin = ?,
                loan_limit = ?,
                stock = ?,
                license = ?,
                credit_score = ?,
                active = ?
            WHERE user_id = ?
        ");

        if (!$customerStmt) {
            throw new RuntimeException("Failed to prepare customer update: " . $conn->error);
        }

        $customerStmt->bind_param(
            "sssssssssddssssssdsiiii",
            $gender,
            $dob,
            $phone,
            $bank_name,
            $bank_account,
            $email,
            $business_name,
            $business_type,
            $business_period,
            $amount_last_value,
            $amount_this_value,
            $district,
            $location,
            $nok_name,
            $nok_tel,
            $nok_rel,
            $nin,
            $loan_limit,
            $stock,
            $license,
            $credit_score,
            $active,
            $user_id
        );

        if (!$customerStmt->execute()) {
            throw new RuntimeException("Failed to update client profile: " . $customerStmt->error);
        }

        $customerStmt->close();

        mysqli_commit($conn);

        flash("Client successfully updated.", "success");

    } catch (Throwable $e) {
        mysqli_rollback($conn);
        flash($e->getMessage(), "error");
    }

    redirectToClient($user_id);
}

/* ============================================================
   RESET PASSWORD
============================================================ */
if ($action === 'reset_password') {
    $new_password     = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if ($new_password === '' || $confirm_password === '') {
        failAndRedirect($user_id, "Both password fields are required.");
    }

    if (strlen($new_password) < 6) {
        failAndRedirect($user_id, "Password must be at least 6 characters.");
    }

    if ($new_password !== $confirm_password) {
        failAndRedirect($user_id, "Passwords do not match.");
    }

    $roleStmt = $conn->prepare("
        SELECT role 
        FROM user 
        WHERE id = ? 
        LIMIT 1
    ");

    if (!$roleStmt) {
        failAndRedirect($user_id, "Failed to verify user role.");
    }

    $roleStmt->bind_param("i", $user_id);
    $roleStmt->execute();
    $roleData = $roleStmt->get_result()->fetch_assoc();
    $roleStmt->close();

    if ((int)($roleData['role'] ?? 0) === 1 && $sessionRole !== 1) {
        failAndRedirect($user_id, "You cannot reset this user's password.");
    }

    $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);

    $passStmt = $conn->prepare("
        UPDATE user
        SET password = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$passStmt) {
        failAndRedirect($user_id, "Failed to prepare password reset.");
    }

    $passStmt->bind_param("si", $hashedPassword, $user_id);

    if ($passStmt->execute()) {
        flash("Client password successfully reset.", "success");
    } else {
        flash("Failed to reset client password.", "error");
    }

    $passStmt->close();

    redirectToClient($user_id);
}

/* ============================================================
   UNKNOWN ACTION
============================================================ */
flash("Unknown action.", "error");
redirectToClient($user_id);