<?php
require "db.php";

$email = $_REQUEST['email'];
$password = $_REQUEST['password'];


if($email=="" || $password==""){

	$data = '{"status":"FAILED","email":"'.$email.'","password":"'.$password.'"}';



}else{

	$sql = "SELECT * FROM user WHERE username = '$username'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['username'] = $user['username'];
            header("Location: dashboard.php");
            exit();
        } else {
            echo "Invalid password!";
        }
    } else {
        echo "No user found!";
    }
}
?>