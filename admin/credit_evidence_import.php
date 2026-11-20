<?php
declare(strict_types=1);

/*
 * Send the files a client already gave us (trading licence from registration,
 * licence and supporting documents uploaded on the profile) to the active AI,
 * so they can count towards the credit score once a reviewer approves them in
 * Loans > Credit Documents. Reviewers (role 1) only. POST from the client or
 * loan Documents tab.
 */

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/credit_scoring.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    header('Location: ../login.php');
    exit();
}

$back = (string)($_POST['back'] ?? '');
if (!preg_match('#^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $back)) {
    $back = 'credit_evidence.php';
}

$flash = ['', 'success'];
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new DomainException('Use the button on the Documents tab.');
    }
    if (empty($_SESSION['credit_ev_csrf']) || !hash_equals($_SESSION['credit_ev_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        throw new DomainException('Your session expired. Please try again.');
    }
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new DomainException('No client selected.');
    }
    @set_time_limit(600);
    $r = credit_evidence_import_existing($conn, $userId);
    if ($r['read'] === 0 && !$r['errors']) {
        $flash = [$r['skipped'] > 0
            ? 'These files were already read. See Loans > Credit Documents.'
            : 'This client has no trading licence or supporting documents to read.', 'info'];
    } else {
        $msg = $r['read'] . ' file(s) read and waiting for review in Loans > Credit Documents.';
        if ($r['skipped'] > 0) {
            $msg .= ' ' . $r['skipped'] . ' already read before.';
        }
        if ($r['errors']) {
            $msg .= ' Problems: ' . implode(' ', $r['errors']);
        }
        $flash = [$msg, $r['errors'] ? 'warning' : 'success'];
    }
} catch (DomainException $e) {
    $flash = [$e->getMessage(), 'danger'];
} catch (Throwable $e) {
    error_log('credit_evidence_import: ' . $e->getMessage());
    $flash = ['The files could not be read. Please try again.', 'danger'];
}

$_SESSION['ce_import_flash'] = $flash;
header('Location: ' . $back);
exit();
