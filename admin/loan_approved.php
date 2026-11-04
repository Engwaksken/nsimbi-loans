<?php
require "db.php";
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['loan_id'])) {
        exit("Error: Loan ID is required.");
    }

    if (empty($_SESSION['fname'])) {
        exit("Error: User not logged in.");
    }

    $loanRequestId = $conn->real_escape_string($_POST['loan_id']);


    $sql = "SELECT * FROM staff_loans WHERE id = ? AND status = 'PENDING'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $loanRequestId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $loanRequest = $result->fetch_assoc();

        $clientId     = (int)$loanRequest['user_id'];
        $loanAmount   = (float)$loanRequest['amount'];
        $loanPeriod   = (int)$loanRequest['loan_period'];

        $interestRate = 10;
        $interest     = $loanAmount * ($interestRate / 100);
        $loanBalance  = $loanAmount + $interest;
        $status       = "OUTSTANDING";

        $approvalDate = new DateTime();
        $approvalDate->add(new DateInterval('P' . $loanPeriod . 'M'));
        $dueDate = $approvalDate->format('Y-m-d H:i:s');

        $updateSql = "UPDATE staff_loans SET status = ?, due_date = ?, interest_rate = ?, interest = ?, loan_balance = ?, sur_charge = 0 WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("ssdddi", $status, $dueDate, $interestRate, $interest, $loanBalance, $loanRequestId);

        if ($updateStmt->execute()) {

            $updateMsgSql = "UPDATE loan_messages SET message_type = 'loan_approved', is_read = 1 WHERE user_id = ? AND message_type = 'loan_request'";
            $updateMsgStmt = $conn->prepare($updateMsgSql);
            $updateMsgStmt->bind_param("i", $clientId);
            $updateMsgStmt->execute();

            echo '<script>
                alert("Loan approved successfully!");
                window.location.href = "staff_loans.php?id=' . $loanRequestId . '";
            </script>';
            exit;
        } else {
            echo "Error updating loan: " . $conn->error;
        }
    } else {
        echo "Loan request not found, or it has already been processed.";
    }
} else {
    echo "Invalid request method.";
}
?>
