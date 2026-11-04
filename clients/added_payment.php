<?php

require"db.php";

$client = $_REQUEST['client'];
$amount = $_REQUEST['amount'];
$loan = $_REQUEST['loan'];
$rec = $_REQUEST['rec'];
$status = "PENDING";

$gloan = mysqli_query($conn, "select * from loan where id='".$_REQUEST['loan']."' ");
$gloan_fetch = mysqli_fetch_assoc($gloan);

$add_payment = mysqli_query($conn, " insert into payments (amount,loan_id,user_id,status,reference) values ('".$amount."','".$loan."','".$client."','".$status."','".$rec."') ");

$sum_payments = mysqli_query($conn, " select sum(amount) from payments where loan_id='".$loan."' && status='SUCCESSFUL' ");
$sum_fetch = mysqli_fetch_assoc($sum_payments);
$payments = $sum_fetch['sum(amount)'];

 

echo '<script>alert("PAYMENT SUCCESSFULLY ADDED")
              window.location.replace("payments.php");
      </script>';

?>
