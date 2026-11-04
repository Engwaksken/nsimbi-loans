<?php
header('Content-Type: application/json');

require 'db.php';
require_once __DIR__ . '/../php/mail-function.php';

if (!isset($conn)) {
    if (isset($connection)) {
        $conn = $connection;
    } elseif (isset($mysqli)) {
        $conn = $mysqli;
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database connection not found.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$fname = trim($_POST['fname'] ?? '');
$lname = trim($_POST['lname'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['password1'] ?? '';
$status = 1;
$dob = $_POST['dob'] ?? '';
$gender = $_POST['gender'] ?? '';
$nin = trim($_POST['nin'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$work_email = trim($_POST['work_email'] ?? '');
$home_address = trim($_POST['home_address'] ?? '');
$employer = trim($_POST['employer'] ?? '');
$department = trim($_POST['department'] ?? '');
$job_title = trim($_POST['job_title'] ?? '');
$employee_no = trim($_POST['employee_no'] ?? '');
$employment_status = $_POST['employment_status'] ?? '';
$date_employment = $_POST['date_employment'] ?? '';
$gross_salary = floatval($_POST['gross_salary'] ?? 0);
$net_salary = floatval($_POST['net_salary'] ?? 0);
$bank_name = trim($_POST['bank_name'] ?? '');
$bank_account = trim($_POST['bank_account'] ?? '');
$role = 4;

if (empty($fname) || empty($lname) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Required fields are missing.']);
    exit;
}

if ($password !== $confirm_password) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Passwords do not match']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit;
}

$stmt = $conn->prepare("SELECT email FROM user WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'Email already exists.']);
    exit;
}
$stmt->close();

// File Uploads
$uploadDir = 'uploads/';
$signatureDir = 'signatures/';
@mkdir($uploadDir, 0755, true);
@mkdir($signatureDir, 0755, true);
$allowedDocTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];

function uploadFile($fieldName, $uploadDir, $allowedTypes, $required = false) {
    if (!isset($_FILES[$fieldName])) {
        if ($required) {
            echo json_encode(['status' => 'error', 'message' => ucfirst($fieldName) . ' is required.']);
            exit;
        }
        return null;
    }

    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Upload error: ' . $file['error']]);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $fileType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($fileType, $allowedTypes)) {
        echo json_encode(['status' => 'error', 'message' => ucfirst($fieldName) . ' has an invalid file type.']);
        exit;
    }

    $newName = uniqid($fieldName . '_') . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
    $destination = $uploadDir . $newName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        echo json_encode(['status' => 'error', 'message' => "Failed to upload $fieldName."]);
        exit;
    }

    return $destination;
}

function processSignature($base64Data, $uploadDir, $required = false) {
    if (empty($base64Data)) {
        if ($required) {
            echo json_encode(['status' => 'error', 'message' => 'Signature is required.']);
            exit;
        }
        return null;
    }

    $base64Data = preg_replace('/^data:image\/[^;]+;base64,/', '', $base64Data);
    $imageData = base64_decode($base64Data);
    if ($imageData === false) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid signature data.']);
        exit;
    }

    $fileName = 'signature_' . uniqid() . '.png';
    $filePath = $uploadDir . $fileName;

    if (!file_put_contents($filePath, $imageData)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save signature.']);
        exit;
    }

    return $filePath;
}

$id_front = uploadFile('id_front', $uploadDir, $allowedDocTypes, false);
$id_back = uploadFile('id_back', $uploadDir, $allowedDocTypes, false);
$signatureData = $_POST['applicant_signature'] ?? '';
$applicant_signature = processSignature($signatureData, $signatureDir, false);

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$conn->autocommit(false);

try {

    $stmt = $conn->prepare("INSERT INTO user (fname, lname, role, password, email, datetime, status) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
    $stmt->bind_param("ssissi", $fname, $lname, $role, $hashedPassword, $email, $status);
    if (!$stmt->execute()) {
        throw new Exception('Failed to insert user: ' . $stmt->error);
    }

    $user_id = $stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO members (
        user_id, dob, gender, nin, phone, work_email, home_address,
        employer, department, job_title, employee_no, employment_status,
        date_employment, gross_salary, net_salary,loan_limit, bank_name, bank_account,
        id_front, id_back, applicant_signature, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?, ?, ?, ?, ?, NOW())");

    $stmt->bind_param(
        "isssssssssssssddsssss",
        $user_id, $dob, $gender, $nin, $phone, $work_email, $home_address,
        $employer, $department, $job_title, $employee_no, $employment_status,
        $date_employment, $gross_salary, $net_salary, $net_salary, $bank_name, $bank_account,
        $id_front, $id_back, $applicant_signature
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to insert member: ' . $stmt->error);
    }

   $conn->commit();
    $conn->autocommit(true);
    
    // Send registration email
    $userName = $fname . ' ' . $lname;
    $loginLink = "https://nsimbiimpact.com/loans/index.php";

    
try {
    if (function_exists('sendRegistrationEmail')) {
        sendRegistrationEmail($email, $userName, $loginLink);
    }
} catch (Exception $emailError) {
    error_log("User registration email failed: " . $emailError->getMessage());
}

// Notify admins about new registration
try {
    if (function_exists('sendAdminNotification')) {
        sendAdminNotification($userName, $email, $conn);
    }
} catch (Exception $adminEmailError) {
    error_log("Admin notification failed: " . $adminEmailError->getMessage());
}

    echo json_encode([
        'status' => 'success',
        'message' => 'Registration completed successfully! You will be redirected to login.',
        'redirect' => '../index.php',
        'delay' => 3000
    ]);
    exit;

} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(true);

    // Delete uploaded files on failure
    foreach ([$id_front, $id_back, $applicant_signature] as $file) {
        if ($file && file_exists($file)) {
            unlink($file);
        }
    }

    error_log("Registration failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Registration failed: ' . $e->getMessage()]);
    exit;
}
?>
