<?php
require '../db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function back($msg = '') {
    $url = '../loan_products.php';
    if ($msg) $url .= '?error=' . urlencode($msg);
    header("Location: $url");
    exit;
}

if ($action === 'create') {
    $stmt = $conn->prepare("
        INSERT INTO loan_products (
            product_code, product_name, description, loan_period_months,
            interest_rate, minimum_amount, maximum_amount, processing_fee,
            penalty_rate, repayment_frequency, interest_type, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "sssddddddsss",
        $_POST['product_code'],
        $_POST['product_name'],
        $_POST['description'],
        $_POST['loan_period_months'],
        $_POST['interest_rate'],
        $_POST['minimum_amount'],
        $_POST['maximum_amount'],
        $_POST['processing_fee'],
        $_POST['penalty_rate'],
        $_POST['repayment_frequency'],
        $_POST['interest_type'],
        $_POST['status']
    );

    $stmt->execute();

    header("Location: ../loan_products.php?success=1");
    exit;
}

if ($action === 'update') {
    $stmt = $conn->prepare("
        UPDATE loan_products SET
            product_code=?,
            product_name=?,
            description=?,
            loan_period_months=?,
            interest_rate=?,
            minimum_amount=?,
            maximum_amount=?,
            processing_fee=?,
            penalty_rate=?,
            repayment_frequency=?,
            interest_type=?,
            status=?
        WHERE id=?
    ");

    $stmt->bind_param(
        "sssddddddsssi",
        $_POST['product_code'],
        $_POST['product_name'],
        $_POST['description'],
        $_POST['loan_period_months'],
        $_POST['interest_rate'],
        $_POST['minimum_amount'],
        $_POST['maximum_amount'],
        $_POST['processing_fee'],
        $_POST['penalty_rate'],
        $_POST['repayment_frequency'],
        $_POST['interest_type'],
        $_POST['status'],
        $_POST['id']
    );

    $stmt->execute();

    header("Location: ../loan_products.php?success=1");
    exit;
}

if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);

    $stmt = $conn->prepare("DELETE FROM loan_products WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    header("Location: ../loan_products.php?success=1");
    exit;
}

back('Invalid action.');