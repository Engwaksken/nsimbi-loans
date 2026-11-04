<?php
require "db.php";

session_start();

if (!isset($_SESSION['logged_in'])) {
    exit("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loan_id = mysqli_real_escape_string($conn, $_POST['loan_id']);
    $user_id = $_SESSION['logged_in'];

   
    $check = mysqli_query($conn, "SELECT * FROM loan WHERE id = '$loan_id' AND client_id = '$user_id'");

    if (mysqli_num_rows($check) === 0) {
        exit("Invalid loan or permission denied.");
    }

   $cancel = mysqli_query($conn, "DELETE FROM loan WHERE id = '$loan_id'");


    if ($cancel) {
        header("Location: loans.php?msg=Loan+cancelled+successfully");
        exit();
    } else {
        exit("Error cancelling loan: " . mysqli_error($conn));
    }
} else {
    exit("Invalid request method.");
}
