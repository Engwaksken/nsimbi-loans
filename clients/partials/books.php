<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit();
}

if ((int)($_SESSION['role'] ?? 0) !== 3) {
    header('Location: ../login.php');
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);

require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/includes/books-dashboard.php';

$books = books_dashboard_view_model($conn, $user_id, $_GET);

function money(float $amount): string
{
    return 'UGX ' . number_format($amount);
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$phead = 'MY BOOKS';
require __DIR__ . '/nav-head.php';
require __DIR__ . '/partials/books-dashboard-view.php';
require __DIR__ . '/footer.php';