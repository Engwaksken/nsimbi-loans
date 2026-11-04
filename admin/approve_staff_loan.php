<?php
require "db.php";
session_start();

if (!$d_db) {
    die("Connection failed: " . mysqli_connect_error());
}

$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;

$loan_id   = isset($_GET['id']) ? mysqli_real_escape_string($d_db, $_GET['id']) : null;

if (!$loan_id) {
    echo '<script>alert("Invalid  Loan ID");
          window.location.replace("pending_loans.php");
    </script>';
    exit;
}

if ($role !== 1) {
    echo '<script>alert("Not Authorized for this Action");
          window.location.replace("pending_saff_loans.php?id=' . htmlspecialchars($loan_id) . '");
    </script>';
    exit;
}

// Fetch loan
$get_loan = mysqli_query($d_db, "SELECT * FROM staff_loans WHERE id = '$loan_id'");
if (!$get_loan || mysqli_num_rows($get_loan) === 0) {
    echo '<script>alert("Loan not found");
          window.location.replace("pending_staff_loans.php");
    </script>';
    exit;
}
$fetch_loan = mysqli_fetch_assoc($get_loan);

$msg = "";
$redirect = "pending_staff_loans.php?id=" . htmlspecialchars($loan_id);

if ($fetch_loan['status'] === "PENDING") {
    $update = mysqli_query($d_db, "UPDATE staff_loans SET status = 'OUTSTANDING' WHERE id = '$loan_id'");
    if ($update) {
        $msg = "LOAN APPROVED SUCCESSFULLY";
        $redirect = "staff_loans.php?id=" . htmlspecialchars($loan_id);
    } else {
        $msg = "ERROR APPROVING LOAN";
    }
} else {
    $update = mysqli_query($d_db, "UPDATE staff_loans SET status = 'PENDING' WHERE id = '$loan_id'");
    if ($update) {
        $msg = "LOAN STATUS SET TO PENDING";
    } else {
        $msg = "ERROR UPDATING LOAN STATUS";
    }
}

echo '<script>alert("' . htmlspecialchars($msg) . '");
      window.location.replace("' . $redirect . '");
</script>';
exit;
?>
