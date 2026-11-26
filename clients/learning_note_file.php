<?php
declare(strict_types=1);

/**
 * Opens a learning note shared with the logged-in client and marks it read.
 * Files live outside direct web access; this checks the note was shared first.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require "db.php";
require_once __DIR__ . '/../includes/client_learning.php';

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
if ($user_id <= 0 || (int)($_SESSION['role'] ?? 0) !== 3) {
    http_response_code(403);
    exit('Please log in.');
}

$noteId = (int)($_GET['id'] ?? 0);
learning_ensure_schema($conn);
$stmt = $conn->prepare('SELECT 1 FROM literacy_note_shares WHERE note_id = ? AND user_id = ? LIMIT 1');
$stmt->bind_param('ii', $noteId, $user_id);
$stmt->execute();
$shared = (bool)$stmt->get_result()->fetch_row();
$stmt->close();

$note = $shared ? learning_note_find($conn, $noteId) : null;
if (!$note) {
    http_response_code(404);
    exit('Note not found.');
}

$stmt = $conn->prepare('UPDATE literacy_note_shares SET viewed_at = NOW() WHERE note_id = ? AND user_id = ? AND viewed_at IS NULL');
$stmt->bind_param('ii', $noteId, $user_id);
$stmt->execute();
$stmt->close();

learning_note_stream($note, isset($_GET['download']));
