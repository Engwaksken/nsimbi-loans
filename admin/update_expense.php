<?php 
require "db.php";
session_start(); 

$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg   = $_SESSION['error_msg'] ?? '';

unset($_SESSION['success_msg'], $_SESSION['error_msg']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id              = intval($_POST['id'] ?? 0);
    $category_name   = trim($_POST['category_name'] ?? '');
    $description     = trim($_POST['description'] ?? '');
    $amount          = isset($_POST['amount']) ? floatval($_POST['amount']) : 0.00;
    $payment_method  = trim($_POST['payment_method'] ?? '');
    $paid_to         = trim($_POST['paid_to'] ?? '');
    $expense_date = $_POST['expense_date'] ?? '';

    if ($id <= 0 || empty($category_name) || $amount <= 0 || empty($expense_date)) {
        $_SESSION['error_msg'] = "Please fill all required fields correctly.";
        header("Location: edit_expense.php?id=" . $id);
        exit;
    } else {
        $stmt = $conn->prepare("
            UPDATE expenses SET category = ?, description = ?, amount = ?, payment_method = ?, paid_to = ?, expense_date = ?
            WHERE id = ?
        ");

        if (!$stmt) {
            $_SESSION['error_msg'] = "Database error: Unable to prepare update.";
            header("Location: edit_expense.php?id=" . $id);
            exit;
        }

        $stmt->bind_param("ssdsssi", $category_name, $description, $amount, $payment_method, $paid_to, $expense_date, $id);

        if ($stmt->execute()) {
            $_SESSION['success_msg'] = "Expense updated successfully.";
        } else {
            $_SESSION['error_msg'] = "Failed to update expense.";
        }

        $stmt->close();

        // Redirect back to edit page to display message
        header("Location: edit_expense.php?id=" . $id);
        exit;
    }
}
?>