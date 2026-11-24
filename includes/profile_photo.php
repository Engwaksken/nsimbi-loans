<?php
declare(strict_types=1);

/**
 * Profile pictures (the selfie taken at sign-up), stored in user.image_url.
 *
 * The app, the website sign-up and admin "Add client" all save the path in
 * user.image_url, in different upload folders; stored_file_url() finds it.
 * Pages show the photo with profile_avatar(), falling back to initials.
 */

require_once __DIR__ . '/file_links.php';

const PROFILE_PHOTO_MAX_BYTES = 8 * 1024 * 1024;

/** Link to a user's photo from a first-level folder (clients/, admin/), or '' when there is none. */
function profile_photo_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '' || (!stored_file_is_image($path) && !preg_match('#^https?://#i', $path))) {
        return '';
    }
    return (string)(stored_file_url($path) ?? '');
}

/** user.image_url for a user id ('' when none). */
function profile_photo_path(mysqli $conn, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    try {
        $stmt = $conn->prepare('SELECT image_url FROM user WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $path = (string)($stmt->get_result()->fetch_assoc()['image_url'] ?? '');
        $stmt->close();
        return $path;
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Avatar markup: the photo when there is one, otherwise the initials.
 * $class is the page's existing avatar class so sizes and colours stay.
 */
function profile_avatar(?string $path, string $initials, string $class, string $alt = 'Profile photo'): string
{
    $initials = htmlspecialchars($initials !== '' ? $initials : 'U', ENT_QUOTES, 'UTF-8');
    $url = profile_photo_url($path);
    if ($url === '') {
        return '<div class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . $initials . '</div>';
    }
    // A missing file falls back to the initials.
    return '<div class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . ' has-photo" data-initials="' . $initials . '">'
        . '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" '
        . 'loading="lazy" onerror="var p=this.parentNode;p.classList.remove(\'has-photo\');p.textContent=p.getAttribute(\'data-initials\')">'
        . '</div>';
}

/**
 * Save an uploaded selfie (JPEG or PNG) as the user's profile photo.
 * Stored in clients/uploads/profile/ and saved in user.image_url.
 *
 * @return string '' on success, otherwise a message for the user.
 */
function profile_photo_save(mysqli $conn, int $userId, ?array $file): string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Please take a selfie first.';
    }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        return 'The photo could not be uploaded. Please try again.';
    }
    if ((int)$file['size'] > PROFILE_PHOTO_MAX_BYTES) {
        return 'The photo is too large (maximum 8 MB).';
    }
    $ext = profile_photo_image_ext((string)$file['tmp_name']);
    if ($ext === '') {
        return 'The photo must be a JPG or PNG picture.';
    }

    $dir = dirname(__DIR__) . '/clients/uploads/profile';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return 'The photo could not be saved. Please try again.';
    }
    $name = 'profile_' . $userId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        return 'The photo could not be saved. Please try again.';
    }

    $path = 'uploads/profile/' . $name;
    $stmt = $conn->prepare('UPDATE user SET image_url = ? WHERE id = ?');
    $stmt->bind_param('si', $path, $userId);
    $stmt->execute();
    $stmt->close();
    return '';
}

/** 'jpg' / 'png' when the file really is that image type, else ''. */
function profile_photo_image_ext(string $tmpPath): string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? '';
    if ($ext === '' || @getimagesize($tmpPath) === false) {
        return '';
    }
    return $ext;
}
