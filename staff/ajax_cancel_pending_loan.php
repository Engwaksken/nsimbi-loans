<?php
declare(strict_types=1);

session_start();

require "db.php";

header("Content-Type: application/json");

function json_response(bool $success, string $message): void
{
    echo json_encode([
        "success" => $success,
        "message" => $message
    ]);
    exit;
}

if (empty($_SESSION['logged_in'])) {
    json_response(false, "Unauthorized access.");
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    json_response(false, "Database connection not found.");
}

$conn->set_charset("utf8mb4");

$client_id = (int)$_SESSION['logged_in'];
$loan_id = (int)($_POST['loan_id'] ?? 0);

if ($loan_id <= 0) {
    json_response(false, "Invalid loan selected.");
}

$stmt = $conn->prepare("
    DELETE FROM loan
    WHERE id = ?
    AND client_id = ?
    AND UPPER(status) = 'PENDING'
");
$stmt->bind_param("ii", $loan_id, $client_id);

if (!$stmt->execute()) {
    json_response(false, "Failed to cancel loan.");
}

if ($stmt->affected_rows < 1) {
    json_response(false, "Loan not found or cannot be cancelled.");
}

$stmt->close();

json_response(true, "Pending loan cancelled successfully.");