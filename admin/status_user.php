<?php

require"db.php";

$user = $_REQUEST['user'];
$admin = $_REQUEST['admin'];

$get_user = mysqli_query($d_db, "select * from user where id='".$user."' "); 
$fetch_user = mysqli_fetch_assoc($get_user);
$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;

if($role==1 || $role==2){

        if($fetch_user['status']==1){
            mysqli_query($d_db, "update user set status=0 where id='".$user."' ");

            $msg = "USER BLOCKED SUCCESSFULY";

        }else{

            mysqli_query($d_db, "update user set status=1 where id='".$user."' ");

            $msg = "USER ACTIVATED SUCCESSFULY";

        }

echo '<script>alert("'.$msg.'")
              window.location.replace("view_user.php?user='.$user.'");
      </script>';
      exit;

}
else{

echo '<script>alert("Not Authorized for this Action")
              window.location.replace("view_user.php?user='.$user.'");
      </script>';

//exit;

}