<?php
require_once __DIR__.'/../db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$uid=(int)($_POST['user_id']??0); $cur=(int)($_POST['status']??0);
$ns=$cur==1?0:1;
$conn->prepare("UPDATE user SET status=? WHERE id=?")->execute() && null;
$s=$conn->prepare("UPDATE user SET status=? WHERE id=?"); $s->bind_param("ii",$ns,$uid); $s->execute();
$t=$conn->prepare("UPDATE customers SET active=? WHERE user_id=?"); $t->bind_param("ii",$ns,$uid); $t->execute();
echo json_encode(['success'=>true,'message'=>$ns==1?'Client activated successfully.':'Client blocked successfully.']);
