<?php
require "db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($name)) {
        echo '<script>alert("Category name is required."); window.history.back();</script>';
        exit;
    }

    $check = $conn->prepare("SELECT id FROM expense_categories WHERE name = ?");
    $check->bind_param("s", $name);
    $check->execute();
    $check_result = $check->get_result();

    if ($check_result->num_rows > 0) {
        echo '<script>alert("Category already exists."); window.history.back();</script>';
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO expense_categories (name, description) VALUES (?, ?)");
    $stmt->bind_param("ss", $name, $description);

    if ($stmt->execute()) {
        echo '<script>alert("Category added successfully."); window.location.href="expense_categories.php";</script>';
    } else {
        echo '<script>alert("Failed to add category."); window.history.back();</script>';
    }
}
?>