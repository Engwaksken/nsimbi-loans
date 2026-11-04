<?php
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
$name = getenv('DB_NAME') ?: '';
if ($user === '' || $name === '') {
    http_response_code(500);
    die('Database configuration is missing.');
}
$conn = new mysqli($host, $user, $pass, $name);
if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    die('Database connection failed.');
}
?>
