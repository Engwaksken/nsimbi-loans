<?php
$servername = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USER') ?: '';
$password = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: '';

if ($username === '' || $dbname === '') {
    http_response_code(500);
    die('Database configuration is missing.');
}

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    die('Database connection failed.');
}
?>
