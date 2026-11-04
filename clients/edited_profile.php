<?php
require "db.php";

$client = isset($_REQUEST['client']) ? (int)$_REQUEST['client'] : 0;

if ($client <= 0) {
    echo '<script>alert("Invalid client ID"); window.location.replace("profile.php");</script>';
    exit;
}

$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->bind_param("i", $client);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<script>alert("Client not found"); window.location.replace("profile.php");</script>';
    exit;
}

$fetch_user = $result->fetch_assoc();

$nok_name     = trim($_REQUEST['nok_name'] ?? '');
$nok_tel      = trim($_REQUEST['nok_tel'] ?? '');
$phone        = trim($_REQUEST['phone'] ?? '');
$location     = trim($_REQUEST['location'] ?? '');
$bank_name    = trim($_REQUEST['bank_name'] ?? '');
$bank_account = trim($_REQUEST['bank_number'] ?? '');

if ($nok_name === '') {
    $nok_name = $fetch_user['nok_name'] ?? '';
}

if ($nok_tel === '') {
    $nok_tel = $fetch_user['nok_tel'] ?? '';
}

if ($phone === '') {
    $phone = $fetch_user['phone'] ?? '';
}

if ($location === '') {
    $location = $fetch_user['location'] ?? '';
}

if ($bank_name === '') {
    $bank_name = $fetch_user['bank_name'] ?? '';
}

if ($bank_account === '') {
    $bank_account = $fetch_user['bank_account'] ?? '';
}

$update = $conn->prepare("
    UPDATE customers 
    SET 
        nok_name = ?,
        nok_tel = ?,
        phone = ?,
        location = ?,
        bank_name = ?,
        bank_account = ?
    WHERE id = ?
");

$update->bind_param(
    "ssssssi",
    $nok_name,
    $nok_tel,
    $phone,
    $location,
    $bank_name,
    $bank_account,
    $client
);

if ($update->execute()) {
    $msg = "CLIENT SUCCESSFULLY UPDATED";
} else {
    $msg = "FAILED TO UPDATE CLIENT";
}

echo '<script>
    alert("' . addslashes($msg) . '");
    window.location.replace("profile.php?client_id=' . $client . '");
</script>';
exit;
?>