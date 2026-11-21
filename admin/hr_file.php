<?php
declare(strict_types=1);

/**
 * Serves a staff HR file: admins see every file, other staff only their own.
 */

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/staff_hr.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$role = (int)($_SESSION['role'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
if ($userId <= 0 || !in_array($role, [1, 2], true)) {
    http_response_code(403);
    exit('Staff only.');
}

$file = hr_file_find($conn, (int)($_GET['id'] ?? 0));
if (!$file) {
    http_response_code(404);
    exit('File not found.');
}
if ($role !== 1) {
    $own = hr_profile_for_user($conn, $userId);
    if (!$own || (int)$own['id'] !== (int)$file['profile_id']) {
        http_response_code(403);
        exit('You can only open your own documents.');
    }
}

hr_file_stream($file, isset($_GET['download']));
