<?php
require "db.php";


mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


$client_id = (int)($_GET['client_id'] ?? $_GET['user_id'] ?? $_GET['id'] ?? 0);

if ($client_id <= 0) {
    echo "<div class='alert alert-warning'>Invalid client ID.</div>";
    require "footer.php";
    exit;
}

// Confirm the client actually exists before attempting to delete anything.
$check = mysqli_prepare($conn, "SELECT id FROM user WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($check, "i", $client_id);
mysqli_stmt_execute($check);
mysqli_stmt_store_result($check);
$exists = mysqli_stmt_num_rows($check) > 0;
mysqli_stmt_close($check);

if (!$exists) {
    echo "<div class='alert alert-warning'>Client not found. It may have already been deleted.</div>";
    require "footer.php";
    exit;
}

mysqli_begin_transaction($conn);

try {
    $stmt1 = mysqli_prepare($conn, "DELETE FROM customers WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt1, "i", $client_id);
    mysqli_stmt_execute($stmt1);
    mysqli_stmt_close($stmt1);

    $stmt2 = mysqli_prepare($conn, "DELETE FROM user WHERE id = ?");
    mysqli_stmt_bind_param($stmt2, "i", $client_id);
    mysqli_stmt_execute($stmt2);
    mysqli_stmt_close($stmt2);

    mysqli_commit($conn);

    header("Location: clients.php?msg=Client+deleted+successfully");
    exit;

} catch (Throwable $e) {
    mysqli_rollback($conn);

 
    $isFkError = str_contains($e->getMessage(), 'foreign key constraint');

    $message = $isFkError
        ? 'This client has existing loan or payment records and cannot be deleted. Block the client instead, or remove their loan history first.'
        : 'Error deleting client: ' . htmlspecialchars($e->getMessage());

    echo "<div class='alert alert-danger'>{$message}</div>";
}

require "footer.php";