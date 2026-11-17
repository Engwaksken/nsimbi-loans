<?php
/**
 * Staff borrower mailbox: write to the loan team, read replies (Gmail-style).
 * See includes/portal_mail.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/loan_workflow.php';
require_once __DIR__ . '/../includes/client_documents.php';
require_once __DIR__ . '/../includes/portal_mail.php';
require_once __DIR__ . '/../includes/portal_mail_view.php';

date_default_timezone_set('Africa/Nairobi');

$user_id = (int)($_SESSION['logged_in'] ?? 0);
if ($user_id <= 0 || (int)($_SESSION['role'] ?? 0) !== 4) {
    header('Location: ../login.php');
    exit;
}

$me = pm_user($conn, $user_id);
if (!$me) {
    header('Location: ../login.php');
    exit;
}

pm_handle_post($conn, $me, 'messages.php');

$page_title = 'Messages';
require 'nav-head.php';
?>
<div class="container-fluid py-4">
<?php
try {
    pm_render($conn, $me, 'messages.php');
} catch (Throwable $e) {
    error_log('Staff mailbox failed: ' . $e->getMessage());
    echo '<div class="alert alert-danger">Messages are not available right now. Please try again later.</div>';
}
?>
</div>
<?php require 'footer.php'; ?>
