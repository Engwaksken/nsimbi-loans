<?php
session_start();
require "db.php";

/* AUTH  */
$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
if (!in_array($role, [1, 2], true)) {
    echo '<script>alert("Not Authorized for this Action"); window.location.replace("view_staff.php");</script>';
    exit;
}

/*  HELPER: CLEAN MONEY (ALLOW COMMAS)  */
function clean_money($value) {
    $value = trim((string)$value);
    if ($value === '') return null; 
    $clean = str_replace([',', ' '], '', $value); // 1,200,000 -> 1200000
    return is_numeric($clean) ? (float)$clean : false;
}

/* INPUT */
$staff        = trim($_REQUEST['staff'] ?? '');
$grossRaw     = $_REQUEST['gross_salary'] ?? '';
$netRaw       = $_REQUEST['net_salary'] ?? '';
$phone        = trim($_REQUEST['phone'] ?? '');
$home_address = trim($_REQUEST['home_address'] ?? '');

/* VALIDATE  */
if ($staff === '') {
    echo '<script>alert("Invalid staff ID."); window.location.replace("view_staff.php");</script>';
    exit;
}

/*  FETCH CURRENT STAFF  */
$stmt = $conn->prepare("SELECT gross_salary, net_salary, phone, home_address FROM members WHERE user_id = ? LIMIT 1");
$stmt->bind_param("s", $staff);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo '<script>alert("Staff not found."); window.location.replace("view_staff.php");</script>';
    exit;
}

/*  PROCESS SALARIES (ALLOW COMMAS)  */
$gross_salary = clean_money($grossRaw);
$net_salary   = clean_money($netRaw);

if ($gross_salary === false) {
    echo '<script>alert("Invalid gross salary format."); window.location.replace("view_staff.php?user_id=' . urlencode($staff) . '");</script>';
    exit;
}
if ($net_salary === false) {
    echo '<script>alert("Invalid net salary format."); window.location.replace("view_staff.php?user_id=' . urlencode($staff) . '");</script>';
    exit;
}

/*  FALLBACKS  */
$gross_salary = ($gross_salary === null) ? (float)$user['gross_salary'] : $gross_salary;
$net_salary   = ($net_salary === null)   ? (float)$user['net_salary']   : $net_salary;

$phone        = ($phone !== '')        ? $phone        : $user['phone'];
$home_address = ($home_address !== '') ? $home_address : $user['home_address'];

/*  UPDATE  */
$update = $conn->prepare("
    UPDATE members
    SET gross_salary = ?, net_salary = ?, phone = ?, loan_limit= ?, home_address = ?
    WHERE user_id = ?
");
$update->bind_param("ddsdss", $gross_salary, $net_salary, $phone, $net_salary, $home_address, $staff);

if ($update->execute()) {
    echo '<script>
        alert("Staff successfully updated.");
        window.location.replace("view_staff.php?user_id=' . urlencode($staff) . '");
    </script>';
} else {
    echo '<script>
        alert("Failed to update staff details.");
        window.location.replace("view_staff.php?user_id=' . urlencode($staff) . '");
    </script>';
}

$update->close();
$conn->close();
?>
