<?php
require '../../includes/db.php';

$client=$_GET['client_id'];

$res=$conn->query("SELECT * FROM loans WHERE client_id=$client");

while($r=$res->fetch_assoc()){
echo "<tr><td>{$r['id']}</td><td>{$r['amount']}</td><td>{$r['status']}</td></tr>";
}
?>