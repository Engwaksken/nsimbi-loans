<?php
declare(strict_types=1);

/* Client accepts or rejects a loan offer from my_loans.php. */

session_start();

require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../includes/loan_offers.php';

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['logged_in']) || (int)($_SESSION['role'] ?? 0) !== 3) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
$token = (string)($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $userId <= 0
    || empty($_SESSION['loan_offer_csrf']) || !hash_equals((string)$_SESSION['loan_offer_csrf'], $token)) {
    $_SESSION['message'] = 'Your session expired. Please try again.';
    $_SESSION['message_type'] = 'error';
    header('Location: my_loans.php', true, 303);
    exit;
}

$result = loan_offer_respond($conn, $userId, (int)($_POST['loan_id'] ?? 0), (string)($_POST['decision'] ?? ''));

$_SESSION['message'] = $result['message'];
$_SESSION['message_type'] = $result['ok'] ? 'success' : 'error';
header('Location: my_loans.php', true, 303);
exit;
