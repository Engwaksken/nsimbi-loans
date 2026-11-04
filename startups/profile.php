<?php
session_start();
require '../db.php';

require 'nav.php';

if (!isset($_SESSION['id'], $_SESSION['role']) || (int)$_SESSION['role'] !== 5) {
    $_SESSION['error'] = "Please login to access your profile.";
    header('Location: ../login.php');
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found.");
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function clean_input($data): string
{
    return htmlspecialchars(strip_tags(trim((string)$data)), ENT_QUOTES, 'UTF-8');
}

function uploadLogo(string $field = 'company_logo', ?string $oldPath = null): ?string
{
    if (
        !isset($_FILES[$field]) ||
        !is_array($_FILES[$field]) ||
        (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        return $oldPath;
    }

    if ((int)$_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Failed to upload logo.');
    }

    $tmp  = $_FILES[$field]['tmp_name'];
    $name = $_FILES[$field]['name'];

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Invalid logo file type. Allowed: jpg, jpeg, png, gif, webp.');
    }

    $uploadDir = '../uploads/startups/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to create upload directory.');
    }

    $fileName = 'startup_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $fullPath = $uploadDir . $fileName;

    if (!move_uploaded_file($tmp, $fullPath)) {
        throw new RuntimeException('Failed to save uploaded logo.');
    }

    return 'uploads/startups/' . $fileName;
}

$user_id = (int)$_SESSION['id'];


$sql = "
    SELECT
        s.id,
        s.startup_id,
        s.company_name,
        s.company_logo,
        s.ceo_name,
        s.phone,
        s.email,
        s.bank_name,
        s.account_name,
        s.account_number,
        s.bank_branch,
        s.company_location,
        s.status AS startup_status,
        s.created_at,
        s.updated_at,
        u.id AS user_id,
        u.fname,
        u.lname,
        u.username,
        u.email AS user_email,
        u.role,
        u.status AS user_status,
        u.datetime
    FROM startups s
    INNER JOIN user u ON s.startup_id = u.id
    WHERE s.startup_id = ? AND u.role = 5
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $_SESSION['error'] = "Failed to load startup profile.";
    header('Location: dashboard.php');
    exit();
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$startup = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$startup) {
    $_SESSION['error'] = "Startup profile not found.";
    header('Location: dashboard.php');
    exit();
}

if ((int)($startup['user_status'] ?? 0) !== 1) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['error'] = "Your account is inactive.";
    header('Location: ../login.php');
    exit();
}

if (isset($startup['startup_status']) && $startup['startup_status'] === 'blocked') {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['error'] = "Your startup account has been blocked.";
    header('Location: ../login.php');
    exit();
}

/* ---------------------------
   HANDLE UPDATE
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name      = clean_input($_POST['company_name'] ?? '');
    $ceo_name          = clean_input($_POST['ceo_name'] ?? '');
    $phone             = clean_input($_POST['phone'] ?? '');
    $email             = clean_input($_POST['email'] ?? '');
    $bank_name         = clean_input($_POST['bank_name'] ?? '');
    $account_name      = clean_input($_POST['account_name'] ?? '');
    $account_number    = clean_input($_POST['account_number'] ?? '');
    $bank_branch       = clean_input($_POST['bank_branch'] ?? '');
    $company_location  = clean_input($_POST['company_location'] ?? '');
    $username          = clean_input($_POST['username'] ?? '');

    if ($company_name === '' || $username === '') {
        $_SESSION['error'] = "Company name and username are required.";
        header('Location: profile.php');
        exit();
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email address.";
        header('Location: profile.php');
        exit();
    }

    mysqli_begin_transaction($conn);

    try {
        $checkUser = $conn->prepare("
            SELECT id
            FROM user
            WHERE username = ? AND id <> ?
            LIMIT 1
        ");
        if (!$checkUser) {
            throw new RuntimeException("Failed to prepare username check.");
        }
        $checkUser->bind_param("si", $username, $user_id);
        $checkUser->execute();
        $existingUser = $checkUser->get_result()->fetch_assoc();
        $checkUser->close();

        if ($existingUser) {
            throw new RuntimeException("Username already exists.");
        }

        if ($email !== '') {
            $checkEmail = $conn->prepare("
                SELECT id
                FROM user
                WHERE email = ? AND id <> ?
                LIMIT 1
            ");
            if (!$checkEmail) {
                throw new RuntimeException("Failed to prepare email check.");
            }
            $checkEmail->bind_param("si", $email, $user_id);
            $checkEmail->execute();
            $existingEmail = $checkEmail->get_result()->fetch_assoc();
            $checkEmail->close();

            if ($existingEmail) {
                throw new RuntimeException("Email address already exists.");
            }
        }

        $logoPath = uploadLogo('company_logo', $startup['company_logo'] ?? null);

        $updateStartup = $conn->prepare("
            UPDATE startups
            SET
                company_name = ?,
                company_logo = ?,
                ceo_name = ?,
                phone = ?,
                email = ?,
                bank_name = ?,
                account_name = ?,
                account_number = ?,
                bank_branch = ?,
                company_location = ?
            WHERE startup_id = ?
            LIMIT 1
        ");
        if (!$updateStartup) {
            throw new RuntimeException("Failed to prepare startup update.");
        }

        $updateStartup->bind_param(
            "ssssssssssi",
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
            $user_id
        );

        if (!$updateStartup->execute()) {
            throw new RuntimeException("Failed to update startup profile.");
        }
        $updateStartup->close();

        $fname = $company_name;
        $lname = 'Startup';

        $updateUser = $conn->prepare("
            UPDATE user
            SET
                fname = ?,
                lname = ?,
                username = ?,
                email = ?
            WHERE id = ? AND role = 5
            LIMIT 1
        ");
        if (!$updateUser) {
            throw new RuntimeException("Failed to prepare account update.");
        }

        $updateUser->bind_param(
            "ssssi",
            $fname,
            $lname,
            $username,
            $email,
            $user_id
        );

        if (!$updateUser->execute()) {
            throw new RuntimeException("Failed to update login account.");
        }
        $updateUser->close();

        mysqli_commit($conn);

        $_SESSION['fname'] = $fname;
        $_SESSION['lname'] = $lname;
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $email;

        $_SESSION['success'] = "Profile updated successfully.";
        header('Location: profile.php');
        exit();
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = $e->getMessage();
        header('Location: profile.php');
        exit();
    }
}

/* ---------------------------
   RELOAD UPDATED DATA
---------------------------- */
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$startup = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$startup) {
    $_SESSION['error'] = "Startup profile not found after update.";
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Startup Profile</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/all.min.css">
    <style>
        body {
            background: #f6f8fb;
        }
        .profile-wrap {
            padding: 24px 0;
        }
        .profile-card,
        .form-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(0,0,0,.06);
        }
        .profile-header {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            color: #fff;
            border-radius: 18px;
            padding: 28px;
            margin-bottom: 24px;
        }
        .profile-logo {
            width: 100px;
            height: 100px;
            border-radius: 18px;
            object-fit: cover;
            background: rgba(255,255,255,.12);
            border: 2px solid rgba(255,255,255,.2);
        }
        .label-text {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 4px;
        }
        .value-text {
            font-weight: 600;
            color: #212529;
            word-break: break-word;
        }
        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 14px;
            color: #212529;
        }
    </style>
</head>
<body>

<div class="container profile-wrap">

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= h($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= h($_SESSION['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <div class="profile-header">
        <div class="row align-items-center">
            <div class="col-md-8 d-flex align-items-center gap-3">
                <?php if (!empty($startup['company_logo'])): ?>
                    <img src="../<?= h($startup['company_logo']) ?>" alt="Company Logo" class="profile-logo">
                <?php else: ?>
                    <div class="profile-logo d-flex align-items-center justify-content-center">
                        <i class="fas fa-building fa-2x text-white"></i>
                    </div>
                <?php endif; ?>

                <div>
                    <h2 class="mb-1"><?= h($startup['company_name'] ?? 'Startup') ?></h2>
                    <div class="mb-2">
                        <span class="me-3"><i class="fas fa-user-tie me-1"></i><?= h($startup['ceo_name'] ?: 'No CEO Name') ?></span>
                        <span><i class="fas fa-user-circle me-1"></i><?= h($startup['username'] ?? '') ?></span>
                    </div>
                    <span class="badge bg-light text-dark"><?= h(ucfirst((string)($startup['startup_status'] ?? 'inactive'))) ?></span>
                </div>
            </div>

            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="dashboard.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
                <a href="../logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                </a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card profile-card h-100">
                <div class="card-body p-4">
                    <div class="section-title">Current Information</div>

                    <div class="mb-3">
                        <div class="label-text">Company Name</div>
                        <div class="value-text"><?= h($startup['company_name'] ?? '-') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-text">CEO Name</div>
                        <div class="value-text"><?= h($startup['ceo_name'] ?? '-') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-text">Phone</div>
                        <div class="value-text"><?= h($startup['phone'] ?? '-') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-text">Email</div>
                        <div class="value-text"><?= h($startup['email'] ?? '-') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-text">Bank Name</div>
                        <div class="value-text"><?= h($startup['bank_name'] ?? '-') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-text">Account Name</div>
                        <div class="value-text"><?= h($startup['account_name'] ?? '-') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-text">Account Number</div>
                        <div class="value-text"><?= h($startup['account_number'] ?? '-') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-text">Bank Branch</div>
                        <div class="value-text"><?= h($startup['bank_branch'] ?? '-') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-text">Company Location</div>
                        <div class="value-text"><?= h($startup['company_location'] ?? '-') ?></div>
                    </div>

                    <hr>

                    <div class="section-title">Login Account</div>

                    <div class="mb-3">
                        <div class="label-text">Username</div>
                        <div class="value-text"><?= h($startup['username'] ?? '-') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-text">Login Email</div>
                        <div class="value-text"><?= h($startup['user_email'] ?? '-') ?></div>
                    </div>

                    <div class="mb-0">
                        <div class="label-text">Role</div>
                        <div class="value-text">Startup User (Role 5)</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card form-card">
                <div class="card-body p-4">
                    <div class="section-title">Edit Profile</div>

                    <form method="post" enctype="multipart/form-data">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Company Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="company_name" value="<?= h($startup['company_name'] ?? '') ?>" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">CEO Name</label>
                                <input type="text" class="form-control" name="ceo_name" value="<?= h($startup['ceo_name'] ?? '') ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone" value="<?= h($startup['phone'] ?? '') ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" value="<?= h($startup['email'] ?? '') ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bank Name</label>
                                <input type="text" class="form-control" name="bank_name" value="<?= h($startup['bank_name'] ?? '') ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Account Name</label>
                                <input type="text" class="form-control" name="account_name" value="<?= h($startup['account_name'] ?? '') ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Account Number</label>
                                <input type="text" class="form-control" name="account_number" value="<?= h($startup['account_number'] ?? '') ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bank Branch</label>
                                <input type="text" class="form-control" name="bank_branch" value="<?= h($startup['bank_branch'] ?? '') ?>">
                            </div>

                            <div class="col-md-12 mb-3">
                                <label class="form-label">Company Location</label>
                                <input type="text" class="form-control" name="company_location" value="<?= h($startup['company_location'] ?? '') ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" value="<?= h($startup['username'] ?? '') ?>" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Upload Logo</label>
                                <input type="file" class="form-control" name="company_logo" accept=".jpg,.jpeg,.png,.gif,.webp">
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Changes
                            </button>
                            <a href="dashboard.php" class="btn btn-light">
                                Cancel
                            </a>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>

</div>

require 'footer.php';
</body>
</html>