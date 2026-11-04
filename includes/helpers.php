<?php
function outstanding($conn,$loan_id){
    $loan=$conn->query("SELECT amount FROM loans WHERE id=$loan_id")->fetch_assoc();
    $paid=$conn->query("SELECT SUM(amount) as paid FROM repayments WHERE loan_id=$loan_id")->fetch_assoc();
    return $loan['amount'] - ($paid['paid'] ?? 0);
}
?>