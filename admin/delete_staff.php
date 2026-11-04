<?php
require "db.php";

if (isset($_GET['staff']) && is_numeric($_GET['staff'])) {
    $staff_id = (int) $_GET['staff'];

    mysqli_begin_transaction($conn);

    try {

        $stmt1 = mysqli_prepare($conn, "DELETE FROM members WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt1, "i", $staff_id);
        mysqli_stmt_execute($stmt1);

        $stmt2 = mysqli_prepare($conn, "DELETE FROM user WHERE id = ?");
        mysqli_stmt_bind_param($stmt2, "i", $staff_id);
        mysqli_stmt_execute($stmt2);

        mysqli_commit($conn);

        header("Location: staff.php?msg=staff+deleted+successfully");
        exit();
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<div class='alert alert-danger'>Error deleting staff: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<div class='alert alert-warning'>Invalid staff ID.</div>";
}

require "footer.php";
?>
