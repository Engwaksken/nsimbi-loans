<?php
/**
 * Shows one of the logged-in client's own uploaded documents.
 * Files live outside direct web access; this checks ownership first.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require "db.php";
require_once __DIR__ . '/../includes/client_documents.php';

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
if ($user_id <= 0 || (int)($_SESSION['role'] ?? 0) !== 3) {
    http_response_code(403);
    exit('Please log in.');
}

$doc = client_docs_find($conn, (int)($_GET['id'] ?? 0));
if (!$doc || (int)$doc['user_id'] !== $user_id) {
    http_response_code(404);
    exit('Document not found.');
}

client_docs_stream($doc, isset($_GET['download']));
