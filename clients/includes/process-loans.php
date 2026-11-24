<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../../php/db.php';
require_once __DIR__ . '/../../includes/loan_workflow.php';
require_once __DIR__ . '/../../includes/loan_products.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    json_response(false, 'Database connection error.');
}

$conn->set_charset('utf8mb4');
date_default_timezone_set('Africa/Nairobi');

function json_response(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
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

function table_has_column(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : ['total' => 0];

    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

/** The product the client picked, if they may use it; otherwise a JSON error. */
function require_loan_product(mysqli $conn, int $product_id, bool $is_new_onboarded, float $amount): array
{
    $product = loan_product_for_request($conn, $product_id, $is_new_onboarded ? 'new_onboarded' : 'business');
    if (!$product) {
        json_response(false, 'Please choose a loan product.');
    }
    $error = loan_product_amount_error($product, $amount);
    if ($error !== '') {
        json_response(false, $error);
    }
    return $product;
}

function get_client(mysqli $conn, int $user_id): array
{
    $stmt = $conn->prepare("
        SELECT
            c.id,
            c.user_id,
            c.phone,
            c.bank_name,
            c.bank_account,
            c.loan_limit,
            c.active,
            " . (loan_table_has_column($conn, 'customers', 'client_category') ? 'c.client_category' : "'business' AS client_category") . ",
            u.fname,
            u.lname,
            u.username,
            u.email
        FROM customers c
        INNER JOIN user u ON u.id = c.user_id
        WHERE c.user_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        json_response(false, 'Database error while checking client profile.');
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();

    $res = $stmt->get_result();
    $client = $res ? $res->fetch_assoc() : null;

    $stmt->close();

    if (!$client) {
        json_response(false, 'Client profile not found. Please contact administrator.');
    }

    return $client;
}

function validate_loan_inputs(float $amount, string $loan_method, string $phone, string $bank_name, string $bank_account, float $loan_limit): void
{
    if ($amount <= 0) {
        json_response(false, 'Please enter a valid loan amount.');
    }

    if (!in_array($loan_method, ['Mobile Money', 'Bank Transfer'], true)) {
        json_response(false, 'Please select a valid disbursement method.');
    }

    if ($loan_limit > 0 && $amount > $loan_limit) {
        json_response(false, 'Loan amount cannot exceed your loan limit.');
    }

    if ($loan_method === 'Mobile Money' && $phone === '') {
        json_response(false, 'Please enter your Mobile Money number.');
    }

    if ($loan_method === 'Bank Transfer' && ($bank_name === '' || $bank_account === '')) {
        json_response(false, 'Please enter both bank name and bank account number.');
    }
}

function update_customer_payment_details(
    mysqli $conn,
    int $customer_id,
    string $loan_method,
    string $phone,
    string $bank_name,
    string $bank_account
): void {
    if ($loan_method === 'Mobile Money') {
        $stmt = $conn->prepare("UPDATE customers SET phone = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Failed to prepare phone update.');
        }

        $stmt->bind_param('si', $phone, $customer_id);
        $stmt->execute();
        $stmt->close();
    }

    if ($loan_method === 'Bank Transfer') {
        $stmt = $conn->prepare("UPDATE customers SET bank_name = ?, bank_account = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Failed to prepare bank update.');
        }

        $stmt->bind_param('ssi', $bank_name, $bank_account, $customer_id);
        $stmt->execute();
        $stmt->close();
    }
}

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['logged_in'])) {
    json_response(false, 'Your session has expired. Please login again.');
}

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
$user_role = (int)($_SESSION['role'] ?? 0);

if ($user_id <= 0) {
    json_response(false, 'Invalid session. Please login again.');
}

if ($user_role !== 3) {
    json_response(false, 'Access denied. Clients only.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Invalid request method.');
}

$action = clean_text($_POST['action'] ?? '');
loan_products_ensure_schema($conn);

if ($action === '') {
    json_response(false, 'Invalid action.');
}

$client = get_client($conn, $user_id);

/*
 * customers.id is used only for customer-table updates. Every loan row stores
 * the user id in client_id, matching the admin and mobile API queries.
 */
$customer_id = (int)$client['id'];
$client_id   = (int)($client['user_id'] ?? $user_id);
$loan_limit = (float)($client['loan_limit'] ?? 0);
$active = (int)($client['active'] ?? 0);
$is_new_onboarded = ($client['client_category'] ?? 'business') === 'new_onboarded';

if ($active !== 1 && in_array($action, ['create_loan', 'update_pending_loan'], true)) {
    json_response(false, 'Your customer profile is inactive. Please contact administrator.');
}

/*
|--------------------------------------------------------------------------
| Get loan for edit modal
|--------------------------------------------------------------------------
*/
if ($action === 'get_loan') {
    $loan_id = (int)($_POST['loan_id'] ?? 0);

    if ($loan_id <= 0) {
        json_response(false, 'Invalid loan selected.');
    }

    $stmt = $conn->prepare("
        SELECT
            id,
            amount,
            interest_rate,
            interest,
            loan_balance,
            loan_period,
            loan_product_id,
            loan_method,
            phone,
            bank_name,
            bank_account,
            status,
            due_date
        FROM loan
        WHERE id = ?
          AND client_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        json_response(false, 'Failed to prepare loan query.');
    }

    $stmt->bind_param('ii', $loan_id, $client_id);
    $stmt->execute();

    $res = $stmt->get_result();
    $loan = $res ? $res->fetch_assoc() : null;

    $stmt->close();

    if (!$loan) {
        json_response(false, 'Loan not found.');
    }

    if (strtoupper((string)$loan['status']) !== 'PENDING') {
        json_response(false, 'Only pending loans can be edited.');
    }

    json_response(true, 'Loan loaded successfully.', [
        'loan' => $loan,
    ]);
}

/*
|--------------------------------------------------------------------------
| Create new pending loan request
|--------------------------------------------------------------------------
*/
if ($action === 'create_loan') {
    $amount = normalize_money($_POST['amount'] ?? 0);
    $product_id = (int)($_POST['product_id'] ?? 0);
    $loan_method = clean_text($_POST['loan_method'] ?? '');
    $phone = clean_text($_POST['phone'] ?? '');
    $bank_name = clean_text($_POST['bank_name'] ?? '');
    $bank_account = clean_text($_POST['bank_account'] ?? '');

    if ($loan_method === 'Mobile Money') {
        $bank_name = '';
        $bank_account = '';
    }

    if ($loan_method === 'Bank Transfer') {
        $phone = '';
    }

    validate_loan_inputs($amount, $loan_method, $phone, $bank_name, $bank_account, $loan_limit);
    $product = require_loan_product($conn, $product_id, $is_new_onboarded, $amount);

    $check = $conn->prepare("
        SELECT id, status
        FROM loan
        WHERE client_id = ?
          AND UPPER(status) NOT IN ('CLEARED','REJECTED','CANCELLED')
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$check) {
        json_response(false, 'Database error while checking existing loans.');
    }

    $check->bind_param('i', $client_id);
    $check->execute();

    $res = $check->get_result();
    $existing = $res ? $res->fetch_assoc() : null;

    $check->close();

    if ($existing) {
        json_response(false, 'You already have a pending, outstanding, or overdue loan. Please clear it first.');
    }

    $status = 'PENDING';

    $conn->begin_transaction();

    try {
        update_customer_payment_details(
            $conn,
            $customer_id,
            $loan_method,
            $phone,
            $bank_name,
            $bank_account
        );

        [$names, $types, $values] = loan_product_existing_columns($conn, [
            'amount'           => ['d', $amount],
            'requested_amount' => ['d', $amount],
            'client_id'        => ['i', $client_id],
            'status'           => ['s', $status],
            'loan_method'      => ['s', $loan_method],
            'phone'            => ['s', $phone],
            'bank_name'        => ['s', $bank_name],
            'bank_account'     => ['s', $bank_account],
        ] + loan_product_request_columns($product, $amount));

        $stmt = $conn->prepare(
            'INSERT INTO loan (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')'
        );

        if (!$stmt) {
            throw new Exception('Failed to prepare loan request.');
        }

        $stmt->bind_param($types, ...$values);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $loan_id = (int)$stmt->insert_id;
        $stmt->close();

        loan_finalize_new_request($conn, $loan_id, 'customer');

        if (table_has_column($conn, 'loan_messages', 'user_id')) {
            $sender_name = trim((string)($client['fname'] ?? '') . ' ' . (string)($client['lname'] ?? ''));
            if ($sender_name === '') {
                $sender_name = (string)($client['username'] ?? 'Client');
            }

            $message_type = 'loan_request';
            $is_read = 0;
            $created_at = date('Y-m-d H:i:s');

            $msg = $conn->prepare("
                INSERT INTO loan_messages (
                    user_id,
                    sender_name,
                    message_type,
                    is_read,
                    created_at
                ) VALUES (?, ?, ?, ?, ?)
            ");

            if ($msg) {
                $msg->bind_param('issis', $user_id, $sender_name, $message_type, $is_read, $created_at);
                $msg->execute();
                $msg->close();
            }
        }

        $conn->commit();

        json_response(true, 'Loan request submitted successfully.');

    } catch (Throwable $e) {
        $conn->rollback();
        json_response(false, 'Loan request failed. ' . $e->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| Update pending loan
|--------------------------------------------------------------------------
*/
if ($action === 'update_pending_loan') {
    $loan_id = (int)($_POST['loan_id'] ?? 0);
    $amount = normalize_money($_POST['amount'] ?? 0);
    $product_id = (int)($_POST['product_id'] ?? 0);
    $loan_method = clean_text($_POST['loan_method'] ?? '');
    $phone = clean_text($_POST['phone'] ?? '');
    $bank_name = clean_text($_POST['bank_name'] ?? '');
    $bank_account = clean_text($_POST['bank_account'] ?? '');

    if ($loan_id <= 0) {
        json_response(false, 'Invalid loan selected.');
    }

    if ($loan_method === 'Mobile Money') {
        $bank_name = '';
        $bank_account = '';
    }

    if ($loan_method === 'Bank Transfer') {
        $phone = '';
    }

    validate_loan_inputs($amount, $loan_method, $phone, $bank_name, $bank_account, $loan_limit);
    $product = require_loan_product($conn, $product_id, $is_new_onboarded, $amount);

    $check = $conn->prepare("
        SELECT id, status
        FROM loan
        WHERE id = ?
          AND client_id = ?
        LIMIT 1
    ");

    if (!$check) {
        json_response(false, 'Failed to check selected loan.');
    }

    $check->bind_param('ii', $loan_id, $client_id);
    $check->execute();

    $res = $check->get_result();
    $loan = $res ? $res->fetch_assoc() : null;

    $check->close();

    if (!$loan) {
        json_response(false, 'Loan not found.');
    }

    if (strtoupper((string)$loan['status']) !== 'PENDING') {
        json_response(false, 'Only pending loans can be edited.');
    }


    $conn->begin_transaction();

    try {
        update_customer_payment_details(
            $conn,
            $customer_id,
            $loan_method,
            $phone,
            $bank_name,
            $bank_account
        );

        [$names, $types, $values] = loan_product_existing_columns($conn, [
            'amount'           => ['d', $amount],
            'requested_amount' => ['d', $amount],
            'loan_method'      => ['s', $loan_method],
            'phone'            => ['s', $phone],
            'bank_name'        => ['s', $bank_name],
            'bank_account'     => ['s', $bank_account],
        ] + loan_product_request_columns($product, $amount));

        $stmt = $conn->prepare(
            'UPDATE loan SET ' . implode(' = ?, ', $names) . " = ?
            WHERE id = ? AND client_id = ? AND UPPER(status) = 'PENDING'"
        );

        if (!$stmt) {
            throw new Exception('Failed to prepare loan update.');
        }

        $types .= 'ii';
        $values[] = $loan_id;
        $values[] = $client_id;
        $stmt->bind_param($types, ...$values);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        if ($stmt->affected_rows < 1) {
            throw new Exception('No changes saved or loan is no longer pending.');
        }

        $stmt->close();

        $conn->commit();

        json_response(true, 'Pending loan updated successfully.');

    } catch (Throwable $e) {
        $conn->rollback();
        json_response(false, 'Loan update failed. ' . $e->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| Cancel (withdraw) a pending loan request
|--------------------------------------------------------------------------
*/
if ($action === 'cancel_pending_loan') {
    $loan_id = (int)($_POST['loan_id'] ?? 0);

    if ($loan_id <= 0) {
        json_response(false, 'Invalid loan selected.');
    }

    $has_document = loan_table_has_column($conn, 'loan', 'collateral_document');

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            SELECT id, status" . ($has_document ? ", collateral_document" : "") . "
            FROM loan
            WHERE id = ?
              AND client_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('ii', $loan_id, $client_id);
        $stmt->execute();
        $loan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$loan) {
            throw new DomainException('Loan not found.');
        }

        if (strtoupper((string)$loan['status']) !== 'PENDING') {
            throw new DomainException('Only pending loan requests can be cancelled.');
        }

        // Remove the staff notification for this request (non-critical).
        try {
            $msg = $conn->prepare("DELETE FROM loan_messages WHERE loan_id = ?");
            $msg->bind_param('i', $loan_id);
            $msg->execute();
            $msg->close();
        } catch (mysqli_sql_exception $e) {
            error_log('Pending loan cancel: loan_messages cleanup skipped: ' . $e->getMessage());
        }

        $stmt = $conn->prepare("
            DELETE FROM loan
            WHERE id = ?
              AND client_id = ?
              AND UPPER(status) = 'PENDING'
        ");
        $stmt->bind_param('ii', $loan_id, $client_id);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        if ($deleted < 1) {
            throw new DomainException('Loan request could not be cancelled.');
        }

        $conn->commit();
    } catch (DomainException $e) {
        $conn->rollback();
        json_response(false, $e->getMessage());
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Pending loan cancel failed: ' . $e->getMessage());
        json_response(false, 'Loan request could not be cancelled. Please try again.');
    }

    $document = (string)($loan['collateral_document'] ?? '');
    if ($document !== '' && preg_match('#^uploads/collateral/[a-f0-9]+\.(jpg|png|pdf)$#', $document)) {
        @unlink(dirname(__DIR__) . '/' . $document);
    }

    json_response(true, 'Loan request cancelled.');
}

/*
|--------------------------------------------------------------------------
| Pay loan
|--------------------------------------------------------------------------
*/
if ($action === 'pay_loan') {
    $loan_id = (int)($_POST['loan_id'] ?? 0);
    $amount = normalize_money($_POST['amount'] ?? 0);
    $payment_method = clean_text($_POST['payment_method'] ?? '');
    $reference = clean_text($_POST['reference'] ?? '');

    if ($loan_id <= 0) {
        json_response(false, 'Invalid loan selected.');
    }

    if ($amount <= 0) {
        json_response(false, 'Please enter a valid payment amount.');
    }

    if ($payment_method === '') {
        json_response(false, 'Please select a payment method.');
    }

    $stmt = $conn->prepare("
        SELECT
            id,
            client_id,
            loan_balance,
            status
        FROM loan
        WHERE id = ?
          AND client_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        json_response(false, 'Failed to check loan.');
    }

    $stmt->bind_param('ii', $loan_id, $client_id);
    $stmt->execute();

    $res = $stmt->get_result();
    $loan = $res ? $res->fetch_assoc() : null;

    $stmt->close();

    if (!$loan) {
        json_response(false, 'Loan not found.');
    }

    $status = strtoupper((string)$loan['status']);
    $balance = (float)$loan['loan_balance'];

    if (!in_array($status, ['OUTSTANDING', 'OVERDUE'], true)) {
        json_response(false, 'Only outstanding or overdue loans can be paid.');
    }

    if ($balance <= 0) {
        json_response(false, 'This loan has no outstanding balance.');
    }

    if ($amount > $balance) {
        json_response(false, 'Payment amount cannot exceed outstanding balance.');
    }

    $new_balance = round($balance - $amount, 2);
    $new_status = $new_balance <= 0 ? 'CLEARED' : $status;
    $payment_status = 'SUCCESSFUL';
    $created_at = date('Y-m-d H:i:s');

    $conn->begin_transaction();

    try {
        /*
        |--------------------------------------------------------------------------
        | Insert into payments table dynamically based on available columns.
        |--------------------------------------------------------------------------
        */
        $columns = [];
        $values = [];
        $types = '';
        $params = [];

        if (table_has_column($conn, 'payments', 'user_id')) {
            $columns[] = 'user_id';
            $values[] = '?';
            $types .= 'i';
            $params[] = $user_id;
        }

        if (table_has_column($conn, 'payments', 'loan_id')) {
            $columns[] = 'loan_id';
            $values[] = '?';
            $types .= 'i';
            $params[] = $loan_id;
        }

        if (table_has_column($conn, 'payments', 'client_id')) {
            $columns[] = 'client_id';
            $values[] = '?';
            $types .= 'i';
            $params[] = $client_id;
        }

        if (table_has_column($conn, 'payments', 'amount')) {
            $columns[] = 'amount';
            $values[] = '?';
            $types .= 'd';
            $params[] = $amount;
        }

        if (table_has_column($conn, 'payments', 'payment_method')) {
            $columns[] = 'payment_method';
            $values[] = '?';
            $types .= 's';
            $params[] = $payment_method;
        }

        if (table_has_column($conn, 'payments', 'method')) {
            $columns[] = 'method';
            $values[] = '?';
            $types .= 's';
            $params[] = $payment_method;
        }

        if (table_has_column($conn, 'payments', 'reference')) {
            $columns[] = 'reference';
            $values[] = '?';
            $types .= 's';
            $params[] = $reference;
        }

        if (table_has_column($conn, 'payments', 'transaction_reference')) {
            $columns[] = 'transaction_reference';
            $values[] = '?';
            $types .= 's';
            $params[] = $reference;
        }

        if (table_has_column($conn, 'payments', 'status')) {
            $columns[] = 'status';
            $values[] = '?';
            $types .= 's';
            $params[] = $payment_status;
        }

        if (table_has_column($conn, 'payments', 'datetime')) {
            $columns[] = 'datetime';
            $values[] = '?';
            $types .= 's';
            $params[] = $created_at;
        }

        if (table_has_column($conn, 'payments', 'created_at')) {
            $columns[] = 'created_at';
            $values[] = '?';
            $types .= 's';
            $params[] = $created_at;
        }

        if (empty($columns)) {
            throw new Exception('Payments table columns not found.');
        }

        $sql = "INSERT INTO payments (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
        $payStmt = $conn->prepare($sql);

        if (!$payStmt) {
            throw new Exception('Failed to prepare payment insert. ' . $conn->error);
        }

        // mysqli_stmt::bind_param() requires every value to be passed by
        // reference. Unpacking a normal value array can fail on newer PHP
        // versions (the later arguments are not referenceable).
        $bindParams = [$types];
        foreach ($params as $index => &$param) {
            $bindParams[] = &$param;
        }
        unset($param);

        if (!call_user_func_array([$payStmt, 'bind_param'], $bindParams)) {
            throw new Exception('Failed to bind payment values.');
        }

        if (!$payStmt->execute()) {
            throw new Exception($payStmt->error);
        }

        $payStmt->close();

        $update = $conn->prepare("
            UPDATE loan
            SET loan_balance = ?, status = ?
            WHERE id = ?
              AND client_id = ?
        ");

        if (!$update) {
            throw new Exception('Failed to prepare loan balance update.');
        }

        $update->bind_param('dsii', $new_balance, $new_status, $loan_id, $client_id);

        if (!$update->execute()) {
            throw new Exception($update->error);
        }

        $update->close();

        $conn->commit();

        json_response(true, 'Payment submitted successfully.');

    } catch (Throwable $e) {
        $conn->rollback();
        json_response(false, 'Payment failed. ' . $e->getMessage());
    }
}

json_response(false, 'Unknown action.');
