<?php
/**
 * Shows a client-uploaded document to staff (admins and loan officers).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/client_documents.php';

if (!isset($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    http_response_code(403);
    exit('Staff only.');
}

$doc = client_docs_find($conn, (int)($_GET['id'] ?? 0));
if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

client_docs_stream($doc, isset($_GET['download']));
