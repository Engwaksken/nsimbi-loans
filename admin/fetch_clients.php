<?php
require "db.php";

// Get all active customers who do not have active loans
$sql = "
    SELECT u.id AS user_id, u.fname, u.lname, c.phone
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    LEFT JOIN loan l ON l.client_id = u.id AND l.status IN ('PENDING','OUTSTANDING','OVERDUE')
    WHERE u.status = 1 AND l.id IS NULL
    ORDER BY u.fname ASC
";

$result = mysqli_query($conn, $sql);
if (!$result) {
    die("Database error: " . mysqli_error($conn));
}

$clients = [];
while ($row = mysqli_fetch_assoc($result)) {
    $clients[] = [
        'id' => $row['user_id'],
        'name' => $row['fname'] . ' ' . $row['lname'] . ' - ' . $row['phone']
    ];
}

header('Content-Type: application/json');
echo json_encode($clients);
