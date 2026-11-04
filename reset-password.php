<?php
$token = $_GET['token'] ?? '';

if (empty($token) || !preg_match('/^[a-zA-Z0-9]{32,}$/', $token)) {
    header("Location: login.php?error=invalid_token");
    exit;
}

session_start();


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Reset your account password securely">
    <title>Reset Password - Secure Account Recovery</title>
    
    <!-- Enhanced CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/soft-ui-dashboard.css?v=1.0.3">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(#123299);
            --success-gradient: linear-gradient(#20d959);
            --danger-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --shadow-soft: 0 2px 25px -5px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        .reset-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px 0;
        }

        .reset-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-soft);
            padding: 0;
            overflow: hidden;
            max-width: 450px;
            width: 100%;
        }

        .card-header {
            background: var(--primary-gradient);
            color: white;
            padding: 2rem;
            text-align: center;
            border: none;
        }

        .card-header h4 {
            margin: 0;
            font-weight: 600;
            font-size: 1.5rem;
        }

        .card-header .subtitle {
            opacity: 0.9;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        .card-body {
            padding: 2rem;
        }

        .form-floating {
            margin-bottom: 1.5rem;
        }

        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            background: white;
        }

        .btn-reset {
            background: #123299;
            border: none;
            border-radius: 8px;
            padding: 0.875rem 2rem;
            font-weight: 600;
            font-size: 1rem;
            color: white;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 172, 254, 0.3);
        }

        .btn-reset:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .alert {
            border: none;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-danger {
            color: #d63384;
            border-left: 4px solid #d63384;
        }

        .alert-success {
            color: #0d6efd;
            border-left: 4px solid #0d6efd;
        }

        .password-strength {
            margin-top: 0.5rem;
        }

        .strength-bar {
            height: 4px;
            border-radius: 2px;
            background: #e9ecef;
            overflow: hidden;
            margin-top: 0.25rem;
        }

        .strength-fill {
            height: 100%;
            transition: width 0.3s ease, background-color 0.3s ease;
            width: 0%;
        }

        .strength-text {
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }

        .input-group {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            color: #6c757d;
            cursor: pointer;
            z-index: 5;
            padding: 4px;
            border-radius: 4px;
            transition: color 0.2s ease;
        }

        .toggle-password:hover {
            color: #495057;
        }

        .back-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e9ecef;
        }

        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .back-link a:hover {
            color: #5a6fd8;
            text-decoration: underline;
        }

        /* Responsive Design */
        @media (max-width: 576px) {
            .reset-container {
                padding: 15px;
            }
            
            .reset-card {
                margin: 0 15px;
            }
            
            .card-header {
                padding: 1.5rem;
            }
            
            .card-header h4 {
                font-size: 1.25rem;
            }
            
            .card-body {
                padding: 1.5rem;
            }
        }

        @media (max-width: 400px) {
            .card-header, .card-body {
                padding: 1rem;
            }
            
            .form-control {
                font-size: 0.9rem;
            }
            
            .btn-reset {
                padding: 0.75rem 1.5rem;
                font-size: 0.9rem;
            }
        }

        /* Loading Animation */
        .btn-loading::after {
            content: "";
            position: absolute;
            width: 16px;
            height: 16px;
            margin: auto;
            border: 2px solid transparent;
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: button-loading-spinner 0.8s ease infinite;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
        }

        @keyframes button-loading-spinner {
            from { transform: rotate(0turn); }
            to { transform: rotate(1turn); }
        }

        .form-validation-message {
            font-size: 0.75rem;
            margin-top: 0.25rem;
            display: none;
        }

        .form-validation-message.show {
            display: block;
        }

        .form-validation-message.error {
            color: #d63384;
        }

        .form-validation-message.success {
            color: #198754;
        }
    </style>
</head>
<body>
    <div class="container-fluid reset-container">
        <div class="row justify-content-center w-100">
            <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5">
                <div class="reset-card mx-auto">
                    <div class="card-header">
                        <p><strong>Reset Your Password</strong></p>
                        <p class="subtitle mb-0">Enter your new password below</p>
                    </div>

                    <!-- Card Body -->
                    <div class="card-body">
                        <!-- Status Messages -->
                        <?php if (!empty($_SESSION['status'])): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?= htmlspecialchars($_SESSION['status']); ?>
                                <?php unset($_SESSION['status']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($_SESSION['success'])): ?>
                            <div class="alert alert-success" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <?= htmlspecialchars($_SESSION['success']); ?>
                                <?php unset($_SESSION['success']); ?>
                            </div>
                        <?php endif; ?>

                     
                        <form id="resetForm" action="php/update-password.php" method="POST" novalidate>
                            <!-- CSRF Token -->
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                            
                            <div class="form-floating mb-3">
                                <div class="input-group">
                                    <input type="password" id="password" name="password" class="form-control" placeholder="New Password" required minlength="8">
                                    <button type="button" class="toggle-password" data-target="password" aria-label="Toggle password visibility">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                
                                <div class="form-validation-message" id="password-validation"></div>
                                
                                <!-- Password Strength Indicator -->
                                <div class="password-strength">
                                    <div class="strength-bar">
                                        <div class="strength-fill" id="strength-fill"></div>
                                    </div>
                                    <div class="strength-text" id="strength-text">Password strength: <span>Weak</span></div>
                                </div>
                            </div>

                          
                            <div class="form-floating mb-4">
                                <div class="input-group">
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm New Password" required >
                                    <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Toggle password visibility">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                             
                                <div class="form-validation-message" id="confirm-password-validation"></div>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" id="submitBtn" class="btn btn-reset w-100 mb-3">
                                <i class="fas fa-key me-2"></i>
                                <span class="btn-text">Reset Password</span>
                            </button>
                        </form>

                       
                        <div class="back-link">
                            <a href="index.php" class="text-info text-gradient font-weight-bold">
                                <i class="fas fa-arrow-left me-2"></i>
                                Back to Login
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('resetForm');
            const passwordField = document.getElementById('password');
            const confirmPasswordField = document.getElementById('confirm_password');
            const submitBtn = document.getElementById('submitBtn');
            const strengthFill = document.getElementById('strength-fill');
            const strengthText = document.getElementById('strength-text');

            // Password strength checker
            function checkPasswordStrength(password) {
                let score = 0;
                let feedback = [];

                if (password.length >= 8) score++;
                else feedback.push('At least 8 characters');

                if (/[a-z]/.test(password)) score++;
                else feedback.push('Lowercase letter');

                if (/[A-Z]/.test(password)) score++;
                else feedback.push('Uppercase letter');

                if (/[0-9]/.test(password)) score++;
                else feedback.push('Number');

                if (/[^A-Za-z0-9]/.test(password)) score++;
                else feedback.push('Special character');

                return { score, feedback };
            }

            // Update password strength indicator
            function updatePasswordStrength() {
                const password = passwordField.value;
                const { score, feedback } = checkPasswordStrength(password);
                
                const percentage = (score / 5) * 100;
                strengthFill.style.width = percentage + '%';
                
                let strengthLevel, color;
                if (score < 2) {
                    strengthLevel = 'Weak';
                    color = '#dc3545';
                } else if (score < 4) {
                    strengthLevel = 'Fair';
                    color = '#fd7e14';
                } else if (score < 5) {
                    strengthLevel = 'Good';
                    color = '#20c997';
                } else {
                    strengthLevel = 'Strong';
                    color = '#198754';
                }
                
                strengthFill.style.backgroundColor = color;
                strengthText.innerHTML = `Password strength: <span style="color: ${color}">${strengthLevel}</span>`;
                
                if (password.length > 0 && feedback.length > 0) {
                    showValidationMessage('password-validation', 'Missing: ' + feedback.join(', '), 'error');
                } else {
                    hideValidationMessage('password-validation');
                }
            }

            // Validate password match
            function validatePasswordMatch() {
                const password = passwordField.value;
                const confirmPassword = confirmPasswordField.value;
                
                if (confirmPassword.length > 0) {
                    if (password !== confirmPassword) {
                        showValidationMessage('confirm-password-validation', 'Passwords do not match', 'error');
                        return false;
                    } else {
                        showValidationMessage('confirm-password-validation', 'Passwords match', 'success');
                        return true;
                    }
                } else {
                    hideValidationMessage('confirm-password-validation');
                    return false;
                }
            }

            function showValidationMessage(elementId, message, type) {
                const element = document.getElementById(elementId);
                element.textContent = message;
                element.className = `form-validation-message show ${type}`;
            }

            function hideValidationMessage(elementId) {
                const element = document.getElementById(elementId);
                element.className = 'form-validation-message';
            }

            // Password visibility toggle
            document.querySelectorAll('.toggle-password').forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const targetInput = document.getElementById(targetId);
                    const icon = this.querySelector('i');
                    
                    if (targetInput.type === 'password') {
                        targetInput.type = 'text';
                        icon.classList.replace('fa-eye', 'fa-eye-slash');
                    } else {
                        targetInput.type = 'password';
                        icon.classList.replace('fa-eye-slash', 'fa-eye');
                    }
                });
            });

            // Event listeners
            passwordField.addEventListener('input', updatePasswordStrength);
            confirmPasswordField.addEventListener('input', validatePasswordMatch);

            // Form validation
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const password = passwordField.value;
                const confirmPassword = confirmPasswordField.value;
                const { score } = checkPasswordStrength(password);
                
                // Validate password strength
                if (score < 3) {
                    showValidationMessage('password-validation', 'Please choose a stronger password', 'error');
                    return;
                }
                
                // Validate password match
                if (password !== confirmPassword) {
                    showValidationMessage('confirm-password-validation', 'Passwords do not match', 'error');
                    return;
                }
                
                // Show loading state
                submitBtn.disabled = true;
                submitBtn.classList.add('btn-loading');
                submitBtn.querySelector('.btn-text').textContent = 'Resetting...';
                
                // Submit form
                setTimeout(() => {
                    form.submit();
                }, 500);
            });

            // Initialize
            if (passwordField.value) {
                updatePasswordStrength();
            }
        });
    </script>
</body>
</html>