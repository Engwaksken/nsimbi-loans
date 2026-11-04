<?php
session_start();
require_once "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: loan_messages.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE notifications SET status = 'read' WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->close();

header("Location: " . $_SERVER['HTTP_REFERER']);
exit;
?>
