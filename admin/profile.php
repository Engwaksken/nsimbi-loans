<?php
declare(strict_types=1);

require "nav-head.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in'])) {
    die("Access denied. User not logged in.");
}

if (!isset($conn)) {
    require "db.php";
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$userId = (int)$_SESSION['logged_in'];

$stmt = $conn->prepare("
    SELECT id, fname, lname, username, email, status, role, datetime
    FROM user
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    die("User query failed: " . h($conn->error));
}

$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die("User not found.");
}

$fullName = trim(($user['fname'] ?? '') . ' ' . ($user['lname'] ?? ''));
$initials = strtoupper(substr((string)($user['fname'] ?? 'U'), 0, 1) . substr((string)($user['lname'] ?? ''), 0, 1));

$message = $_SESSION['message'] ?? '';
$messageType = $_SESSION['message_type'] ?? 'info';
unset($_SESSION['message'], $_SESSION['message_type']);
?>

<style>
.profile-grid {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 1rem;
    align-items: start;
}

.profile-card {
    background: #fff;
    border: 0;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(15,23,42,.08);
    overflow: hidden;
}

.profile-cover {
    height: 110px;
    background: linear-gradient(135deg, #0d2475, #123299);
}

.profile-avatar-wrap {
    padding: 0 1.2rem 1.2rem;
    margin-top: -42px;
}

.profile-avatar {
    width: 86px;
    height: 86px;
    border-radius: 24px;
    background: #111827;
    color: #fff;
    border: 4px solid #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.7rem;
    font-weight: 900;
    box-shadow: 0 10px 25px rgba(15,23,42,.18);
}

.profile-name {
    margin-top: .75rem;
    font-size: 1.15rem;
    font-weight: 900;
    color: #111827;
}

.profile-email {
    color: #64748b;
    font-size: .88rem;
}

.profile-info-list {
    padding: 0 1.2rem 1.2rem;
}

.profile-info-item {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    border-top: 1px solid #f1f5f9;
    padding: .85rem 0;
}

.profile-info-label {
    font-size: .75rem;
    color: #64748b;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.profile-info-value {
    color: #111827;
    font-weight: 700;
    text-align: right;
}

.profile-form-card {
    background: #fff;
    border-radius: 18px;
    border: 0;
    box-shadow: 0 8px 24px rgba(15,23,42,.08);
    margin-bottom: 1rem;
}

.profile-form-header {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e5e7eb;
}

.profile-form-title {
    margin: 0;
    font-weight: 900;
    color: #111827;
}

.profile-form-body {
    padding: 1.2rem;
}

.profile-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.profile-status {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .35rem .7rem;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 800;
}

.profile-status.active {
    background: #dcfce7;
    color: #166534;
}

.profile-status.blocked {
    background: #fee2e2;
    color: #991b1b;
}

@media (max-width: 992px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }

    .profile-form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container-fluid py-4">

    <div class="page-header mb-4">
        <div>
            <div class="title">My Profile</div>
            <div class="sub">View and update your account information.</div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $messageType === 'success' ? 'alert-success' : 'alert-danger' ?> alert-dismissible fade show">
            <?= h($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="profile-grid">

        <div class="profile-card">
            <div class="profile-cover"></div>

            <div class="profile-avatar-wrap">
                <div class="profile-avatar"><?= h($initials ?: 'U') ?></div>
                <div class="profile-name"><?= h($fullName ?: 'User') ?></div>
                <div class="profile-email"><?= h($user['email'] ?? '') ?></div>
            </div>

            <div class="profile-info-list">
                <div class="profile-info-item">
                    <div class="profile-info-label">Username</div>
                    <div class="profile-info-value"><?= h($user['username'] ?? 'N/A') ?></div>
                </div>

                <div class="profile-info-item">
                    <div class="profile-info-label">Status</div>
                    <div class="profile-info-value">
                        <?php if ((int)$user['status'] === 1): ?>
                            <span class="profile-status active"><i class="fas fa-check-circle"></i> Active</span>
                        <?php else: ?>
                            <span class="profile-status blocked"><i class="fas fa-ban"></i> Blocked</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="profile-info-item">
                    <div class="profile-info-label">Role</div>
                    <div class="profile-info-value"><?= h($user['role'] ?? 'N/A') ?></div>
                </div>

                <div class="profile-info-item">
                    <div class="profile-info-label">Created</div>
                    <div class="profile-info-value">
                        <?= !empty($user['datetime']) ? h(date('M d, Y', strtotime((string)$user['datetime']))) : 'N/A' ?>
                    </div>
                </div>
            </div>
        </div>

        <div>
            <div class="profile-form-card">
                <div class="profile-form-header">
                    <h5 class="profile-form-title">
                        <i class="fas fa-user-edit me-2"></i>Edit Profile
                    </h5>
                </div>

                <div class="profile-form-body">
                    <form method="POST" action="includes/process-profile.php">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                        <div class="profile-form-grid">
                            <div>
                                <label class="form-label fw-bold">First Name</label>
                                <input type="text" name="fname" class="form-control" value="<?= h($user['fname']) ?>" required>
                            </div>

                            <div>
                                <label class="form-label fw-bold">Last Name</label>
                                <input type="text" name="lname" class="form-control" value="<?= h($user['lname']) ?>" required>
                            </div>

                            <div>
                                <label class="form-label fw-bold">Username</label>
                                <input type="text" name="username" class="form-control" value="<?= h($user['username']) ?>" required>
                            </div>

                            <div>
                                <label class="form-label fw-bold">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= h($user['email']) ?>" required>
                            </div>
                        </div>

                        <div class="text-end mt-4">
                            <button type="submit" class="btn bg-gradient-info">
                                <i class="fas fa-save me-1"></i> Update Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="profile-form-card">
                <div class="profile-form-header">
                    <h5 class="profile-form-title">
                        <i class="fas fa-lock me-2"></i>Update Password
                    </h5>
                </div>

                <div class="profile-form-body">
                    <form method="POST" action="includes/process-profile.php">
                        <input type="hidden" name="action" value="update_password">
                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                        <div class="profile-form-grid">
                            <div>
                                <label class="form-label fw-bold">New Password</label>
                                <input type="password" name="password" class="form-control" placeholder="New password" required>
                            </div>

                            <div>
                                <label class="form-label fw-bold">Confirm Password</label>
                                <input type="password" name="con_password" class="form-control" placeholder="Confirm password" required>
                            </div>
                        </div>

                        <div class="text-end mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key me-1"></i> Save Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require "footer.php"; ?>