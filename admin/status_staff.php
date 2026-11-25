<?php
require_once __DIR__ . '/includes/alert_bridge.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require"db.php"; 
require_once dirname(__DIR__) . '/includes/officer_assignment.php';
// Staff only.
if (empty($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    http_response_code(403);
    exit('Not authorized.');
}
// Whole numbers only: these go into SQL and the redirect below.
$staff = (int)($_REQUEST['staff'] ?? 0);
$admin = (int)($_REQUEST['admin'] ?? 0);
$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
officer_require_client($conn, 'member', (int)$staff);

$get_user = mysqli_query($conn, "select * from user where id='".$staff."' "); 
$fetch_user = mysqli_fetch_assoc($get_user);

if($role==1 || $role==2){

        if($fetch_user['status']==1){
            mysqli_query($conn, "update user set status=0 where id='".$staff."' ");

            $msg = "STAFF BLOCKED SUCCESSFULY";

        }else{

            mysqli_query($conn, "update user set status=1 where id='".$staff."' ");

            $msg = "STAFF ACTIVATED SUCCESSFULY";
            echo '<script>alert("'.$msg.'")
              window.location.replace("staff.php?staff='.$staff.'");
      </script>';
      exit;

        }

echo '<script>alert("'.$msg.'")
              window.location.replace("staff.php?staff='.$staff.'");
      </script>';
      exit;

}
else{

echo '<script>alert("Not Authorized for this Action")
              window.location.replace("staff.php?staff='.$staff.'");
      </script>';

exit;

}