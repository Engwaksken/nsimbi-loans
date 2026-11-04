<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require "db.php";

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function jsonResponse($status, $message, $code = 200, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge([
        "status" => $status,
        "message" => $message
    ], $extra));
    exit;
}

function postValue($key, $default = '') {
    return trim((string)($_POST[$key] ?? $default));
}

function ensureDir($dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function formatPhone($phone) {
    $phone = preg_replace('/[^\d+]/', '', $phone);

    if ($phone === '') {
        return '';
    }

    if (str_starts_with($phone, '0')) {
        return '+256' . substr($phone, 1);
    }

    if (str_starts_with($phone, '256')) {
        return '+' . $phone;
    }

    if (!str_starts_with($phone, '+')) {
        return '+256' . $phone;
    }

    return $phone;
}

function uploadFile($field, $folder = 'uploads') {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        jsonResponse("error", "Failed to upload " . $field, 400);
    }

    if ($_FILES[$field]['size'] > 8 * 1024 * 1024) {
        jsonResponse("error", $field . " is too large. Maximum 8MB allowed.", 400);
    }

    ensureDir(__DIR__ . "/" . $folder);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf'
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES[$field]['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$mime])) {
        jsonResponse("error", $field . " must be JPG, PNG or PDF.", 400);
    }

    $ext = $allowed[$mime];
    $name = $field . "_" . date("YmdHis") . "_" . bin2hex(random_bytes(6)) . "." . $ext;
    $path = $folder . "/" . $name;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], __DIR__ . "/" . $path)) {
        jsonResponse("error", "Failed to save " . $field, 500);
    }

    return $path;
}

function saveSignature($base64) {
    $base64 = trim($base64);

    if ($base64 === '') {
        jsonResponse("error", "Please draw your signature before registering.", 400);
    }

    if (!preg_match('/^data:image\/png;base64,/', $base64)) {
        return '';
    }

    ensureDir(__DIR__ . "/signatures");

    $data = preg_replace('/^data:image\/png;base64,/', '', $base64);
    $image = base64_decode($data, true);

    if ($image === false || strlen($image) > 1024 * 1024) {
        jsonResponse("error", "The signature image is invalid or too large.", 400);
    }

    $imageInfo = getimagesizefromstring($image);
    if (!$imageInfo || $imageInfo['mime'] !== 'image/png') {
        jsonResponse("error", "The signature must be a valid PNG image.", 400);
    }

    $name = "signature_" . date("YmdHis") . "_" . bin2hex(random_bytes(6)) . ".png";
    $path = "signatures/" . $name;

    if (file_put_contents(__DIR__ . "/" . $path, $image, LOCK_EX) === false) {
        jsonResponse("error", "Could not save the signature. Please try again.", 500);
    }

    return $path;
}

function amountToNumber($value) {
    $value = trim($value);

    $map = [
        "10,000 - 50,000" => 30000,
        "50,000 - 100,000" => 75000,
        "100,000 - 500,000" => 300000,
        "500,000 - 1M" => 750000,
        "1 - 2M" => 1500000,
        "Above 2M" => 2000000
    ];

    return $map[$value] ?? 0;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse("error", "Invalid request method.", 405);
}

$fname = postValue("fname");
$lname = postValue("lname");
$username = postValue("username");
$gender = postValue("gender");
$dob = postValue("dob");
$phone = formatPhone(postValue("phone"));
$email = postValue("email");
$bank_name = postValue("bank_name");
$bank_account = postValue("bank_account");
$business_name = postValue("business_name");
$business_type = postValue("business_type");
$business_period = postValue("business_period");
$amount_last_raw = postValue("amount_last");
$amount_this_raw = postValue("amount_this");
$district = postValue("district");
$location = postValue("location");
$nok_name = postValue("nok_name");
$nok_tel = formatPhone(postValue("nok_tel"));
$nok_rel = postValue("nok_rel");
$nin = postValue("nin");
$password = (string)($_POST["password"] ?? "");
$con_password = (string)($_POST["con_password"] ?? "");

$required = [
    "fname" => $fname,
    "lname" => $lname,
    "username" => $username,
    "gender" => $gender,
    "dob" => $dob,
    "phone" => $phone,
    "business_name" => $business_name,
    "business_type" => $business_type,
    "business_period" => $business_period,
    "amount_last" => $amount_last_raw,
    "amount_this" => $amount_this_raw,
    "district" => $district,
    "location" => $location,
    "nok_name" => $nok_name,
    "nok_tel" => $nok_tel,
    "nok_rel" => $nok_rel,
    "nin" => $nin,
    "password" => $password,
    "con_password" => $con_password
];

foreach ($required as $key => $value) {
    if ($value === '') {
        jsonResponse("error", "Required field missing: " . $key, 400);
    }
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse("error", "Invalid email address.", 400);
}

if ($password !== $con_password) {
    jsonResponse("error", "Passwords do not match.", 400);
}

if (strlen($password) < 6) {
    jsonResponse("error", "Password must be at least 6 characters.", 400);
}

try {
    $stmt = $conn->prepare("SELECT id FROM customers WHERE nin = ? LIMIT 1");
    $stmt->bind_param("s", $nin);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        jsonResponse("error", "This NIN already exists.", 409);
    }

    $stmt->close();

    if ($email !== '') {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            jsonResponse("error", "This email already exists.", 409);
        }

        $stmt->close();
    }

    $applicant_signature = saveSignature($_POST["applicant_signature"] ?? "");
    $profile_img = uploadFile("profile_img");
    $id_front = uploadFile("uploadid1");
    $id_back = uploadFile("uploadid2");
    $stock = uploadFile("stock");
    $license = uploadFile("license");

    $amount_last = amountToNumber($amount_last_raw);
    $amount_this = amountToNumber($amount_this_raw);

    $credit_scores = [
        "Beauty salon" => 6,
        "Education" => 8,
        "Electronics" => 4,
        "Farming" => 5,
        "Healthcare" => 9,
        "Market stall" => 8,
        "Poultry keeping" => 4,
        "Restaurant" => 7,
        "Retail shop" => 6,
        "Sell second hand clothes" => 4,
        "Tailoring" => 7,
        "Transportation" => 5
    ];

    $credit_score = $credit_scores[$business_type] ?? 5;
    $avg_amount = ($amount_last + $amount_this) / 2;
    $loan_limit = $avg_amount + (($avg_amount * $credit_score) / 10);

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $active = 1;

    $conn->begin_transaction();

    $stmt = $conn->prepare("
        INSERT INTO user
            (fname, lname, username, role, password, email, image_url, datetime, status)
        VALUES
            (?, ?, ?, 3, ?, ?, ?, NOW(), 1)
    ");

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param(
        "ssssss",
        $fname,
        $lname,
        $username,
        $hashedPassword,
        $email,
        $profile_img
    );

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $user_id = $stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO customers
            (
                user_id,
                gender,
                dob,
                phone,
                bank_name,
                bank_account,
                email,
                business_name,
                business_type,
                business_period,
                amount_last,
                amount_this,
                district,
                location,
                nok_name,
                nok_tel,
                nok_rel,
                nin,
                loan_limit,
                id_front,
                id_back,
                stock,
                license,
                applicant_signature,
                password,
                credit_score,
                active,
                created_at
            )
        VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
    ");

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param(
        "isssssssssddssssssdssssssii",
        $user_id,
        $gender,
        $dob,
        $phone,
        $bank_name,
        $bank_account,
        $email,
        $business_name,
        $business_type,
        $business_period,
        $amount_last,
        $amount_this,
        $district,
        $location,
        $nok_name,
        $nok_tel,
        $nok_rel,
        $nin,
        $loan_limit,
        $id_front,
        $id_back,
        $stock,
        $license,
        $applicant_signature,
        $hashedPassword,
        $credit_score,
        $active
    );

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $customer_id = $stmt->insert_id;
    $stmt->close();

    $conn->commit();

    jsonResponse("success", "Registration completed successfully.", 200, [
        "redirect" => "../index.php",
        "delay" => 2000,
        "user_id" => $user_id,
        "customer_id" => $customer_id
    ]);

} catch (Throwable $e) {
    if ($conn instanceof mysqli) {
        $conn->rollback();
    }

    error_log("Customer registration failed: " . $e->getMessage());

    jsonResponse("error", "Registration failed: " . $e->getMessage(), 500);
}
?>
