<?php
declare(strict_types=1);

/**
 * Sign-in handler for every portal.
 *
 * Users sign in with their email, username or (clients only) the phone
 * number on their customer profile. Phone numbers are matched on their last
 * nine digits, so "0772 123456", "+256772123456" and "772-123-456" are the
 * same number regardless of how it was saved.
 */

session_start();

require_once __DIR__ . '/db.php';

const LOGIN_PHONE_DIGITS = 9; // Ugandan numbers: 9 digits after the 0 / +256 prefix

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

/** Last nine digits of a phone-like input, or '' when it is not a phone number. */
function login_phone_key(string $value): string
{
    if (!preg_match('/^\+?[\d\s().-]+$/', $value)) {
        return '';
    }
    $digits = preg_replace('/\D/', '', $value);
    $length = strlen($digits);
    if ($length < LOGIN_PHONE_DIGITS || $length > 15) {
        return '';
    }
    return substr($digits, -LOGIN_PHONE_DIGITS);
}

/** @return array<int, array<string, mixed>> */
function login_users_by_identity(mysqli $conn, string $login): array
{
    $stmt = $conn->prepare("
        SELECT id, fname, lname, username, email, role, password, status
        FROM user
        WHERE email = ? OR username = ?
        LIMIT 2
    ");
    $stmt->bind_param('ss', $login, $login);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * Client accounts whose saved phone number ends with the same nine digits.
 *
 * @return array<int, array<string, mixed>>
 */
function login_users_by_phone(mysqli $conn, string $phoneKey): array
{
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id, u.fname, u.lname, u.username, u.email, u.role, u.password, u.status
        FROM customers c
        INNER JOIN user u ON u.id = c.user_id
        WHERE u.role = 3
          AND RIGHT(
                REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone, ''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), '.', ''),
                ?
              ) = ?
        LIMIT 10
    ");
    $digits = LOGIN_PHONE_DIGITS;
    $stmt->bind_param('is', $digits, $phoneKey);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
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
    // 1. Email or username (unchanged behaviour for every role).
    $candidates = login_users_by_identity($conn, $loginIdentifier);

    // 2. Phone number on a client profile.
    if (!$candidates) {
        $phoneKey = login_phone_key($loginIdentifier);
        if ($phoneKey !== '') {
            $candidates = login_users_by_phone($conn, $phoneKey);
        }
    }
} catch (Throwable $e) {
    error_log('Login lookup failed: ' . $e->getMessage());
    redirect_login('Database error. Please try again.');
}

// Keep only accounts whose password matches. If two client profiles share a
// phone number this still finds the right one without revealing the other.
$matches = array_values(array_filter(
    $candidates,
    static fn(array $u): bool => password_verify($password, (string)$u['password'])
));

if (!$matches) {
    redirect_login('Invalid login details or password.');
}

if (count($matches) > 1) {
    redirect_login('More than one account uses these details. Please sign in with your email or username.');
}

$user = $matches[0];

if ((int)$user['status'] !== 1) {
    redirect_login('Your account is not active. Please contact administrator.');
}

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
