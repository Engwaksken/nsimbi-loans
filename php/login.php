<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/db.php';

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

$loginIdentifier = clean_input($_POST['login'] ?? '');
$password = (string)($_POST['password'] ?? '');

$_SESSION['old_login'] = $loginIdentifier;

if ($loginIdentifier === '' || $password === '') {
    redirect_login('Please enter username/email and password.');
}

$stmt = $conn->prepare("
    SELECT id, fname, lname, username, email, role, password, status
    FROM user
    WHERE email = ? OR username = ?
    LIMIT 1
");

if (!$stmt) {
    redirect_login('Database error. Please try again.');
}

$stmt->bind_param('ss', $loginIdentifier, $loginIdentifier);
$stmt->execute();

$result = $stmt->get_result();

if (!$result || $result->num_rows < 1) {
    $stmt->close();
    redirect_login('Invalid login credentials or password.');
}

$user = $result->fetch_assoc();
$stmt->close();

if (!password_verify($password, (string)$user['password'])) {
    redirect_login('Invalid login credentials or password.');
}

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
