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
function redirectToClient(int $userId, string $tab = 'edit'): void
{
    header("Location: ../view_client.php?user_id=" . urlencode((string)$userId) . "&tab=" . urlencode($tab));
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
    /*
     * Only fields actually present in the submitted form are written.
     * The edit form does not carry every column (gender, dob, bank details,
     * uploaded stock/license files, credit score, ...). Blanking those on
     * every save wiped client data and made the whole update fail (and roll
     * back) on strict columns, so edits never appeared to save.
     */
    $posted = static function (string $key): ?string {
        return array_key_exists($key, $_POST) ? trim((string)$_POST[$key]) : null;
    };

    $fname    = $posted('fname');
    $lname    = $posted('lname');
    $username = $posted('username');
    $email    = $posted('email');

    if ((string)$fname === '' || (string)$lname === '' || (string)$username === '') {
        failAndRedirect($user_id, "First name, last name, and username are required.");
    }

    if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        failAndRedirect($user_id, "Invalid email address.");
    }

    // column => [bind type, value]
    $userSets = [
        'fname'    => ['s', $fname],
        'lname'    => ['s', $lname],
        'username' => ['s', $username],
    ];
    $customerSets = [];

    if ($email !== null) {
        $userSets['email']     = ['s', $email];
        $customerSets['email'] = ['s', $email];
    }

    $textColumns = [
        'gender', 'phone', 'bank_name', 'bank_account', 'business_name', 'business_type',
        'business_period', 'district', 'location', 'nok_name', 'nok_tel', 'nok_rel', 'nin',
    ];
    foreach ($textColumns as $col) {
        $val = $posted($col);
        if ($val !== null) {
            $customerSets[$col] = ['s', $val];
        }
    }

    $dob = $posted('dob');
    if ($dob !== null && $dob !== '') {
        $dobCheck = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dobCheck || $dobCheck->format('Y-m-d') !== $dob) {
            failAndRedirect($user_id, "Invalid date of birth.");
        }
        $customerSets['dob'] = ['s', $dob];
    }

    try {
        foreach (['loan_limit', 'amount_last', 'amount_this'] as $col) {
            $val = $posted($col);
            if ($val !== null) {
                $customerSets[$col] = ['d', cleanMoney($val)];
            }
        }
    } catch (Throwable $e) {
        failAndRedirect($user_id, $e->getMessage());
    }

    $creditScoreRaw = $posted('credit_score');
    if ($creditScoreRaw !== null && $creditScoreRaw !== '') {
        if (!is_numeric($creditScoreRaw)) {
            failAndRedirect($user_id, "Invalid credit score.");
        }
        $customerSets['credit_score'] = ['i', max(0, min(100, (int)$creditScoreRaw))];
    }

    $activeRaw = $posted('active');
    if ($activeRaw !== null) {
        $active = ((int)$activeRaw === 1) ? 1 : 0;
        $userSets['status']     = ['i', $active];
        $customerSets['active'] = ['i', $active];
    }

    $runUpdate = static function (mysqli $conn, string $table, string $keyCol, array $sets, int $id): void {
        if (!$sets) {
            return;
        }

        $types  = '';
        $values = [];
        $parts  = [];
        foreach ($sets as $col => [$type, $value]) {
            $parts[]  = "`$col` = ?";
            $types   .= $type;
            $values[] = $value;
        }
        $types   .= 'i';
        $values[] = $id;

        $stmt = $conn->prepare("UPDATE `$table` SET " . implode(', ', $parts) . " WHERE `$keyCol` = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException("Failed to prepare $table update: " . $conn->error);
        }

        $stmt->bind_param($types, ...$values);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException("Failed to update client profile: " . $error);
        }

        $stmt->close();
    };

    mysqli_begin_transaction($conn);

    try {
        $checkUsername = $conn->prepare("SELECT id FROM user WHERE username = ? AND id <> ? LIMIT 1");
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

        if ($email !== null && $email !== '') {
            $checkEmail = $conn->prepare("SELECT id FROM user WHERE email = ? AND id <> ? LIMIT 1");
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

        $runUpdate($conn, 'user', 'id', $userSets, $user_id);
        $runUpdate($conn, 'customers', 'user_id', $customerSets, $user_id);

        mysqli_commit($conn);

        flash("Client successfully updated.", "success");

    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('view_client_process update_client failed: ' . $e->getMessage());
        flash($e->getMessage(), "error");
    }

    redirectToClient($user_id);
}

/* ============================================================
   RESET PASSWORD
============================================================ */
if ($action === 'reset_password' || $action === 'update_password') {
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
