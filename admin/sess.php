<?php
session_start();

if(!(isset($_SESSION['logged_in']))){

}else{
	print_r($_SESSION['logged_in']);
}
