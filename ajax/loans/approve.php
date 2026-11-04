<?php
require '../../includes/db.php';
require '../../includes/auth.php';

if(!isAdmin()){ die("Unauthorized"); }

$id=$_POST['id'];
$conn->query("UPDATE loans SET status='approved', approved_at=NOW() WHERE id='$id'");
?>