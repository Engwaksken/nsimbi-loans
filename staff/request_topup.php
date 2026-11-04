<?php
declare(strict_types=1);

require 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logged_in'])) {
    $_SESSION['error'] = 'Unauthorized access.';
    header('Location: loans.php');
    exit;
}



function redirectBack(): never
{
    header('Location: loans.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    redirectBack();
}

$userId          = (int)$_SESSION['logged_in'];
$loanId          = (int)($_POST['loan_id'] ?? 0);
$clientId        = (int)($_POST['client_id'] ?? 0);
$requestedAmount = (float)($_POST['requested_amount'] ?? 0);
$reason          = trim((string)($_POST['reason'] ?? ''));

if ($loanId <= 0 || $clientId <= 0 || $requestedAmount <= 0 || $reason === '') {
    $_SESSION['error'] = 'Please provide all required fields.';
    redirectBack();
}

try {
    $conn->begin_transaction();

    $loanSql = "
        SELECT
            id,
            client_id,
            COALESCE(effective_balance, principal_balance, loan_balance, 0) AS principal_balance,
            COALESCE(interest_balance, 0) AS interest_balance,
            COALESCE(outstanding_balance, 0) AS outstanding_balance,
            status
        FROM loan
        WHERE id = ? AND client_id = ?
        LIMIT 1
        FOR UPDATE
    ";
    $loanStmt = $conn->prepare($loanSql);
    $loanStmt->bind_param('ii', $loanId, $clientId);
    $loanStmt->execute();
    $loanRes = $loanStmt->get_result();
    $loan = $loanRes ? $loanRes->fetch_assoc() : null;
    $loanStmt->close();

    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }

    $status = strtoupper(trim((string)$loan['status']));
    if (!in_array($status, ['OUTSTANDING', 'OVERDUE'], true)) {
        throw new RuntimeException('Only outstanding or overdue loans can be topped up.');
    }

    $principalBalance = (float)($loan['principal_balance'] ?? 0);
    $interestBalance  = (float)($loan['interest_balance'] ?? 0);
    $outstanding      = (float)($loan['outstanding_balance'] ?? 0);

    if ($outstanding <= 0) {
        $outstanding = $principalBalance + $interestBalance;
    }

    $amountToClear  = min($requestedAmount, $outstanding);
    $netTopupAmount = max(0, $requestedAmount - $outstanding);

    if ($netTopupAmount <= 0) {
        throw new RuntimeException('Requested amount must be greater than the existing outstanding balance.');
    }

    $insertSql = "
        INSERT INTO loan_topups (
            loan_id,
            client_id,
            requested_amount,
            outstanding_balance,
            amount_to_clear,
            net_topup_amount,
            reason,
            status,
            created_by,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', ?, NOW())
    ";
    $insertStmt = $conn->prepare($insertSql);
    $insertStmt->bind_param(
        'iiddddsi',
        $loanId,
        $clientId,
        $requestedAmount,
        $outstanding,
        $amountToClear,
        $netTopupAmount,
        $reason,
        $userId
    );
    $insertStmt->execute();
    $topupId = $insertStmt->insert_id;
    $insertStmt->close();

    $note = "Loan topup request submitted. Loan ID: {$loanId}, Requested: {$requestedAmount}, Clear: {$amountToClear}, Net Topup: {$netTopupAmount}";

    if ($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows > 0) {
        $notifyStmt = $conn->prepare("
            INSERT INTO notifications (user_id, loan_id, message, status, notification_type, created_at)
            VALUES (?, ?, ?, 'Unread', 'topup_request', NOW())
        ");
        $notifyStmt->bind_param('iis', $userId, $loanId, $note);
        $notifyStmt->execute();
        $notifyStmt->close();
    }

    $conn->commit();
    $_SESSION['success'] = 'Topup request submitted successfully.';
} catch (Throwable $e) {
    if ($conn->errno || $conn->connect_errno === 0) {
        $conn->rollback();
    }
    $_SESSION['error'] = $e->getMessage();
}

redirectBack();