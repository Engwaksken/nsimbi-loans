<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$email = sanitize($conn, $_POST['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please provide a valid email.'
    ]);
    exit;
}

$ex = $conn->query("SELECT id FROM form_submissions WHERE form_type='newsletter' AND email='$email' LIMIT 1");

if ($ex && $ex->num_rows) {
    echo json_encode([
        'success' => true,
        'message' => 'Already subscribed! Thank you.'
    ]);
    exit;
}

$conn->query("INSERT INTO form_submissions (form_type,email) VALUES('newsletter','$email')");

echo json_encode([
    'success' => true,
    'message' => 'Subscribed successfully! Welcome to the Nsimbi community.'
]);
exit;