<?php
declare(strict_types=1);

/** Opens a learning note's file for staff (admins and loan officers). */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
if (empty($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    http_response_code(403);
    exit('Please log in.');
}
require_once dirname(__DIR__) . '/includes/client_learning.php';

$note = learning_note_find($conn, (int)($_GET['id'] ?? 0));
if (!$note) {
    http_response_code(404);
    exit('Note not found.');
}
learning_note_stream($note, isset($_GET['download']));
