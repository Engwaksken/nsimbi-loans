<?php
require_once __DIR__ . '/includes/alert_bridge.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require"db.php";
require_once dirname(__DIR__) . '/includes/officer_assignment.php';
//require "nav-head.php"; 
// Staff only.
if (empty($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    http_response_code(403);
    exit('Not authorized.');
}

// Whole numbers only: these go into SQL and the redirect below.
$client = (int)($_REQUEST['client'] ?? 0);
$admin = (int)($_REQUEST['admin'] ?? 0);

$get_user = mysqli_query($conn, "select * from customers where id='".$client."' "); 
$fetch_user = mysqli_fetch_assoc($get_user);
if ($fetch_user) {
    officer_require_client($conn, 'customer', (int)$fetch_user['user_id']);
}
$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;

if($role==1 || $role==2){

        if($fetch_user['active']==1){
            mysqli_query($conn, "update customers set active=0 where id='".$client."' ");

            $msg = "CLIENT BLOCKED SUCCESSFULY";

        }else{

            mysqli_query($conn, "update customers set active=1 where id='".$client."' ");

            $msg = "CLIENT ACTIVATED SUCCESSFULY";
            echo '<script>alert("'.$msg.'")
              window.location.replace("view_client.php?client='.$client.'");
      </script>';
      exit;

        }

echo '<script>alert("'.$msg.'")
              window.location.replace("view_client.php?client='.$client.'");
      </script>';
      exit;

}
else{

echo '<script>alert("Not Authorized for this Action")
              window.location.replace("view_client.php?client='.$client.'");
      </script>';

exit;

}