<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: '';
$dbPassValue = getenv('DB_PASS');
$dbPass = $dbPassValue !== false ? (string)$dbPassValue : (string)(getenv('DB_PASSWORD') ?: '');
$dbName = getenv('DB_NAME') ?: '';
$dbPort = filter_var(getenv('DB_PORT') ?: '3306', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) ?: 3306;

if ($dbUser === '' || $dbName === '') {
    error_log('Database configuration is missing DB_USER or DB_NAME.');
    http_response_code(500);
    exit('Database connection failed. Check the database environment configuration.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed. Check the database settings and server logs.');
}
