<?php
session_start();
require "db.php";

/* ---------- AUTH ---------- */
$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;

if (!in_array($role, [1, 2], true)) {
    $_SESSION['message'] = "Not Authorized for this Action";
    $_SESSION['message_type'] = "error";
    header("Location: dashboard.php");
    exit;
}

/* ---------- INPUT ---------- */
$user_id = isset($_POST['client'])
    ? (int)$_POST['client']
    : (int)($_REQUEST['user_id'] ?? 0);

$phone         = trim($_POST['phone'] ?? '');
$loanLimitRaw  = trim($_POST['loan_limit'] ?? '');

/* ---------- VALIDATE ---------- */
if ($user_id <= 0) {
    $_SESSION['message'] = "Invalid client selected.";
    $_SESSION['message_type'] = "error";
    header("Location: clients.php");
    exit;
}

/* ---------- FETCH CURRENT CLIENT ---------- */
$stmt = $conn->prepare("SELECT phone, loan_limit FROM customers WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$clientData = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$clientData) {
    $_SESSION['message'] = "Client not found.";
    $_SESSION['message_type'] = "error";
    header("Location: view_client.php?user_id=" . urlencode($user_id));
    exit;
}

/* ---------- PROCESS LOAN LIMIT (ALLOW COMMAS) ---------- */
if ($loanLimitRaw !== '') {
    // Remove commas and spaces: 1,200,000 ? 1200000
    $cleanLoanLimit = str_replace([',', ' '], '', $loanLimitRaw);

    if (!is_numeric($cleanLoanLimit)) {
        $_SESSION['message'] = "Invalid loan limit amount.";
        $_SESSION['message_type'] = "error";
        header("Location: view_client.php?user_id=" . urlencode($user_id));
        exit;
    }

    $loanLimit = (float)$cleanLoanLimit;
} else {
    // Keep existing value
    $loanLimit = (float)$clientData['loan_limit'];
}

/* ---------- FALLBACK PHONE ---------- */
$phone = ($phone !== '') ? $phone : $clientData['phone'];

/* ---------- UPDATE ---------- */
$updateStmt = $conn->prepare("
    UPDATE customers
    SET phone = ?, loan_limit = ?
    WHERE user_id = ?
");
$updateStmt->bind_param("sdi", $phone, $loanLimit, $user_id);

if ($updateStmt->execute()) {
    $_SESSION['message'] = "Client successfully updated.";
    $_SESSION['message_type'] = "success";
} else {
    $_SESSION['message'] = "Failed to update client.";
    $_SESSION['message_type'] = "error";
}

$updateStmt->close();

header("Location: view_client.php?user_id=" . urlencode($user_id));
exit;
?>
