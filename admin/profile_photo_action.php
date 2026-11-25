<?php
declare(strict_types=1);

/**
 * Saves the signed-in team member's own profile photo (admin, loan officer)
 * from the photo form on profile.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/profile_photo.php';

function admin_photo_back(string $message, string $type): never
{
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    header('Location: profile.php', true, 303);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
if ($userId <= 0 || !in_array((int)($_SESSION['role'] ?? 0), [1, 2, 6], true)) {
    header('Location: ../login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php');
    exit;
}
// An oversized upload empties $_POST entirely; report that clearly.
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    admin_photo_back('The photo is too large. Please take it again.', 'error');
}
if (empty($_SESSION['admin_photo_csrf']) || !hash_equals((string)$_SESSION['admin_photo_csrf'], (string)($_POST['csrf'] ?? ''))) {
    admin_photo_back('Your session expired. Please try again.', 'error');
}

try {
    $error = profile_photo_save($conn, $userId, $_FILES['profile_img'] ?? null);
} catch (Throwable $e) {
    error_log('Admin profile photo save failed for user ' . $userId . ': ' . $e->getMessage());
    $error = 'The photo could not be saved. Please try again.';
}

admin_photo_back($error === '' ? 'Profile photo updated.' : $error, $error === '' ? 'success' : 'error');
