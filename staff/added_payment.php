<?php

require"db.php";

$client = $_REQUEST['client'];
$amount = $_REQUEST['amount'];
$loan = $_REQUEST['loan'];
$rec = $_REQUEST['rec'];
$status = "PENDING";

$gloan = mysqli_query($conn, "select * from staff_loans where id='".$_REQUEST['loan']."' ");
$gloan_fetch = mysqli_fetch_assoc($gloan);

$add_payment = mysqli_query($conn, " insert into staff_payments (amount,loan_id,user_id,status,reference) values ('".$amount."','".$loan."','".$client."','".$status."','".$rec."') ");

$sum_payments = mysqli_query($conn, " select sum(amount) from staff_payments where loan_id='".$loan."' && status='SUCCESSFUL' ");
$sum_fetch = mysqli_fetch_assoc($sum_payments);
$payments = $sum_fetch['sum(amount)'];
/* $eff_bal = $gloan_fetch['loan_balance']-$amount;

mysqli_query($conn, "update staff_loans set loan_balance = '".$eff_bal."' where id='".$loan."' ");

if($eff_bal<=0){

mysqli_query($conn, "update staff_loans set loan_balance = '0',status='CLEARED' where id='".$loan."' ");
}*/
 

echo '<script>alert("PAYMENT SUCCESSFULLY ADDED")
              window.location.replace("loan_payments.php");
      </script>';

/*
header('Location: loans.php');
exit;*/
