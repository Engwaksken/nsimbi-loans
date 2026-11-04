<?php
require "nav-head.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['error'] = "Database connection not established.";
    header("Location:loan_payments.php");
    exit;
}

if (!isset($_SESSION['logged_in'])) {
    $_SESSION['error'] = "User not logged in.";
    header("Location:loan_payments.php");
    exit;
}

$conn->set_charset('utf8mb4');

$client_id = (int)$_SESSION['logged_in'];

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SHOW TABLES LIKE ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location:loan_payments.php");
    exit;
}

$loan_id           = (int)($_POST['loan_id'] ?? 0);
$amount            = (float)($_POST['amount'] ?? 0);
$payment_method    = trim((string)($_POST['payment_method'] ?? ''));
$reference_number  = trim((string)($_POST['reference_number'] ?? ''));
$notes             = trim((string)($_POST['notes'] ?? ''));

if ($loan_id <= 0 || $amount <= 0 || $payment_method === '') {
    $_SESSION['error'] = "Please fill in all required repayment details.";
    header("Location:loan_payments.php");
    exit;
}

if (!tableExists($conn, 'loan_repayment_requests')) {
    $_SESSION['error'] = "loan_repayment_requests table not found.";
    header("Location:loan_payments.php");
    exit;
}

$balanceField = columnExists($conn, 'loan', 'effective_balance')
    ? 'effective_balance'
    : (columnExists($conn, 'loan', 'outstanding_balance') ? 'outstanding_balance' : 'amount');

try {
    $conn->begin_transaction();

    $loanSql = "
        SELECT id, client_id, status, {$balanceField} AS effective_balance
        FROM loan
        WHERE id = ? AND client_id = ?
        LIMIT 1
        FOR UPDATE
    ";
    $loanStmt = $conn->prepare($loanSql);
    if (!$loanStmt) {
        throw new Exception("Failed to validate loan.");
    }

    $loanStmt->bind_param("ii", $loan_id, $client_id);
    $loanStmt->execute();
    $loan = $loanStmt->get_result()->fetch_assoc();
    $loanStmt->close();

    if (!$loan) {
        throw new Exception("Loan not found.");
    }

    $loanStatus = strtoupper((string)$loan['status']);
    if (!in_array($loanStatus, ['APPROVED', 'OUTSTANDING', 'OVERDUE'], true)) {
        throw new Exception("This loan is not available for repayment.");
    }

    $effectiveBalance = (float)($loan['effective_balance'] ?? 0);
    if ($effectiveBalance <= 0) {
        throw new Exception("This loan is already cleared.");
    }

    $receiptPath = null;
    if (isset($_FILES['receipt_file']) && !empty($_FILES['receipt_file']['name'])) {
        if ($_FILES['receipt_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Failed to upload receipt file.");
        }

        $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
        $ext = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed, true)) {
            throw new Exception("Only JPG, JPEG, PNG, PDF and WEBP files are allowed.");
        }

        $uploadDir = __DIR__ . '/uploads/loan_repayments/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
            throw new Exception("Failed to create upload directory.");
        }

        $fileName = 'repayment_' . $client_id . '_' . $loan_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($_FILES['receipt_file']['tmp_name'], $targetPath)) {
            throw new Exception("Failed to save uploaded receipt.");
        }

        $receiptPath = 'uploads/loan_repayments/' . $fileName;
    } else {
        throw new Exception("Receipt / screenshot is required.");
    }

    $reqSql = "
        INSERT INTO loan_repayment_requests
        (loan_id, client_id, requested_amount, payment_method, reference_number, receipt_file, notes, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW(), NOW())
    ";
    $reqStmt = $conn->prepare($reqSql);
    if (!$reqStmt) {
        throw new Exception("Failed to save repayment request.");
    }

    $reqStmt->bind_param(
        "iidssss",
        $loan_id,
        $client_id,
        $amount,
        $payment_method,
        $reference_number,
        $receiptPath,
        $notes
    );
    $reqStmt->execute();
    $reqStmt->close();

    if (tableExists($conn, 'notifications')) {
        $roleRes = $conn->query("
            SELECT id, role
            FROM user
            WHERE LOWER(role) IN ('admin', 'administrator', 'loan officer', 'loan_officer', 'accountant')
        ");

        if ($roleRes) {
            while ($u = $roleRes->fetch_assoc()) {
                $recipientId = (int)($u['id'] ?? 0);
                if ($recipientId <= 0) {
                    continue;
                }

                $title   = 'Loan Repayment Approval Needed';
                $message = "A new loan repayment request has been submitted. Loan ID: {$loan_id}, Client ID: {$client_id}, Amount: " . number_format($amount, 2) . ".";

                $cols = [];
                $vals = [];
                $types = '';
                $params = [];

                $data = [
                    'user_id'    => [$recipientId, 'i'],
                    'client_id'  => [$client_id, 'i'],
                    'loan_id'    => [$loan_id, 'i'],
                    'title'      => [$title, 's'],
                    'message'    => [$message, 's'],
                    'type'       => ['loan_repayment', 's'],
                    'status'     => ['unread', 's'],
                    'created_at' => [date('Y-m-d H:i:s'), 's'],
                    'updated_at' => [date('Y-m-d H:i:s'), 's'],
                ];

                foreach ($data as $col => [$val, $type]) {
                    if (columnExists($conn, 'notifications', $col)) {
                        $cols[] = $col;
                        $vals[] = '?';
                        $types .= $type;
                        $params[] = $val;
                    }
                }

                if ($cols) {
                    $sql = "INSERT INTO notifications (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $bind = [$types];
                        foreach ($params as $k => $v) {
                            $bind[] = &$params[$k];
                        }
                        call_user_func_array([$stmt, 'bind_param'], $bind);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }
    }

    $conn->commit();
    $_SESSION['success'] = "Repayment request submitted successfully and sent for approval.";
    header("Location:loan_payments.php");
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['error'] = $e->getMessage();
    header("Location:loan_payments.php");
    exit;
}