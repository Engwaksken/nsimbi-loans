<?php
require_once __DIR__.'/../db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$lid    =(int)($_POST['loan_id']??0);
$amount =(float)($_POST['amount']??0);
$date   =$_POST['payment_date']??date('Y-m-d');
$method =$_POST['payment_method']??'cash';
$notes  =$_POST['notes']??'';
if (!$lid||$amount<=0) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }
$loan=$conn->query("SELECT * FROM loan WHERE id=$lid LIMIT 1")->fetch_assoc();
if (!$loan) { echo json_encode(['success'=>false,'message'=>'Loan not found.']); exit; }
if ($amount>$loan['loan_balance']) { echo json_encode(['success'=>false,'message'=>'Amount exceeds balance.']); exit; }
$conn->begin_transaction();
try {
    $s=$conn->prepare("INSERT INTO payments (loan_id,amount,datetime,status,payment_method,notes) VALUES (?,?,?,'SUCCESSFUL',?,?)");
    $s->bind_param("idsss",$lid,$amount,$date,$method,$notes); $s->execute();
    $nb=$loan['loan_balance']-$amount;
    $ns=$nb<=0?'CLEARED':$loan['status'];
    $u=$conn->prepare("UPDATE loan SET loan_balance=?,status=? WHERE id=?");
    $u->bind_param("dsi",$nb,$ns,$lid); $u->execute();
    $conn->commit();
    echo json_encode(['success'=>true,'message'=>$nb<=0?'Payment recorded. Loan is now CLEARED!':'Payment of UGX '.number_format($amount).' recorded successfully.']);
} catch (Exception $e) { $conn->rollback(); echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]); }
