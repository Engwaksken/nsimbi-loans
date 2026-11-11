<?php
session_start();

require "db.php";
require_once __DIR__ . '/../php/mail-function.php';
require_once __DIR__ . '/../includes/loan_workflow.php';

date_default_timezone_set('Africa/Nairobi');


function redirect_with_message(string $message, string $type = 'error', string $location = 'requesting_loan.php'): void
{
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    header("Location: {$location}");
    exit;
}

function clean_text($value): string
{
    return trim((string)$value);
}

function normalize_money($value): float
{
    return (float)str_replace([',', ' '], '', (string)$value);
}


$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
$user_role = (int)($_SESSION['role'] ?? 0);

if ($user_id <= 0) {
    redirect_with_message("Your session has expired. Please login again.", 'error', '../index.php');
}

if ($user_role !== 3) {
    redirect_with_message("Access denied. Clients only.", 'error', 'dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_message("Invalid request method.");
}


$amount = normalize_money($_POST['amount'] ?? 0);
$loan_period = (int)($_POST['loan_period'] ?? 0);
$loan_method = clean_text($_POST['loan_method'] ?? '');
$phone = clean_text($_POST['phone'] ?? '');
$bank_name = clean_text($_POST['bank_name'] ?? '');
$bank_account = clean_text($_POST['bank_account'] ?? '');


if ($amount <= 0) {
    redirect_with_message("Please enter a valid loan amount.");
}

if ($loan_period <= 0) {
    redirect_with_message("Please select a valid loan period.");
}

$allowed_methods = ['Mobile Money', 'Bank Transfer'];
if (!in_array($loan_method, $allowed_methods, true)) {
    redirect_with_message("Invalid loan disbursement method selected.");
}

if ($loan_method === 'Mobile Money') {
    if ($phone === '') {
        redirect_with_message("Please enter your Mobile Money number.");
    }

    $bank_name = '';
    $bank_account = '';
}

if ($loan_method === 'Bank Transfer') {
    if ($bank_name === '' || $bank_account === '') {
        redirect_with_message("Please enter both bank name and bank account number.");
    }

    $phone = '';
}


$get_client = $conn->prepare("
    SELECT 
        c.id,
        c.user_id,
        c.phone,
        c.bank_name,
        c.bank_account,
        c.loan_limit,
        c.active,
        c.client_category,
        u.fname,
        u.lname,
        u.username,
        u.email
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    WHERE c.user_id = ?
    LIMIT 1
");

if (!$get_client) {
    redirect_with_message("Database error while checking your customer profile.");
}

$get_client->bind_param("i", $user_id);
$get_client->execute();
$client_result = $get_client->get_result();
$client = $client_result ? $client_result->fetch_assoc() : null;
$get_client->close();

if (!$client) {
    redirect_with_message("Client profile not found. Please contact administrator.");
}

/*
 * loans.client_id stores the user id (customers.user_id), not the customers
 * table's auto-increment id. Keeping the two identifiers separate prevents
 * new client requests from disappearing from the admin pending queue.
 */
$client_id     = (int)$client['user_id'];
$customer_id   = (int)$client['id'];
$is_new_onboarded = ($client['client_category'] ?? 'business') === 'new_onboarded';
$collateral_description = clean_text($_POST['collateral_description'] ?? '');
$collateral_document = '';
if ($is_new_onboarded) {
    $upload = $_FILES['collateral_document'] ?? [];
    if ($collateral_description === '' || empty($upload['tmp_name']) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($upload['size'] ?? 0) > 5 * 1024 * 1024) {
        redirect_with_message('Collateral details and valid evidence (up to 5 MB) are required.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? '';
    if ($ext === '') redirect_with_message('Collateral evidence must be JPG, PNG, or PDF.');
    $dir = __DIR__ . '/uploads/collateral';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) redirect_with_message('Unable to save collateral evidence.');
    $filename = bin2hex(random_bytes(18)) . '.' . $ext;
    if (!move_uploaded_file($upload['tmp_name'], $dir . '/' . $filename)) redirect_with_message('Unable to save collateral evidence.');
    $collateral_document = 'uploads/collateral/' . $filename;
}
$allowed_periods = $is_new_onboarded ? [8, 9, 10, 11, 12] : [1, 2, 3, 6];
if (!in_array($loan_period, $allowed_periods, true)) {
    redirect_with_message("Invalid loan period selected.");
}
$loan_limit = (float)($client['loan_limit'] ?? 0);
$is_active = (int)($client['active'] ?? 0);

$fname = clean_text($client['fname'] ?? '');
$lname = clean_text($client['lname'] ?? '');
$user_email = clean_text($client['email'] ?? '');
$sender_name = trim($fname . ' ' . $lname);

if ($sender_name === '') {
    $sender_name = clean_text($client['username'] ?? 'Client');
}

if ($is_active !== 1) {
    redirect_with_message("Your customer profile is inactive. Please contact administrator.");
}


$get_loan = $conn->prepare("
    SELECT id, status
    FROM loan
    WHERE client_id = ?
      AND UPPER(status) NOT IN ('CLEARED', 'REJECTED', 'CANCELLED')
    ORDER BY id DESC
    LIMIT 1
");

if (!$get_loan) {
    redirect_with_message("Database error while checking existing loans.");
}

$get_loan->bind_param("i", $client_id);
$get_loan->execute();
$loan_result = $get_loan->get_result();
$existing_loan = $loan_result ? $loan_result->fetch_assoc() : null;
$get_loan->close();

if ($existing_loan) {
    redirect_with_message("FAILED: You have an uncleared loan. Please clear it before requesting a new one.");
}


if ($loan_limit > 0 && $amount > $loan_limit) {
    redirect_with_message("FAILED: Loan amount exceeds your loan limit.");
}


$interest_rate = $is_new_onboarded ? 3.0 : (($loan_period === 1) ? 10.0 : 6.6);
$interest = round($amount * ($interest_rate / 100) * ($is_new_onboarded ? $loan_period : 1), 2);
$effective_balance = round($amount + $interest, 2);
$status = "PENDING";
$due_date = (new DateTime())->modify("+{$loan_period} months")->format("Y-m-d H:i:s");


$conn->begin_transaction();

try {
  
    if ($loan_method === 'Mobile Money') {
        $upd = $conn->prepare("
            UPDATE customers 
            SET phone = ?
            WHERE id = ?
        ");

        if (!$upd) {
            throw new Exception("Failed to prepare customer phone update.");
        }

        $upd->bind_param("si", $phone, $customer_id);
        $upd->execute();
        $upd->close();
    }

    if ($loan_method === 'Bank Transfer') {
        $upd = $conn->prepare("
            UPDATE customers 
            SET bank_name = ?, bank_account = ?
            WHERE id = ?
        ");

        if (!$upd) {
            throw new Exception("Failed to prepare customer bank update.");
        }

        $upd->bind_param("ssi", $bank_name, $bank_account, $customer_id);
        $upd->execute();
        $upd->close();
    }

  
    $stmt = $conn->prepare("
        INSERT INTO loan (
            amount,
            interest_rate,
            client_id,
            status,
            interest,
            due_date,
            loan_balance,
            loan_period,
            loan_method,
            phone,
            bank_name,
            bank_account,
            loan_category,
            collateral_description,
            collateral_document
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception("Failed to prepare loan request.");
    }

    $stmt->bind_param(
        "ddisdsdisssssss",
        $amount,
        $interest_rate,
        $client_id,
        $status,
        $interest,
        $due_date,
        $effective_balance,
        $loan_period,
        $loan_method,
        $phone,
        $bank_name,
        $bank_account,
        $is_new_onboarded ? 'new_onboarded' : 'business',
        $collateral_description,
        $collateral_document
    );

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $loan_id = (int)$stmt->insert_id;
    $stmt->close();

    // Classify the request and assign the least-busy loan officer so it lands
    // in the reviewer's queue with an owner instead of sitting unassigned.
    loan_finalize_new_request($conn, $loan_id, 'customer');


    $message_type = "loan_request";
    $is_read = 0;
    $created_at = date("Y-m-d H:i:s");

    $msg_stmt = $conn->prepare("
        INSERT INTO loan_messages (
            user_id,
            loan_id,
            sender_name,
            message_type,
            is_read,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");

    if ($msg_stmt) {
        $msg_stmt->bind_param(
            "iissis",
            $user_id,
            $loan_id,
            $sender_name,
            $message_type,
            $is_read,
            $created_at
        );
        $msg_stmt->execute();
        $msg_stmt->close();
    }

    $conn->commit();

} catch (Throwable $e) {
    $conn->rollback();
    redirect_with_message("Loan request failed. " . $e->getMessage());
}


if (function_exists('sendAdminLoanRequest')) {
    try {
        sendAdminLoanRequest($sender_name, $user_email, $conn);
    } catch (Throwable $e) {
       
    }
}


redirect_with_message("LOAN SUCCESSFULLY REQUESTED", 'success', 'pending_loans.php');
?>
