<?php
require '../../includes/db.php';

$loan=$_POST['loan_id'];
$amount=$_POST['amount'];

$conn->query("INSERT INTO repayments(loan_id,amount) VALUES('$loan','$amount')");
?>