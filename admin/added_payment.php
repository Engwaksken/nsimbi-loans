<?php
require "db.php";


$amount = isset($_REQUEST['amount']) ? (float)$_REQUEST['amount'] : 0;
$loan = isset($_REQUEST['loan']) ? mysqli_real_escape_string($conn, $_REQUEST['loan']) : null;
$client_id = isset($_REQUEST['client_id']) ? mysqli_real_escape_string($conn, $_REQUEST['client_id']) : null;
$status = "SUCCESSFUL";

// Validate input
if ($amount <= 0 || !$loan || !$client_id) {
    echo '<script>alert("Invalid payment information.");
          window.location.replace("staff_payments.php");
    </script>';
    exit;
}

// Fetch the loan
$gloan = mysqli_query($conn, "SELECT * FROM loan WHERE id = '$loan' AND client_id = '$client_id'");
if (!$gloan || mysqli_num_rows($gloan) === 0) {
    echo '<script>alert("Loan not found.");
          window.location.replace("staff_payments.php");
    </script>';
    exit;
}

$gloan_fetch = mysqli_fetch_assoc($gloan);
$current_balance = (float)$gloan_fetch['loan_balance'];

// Check if payment exceeds loan balance
if ($amount > $current_balance) {
    echo '<script>alert("Payment amount exceeds the remaining loan balance.");
          window.location.replace("staff_payments.php");
    </script>';
    exit;
}

$add_payment = mysqli_query($conn, "
    INSERT INTO payments (amount, loan_id, user_id, status)
    VALUES ('$amount', '$loan', '$client_id', '$status')
");

$new_balance = $current_balance - $amount;
if ($new_balance <= 0) {
    $new_balance = 0;
    mysqli_query($conn, "UPDATE loan SET loan_balance = '$new_balance', status = 'CLEARED' WHERE id = '$loan'");
} else {
    mysqli_query($conn, "UPDATE loan SET loan_balance = '$new_balance' WHERE id = '$loan'");
}

echo '<script>alert("PAYMENT SUCCESSFULLY ADDED");
      window.location.replace("staff_payments.php");
</script>';
exit;
?>
