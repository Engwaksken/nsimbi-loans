<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';

date_default_timezone_set('Africa/Nairobi');

function respond(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}

function postValue(string $key, $default = null)
{
    return $_POST[$key] ?? $_REQUEST[$key] ?? $default;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function tableColumns(mysqli $conn, string $table): array
{
    $cols = [];
    $safe = str_replace('`', '', $table);
    $res = $conn->query("SHOW COLUMNS FROM `{$safe}`");

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cols[] = $r['Field'];
        }
    }

    return $cols;
}

function insertFlexible(mysqli $conn, string $table, array $data): int
{
    if (!tableExists($conn, $table)) {
        return 0;
    }

    $columns = tableColumns($conn, $table);
    $insertData = [];

    foreach ($data as $key => $value) {
        if (in_array($key, $columns, true)) {
            $insertData[$key] = $value;
        }
    }

    if (!$insertData) {
        return 0;
    }

    $fields = array_keys($insertData);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $fieldSql = '`' . implode('`,`', $fields) . '`';

    $sql = "INSERT INTO `{$table}` ({$fieldSql}) VALUES ({$placeholders})";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException("Receipt insert prepare failed: " . $conn->error);
    }

    $types = '';
    $values = [];

    foreach ($insertData as $value) {
        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }

        $values[] = $value;
    }

    $stmt->bind_param($types, ...$values);

    if (!$stmt->execute()) {
        throw new RuntimeException("Receipt insert failed: " . $stmt->error);
    }

    $id = $stmt->insert_id;
    $stmt->close();

    return (int)$id;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

if (!isset($conn) || !$conn instanceof mysqli) {
    respond(false, 'Database connection not established.');
}

$loanId = (int)postValue('loan_id', 0);
$amount = (float)postValue('amount', 0);
$paymentMethod = trim((string)postValue('payment_method', 'Cash'));
$reference = trim((string)postValue('reference', ''));
$notes = trim((string)postValue('notes', ''));

$createdBy = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

if ($loanId <= 0) {
    respond(false, 'Please select a valid loan.');
}

if ($amount <= 0) {
    respond(false, 'Payment amount must be greater than zero.');
}

$conn->begin_transaction();

try {
    $loanStmt = $conn->prepare("
        SELECT id, client_id, amount, interest, loan_balance, status
        FROM loan
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");

    if (!$loanStmt) {
        throw new RuntimeException('Loan lookup failed: ' . $conn->error);
    }

    $loanStmt->bind_param('i', $loanId);
    $loanStmt->execute();
    $loan = $loanStmt->get_result()->fetch_assoc();
    $loanStmt->close();

    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }

    $oldBalance = (float)($loan['loan_balance'] ?? 0);

    if ($oldBalance <= 0 || strtoupper((string)$loan['status']) === 'CLEARED') {
        throw new RuntimeException('This loan is already cleared.');
    }

    if ($amount > $oldBalance) {
        throw new RuntimeException('Payment amount cannot exceed outstanding balance.');
    }

    $newBalance = max($oldBalance - $amount, 0);
    $newStatus = $newBalance <= 0 ? 'CLEARED' : 'OUTSTANDING';
    $now = date('Y-m-d H:i:s');

    $paymentStmt = $conn->prepare("
        INSERT INTO payments (
            loan_id,
            amount,
            status,
            datetime
        ) VALUES (?, ?, 'SUCCESSFUL', ?)
    ");

    if (!$paymentStmt) {
        throw new RuntimeException('Payment insert prepare failed: ' . $conn->error);
    }

    $paymentStmt->bind_param('ids', $loanId, $amount, $now);

    if (!$paymentStmt->execute()) {
        throw new RuntimeException('Payment insert failed: ' . $paymentStmt->error);
    }

    $paymentId = $paymentStmt->insert_id;
    $paymentStmt->close();

    $updateStmt = $conn->prepare("
        UPDATE loan
        SET loan_balance = ?, status = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$updateStmt) {
        throw new RuntimeException('Loan update prepare failed: ' . $conn->error);
    }

    $updateStmt->bind_param('dsi', $newBalance, $newStatus, $loanId);

    if (!$updateStmt->execute()) {
        throw new RuntimeException('Loan update failed: ' . $updateStmt->error);
    }

    $updateStmt->close();

    $receiptNo = 'RCT-' . date('Ymd') . '-' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT);

    $receiptId = insertFlexible($conn, 'receipts', [
        'receipt_no' => $receiptNo,
        'receipt_number' => $receiptNo,
        'payment_id' => $paymentId,
        'loan_id' => $loanId,
        'client_id' => (int)$loan['client_id'],
        'amount' => $amount,
        'payment_method' => $paymentMethod,
        'reference' => $reference,
        'notes' => $notes,
        'status' => 'PAID',
        'created_by' => $createdBy,
        'created_at' => $now,
        'datetime' => $now,
        'issued_at' => $now,
    ]);

    $conn->commit();

    respond(true, 'Repayment saved successfully and receipt generated.', [
        'payment_id' => $paymentId,
        'receipt_id' => $receiptId,
        'receipt_no' => $receiptNo,
        'old_balance' => $oldBalance,
        'new_balance' => $newBalance,
        'loan_status' => $newStatus,
        'receipt_url' => 'receipt.php?payment_id=' . $paymentId
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    respond(false, $e->getMessage());
}