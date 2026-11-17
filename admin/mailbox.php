<?php
/**
 * Loan team mailbox (admins and loan officers): read and reply to messages
 * from clients and staff borrowers, or write to them (Gmail-style).
 * See includes/portal_mail.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/loan_workflow.php';
require_once dirname(__DIR__) . '/includes/client_documents.php';
require_once dirname(__DIR__) . '/includes/portal_mail.php';
require_once dirname(__DIR__) . '/includes/portal_mail_view.php';

date_default_timezone_set('Africa/Nairobi');

$staff_id = (int)($_SESSION['logged_in'] ?? 0);
if ($staff_id <= 0 || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    header('Location: ../login.php');
    exit;
}

$me = pm_user($conn, $staff_id);
if (!$me) {
    header('Location: ../login.php');
    exit;
}

pm_handle_post($conn, $me, 'mailbox.php');

$phead = 'Mailbox';
require 'nav-head.php';
?>
<div class="page-header">
    <div>
        <div class="title">Mailbox</div>
        <div class="sub">Conversations with clients and staff borrowers. Every message is also delivered by email.</div>
    </div>
</div>
<?php
try {
    pm_render($conn, $me, 'mailbox.php');
} catch (Throwable $e) {
    error_log('Admin mailbox failed: ' . $e->getMessage());
    echo '<div class="alert alert-danger">The mailbox is not available right now. Please try again later.</div>';
}

require 'footer.php';
