<?php

require"db.php"; 
$staff = $_REQUEST['staff'];
$admin = $_REQUEST['admin'];
$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;

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