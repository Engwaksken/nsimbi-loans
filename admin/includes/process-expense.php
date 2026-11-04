<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';

function respond(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}

function postValue(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

if (!isset($conn) || !$conn instanceof mysqli) {
    respond(false, 'Database connection not established.');
}

$action = trim((string)postValue('action', 'create'));

$id = (int)postValue('id', 0);
$categoryName = trim((string)postValue('category_name'));
$description = trim((string)postValue('description'));
$amount = (float)postValue('amount', 0);
$paymentMethod = trim((string)postValue('payment_method'));
$paidTo = trim((string)postValue('paid_to'));
$expenseDate = trim((string)postValue('expense_date'));

if (!in_array($action, ['create', 'update'], true)) {
    respond(false, 'Invalid expense action.');
}

if ($action === 'update' && $id <= 0) {
    respond(false, 'Invalid expense selected.');
}

if ($categoryName === '') {
    respond(false, 'Please select expense category.');
}

if ($amount <= 0) {
    respond(false, 'Amount must be greater than zero.');
}

if ($expenseDate === '') {
    respond(false, 'Expense date is required.');
}

$dateCheck = DateTime::createFromFormat('Y-m-d', $expenseDate);
if (!$dateCheck || $dateCheck->format('Y-m-d') !== $expenseDate) {
    respond(false, 'Invalid expense date.');
}

$allowedMethods = ['', 'Cash', 'Mobile Money', 'Bank Transfer'];
if (!in_array($paymentMethod, $allowedMethods, true)) {
    respond(false, 'Invalid payment method.');
}

$catStmt = $conn->prepare("
    SELECT id
    FROM expense_categories
    WHERE name = ?
    LIMIT 1
");

if (!$catStmt) {
    respond(false, 'Category validation failed.');
}

$catStmt->bind_param('s', $categoryName);
$catStmt->execute();
$catResult = $catStmt->get_result();
$catStmt->close();

if (!$catResult || $catResult->num_rows === 0) {
    respond(false, 'Selected expense category does not exist.');
}

if ($action === 'create') {
    $stmt = $conn->prepare("
        INSERT INTO expenses (
            category,
            description,
            amount,
            payment_method,
            paid_to,
            expense_date
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        respond(false, 'Database error: Unable to prepare insert.');
    }

    $stmt->bind_param(
        'ssdsss',
        $categoryName,
        $description,
        $amount,
        $paymentMethod,
        $paidTo,
        $expenseDate
    );

    if (!$stmt->execute()) {
        respond(false, 'Failed to add expense.');
    }

    $newId = $stmt->insert_id;
    $stmt->close();

    respond(true, 'Expense added successfully.', [
        'id' => $newId
    ]);
}

$stmt = $conn->prepare("
    UPDATE expenses
    SET 
        category = ?,
        description = ?,
        amount = ?,
        payment_method = ?,
        paid_to = ?,
        expense_date = ?
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    respond(false, 'Database error: Unable to prepare update.');
}

$stmt->bind_param(
    'ssdsssi',
    $categoryName,
    $description,
    $amount,
    $paymentMethod,
    $paidTo,
    $expenseDate,
    $id
);

if (!$stmt->execute()) {
    respond(false, 'Failed to update expense.');
}

$stmt->close();

respond(true, 'Expense updated successfully.', [
    'id' => $id
]);