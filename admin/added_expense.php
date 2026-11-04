<?php
require "db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   
    $category_name = trim($_POST['category_name'] ?? '');
    $description      = trim($_POST['description'] ?? '');
    $amount           = isset($_POST['amount']) ? floatval($_POST['amount']) : 0.00;
    $payment_method   = trim($_POST['payment_method'] ?? '');
    $paid_to          = trim($_POST['paid_to'] ?? '');
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');

    if ($amount <= 0 || empty($expense_date)) {
        echo '<script>alert("Please fill in all required fields correctly."); window.history.back();</script>';
        exit;
    }
    $stmt = $conn->prepare("
        INSERT INTO expenses (category, description, amount, payment_method, paid_to, expense_date)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        echo '<script>alert("Database error: Unable to prepare statement."); window.history.back();</script>';
        exit;
    }

    $stmt->bind_param("ssdsss", $category_name, $description, $amount, $payment_method, $paid_to, $expense_date);

    if ($stmt->execute()) {
        echo '<script>alert("Expense added successfully."); window.location.href="expenses.php";</script>';
    } else {
        echo '<script>alert("Error: Failed to add expense."); window.history.back();</script>';
    }

    $stmt->close();
}
?>
