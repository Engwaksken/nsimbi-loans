<?php
declare(strict_types=1);

require_once __DIR__.'/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    echo json_encode(['results' => [], 'message' => 'Forbidden']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));

if (mb_strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$like = '%' . $q . '%';

$sql = "
    SELECT
        u.id,
        u.fname,
        u.lname,
        u.username,
        u.email,
        u.role,

        CASE 
            WHEN EXISTS (
                SELECT 1
                FROM loan l
                WHERE l.client_id = u.id
                  AND UPPER(l.status) IN (
                    'PENDING',
                    'REVIEW',
                    'REVIEWED',
                    'APPROVED',
                    'DISBURSED',
                    'OUTSTANDING',
                    'OVERDUE'
                  )
                LIMIT 1
            ) THEN 1
            ELSE 0
        END AS has_outstanding

    FROM user u
    WHERE 
        (
            u.fname LIKE ?
            OR u.lname LIKE ?
            OR u.username LIKE ?
            OR u.email LIKE ?
            OR CONCAT(u.fname, ' ', u.lname) LIKE ?
        )
        AND LOWER(CAST(u.role AS CHAR)) IN ('4', 'client', 'customer', 'member', 'staff', 'startup')
    ORDER BY u.fname ASC, u.lname ASC
    LIMIT 20
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode([
        'results' => [],
        'message' => 'Query prepare failed: ' . $conn->error
    ]);
    exit;
}

$stmt->bind_param('sssss', $like, $like, $like, $like, $like);
$stmt->execute();

$res = $stmt->get_result();
$results = [];

while ($row = $res->fetch_assoc()) {
    $fullName = trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));

    if ($fullName === '') {
        $fullName = $row['username'] ?: $row['email'] ?: 'Unnamed Client';
    }

    $results[] = [
        'id' => (int)$row['id'],
        'type' => 'client',
        'name' => $fullName,
        'username' => $row['username'] ?? '',
        'email' => $row['email'] ?? '',
        'role' => (string)($row['role'] ?? ''),
        'has_outstanding' => ((int)$row['has_outstanding'] === 1),
        'sub' => trim(
            'Username: ' . ($row['username'] ?? 'N/A') .
            ' — Email: ' . ($row['email'] ?? 'N/A') .
            (((int)$row['has_outstanding'] === 1) ? ' — Has outstanding loan' : '')
        ),
    ];
}

$stmt->close();

echo json_encode(['results' => $results]);