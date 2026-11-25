<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require "db.php";
require_once dirname(__DIR__) . '/includes/officer_assignment.php';

// Select staff who do NOT have pending, outstanding, or overdue loans
$sql = "
    SELECT u.id AS user_id, u.fname, u.lname, m.phone
    FROM members m
    INNER JOIN user u ON m.user_id = u.id
    LEFT JOIN loan l ON l.client_id = u.id AND l.status IN ('PENDING','OUTSTANDING','OVERDUE')
    WHERE u.status = 1 AND l.id IS NULL" . officer_client_sql('m') . "
    ORDER BY u.fname ASC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Database error: " . mysqli_error($conn));
}

$staff = [];
while ($row = mysqli_fetch_assoc($result)) {
    $staff[] = [
        'id' => $row['user_id'],
        'name' => $row['fname'] . ' ' . $row['lname'] . ' - ' . $row['phone']
    ];
}

header('Content-Type: application/json');
echo json_encode($staff);
