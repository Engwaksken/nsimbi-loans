<?php
require "db.php";

session_start();

if (!isset($_SESSION['logged_in'])) {
    exit("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loan_id = mysqli_real_escape_string($conn, $_POST['loan_id']);
    $amount = mysqli_real_escape_string($conn, $_POST['amount']);
    
    $interest_rate = 10; 
    $interest = $amount * ($interest_rate / 100);
    
    $loan_balance =($amount + $interest);

    $user_id = $_SESSION['logged_in'];
    $check = mysqli_query($conn, "SELECT * FROM loan WHERE id = '$loan_id' AND cleint_id = '$user_id'");
    
    if (mysqli_num_rows($check) === 0) {
        exit("Invalid loan or permission denied.");
    }

    $update = mysqli_query($conn, "
        UPDATE staff_loans 
        SET amount = '$amount', interest = '$interest', loan_balance = '$loan_balance'
        WHERE id = '$loan_id'
    ");

    if ($update) {
        header("Location: loans.php?msg=Loan+updated+successfully");
        exit();
    } else {
        exit("Error updating loan: " . mysqli_error($conn));
    }
} else {
    exit("Invalid request method.");
}
?>