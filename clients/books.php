<?php
declare(strict_types=1);

/**
 * My Books � authenticated dashboard with voice/manual record creation.
 *
 * Dependencies:
 *   /php/db.php
 *   /user/includes/books-dashboard.php (updated voice-enabled module)
 *   /user/partials/books-dashboard-view.php (existing dashboard display)
 *   /user/nav-head.php and /user/footer.php
 *
 * Run all POST processing before rendering HTML so PRG redirects work.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit;
}

if ((int)($_SESSION['role'] ?? 0) !== 3) {
    http_response_code(403);
    header('Location: ../login.php');
    exit;
}

// Never interpret a boolean login flag as a user ID.
$user_id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($user_id === false || $user_id === null) {
    header('Location: ../login.php');
    exit;
}
$user_id = (int)$user_id;

require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/includes/books-dashboard.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log('My Books: no valid mysqli connection available.');
    http_response_code(503);
    exit('The books service is temporarily unavailable.');
}

// The updated dashboard module validates CSRF, entry type, amount, date,
// and user ownership, then saves a confirmed entry and redirects (POST/Redirect/GET).
books_dash_handle_post($conn, $user_id);

// Model used by the existing dashboard partial (statistics, sorting,
// date cadence, filtering, and pagination).
$books = books_dashboard_view_model($conn, $user_id, $_GET);

// These utility functions are used by the existing dashboard partial.
// Guard against collisions with helpers loaded by nav-head.php.
if (!function_exists('money')) {
    function money(float $amount): string
    {
        return 'UGX ' . number_format($amount);
    }
}

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$phead = 'MY BOOKS';
require __DIR__ . '/nav-head.php';
?>

<!-- Modal form is rendered here; opening buttons are in the dashboard view. -->
<div class="books-voice-entry-container" style="margin-bottom:1rem">
    <?php books_dash_render_entry_widget(); ?>
</div>

<?php
// Preserve the original dashboard and all its reporting features.
require __DIR__ . '/partials/books-dashboard-view.php';

// Attach speech-to-text behavior after the form has been rendered.
books_dash_render_voice_script();

require __DIR__ . '/footer.php';
