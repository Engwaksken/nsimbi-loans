<?php
require '../../includes/db.php';
require '../../includes/auth.php';

$client=$_POST['client_id'];
$amount=$_POST['amount'];

$status = isAdmin() ? 'approved' : 'pending';

$conn->query("INSERT INTO loans(client_id,amount,status) VALUES('$client','$amount','$status')");
?>