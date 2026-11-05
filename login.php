<?php
session_start();
require_once __DIR__ . '/includes/site_cms.php';
$brand = site_settings($conn);

if (isset($_SESSION['logged_in'])) {
    $role = (int)($_SESSION['role'] ?? 0);

    if ($role === 1 || $role === 2) {
        header('Location: admin/dashboard.php');
    } elseif ($role === 3) {
        header('Location: clients/dashboard.php');
    } elseif ($role === 4) {
        header('Location: staff/dashboard.php');
    } elseif ($role === 5) {
        header('Location: startups/dashboard.php');
    } else {
        session_destroy();
        header('Location: index.php');
    }
    exit;
}

$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);

$oldLogin = $_SESSION['old_login'] ?? '';
unset($_SESSION['old_login']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | <?= site_e($brand['site_name']) ?></title>

    <?= site_favicon_tags() ?>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="assets/css/loanpro.css">
    <?= site_panel_style_tags() ?>
</head>
<body>

<div class="login-page">

    <div class="login-brand">
        <div class="login-brand-inner">
            <div class="login-logo-box">
                <img src="<?= site_e($brand['logo']) ?>" alt="<?= site_e($brand['site_name']) ?> logo" style="max-width:80px;max-height:80px;object-fit:contain;">
            </div>

            <h1>Nsimbi<span> Impact</span></h1>
            <p>Loan management for businesses and individuals. Streamlined, secure, and built for growth.</p>

            <div class="brand-stats">
                <div class="brand-stat">
                    <span class="brand-stat-val">500+</span>
                    <span class="brand-stat-lbl">Active Loans</span>
                </div>
                <div class="brand-stat">
                    <span class="brand-stat-val">90.4%</span>
                    <span class="brand-stat-lbl">Repayment Rate</span>
                </div>
                <div class="brand-stat">
                    <span class="brand-stat-val">2+ Yrs</span>
                    <span class="brand-stat-lbl">Experience</span>
                </div>
            </div>
        </div>
    </div>

   <div class="login-form-panel">
    <div class="login-card">

        <!-- Logo -->
        <div style="display:flex;justify-content:center;align-items:center;margin-bottom:1.2rem;">
            <img 
                src="<?= site_e($brand['logo']) ?>"
                alt="<?= site_e($brand['site_name']) ?> logo"
                style="max-width:90px;max-height:90px;object-fit:contain;"
            >
        </div>

        <!-- Heading -->
        <h2 style="margin-bottom:.4rem;text-align:center;">
            Welcome Back
        </h2>

        <p class="sub" style="text-align:center;">
            Sign in to your account to continue
        </p>

        <!-- Error -->
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom:1.2rem;text-align:left;">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="php/login.php">

            <!-- Email / Username -->
            <div class="form-group" style="text-align:left;">
                <label class="form-label">
                    Email or Username
                </label>

                <div class="input-group">
                    <input
                        type="text"
                        name="login"
                        class="form-control"
                        placeholder="Enter email or username"
                        value="<?= htmlspecialchars($oldLogin, ENT_QUOTES, 'UTF-8') ?>"
                        required
                        autofocus
                    >

                    <span class="input-group-text">
                        <i class="fas fa-user"></i>
                    </span>
                </div>
            </div>

            <!-- Password -->
            <div class="form-group" style="text-align:left;">

                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.36rem">
                    <label class="form-label" style="margin:0">
                        Password
                    </label>

                    <a href="forgot-password.php" style="font-size:.76rem;color:var(--text-muted)">
                        Forgot password?
                    </a>
                </div>

                <div class="input-group">
                    <input
                        type="password"
                        name="password"
                        id="pw"
                        class="form-control"
                        placeholder="Password"
                        required
                    >

                    <button
                        type="button"
                        class="input-group-text"
                        style="cursor:pointer;background:transparent;border-left:none"
                        onclick="
                            const f = document.getElementById('pw');
                            const i = this.querySelector('i');

                            f.type = f.type === 'password' ? 'text' : 'password';

                            i.className = 'fas fa-' + (f.type === 'password' ? 'eye' : 'eye-slash');
                        "
                    >
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Submit -->
            <button type="submit" class="login-submit">
                <i class="fas fa-sign-in-alt" style="margin-right:.4rem"></i>
                Sign In
            </button>

        </form>

        <!-- Footer Links -->
        <div style="margin-top:1.8rem;text-align:center;font-size:.76rem;color:var(--text-muted)">
            <a href="clients/index.php">Have no account?</a>
        </div>

        <div style="margin-top:1.8rem;text-align:center;font-size:.76rem;color:var(--text-muted)">
            &copy; <?= date('Y') ?> Nsimbi Impact Limited. All rights reserved.
        </div>

    </div>
</div>
</div>

</body>
</html>
