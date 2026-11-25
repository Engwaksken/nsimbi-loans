<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require "db.php";
require_once dirname(__DIR__) . '/includes/officer_assignment.php';

if (empty($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

// Loan officers can only mark messages about their own loans or clients.
$officerId = officer_scope_id();
$officerSql = $officerId > 0 ? " AND (
        loan_id IN (SELECT id FROM loan WHERE assigned_officer_id = {$officerId})
        OR (COALESCE(loan_id, 0) = 0 AND (
            user_id IN (SELECT user_id FROM customers WHERE assigned_officer_id = {$officerId})
            OR user_id IN (SELECT user_id FROM members WHERE assigned_officer_id = {$officerId})
        ))
    )" : '';

if ($id > 0) {
    $stmt = $conn->prepare("UPDATE loan_messages SET is_read = 1 WHERE id = ?" . $officerSql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
}

echo json_encode(['success' => true]);