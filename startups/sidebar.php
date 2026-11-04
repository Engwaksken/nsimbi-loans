<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id'], $_SESSION['role']) || (int)$_SESSION['role'] !== 5) {
    $_SESSION['error'] = "Please login to access the startup panel.";
    header('Location: ../login.php');
    exit();
}

function startup_nav_is_active(array $files): string
{
    $current = basename($_SERVER['PHP_SELF'] ?? '');
    return in_array($current, $files, true) ? 'active' : '';
}

$startupName = trim((string)(($_SESSION['fname'] ?? '') . ' ' . ($_SESSION['lname'] ?? '')));
$username    = trim((string)($_SESSION['username'] ?? ''));

if ($startupName === '') {
    $startupName = 'Startup User';
}
?>
<style>
    .stp-layout {
        display: flex;
        min-height: 100vh;
        background: #f6f8fb;
    }

    .stp-sidebar {
        width: 280px;
        background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
        color: #fff;
        padding: 22px 18px;
        position: sticky;
        top: 0;
        height: 100vh;
        overflow-y: auto;
        box-shadow: 4px 0 20px rgba(0,0,0,.08);
        z-index: 1000;
    }

    .stp-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 24px;
        padding-bottom: 18px;
        border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .stp-brand__icon {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255,255,255,.12);
        font-size: 18px;
    }

    .stp-brand__title {
        font-size: 16px;
        font-weight: 700;
        line-height: 1.2;
        margin: 0;
    }

    .stp-brand__subtitle {
        font-size: 12px;
        color: rgba(255,255,255,.72);
        margin: 2px 0 0;
    }

    .stp-user {
        background: rgba(255,255,255,.06);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 16px;
        padding: 14px;
        margin-bottom: 20px;
    }

    .stp-user__name {
        font-size: 14px;
        font-weight: 700;
        margin-bottom: 3px;
    }

    .stp-user__meta {
        font-size: 12px;
        color: rgba(255,255,255,.7);
    }

    .stp-nav-title {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: rgba(255,255,255,.55);
        margin: 18px 10px 10px;
    }

    .stp-nav {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .stp-nav li {
        margin-bottom: 6px;
    }

    .stp-nav a {
        display: flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
        color: rgba(255,255,255,.85);
        padding: 12px 14px;
        border-radius: 14px;
        transition: all .2s ease;
        font-size: 14px;
        font-weight: 500;
    }

    .stp-nav a:hover {
        background: rgba(255,255,255,.08);
        color: #fff;
        transform: translateX(2px);
    }

    .stp-nav a.active {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff;
        box-shadow: 0 10px 20px rgba(37,99,235,.22);
    }

    .stp-nav a i {
        width: 18px;
        text-align: center;
        font-size: 15px;
    }

    .stp-sidebar__footer {
        margin-top: 24px;
        padding-top: 18px;
        border-top: 1px solid rgba(255,255,255,.08);
    }

    .stp-sidebar__footer a {
        display: flex;
        align-items: center;
        gap: 12px;
        color: rgba(255,255,255,.82);
        text-decoration: none;
        padding: 12px 14px;
        border-radius: 14px;
        transition: all .2s ease;
        font-size: 14px;
        font-weight: 500;
    }

    .stp-sidebar__footer a:hover {
        background: rgba(239,68,68,.14);
        color: #fff;
    }

    .stp-main {
        flex: 1;
        min-width: 0;
    }

    .stp-topbar {
        display: none;
        align-items: center;
        justify-content: space-between;
        background: #0f172a;
        color: #fff;
        padding: 14px 16px;
        position: sticky;
        top: 0;
        z-index: 999;
    }

    .stp-topbar__brand {
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 700;
    }

    .stp-menu-btn {
        border: 0;
        background: rgba(255,255,255,.12);
        color: #fff;
        width: 42px;
        height: 42px;
        border-radius: 12px;
        cursor: pointer;
    }

    .stp-overlay {
        display: none;
    }

    @media (max-width: 991.98px) {
        .stp-topbar {
            display: flex;
        }

        .stp-sidebar {
            position: fixed;
            left: -300px;
            top: 0;
            transition: left .25s ease;
            height: 100vh;
        }

        .stp-sidebar.show {
            left: 0;
        }

        .stp-overlay.show {
            display: block;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.45);
            z-index: 998;
        }
    }
</style>

<div class="stp-topbar">
    <div class="stp-topbar__brand">
        <i class="fas fa-building"></i>
        <span>Startup Panel</span>
    </div>
    <button class="stp-menu-btn" type="button" onclick="toggleStartupSidebar()">
        <i class="fas fa-bars"></i>
    </button>
</div>

<div class="stp-overlay" id="stpSidebarOverlay" onclick="toggleStartupSidebar(false)"></div>

<div class="stp-layout">
    <aside class="stp-sidebar" id="stpSidebar">
        <div class="stp-brand">
            <div class="stp-brand__icon">
                <i class="fas fa-building"></i>
            </div>
            <div>
                <p class="stp-brand__title">Startup Panel</p>
                <p class="stp-brand__subtitle">Role 5 Dashboard</p>
            </div>
        </div>

        <div class="stp-user">
            <div class="stp-user__name"><?= htmlspecialchars($startupName, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="stp-user__meta">@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <div class="stp-nav-title">Main</div>
        <ul class="stp-nav">
            <li>
                <a class="<?= startup_nav_is_active(['dashboard.php']) ?>" href="dashboard.php">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a class="<?= startup_nav_is_active(['profile.php']) ?>" href="profile.php">
                    <i class="fas fa-user-edit"></i>
                    <span>My Profile</span>
                </a>
            </li>
            <li>
                <a class="<?= startup_nav_is_active(['change-password.php']) ?>" href="change-password.php">
                    <i class="fas fa-key"></i>
                    <span>Change Password</span>
                </a>
            </li>
        </ul>

        <div class="stp-nav-title">Applications</div>
        <ul class="stp-nav">
            <li>
                <a class="<?= startup_nav_is_active(['applications.php']) ?>" href="applications.php">
                    <i class="fas fa-file-alt"></i>
                    <span>My Applications</span>
                </a>
            </li>
            <li>
                <a class="<?= startup_nav_is_active(['new-application.php']) ?>" href="new-application.php">
                    <i class="fas fa-plus-circle"></i>
                    <span>New Application</span>
                </a>
            </li>
        </ul>

        <div class="stp-nav-title">Finance</div>
        <ul class="stp-nav">
            <li>
                <a class="<?= startup_nav_is_active(['loans.php']) ?>" href="loans.php">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>Loans</span>
                </a>
            </li>
            <li>
                <a class="<?= startup_nav_is_active(['repayments.php']) ?>" href="repayments.php">
                    <i class="fas fa-money-check-alt"></i>
                    <span>Repayments</span>
                </a>
            </li>
        </ul>

        <div class="stp-sidebar__footer">
            <a href="../logout.php">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <main class="stp-main">