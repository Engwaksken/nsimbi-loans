<?php
declare(strict_types=1);

header('Content-Type: application/json');

require 'db.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');



function jsonError(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function jsonSuccess(array $payload): never
{
    http_response_code(200);
    echo json_encode(array_merge(['status' => 'success'], $payload));
    exit;
}

function post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function formatPhone(string $phone): string
{
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (str_starts_with($phone, '0'))      return '+256' . substr($phone, 1);
    if (str_starts_with($phone, '256'))    return '+' . $phone;
    if (!str_starts_with($phone, '+'))     return '+256' . $phone;
    return $phone;
}



function uploadFile(string $field, string $dir, array $allowed, bool $required = false): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        if ($required) jsonError(ucfirst($field) . ' file is required.');
        return null;
    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonError("Upload error for {$field} (code {$file['error']}).");
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        jsonError("{$field} exceeds the 5 MB limit.");
    }

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowed, true)) {
        jsonError("{$field} has an invalid file type. Allowed: JPEG, PNG, PDF.");
    }

    @mkdir($dir, 0755, true);
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $name = $field . '_' . uniqid() . '.' . $ext;
    $dest = rtrim($dir, '/') . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        jsonError("Failed to save {$field}. Check server permissions.");
    }

    return $dest;
}



function saveSignature(string $base64, string $dir, bool $required = false): ?string
{
    if ($base64 === '') {
        if ($required) jsonError('Applicant signature is required.');
        return null;
    }

    $raw = preg_replace('/^data:image\/[^;]+;base64,/', '', $base64);
    $img = base64_decode($raw, true);
    if ($img === false) jsonError('Invalid signature data.');

    @mkdir($dir, 0755, true);
    $path = rtrim($dir, '/') . '/signature_' . uniqid() . '.png';

    if (file_put_contents($path, $img) === false) {
        jsonError('Failed to save signature.');
    }

    return $path;
}



function creditScore(string $type): int
{
    // One scoring table for website, admin, import and the loan pages.
    // Business types not in the table get the default score of 3.
    require_once dirname(__DIR__) . '/includes/loan_assessment.php';
    return loan_assessment_business_score($type)[0];
}

function parseAmountRange(string $str): float
{
    // Same reader as the AI recommended amount: plain amounts, k/M, currency
    // words, ranges ("50k-100k", "500,000 to 1M") and open ranges ("Above 2M").
    require_once dirname(__DIR__) . '/includes/loan_assessment.php';
    return loan_assessment_parse_turnover($str)[0];
}

function calcLoanLimit(string $amountLast, string $amountThis, string $businessType): float
{
    $last  = parseAmountRange($amountLast)  * 0.9;
    $this_ = parseAmountRange($amountThis)  * 1.1;
    $avg   = ($last + $this_) / 2;
    return $avg + $avg * creditScore($businessType) / 10;
}



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Invalid request method.', 405);
}

// Required text fields
$required = [
    'fname', 'lname', 'username', 'gender', 'dob', 'nin',
    'phone', 'bank_name',
    'district', 'business_name', 'business_type', 'location',
    'business_period', 'amount_last', 'amount_this',
    'nok_name', 'nok_tel', 'nok_rel',
    'password', 'con_password',
];

$missing = array_filter($required, fn($f) => post($f) === '');
if (!empty($missing)) {
    jsonError('Missing required fields: ' . implode(', ', $missing));
}



$fname           = post('fname');
$lname           = post('lname');
$username        = post('username');
$gender          = post('gender');
$dob             = post('dob');
$nin             = post('nin');
$phone           = formatPhone(post('phone'));
$email           = post('email');
$bank_name       = post('bank_name');
$bank_account    = post('bank_account');
$district        = post('district');
$business_name   = post('business_name');
$business_type   = post('business_type');
$client_category = post('client_category') === 'new_onboarded' ? 'new_onboarded' : 'business';
$location        = post('location');
$business_period = post('business_period');
$amount_last_raw = post('amount_last');
$amount_this_raw = post('amount_this');
$nok_name        = post('nok_name');
$nok_tel         = formatPhone(post('nok_tel'));
$nok_rel         = post('nok_rel');
$password        = post('password');
$con_password    = post('con_password');


$manual_limit    = post('loan');
$loan_limit      = $manual_limit !== ''
    ? (float)$manual_limit
    : calcLoanLimit($amount_last_raw, $amount_this_raw, $business_type);

$credit_score    = creditScore($business_type);
$amount_last_num = parseAmountRange($amount_last_raw) * 0.9;
$amount_this_num = parseAmountRange($amount_this_raw) * 1.1;

$role   = 3;
$active = 1;



if ($password !== $con_password) {
    jsonError('Passwords do not match.');
}

if (strlen($password) < 6) {
    jsonError('Password must be at least 6 characters.');
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Invalid email address format.');
}



if ($email !== '') {
    $s = $conn->prepare('SELECT id FROM user WHERE email = ? LIMIT 1');
    $s->bind_param('s', $email);
    $s->execute();
    $s->store_result();
    if ($s->num_rows > 0) jsonError('That email address is already registered.', 409);
    $s->close();
}

$s = $conn->prepare('SELECT id FROM customers WHERE nin = ? LIMIT 1');
$s->bind_param('s', $nin);
$s->execute();
$s->store_result();
if ($s->num_rows > 0) jsonError('A client with that NIN already exists.', 409);
$s->close();

$s = $conn->prepare('SELECT id FROM user WHERE username = ? LIMIT 1');
$s->bind_param('s', $username);
$s->execute();
$s->store_result();
if ($s->num_rows > 0) jsonError('That username is already taken. Please choose another.', 409);
$s->close();



$allowed = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];

$profile_img         = uploadFile('profile_img', 'uploads/',    $allowed, false);
$id_front            = uploadFile('uploadid1',   'uploads/',    $allowed, false);
$id_back             = uploadFile('uploadid2',   'uploads/',    $allowed, false);
$stock               = uploadFile('stock',       'uploads/',    $allowed, false);
$license             = uploadFile('license',     'uploads/',    $allowed, false);
$applicant_signature = saveSignature(post('applicant_signature'), 'signatures/', false);

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);



$conn->autocommit(false);

try {

 
    $stmt = $conn->prepare('
        INSERT INTO user
            (fname, lname, username, role, password, email, image_url, datetime, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ');
    if (!$stmt) throw new RuntimeException('Prepare user: ' . $conn->error);

    $stmt->bind_param('sssisssi',
        $fname, $lname, $username,
        $role, $hashedPassword,
        $email, $profile_img, $active
    );
    if (!$stmt->execute()) throw new RuntimeException('Insert user: ' . $stmt->error);
    $user_id = (int)$stmt->insert_id;
    $stmt->close();

  
    $stmt = $conn->prepare('
        INSERT INTO customers (
            user_id, gender, dob, nin,
            id_front, id_back,
            phone, bank_name, bank_account, email,
            district, business_name, business_type, client_category, location,
            stock, amount_last, amount_this, license,
            nok_name, nok_tel, nok_rel, business_period,
            loan_limit, password, credit_score,
            applicant_signature, active
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, 1
        )
    ');
    if (!$stmt) throw new RuntimeException('Prepare customer: ' . $conn->error);

    $stmt->bind_param(
        'issssssssssssssddssssssdiss',
        $user_id,
        $gender, $dob, $nin,
        $id_front, $id_back,
        $phone, $bank_name, $bank_account, $email,
        $district, $business_name, $business_type, $client_category, $location,
        $stock, $amount_last_num, $amount_this_num, $license,
        $nok_name, $nok_tel, $nok_rel, $business_period,
        $loan_limit, $hashedPassword, $credit_score,
        $applicant_signature
    );
    if (!$stmt->execute()) throw new RuntimeException('Insert customer: ' . $stmt->error);
    $customer_id = (int)$stmt->insert_id;
    $stmt->close();

    $conn->commit();
    $conn->autocommit(true);

    jsonSuccess([
        'message'      => 'Client registered successfully.',
        'redirect'     => 'clients.php',
        'delay'        => 1500,
        'user_id'      => $user_id,
        'customer_id'  => $customer_id,
        'loan_limit'   => round($loan_limit),
        'credit_score' => $credit_score,
    ]);

} catch (Throwable $e) {

    $conn->rollback();
    $conn->autocommit(true);

 
    foreach ([$profile_img, $id_front, $id_back, $stock, $license, $applicant_signature] as $f) {
        if (is_string($f) && $f !== '' && file_exists($f)) {
            @unlink($f);
        }
    }

    error_log('[added_client] ' . $e->getMessage());

    jsonError('Registration failed. Please check your information and try again.', 500);
}
