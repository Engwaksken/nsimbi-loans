<?php
session_start();
if(!isset($_SESSION['role'])){
    header("Location: index.php");
    exit;
}
function isAdmin(){ return $_SESSION['role']==1; }
function isOfficer(){ return $_SESSION['role']==2; }
?>