<?php
declare(strict_types=1);

/**
 * Saves the client's new profile photo (selfie) from the Profile Photo modal
 * on profile.php. Always updates the logged-in client's own record.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require "db.php";
require_once __DIR__ . '/../includes/profile_photo.php';

function photo_back(string $message, string $type): never
{
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    header('Location: profile.php', true, 303);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
if ($userId <= 0 || (int)($_SESSION['role'] ?? 0) !== 3) {
    header('Location: ../login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php');
    exit;
}
// An oversized upload empties $_POST entirely; report that clearly.
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    photo_back('The photo is too large. Please take the selfie again.', 'error');
}
if (empty($_SESSION['docs_csrf']) || !hash_equals((string)$_SESSION['docs_csrf'], (string)($_POST['csrf'] ?? ''))) {
    photo_back('Your session expired. Please try again.', 'error');
}

try {
    $error = profile_photo_save($conn, $userId, $_FILES['profile_img'] ?? null);
} catch (Throwable $e) {
    error_log('Profile photo save failed for user ' . $userId . ': ' . $e->getMessage());
    $error = 'The photo could not be saved. Please try again.';
}

photo_back($error === '' ? 'Profile photo updated.' : $error, $error === '' ? 'success' : 'error');
