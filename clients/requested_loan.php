<?php
/**
 * Handles the client "Request a Loan" form (requesting_loan.php).
 *
 * Notes:
 *  - loan.client_id stores the user id (customers.user_id), not customers.id.
 *  - mysqli runs in STRICT report mode, so every DB call is wrapped in
 *    try/catch and turned into a friendly message instead of a blank page.
 *  - bind_param() needs real variables (by reference); never pass
 *    expressions or literals straight into it.
 *  - Optional columns (client_category, loan_category, collateral_*,
 *    interest_rate, phone, bank_*) are only used when they exist, so the
 *    form keeps working before/after the new-onboarded migration.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    $value = preg_replace('/[^\d.]/', '', (string)$value);
    return is_numeric($value) ? (float)$value : 0.0;
}

/** Bind an array of values to a statement by reference. */
function bind_values(mysqli_stmt $stmt, string $types, array $values): void
{
    $refs = [$types];
    foreach ($values as $i => $value) {
        $refs[] = &$values[$i];
    }
    if (!call_user_func_array([$stmt, 'bind_param'], $refs)) {
        throw new RuntimeException('Failed to bind query values.');
    }
}

/* ---------------------------------------------------------------------
 * Session / request checks
 * ------------------------------------------------------------------- */
$user_id   = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
$user_role = (int)($_SESSION['role'] ?? 0);

if ($user_id <= 0) {
    redirect_with_message("Your session has expired. Please login again.", 'error', '../login.php');
}

if ($user_role !== 3) {
    redirect_with_message("Access denied. Clients only.", 'error', 'dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_message("Please use the form to request a loan.");
}

// An oversized upload empties $_POST entirely; report that clearly.
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    redirect_with_message("The uploaded file is too large. Please upload a file of at most 5 MB.");
}

/* ---------------------------------------------------------------------
 * Input
 * ------------------------------------------------------------------- */
$amount       = normalize_money($_POST['amount'] ?? 0);
$loan_period  = (int)($_POST['loan_period'] ?? 0);
$loan_method  = clean_text($_POST['loan_method'] ?? '');
$phone        = clean_text($_POST['phone'] ?? '');
$bank_name    = clean_text($_POST['bank_name'] ?? '');
$bank_account = clean_text($_POST['bank_account'] ?? '');
$collateral_description = clean_text($_POST['collateral_description'] ?? '');

if ($amount <= 0) {
    redirect_with_message("Please enter a valid loan amount.");
}

if (!in_array($loan_method, ['Mobile Money', 'Bank Transfer'], true)) {
    redirect_with_message("Please select a valid disbursement method.");
}

if ($loan_method === 'Mobile Money') {
    if ($phone === '') {
        redirect_with_message("Please enter your Mobile Money number.");
    }
    $bank_name = '';
    $bank_account = '';
} else {
    if ($bank_name === '' || $bank_account === '') {
        redirect_with_message("Please enter both bank name and bank account number.");
    }
    $phone = '';
}

try {
    /* -----------------------------------------------------------------
     * Customer profile
     * --------------------------------------------------------------- */
    $has_client_category = loan_table_has_column($conn, 'customers', 'client_category');

    $stmt = $conn->prepare("
        SELECT c.id, c.user_id, c.loan_limit, c.active,
               " . ($has_client_category ? "c.client_category" : "'business' AS client_category") . ",
               u.fname, u.lname, u.username, u.email
        FROM customers c
        INNER JOIN user u ON u.id = c.user_id
        WHERE c.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$client) {
        redirect_with_message("Client profile not found. Please contact the administrator.");
    }

    $client_id   = (int)$client['user_id'];
    $customer_id = (int)$client['id'];
    $loan_limit  = (float)($client['loan_limit'] ?? 0);
    $is_new_onboarded = ($client['client_category'] ?? 'business') === 'new_onboarded';

    $sender_name = trim(clean_text($client['fname'] ?? '') . ' ' . clean_text($client['lname'] ?? ''));
    if ($sender_name === '') {
        $sender_name = clean_text($client['username'] ?? 'Client');
    }
    $user_email = clean_text($client['email'] ?? '');

    if ((int)($client['active'] ?? 0) !== 1) {
        redirect_with_message("Your customer profile is inactive. Please contact the administrator.");
    }

    $allowed_periods = $is_new_onboarded ? [8, 9, 10, 11, 12] : [1, 2, 3, 6];
    if (!in_array($loan_period, $allowed_periods, true)) {
        redirect_with_message("Please select a valid loan period.");
    }

    if ($loan_limit > 0 && $amount > $loan_limit) {
        redirect_with_message("Loan amount exceeds your loan limit of UGX " . number_format($loan_limit, 2) . ".");
    }

    /* -----------------------------------------------------------------
     * Existing open loan check
     * --------------------------------------------------------------- */
    $stmt = $conn->prepare("
        SELECT id
        FROM loan
        WHERE client_id = ?
          AND UPPER(COALESCE(status, '')) NOT IN ('CLEARED', 'REJECTED', 'CANCELLED', 'DECLINED')
        LIMIT 1
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $existing_loan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing_loan) {
        redirect_with_message("You have an uncleared loan or pending request. Please clear it before requesting a new one.");
    }
} catch (mysqli_sql_exception $e) {
    error_log('Loan request lookup failed: ' . $e->getMessage());
    redirect_with_message("We could not verify your profile right now. Please try again.");
}

/* ---------------------------------------------------------------------
 * Collateral (new onboarded clients only)
 * ------------------------------------------------------------------- */
$collateral_document = '';
$saved_file = '';

if ($is_new_onboarded) {
    $upload = $_FILES['collateral_document'] ?? null;

    if ($collateral_description === '') {
        redirect_with_message("Please describe the collateral you are offering.");
    }
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        redirect_with_message("Please upload collateral evidence (JPG, PNG or PDF).");
    }
    if (($upload['error'] ?? 0) !== UPLOAD_ERR_OK || (int)$upload['size'] > 5 * 1024 * 1024) {
        redirect_with_message("Collateral evidence could not be uploaded. Use a JPG, PNG or PDF of at most 5 MB.");
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? '';
    if ($ext === '') {
        redirect_with_message("Collateral evidence must be a JPG, PNG or PDF file.");
    }

    $dir = __DIR__ . '/uploads/collateral';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        redirect_with_message("Unable to save collateral evidence. Please try again.");
    }

    $filename = bin2hex(random_bytes(18)) . '.' . $ext;
    $saved_file = $dir . '/' . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $saved_file)) {
        redirect_with_message("Unable to save collateral evidence. Please try again.");
    }
    $collateral_document = 'uploads/collateral/' . $filename;
}

/* ---------------------------------------------------------------------
 * Loan figures
 * ------------------------------------------------------------------- */
if ($is_new_onboarded) {
    $interest_rate = 3.0; // per month
    $interest = round($amount * 0.03 * $loan_period, 2);
} else {
    $interest_rate = ($loan_period === 1) ? 10.0 : 6.6;
    $interest = round($amount * ($interest_rate / 100), 2);
}
$loan_balance  = round($amount + $interest, 2);
$status        = 'PENDING';
$due_date      = (new DateTime())->modify("+{$loan_period} months")->format('Y-m-d H:i:s');
$loan_category = $is_new_onboarded ? 'new_onboarded' : 'business';

/* ---------------------------------------------------------------------
 * Save
 * ------------------------------------------------------------------- */
$conn->begin_transaction();

try {
    // Remember the client's latest payout details on their profile.
    if ($loan_method === 'Mobile Money') {
        $upd = $conn->prepare("UPDATE customers SET phone = ? WHERE id = ?");
        $upd->bind_param("si", $phone, $customer_id);
    } else {
        $upd = $conn->prepare("UPDATE customers SET bank_name = ?, bank_account = ? WHERE id = ?");
        $upd->bind_param("ssi", $bank_name, $bank_account, $customer_id);
    }
    $upd->execute();
    $upd->close();

    // Required columns.
    $columns = ['amount', 'client_id', 'status', 'interest', 'due_date', 'loan_balance', 'loan_period', 'loan_method'];
    $types   = 'disdsdis';
    $values  = [$amount, $client_id, $status, $interest, $due_date, $loan_balance, $loan_period, $loan_method];

    // Optional columns, only when present in this database.
    $optional = [
        'interest_rate'          => ['d', $interest_rate],
        'phone'                  => ['s', $phone],
        'bank_name'              => ['s', $bank_name],
        'bank_account'           => ['s', $bank_account],
        'loan_category'          => ['s', $loan_category],
        'collateral_description' => ['s', $collateral_description],
        'collateral_document'    => ['s', $collateral_document],
    ];
    foreach ($optional as $column => [$type, $value]) {
        if (loan_table_has_column($conn, 'loan', $column)) {
            $columns[] = $column;
            $types    .= $type;
            $values[]  = $value;
        }
    }

    $sql = "INSERT INTO loan (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")";
    $stmt = $conn->prepare($sql);
    bind_values($stmt, $types, $values);
    $stmt->execute();
    $loan_id = (int)$stmt->insert_id;
    $stmt->close();

    // Classify the request and assign the least-busy loan officer.
    loan_finalize_new_request($conn, $loan_id, 'customer');

    // Notification for staff (non-critical).
    try {
        $message_type = 'loan_request';
        $is_read      = 0;
        $created_at   = date('Y-m-d H:i:s');

        $msg = $conn->prepare("
            INSERT INTO loan_messages (user_id, loan_id, sender_name, message_type, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $msg->bind_param("iissis", $user_id, $loan_id, $sender_name, $message_type, $is_read, $created_at);
        $msg->execute();
        $msg->close();
    } catch (mysqli_sql_exception $e) {
        error_log('Loan request message not saved: ' . $e->getMessage());
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    if ($saved_file !== '' && is_file($saved_file)) {
        @unlink($saved_file);
    }
    error_log('Client loan request failed for user ' . $user_id . ': ' . $e->getMessage());
    redirect_with_message("Loan request failed. Please try again or contact support.");
}

if (function_exists('sendAdminLoanRequest')) {
    try {
        sendAdminLoanRequest($sender_name, $user_email, $conn);
    } catch (Throwable $e) {
        error_log('Admin loan request email failed: ' . $e->getMessage());
    }
}

redirect_with_message("Loan successfully requested. It is now pending approval.", 'success', 'pending_loans.php');
