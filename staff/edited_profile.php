<?php

require"db.php";

$staff = $_REQUEST['staff'];
$gross_salary = $_REQUEST['gross_salary'];
$net_salary = $_REQUEST['net_salary'];
$phone = $_REQUEST['phone'];
$home_address = $_REQUEST['home_address'];
$bank_name = $_REQUEST['bank_name'];
$bank_number = $_REQUEST['bank_number'];


$get_user = mysqli_query($conn, "select * from members where user_id='".$staff."' "); 
$fetch_user = mysqli_fetch_assoc($get_user);

       if($gross_salary==""){ $gross_salary=$fetch_user['gross_salary']; }else{ $gross_salary=$gross_salary; }
       if($net_salary==""){ $net_salary=$fetch_user['net_salary']; }else{ $net_salary=$net_salary; }
       if($phone==""){ $phone=$fetch_user['phone']; }else{ $phone=$phone; }
       if($home_address==""){ $home_address=$fetch_user['home_address']; }else{ $home_address=$home_address; }
       if($bank_name==""){ $bank_name=$fetch_user['bank_name']; }else{ $bank_name=$bank_name; }
       if($bank_number ==""){ $bank_number =$fetch_user['bank_number ']; }else{ $bank_number =$bank_number ; }
        
            mysqli_query($conn, "update members set gross_salary='".$gross_salary."', net_salary='".$net_salary."', phone='".$phone."', home_address='".$home_address."',bank_name='".$bank_name."', bank_account='".$bank_number."' where user_id='".$staff."' ");

            $msg = "STAFF SUCCESSFULY UPDATED";

        

echo '<script>alert("'.$msg.'")
              window.location.replace("profile.php?user_id='.$staff.'");
      </script>';
      exit;



?>



