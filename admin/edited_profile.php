<?php

require"db.php";

$user = $_REQUEST['user'];
$fname = $_REQUEST['fname'];
$lname = $_REQUEST['lname'];
$email = $_REQUEST['email'];



$get_user = mysqli_query($conn, "select * from user where id='".$user."' "); 
$fetch_user = mysqli_fetch_assoc($get_user);

       if($fname==""){ $fname=$fetch_user['fname']; }else{ $fname=$fname; }
       if($lname==""){ $lname=$fetch_user['lname']; }else{ $lname=$lname; }
       if($email==""){ $email=$fetch_user['email']; }else{ $email=$email; }
       
        
            mysqli_query($conn, "update user set fname='".$fname."', lname='".$lname."', email='".$email."' where id='".$user."' ");

            $msg = "USER SUCCESSFULY UPDATED";

        

echo '<script>alert("'.$msg.'")
              window.location.replace("profile.php?id='.$user.'");
      </script>';
      exit;



?>



