<?php
require '../../includes/db.php';
require '../../includes/helpers.php';

$loan_id=$_POST['loan_id'];
$topup=$_POST['amount'];

$outstanding = outstanding($conn,$loan_id);

if($outstanding > 0){
    die("Clear existing loan first");
}

$new_amount = $topup - $outstanding;

$conn->query("UPDATE loans SET amount = amount + $new_amount WHERE id=$loan_id");
?>