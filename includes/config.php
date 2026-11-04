<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : (getenv('DB_PASSWORD') ?: ''));
define('DB_NAME', getenv('DB_NAME') ?: '');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));

define('SITE_NAME', 'Nsimbi Impact');
define('SITE_URL', getenv('SITE_URL') ?: 'https://fin.nsimbiimpact.com');

define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_DIR', ROOT_PATH . '/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

require_once __DIR__ . '/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function isAdmin(): bool
{
    return (int)($_SESSION['role'] ?? 0) === 1;
}

function redirect(string $path): never
{
    if (!preg_match('#^(https?:)?//#i', $path) && !str_starts_with($path, '/')) {
        $path = '/' . ltrim($path, '/');
    }

    header('Location: ' . $path);
    exit;
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect('/admin/login');
    }
}

function requireRole($role): void
{
    requireLogin();

    $sessionRole = (int)($_SESSION['role'] ?? 0);
    $requiredRole = (int)$role;

    if ($sessionRole !== $requiredRole && $sessionRole !== 1) {
        redirect('/admin/index?error=unauthorized');
    }
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sanitize(mysqli $conn, $val): string
{
    return trim(strip_tags((string)$val));
}

function uploadFile(string $inputName, string $subDir = 'slides')
{
    if (empty($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $file = $_FILES[$inputName];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'ogg', 'mov', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

    if (!in_array($ext, $allowed, true)) {
        return false;
    }

    $safeSubDir = trim($subDir, '/');
    $destDir = UPLOAD_DIR . $safeSubDir . '/';

    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $destDir . $name;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'uploads/' . $safeSubDir . '/' . $name;
    }

    return false;
}

function getSetting(mysqli $conn, string $key, string $default = ''): string
{
    try {
        $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->bind_param('s', $key);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (string)$row['value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function setSetting(mysqli $conn, string $key, string $value): void
{
    $stmt = $conn->prepare("
        INSERT INTO settings (`key`, `value`)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

function timeAgo(string $datetime): string
{
    $time = strtotime($datetime);

    if (!$time) {
        return '';
    }

    $diff = time() - $time;

    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';

    return floor($diff / 86400) . 'd ago';
}
