<?php
declare(strict_types=1);

/**
 * Website sign-in handler for every portal.
 *
 * Accounts are matched by includes/auth_lookup.php, which the Android app's
 * API login (api.php) uses too, so the same email, username or client phone
 * number and password work on both.
 */

session_start();

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/auth_lookup.php';

function redirect_login(string $message): void
{
    $_SESSION['error'] = $message;
    header('Location: /login');
    exit;
}

function clean_input(string $data): string
{
    return trim(strip_tags($data));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    redirect_login('Database connection error.');
}

$loginIdentifier = clean_input((string)($_POST['login'] ?? ''));
$password = (string)($_POST['password'] ?? '');

$_SESSION['old_login'] = $loginIdentifier;

if ($loginIdentifier === '' || $password === '') {
    redirect_login('Please enter your email, phone number or username, and your password.');
}

try {
    $attempt = auth_attempt($conn, $loginIdentifier, $password);
} catch (Throwable $e) {
    error_log('Login lookup failed: ' . $e->getMessage());
    redirect_login('Database error. Please try again.');
}

switch ($attempt['result']) {
    case 'ok':
        break;
    case 'inactive':
        redirect_login('Your account is not active. Please contact administrator.');
    case 'ambiguous':
        redirect_login('More than one account uses these details. Please sign in with your email or username.');
    default:
        redirect_login('Invalid login details or password.');
}

$user = $attempt['user'];

session_regenerate_id(true);

unset($_SESSION['error'], $_SESSION['old_login']);

$userId = (int)$user['id'];
$roleId = (int)$user['role'];

$_SESSION['logged_in']    = $userId;
$_SESSION['id']           = $userId;
$_SESSION['user_id']      = $userId;
$_SESSION['client_id']    = $userId;
$_SESSION['is_logged_in'] = true;

$_SESSION['role']     = $roleId;
$_SESSION['fname']    = $user['fname'] ?? '';
$_SESSION['lname']    = $user['lname'] ?? '';
$_SESSION['username'] = $user['username'] ?? '';
$_SESSION['email']    = $user['email'] ?? '';

switch ($roleId) {
    case 1:
    case 2:
        header('Location: /admin/dashboard');
        exit;

    case 3:
        header('Location: /clients/dashboard');
        exit;

    case 4:
        header('Location: /staff/dashboard');
        exit;

    case 5:
        header('Location: /startups/dashboard');
        exit;

    default:
        session_unset();
        session_destroy();
        session_start();
        redirect_login('Unknown user role.');
}
