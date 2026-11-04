<?php
declare(strict_types=1);

session_start();

require "db.php";
require_once __DIR__ . "/../php/mail-function.php";

date_default_timezone_set("Africa/Nairobi");

if (!isset($conn) || !($conn instanceof mysqli)) {
    exit("Database connection not found.");
}

$conn->set_charset("utf8mb4");

function show_message_and_redirect(
    string $message,
    string $type = "success",
    string $redirect = "pending_loans.php"
): void {
    $type = strtolower($type);

    $bg = match ($type) {
        "success" => "success",
        "warning" => "warning",
        default => "danger"
    };

    $icon = match ($type) {
        "success" => "fa-check",
        "warning" => "fa-exclamation",
        default => "fa-times"
    };

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Loan Topup Status</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

        <style>
            body {
                background: #f8fafc;
                min-height: 100vh;
            }

            .modal-content {
                border: none;
                border-radius: 20px;
                overflow: hidden;
            }

            .status-icon {
                width: 90px;
                height: 90px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
                font-size: 40px;
                color: #fff;
            }
        </style>
    </head>
    <body>

    <div class="modal fade show"
         tabindex="-1"
         style="display:block;background:rgba(15,23,42,.55);"
         aria-modal="true"
         role="dialog">

        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">

                <div class="modal-body text-center p-5">

                    <div class="status-icon bg-<?= htmlspecialchars($bg) ?>">
                        <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                    </div>

                    <h3 class="mb-3">
                        <?= htmlspecialchars(ucfirst($type)) ?>
                    </h3>

                    <p class="text-muted mb-4">
                        <?= htmlspecialchars($message) ?>
                    </p>

                    <div class="spinner-border text-<?= htmlspecialchars($bg) ?>" role="status"></div>

                    <div class="mt-3 text-muted small">
                        Redirecting...
                    </div>

                </div>

            </div>
        </div>
    </div>

    <script>
        setTimeout(function () {
            window.location.href = <?= json_encode($redirect) ?>;
        }, 3000);
    </script>

    </body>
    </html>
    <?php
    exit;
}

$client_id = (int)($_SESSION["logged_in"] ?? $_POST["client_id"] ?? 0);

$fname = trim((string)($_SESSION["fname"] ?? ""));
$lname = trim((string)($_SESSION["lname"] ?? ""));
$userName = trim($fname . " " . $lname);
$userEmail = trim((string)($_SESSION["email"] ?? ""));

if ($userName === "") {
    $userName = "Client";
}

$loan_id = (int)($_POST["loan_id"] ?? 0);

$requested_amount = (float)(
    $_POST["requested_amount"]
    ?? $_POST["amount"]
    ?? 0
);

$reason = trim((string)($_POST["reason"] ?? "Loan topup request"));

if ($client_id <= 0) {
    show_message_and_redirect(
        "Session expired. Please login again.",
        "error",
        "login.php"
    );
}

if ($loan_id <= 0) {
    show_message_and_redirect(
        "Invalid request. Loan ID is missing.",
        "error",
        "loans.php"
    );
}

if ($requested_amount <= 0) {
    show_message_and_redirect(
        "Invalid request. Topup amount is required.",
        "error",
        "loans.php"
    );
}

$stmt = $conn->prepare("
    SELECT *
    FROM members
    WHERE user_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    show_message_and_redirect(
        "Client not found.",
        "error",
        "loans.php"
    );
}

$stmt = $conn->prepare("
    SELECT *
    FROM loan
    WHERE id = ?
    AND client_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $loan_id, $client_id);
$stmt->execute();
$oldLoan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$oldLoan) {
    show_message_and_redirect(
        "Original loan not found.",
        "error",
        "loans.php"
    );
}

$old_status = strtoupper(trim((string)($oldLoan["status"] ?? "")));

if ($old_status === "CLEARED") {
    show_message_and_redirect(
        "This loan is already cleared. Please request a new loan.",
        "warning",
        "loans.php"
    );
}

$principal_balance = (float)(
    $oldLoan["principal_balance"]
    ?? $oldLoan["loan_balance"]
    ?? 0
);

$interest_balance = (float)($oldLoan["interest_balance"] ?? 0);
$outstanding_balance = (float)($oldLoan["outstanding_balance"] ?? 0);

if ($outstanding_balance <= 0) {
    $outstanding_balance = $principal_balance + $interest_balance;
}

if ($outstanding_balance <= 0) {
    show_message_and_redirect(
        "This loan has no outstanding balance.",
        "warning",
        "loans.php"
    );
}

if ($requested_amount <= $outstanding_balance) {
    show_message_and_redirect(
        "Topup amount must be greater than the current outstanding balance of UGX " .
        number_format($outstanding_balance, 2),
        "warning",
        "loans.php"
    );
}

$loan_limit = (float)($client["loan_limit"] ?? 0);

if ($loan_limit > 0 && $requested_amount > $loan_limit) {
    show_message_and_redirect(
        "FAILED. Topup request exceeds your loan limit.",
        "error",
        "loans.php"
    );
}

$phone = trim((string)(
    $_POST["phone"]
    ?? $client["phone"]
    ?? $oldLoan["phone"]
    ?? ""
));

$bank_name = trim((string)(
    $_POST["bank_name"]
    ?? $client["bank_name"]
    ?? $oldLoan["bank_name"]
    ?? ""
));

$bank_account = trim((string)(
    $_POST["bank_account"]
    ?? $client["bank_account"]
    ?? $oldLoan["bank_account"]
    ?? ""
));

$loan_period = (int)(
    $_POST["loan_period"]
    ?? $oldLoan["loan_period"]
    ?? 1
);

if ($loan_period <= 0) {
    $loan_period = 1;
}

$loan_method = trim((string)(
    $_POST["loan_method"]
    ?? "topup"
));

$interest_rate = (float)($oldLoan["interest_rate"] ?? 10);

if ($interest_rate <= 0) {
    $interest_rate = 10;
}

$interest = $requested_amount * ($interest_rate / 100);
$loan_balance = $requested_amount + $interest;
$status = "PENDING";
$due_date = (new DateTime())->modify("+{$loan_period} months")->format("Y-m-d H:i:s");

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        INSERT INTO loan (
            amount,
            principal_balance,
            interest_balance,
            outstanding_balance,
            interest_rate,
            client_id,
            status,
            datetime,
            updated_at,
            due_date,
            loan_balance,
            sur_charge,
            interest,
            loan_period,
            loan_method,
            bank_name,
            bank_account,
            phone
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, 0, ?, ?, ?, ?, ?, ?
        )
    ");

    $stmt->bind_param(
        "dddddissddissss",
        $requested_amount,
        $requested_amount,
        $interest,
        $loan_balance,
        $interest_rate,
        $client_id,
        $status,
        $due_date,
        $loan_balance,
        $interest,
        $loan_period,
        $loan_method,
        $bank_name,
        $bank_account,
        $phone
    );

    $stmt->execute();
    $new_loan_id = $stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO loan_messages (
            user_id,
            sender_name,
            message_type,
            is_read,
            created_at
        ) VALUES (
            ?,
            ?,
            'loan_topup_request',
            0,
            NOW()
        )
    ");

    $stmt->bind_param("is", $client_id, $userName);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    if (function_exists("sendAdminLoanRequest")) {
        sendAdminLoanRequest($userName, $userEmail, $conn);
    }

    show_message_and_redirect(
        "Loan topup request submitted successfully.",
        "success",
        "pending_loans.php"
    );

} catch (Throwable $e) {
    $conn->rollback();

    show_message_and_redirect(
        "Topup request failed: " . $e->getMessage(),
        "error",
        "loans.php"
    );
}
?>