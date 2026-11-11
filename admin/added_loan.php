<?php

require "db.php";
require "services_check.php";
require_once __DIR__ . '/../includes/loan_workflow.php';

date_default_timezone_set('Africa/Nairobi');

$client = mysqli_real_escape_string($conn, $_REQUEST['user_id']);
$amount = floatval($_REQUEST['amount']);
$loanPeriod = intval($_REQUEST['loan_period']);
$interestRate = 0; // initialize

// Determine interest rate based on loan period
if ($loanPeriod === 1) {
    $interestRate = 10;
} elseif ($loanPeriod >= 2) {
    $interestRate = 6.6;
}

// Convert interest rate to decimal
$interestDecimal = $interestRate / 100;

// Calculate interest and effective balance
$interest = $amount * $interestDecimal;
$eff_bal = $amount + $interest;
$status = "PENDING";

// Calculate due date
$now = new DateTime();
$due_date_obj = (clone $now)->modify("+{$loanPeriod} months");
$due_date = $due_date_obj->format('Y-m-d H:i');

// Fetch client
$get_client = mysqli_query($conn, "SELECT * FROM customers WHERE user_id='$client'");
$fetch_client = mysqli_fetch_assoc($get_client);

if (!$fetch_client) {
    echo '<script>
            alert("Client not found.");
            window.location.replace("adding_loan.php?id=' . $client . '");
          </script>';
    exit;
}

// Check loan limit
if ($amount > $fetch_client['loan_limit']) {
    echo '<script>
            alert("FAILED. LOAN LIMIT EXCEEDED");
            window.location.replace("adding_loan.php?id=' . $client . '");
          </script>';
    exit;
}

// Insert loan
$add_loan = mysqli_query($conn, "INSERT INTO loan (amount, interest_rate, client_id, status, interest, due_date, loan_balance, loan_period) VALUES ('$amount', '$interestRate', '$client', '$status', '$interest', '$due_date', '$eff_bal', '$loanPeriod')");

if ($add_loan) {
    loan_finalize_new_request($conn, (int)mysqli_insert_id($conn), 'customer');

    echo '<script>
            alert("LOAN SUCCESSFULLY ADDED");
            window.location.replace("loans.php");
          </script>';
} else {
    echo '<script>
            alert("LOAN ADDITION FAILED. CONTACT SUPPORT");
            window.location.replace("adding_loan.php?id=' . $client . '");
          </script>';
}

?>
