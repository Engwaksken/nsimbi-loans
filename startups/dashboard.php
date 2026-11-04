<?php
session_start();
require '../db.php';
require 'nav.php';

if (!isset($_SESSION['id'], $_SESSION['role']) || (int)$_SESSION['role'] !== 5) {
    $_SESSION['error'] = "Please login to access your dashboard.";
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
    $_SESSION['error'] = "Failed to load startup dashboard.";
    header('Location: ../login.php');
    exit();
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$startup = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$startup) {
    $_SESSION['error'] = "Startup profile not found.";
    header('Location: ../login.php');
    exit();
}

/* ---------------------------
   CHECK ACCOUNT STATUS
---------------------------- */
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
   OPTIONAL COUNTS
---------------------------- */
$profileCompletion = 0;
$filled = 0;
$totalFields = 8;

$profileFields = [
    $startup['company_name'] ?? '',
    $startup['ceo_name'] ?? '',
    $startup['phone'] ?? '',
    $startup['email'] ?? '',
    $startup['bank_name'] ?? '',
    $startup['account_name'] ?? '',
    $startup['account_number'] ?? '',
    $startup['company_location'] ?? ''
];

foreach ($profileFields as $field) {
    if (trim((string)$field) !== '') {
        $filled++;
    }
}
$profileCompletion = (int)round(($filled / $totalFields) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Startup Dashboard</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
        }
        .dashboard-wrap {
            padding: 24px 0;
        }
        .startup-cover {
            background: linear-gradient(135deg, #0d6efd, #0a58ca);
            color: #fff;
            border-radius: 18px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px rgba(13,110,253,.15);
        }
        .startup-logo {
            width: 90px;
            height: 90px;
            border-radius: 16px;
            object-fit: cover;
            background: rgba(255,255,255,.15);
            border: 2px solid rgba(255,255,255,.25);
        }
        .info-card,
        .stat-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(0,0,0,.06);
        }
        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(13,110,253,.1);
            color: #0d6efd;
            font-size: 18px;
        }
        .meta-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 4px;
        }
        .meta-value {
            font-weight: 600;
            color: #212529;
            word-break: break-word;
        }
        .badge-soft {
            padding: .5rem .8rem;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-soft-success {
            background: rgba(25,135,84,.12);
            color: #198754;
        }
        .badge-soft-danger {
            background: rgba(220,53,69,.12);
            color: #dc3545;
        }
        .badge-soft-secondary {
            background: rgba(108,117,125,.12);
            color: #6c757d;
        }
    </style>
</head>
<body>

<div class="container dashboard-wrap">

    <div class="startup-cover">
        <div class="row align-items-center">
            <div class="col-md-8 d-flex align-items-center gap-3">
                <?php if (!empty($startup['company_logo'])): ?>
                    <img src="../<?= h($startup['company_logo']) ?>" alt="Startup Logo" class="startup-logo">
                <?php else: ?>
                    <div class="startup-logo d-flex align-items-center justify-content-center">
                        <i class="fas fa-building fa-2x text-white"></i>
                    </div>
                <?php endif; ?>

                <div>
                    <h2 class="mb-1"><?= h($startup['company_name'] ?? 'Startup') ?></h2>
                    <div class="mb-2">
                        <span class="me-3"><i class="fas fa-user-tie me-1"></i> <?= h($startup['ceo_name'] ?: 'No CEO Name') ?></span>
                        <span><i class="fas fa-user-circle me-1"></i> <?= h($startup['username'] ?? '') ?></span>
                    </div>

                    <?php
                    $startupStatus = strtolower((string)($startup['startup_status'] ?? 'inactive'));
                    $statusClass = 'badge-soft-secondary';
                    if ($startupStatus === 'active') {
                        $statusClass = 'badge-soft-success';
                    } elseif ($startupStatus === 'blocked') {
                        $statusClass = 'badge-soft-danger';
                    }
                    ?>
                    <span class="badge-soft <?= $statusClass ?>">
                        <?= ucfirst(h($startupStatus)) ?>
                    </span>
                </div>
            </div>

            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="profile.php" class="btn btn-light btn-sm">
                    <i class="fas fa-user-edit me-1"></i> Update Profile
                </a>
                <a href="../logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                </a>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Profile Completion</div>
                        <h3 class="mb-0"><?= $profileCompletion ?>%</h3>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Account Status</div>
                        <h3 class="mb-0"><?= ((int)$startup['user_status'] === 1) ? 'Active' : 'Blocked' ?></h3>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Registered On</div>
                        <h6 class="mb-0"><?= h($startup['created_at'] ?? $startup['datetime'] ?? '-') ?></h6>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card info-card h-100">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0">Startup Information</h5>
                </div>
                <div class="card-body px-4 pb-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="meta-label">Company Name</div>
                            <div class="meta-value"><?= h($startup['company_name'] ?? '-') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="meta-label">CEO Name</div>
                            <div class="meta-value"><?= h($startup['ceo_name'] ?? '-') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="meta-label">Phone</div>
                            <div class="meta-value"><?= h($startup['phone'] ?? '-') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="meta-label">Email</div>
                            <div class="meta-value"><?= h($startup['email'] ?? '-') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="meta-label">Bank Name</div>
                            <div class="meta-value"><?= h($startup['bank_name'] ?? '-') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="meta-label">Account Name</div>
                            <div class="meta-value"><?= h($startup['account_name'] ?? '-') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="meta-label">Account Number</div>
                            <div class="meta-value"><?= h($startup['account_number'] ?? '-') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="meta-label">Bank Branch</div>
                            <div class="meta-value"><?= h($startup['bank_branch'] ?? '-') ?></div>
                        </div>
                        <div class="col-12">
                            <div class="meta-label">Company Location</div>
                            <div class="meta-value"><?= h($startup['company_location'] ?? '-') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card info-card mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0">Login Account</h5>
                </div>
                <div class="card-body px-4 pb-4">
                    <div class="mb-3">
                        <div class="meta-label">Username</div>
                        <div class="meta-value"><?= h($startup['username'] ?? '-') ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="meta-label">System Role</div>
                        <div class="meta-value">Startup User (Role 5)</div>
                    </div>
                    <div class="mb-3">
                        <div class="meta-label">Login Email</div>
                        <div class="meta-value"><?= h($startup['user_email'] ?? '-') ?></div>
                    </div>
                    <div>
                        <div class="meta-label">User Account Status</div>
                        <div class="meta-value"><?= ((int)$startup['user_status'] === 1) ? 'Active' : 'Blocked' ?></div>
                    </div>
                </div>
            </div>

            <div class="card info-card">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0">Quick Actions</h5>
                </div>
                <div class="card-body px-4 pb-4">
                    <div class="d-grid gap-2">
                        <a href="profile.php" class="btn btn-primary">
                            <i class="fas fa-user-edit me-2"></i>Edit Startup Profile
                        </a>
                        <a href="change-password.php" class="btn btn-outline-primary">
                            <i class="fas fa-key me-2"></i>Change Password
                        </a>
                        <a href="../logout.php" class="btn btn-outline-danger">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
require 'footer.php';
</body>
</html>