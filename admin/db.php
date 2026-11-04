<?php
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
$name = getenv('DB_NAME') ?: '';
if ($user === '' || $name === '') {
    http_response_code(500);
    die('Database configuration is missing.');
}
$conn = mysqli_connect($host, $user, $pass, $name);
if (!$conn) {
    error_log('Database connection failed: ' . mysqli_connect_error());
    http_response_code(500);
    die('Database connection failed.');
}
?>
