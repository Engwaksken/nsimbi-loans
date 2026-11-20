<?php
/**
 * Shows a client's credit document (statement, utility bill, business records) to staff.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/credit_scoring.php';

if (!isset($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    http_response_code(403);
    exit('Staff only.');
}

$doc = credit_evidence_find($conn, (int)($_GET['id'] ?? 0));
if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

client_docs_stream($doc, isset($_GET['download']));
