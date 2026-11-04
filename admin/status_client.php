<?php

require"db.php";
//require "nav-head.php"; 
$client = $_REQUEST['client'];
$admin = $_REQUEST['admin'];

$get_user = mysqli_query($conn, "select * from customers where id='".$client."' "); 
$fetch_user = mysqli_fetch_assoc($get_user);
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