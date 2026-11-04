<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit;
}

$form_type = sanitize($conn, $_POST['form_type'] ?? '');
$allowed   = ['donor','eso','loan','contact','newsletter'];
if (!in_array($form_type, $allowed)) {
    echo json_encode(['success'=>false,'message'=>'Unknown form type.']); exit;
}

// Newsletter – only email needed
if ($form_type === 'newsletter') {
    $email = sanitize($conn, $_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success'=>false,'message'=>'Please provide a valid email address.']); exit;
    }
    $exists = $conn->query("SELECT id FROM form_submissions WHERE form_type='newsletter' AND email='$email' LIMIT 1");
    if ($exists && $exists->num_rows) {
        echo json_encode(['success'=>true,'message'=>'You are already subscribed. Thank you!']); exit;
    }
    $conn->query("INSERT INTO form_submissions (form_type,email) VALUES('newsletter','$email')");
    echo json_encode(['success'=>true,'message'=>'Thank you for subscribing!']); exit;
}

// Donor / ESO / Loan / Contact
$fname    = sanitize($conn, $_POST['fname']    ?? '');
$lname    = sanitize($conn, $_POST['lname']    ?? '');
$email    = sanitize($conn, $_POST['email']    ?? '');
$phone    = sanitize($conn, $_POST['phone']    ?? '');
$org      = sanitize($conn, $_POST['organization'] ?? '');
$country  = sanitize($conn, $_POST['country'] ?? '');
$message  = $conn->real_escape_string($_POST['message'] ?? '');
$amount   = !empty($_POST['amount']) ? (float)$_POST['amount'] : 'NULL';

if (!$fname || !$email) {
    echo json_encode(['success'=>false,'message'=>'Please fill all required fields.']); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success'=>false,'message'=>'Please provide a valid email address.']); exit;
}

// Extra data (ESO specific)
$extra = [];
foreach(['extra_type','extra_reach','loan_type','loan_amount','business_name'] as $k) {
    if (!empty($_POST[$k])) $extra[$k] = $_POST[$k];
}
$extra_json = $conn->real_escape_string(json_encode($extra));
$amountSql  = is_numeric($amount) ? $amount : 'NULL';

$conn->query("INSERT INTO form_submissions
    (form_type,fname,lname,email,phone,organization,country,amount,message,extra_data,status)
    VALUES
    ('$form_type','$fname','$lname','$email','$phone','$org','$country',$amountSql,'$message','$extra_json','new')");

$messages = [
    'donor'   => 'Thank you for your interest in supporting Nsimbi Impact! We will be in touch soon.',
    'eso'     => 'Thank you for your partnership interest! Our team will review your application and contact you shortly.',
    'loan'    => 'Your loan application has been received. We will review it and contact you within 3–5 business days.',
    'contact' => 'Thank you for reaching out! We will respond to your message within 48 hours.',
];

echo json_encode(['success'=>true,'message'=> $messages[$form_type] ?? 'Submission received. Thank you!']);
