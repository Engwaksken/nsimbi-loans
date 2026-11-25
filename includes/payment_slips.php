<?php
declare(strict_types=1);

/**
 * Loan repayments with a payment slip.
 *
 * The client says how much they paid and attaches the slip (mobile money
 * screenshot, bank deposit slip, receipt). That creates a PENDING row in
 * `payments`; the loan balance does not change. A loan officer looks at the
 * slip on admin/payment_slips.php and approves it (optionally correcting the
 * amount to what the slip shows) or rejects it with a reason. Approval takes
 * the amount off the loan - interest first, then principal - and clears the
 * loan when nothing is left. The client is told either way.
 *
 * Slips are kept in storage/client_documents/{user_id}/ (no web access) and
 * shown to staff through admin/payment_slip_file.php.
 */

require_once __DIR__ . '/client_documents.php';
require_once __DIR__ . '/loan_offers.php';

const PAYMENT_SLIP_MAX_BYTES = 5 * 1024 * 1024;
const PAYMENT_METHODS = [
    'mobile_money'  => 'Mobile Money',
    'bank_transfer' => 'Bank deposit / transfer',
    'cash'          => 'Cash at office',
    'other'         => 'Other',
];
/** Staff who may review slips: reviewer (1), loan officer (2), accountant (6). */
const PAYMENT_SLIP_REVIEW_ROLES = [1, 2, 6];

function payment_slips_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    loan_offers_ensure_schema($conn);

    $columns = [
        'user_id'               => 'ADD COLUMN user_id INT NULL',
        'datetime'              => 'ADD COLUMN datetime DATETIME NULL',
        'payment_method'        => 'ADD COLUMN payment_method VARCHAR(40) NULL',
        'transaction_reference' => 'ADD COLUMN transaction_reference VARCHAR(100) NULL',
        'slip_path'             => 'ADD COLUMN slip_path VARCHAR(255) NULL',
        'slip_mime'             => 'ADD COLUMN slip_mime VARCHAR(100) NULL',
        'slip_name'             => 'ADD COLUMN slip_name VARCHAR(255) NULL',
        'submitted_via'         => 'ADD COLUMN submitted_via VARCHAR(20) NULL',
        'claimed_amount'        => 'ADD COLUMN claimed_amount DECIMAL(15,2) NULL',
        'reviewed_by'           => 'ADD COLUMN reviewed_by INT NULL',
        'reviewed_at'           => 'ADD COLUMN reviewed_at DATETIME NULL',
        'review_note'           => 'ADD COLUMN review_note VARCHAR(500) NULL',
    ];
    foreach ($columns as $col => $ddl) {
        loan_offers_add_column($conn, 'payments', $col, $ddl);
    }
}

/** Open loan the client is repaying (oldest due first), or null. */
function payment_slip_open_loan(mysqli $conn, int $userId, int $loanId = 0): ?array
{
    $sql = "SELECT id, loan_balance, status FROM loan
            WHERE client_id = ?
              AND COALESCE(loan_balance, 0) > 0
              AND UPPER(COALESCE(status, '')) IN ('OUTSTANDING', 'OVERDUE', 'ACTIVE')"
        . ($loanId > 0 ? ' AND id = ?' : '')
        . ' ORDER BY COALESCE(due_date, datetime) ASC, id ASC LIMIT 1';
    $stmt = $conn->prepare($sql);
    if ($loanId > 0) {
        $stmt->bind_param('ii', $userId, $loanId);
    } else {
        $stmt->bind_param('i', $userId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Sum of the client's payments still waiting for review on a loan. */
function payment_slip_pending_total(mysqli $conn, int $loanId): float
{
    payment_slips_ensure_schema($conn);
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS t FROM payments WHERE loan_id = ? AND UPPER(status) = 'PENDING'");
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $t = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);
    $stmt->close();
    return $t;
}

/**
 * Records a repayment for review.
 *
 * @param string|null $slipBytes file contents, or null when no slip was sent
 *                               (older app versions); staff then check the
 *                               payment with the client before approving.
 * @return array{payment_id:int, loan_id:int, amount:float, pending_total:float, balance:float}
 * @throws DomainException with a message for the client
 */
function payment_slip_submit(
    mysqli $conn,
    int $userId,
    int $loanId,
    float $amount,
    string $method,
    string $reference,
    ?string $slipBytes,
    string $slipName,
    string $via
): array {
    payment_slips_ensure_schema($conn);

    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new DomainException('Please enter the amount you paid.');
    }
    $method = array_key_exists($method, PAYMENT_METHODS) ? $method : 'mobile_money';
    $reference = mb_substr(trim($reference), 0, 100);

    $loan = payment_slip_open_loan($conn, $userId, $loanId);
    if (!$loan) {
        throw new DomainException('You have no loan to repay.');
    }
    $loanId = (int)$loan['id'];
    $balance = (float)$loan['loan_balance'];
    $pending = payment_slip_pending_total($conn, $loanId);
    if ($amount > $balance - $pending + 0.01) {
        throw new DomainException($pending > 0
            ? 'This is more than your balance of ' . loan_money($balance) . ' minus ' . loan_money($pending) . ' already waiting for review.'
            : 'This is more than your balance of ' . loan_money($balance) . '.');
    }

    if ($reference !== '') {
        $stmt = $conn->prepare('SELECT 1 FROM payments WHERE loan_id = ? AND transaction_reference = ? AND UPPER(status) <> ? LIMIT 1');
        $rejected = 'REJECTED';
        $stmt->bind_param('iss', $loanId, $reference, $rejected);
        $stmt->execute();
        $dup = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($dup) {
            throw new DomainException('This transaction ID has already been sent.');
        }
    }

    $slipPath = null;
    $slipMime = null;
    $storedFile = null;
    if ($slipBytes !== null && $slipBytes !== '') {
        if (strlen($slipBytes) > PAYMENT_SLIP_MAX_BYTES) {
            throw new DomainException('The slip is too large. The maximum size is 5 MB.');
        }
        $slipMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($slipBytes) ?: '';
        $ext = CLIENT_DOC_MIME[$slipMime] ?? '';
        if ($ext === '') {
            throw new DomainException('The slip must be a photo (JPG, PNG, WebP) or a PDF.');
        }
        $dir = client_docs_ensure_storage($userId);
        $file = 'payment_slip_' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $file, $slipBytes) === false) {
            throw new RuntimeException('Could not save payment slip ' . $file);
        }
        $storedFile = $dir . '/' . $file;
        $slipPath = $userId . '/' . $file;
    }
    $slipName = mb_substr(basename($slipName !== '' ? $slipName : (string)$slipPath), 0, 255);

    try {
        $status = 'PENDING';
        $stmt = $conn->prepare('
            INSERT INTO payments
                (loan_id, user_id, amount, claimed_amount, status, datetime, payment_method,
                 transaction_reference, slip_path, slip_mime, slip_name, submitted_via)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param('iiddsssssss', $loanId, $userId, $amount, $amount, $status, $method,
            $reference, $slipPath, $slipMime, $slipName, $via);
        $stmt->execute();
        $paymentId = (int)$stmt->insert_id;
        $stmt->close();
    } catch (Throwable $e) {
        if ($storedFile !== null) {
            @unlink($storedFile);
        }
        throw $e;
    }

    // Staff see new slips in Notifications as well as on the Payment Slips page.
    try {
        $type = 'payment_slip';
        $msg = $conn->prepare("
            INSERT INTO loan_messages (user_id, loan_id, sender_name, message_type, is_read, created_at)
            SELECT u.id, ?, TRIM(CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, ''))), ?, 0, NOW()
            FROM user u WHERE u.id = ?
        ");
        $msg->bind_param('isi', $loanId, $type, $userId);
        $msg->execute();
        $msg->close();
    } catch (Throwable $e) {
        error_log('payment slip message failed: ' . $e->getMessage());
    }

    return [
        'payment_id' => $paymentId,
        'loan_id' => $loanId,
        'amount' => $amount,
        'pending_total' => $pending + $amount,
        'balance' => $balance,
    ];
}

/**
 * Approve or reject a pending payment.
 * Approve takes $approvedAmount (defaults to what the client entered) off the
 * loan: interest first, then principal; the loan is CLEARED at zero.
 *
 * @throws DomainException with a message for staff
 */
function payment_slip_review(
    mysqli $conn,
    int $paymentId,
    int $actorId,
    int $actorRole,
    string $decision,
    string $note,
    ?float $approvedAmount = null
): array {
    payment_slips_ensure_schema($conn);
    if (!in_array($decision, ['approve', 'reject'], true)) {
        throw new DomainException('Choose approve or reject.');
    }
    $note = mb_substr(trim($note), 0, 500);
    if ($decision === 'reject' && $note === '') {
        throw new DomainException('Give the client a reason for rejecting the payment.');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? AND UPPER(status) = 'PENDING' FOR UPDATE");
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$payment) {
            throw new DomainException('This payment was already reviewed.');
        }

        $loanId = (int)$payment['loan_id'];
        $stmt = $conn->prepare('SELECT * FROM loan WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $loanId);
        $stmt->execute();
        $loan = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$loan) {
            throw new DomainException('The loan for this payment was not found.');
        }

        if ($actorRole === 2) {
            $assigned = (int)($loan['assigned_officer_id'] ?? 0);
            if ($assigned !== $actorId) {
                throw new DomainException('This loan is not assigned to you.');
            }
        }

        $clientId = (int)($payment['user_id'] ?? 0) ?: (int)$loan['client_id'];
        $oldBalance = (float)$loan['loan_balance'];
        $result = ['loan_id' => $loanId, 'client_id' => $clientId, 'old_balance' => $oldBalance];

        if ($decision === 'reject') {
            $status = 'REJECTED';
            $stmt = $conn->prepare('UPDATE payments SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?');
            $stmt->bind_param('sisi', $status, $actorId, $note, $paymentId);
            $stmt->execute();
            $stmt->close();
            $conn->commit();

            loan_notify_client($conn, $clientId, $loanId, 'payment_rejected',
                'Payment not confirmed - Nsimbi Impact',
                'We could not confirm your payment of ' . loan_money((float)$payment['amount']) . '. Reason: ' . $note
                . ' Your loan balance is still ' . loan_money($oldBalance) . '. Please send the correct slip in the Nsimbi app.');
            return $result + ['status' => 'REJECTED', 'new_balance' => $oldBalance];
        }

        $amount = round($approvedAmount !== null && $approvedAmount > 0 ? $approvedAmount : (float)$payment['amount'], 2);
        if ($oldBalance <= 0) {
            throw new DomainException('This loan has no balance left.');
        }
        if ($amount > $oldBalance + 0.01) {
            throw new DomainException('The amount is more than the loan balance of ' . loan_money($oldBalance) . '.');
        }
        $amount = min($amount, $oldBalance);

        $newBalance = round(max(0.0, $oldBalance - $amount), 2);
        $interestBalance = max(0.0, (float)($loan['interest_balance'] ?? 0));
        $principalBalance = max(0.0, (float)($loan['principal_balance'] ?? 0));
        $left = $amount;
        $interestPaid = min($interestBalance, $left);
        $interestBalance -= $interestPaid;
        $left -= $interestPaid;
        $principalBalance = max(0.0, $principalBalance - $left);

        $loanStatus = strtoupper((string)$loan['status']);
        if ($newBalance <= 0) {
            $loanStatus = 'CLEARED';
            $interestBalance = 0.0;
            $principalBalance = 0.0;
        }

        $stmt = $conn->prepare('
            UPDATE loan
            SET loan_balance = ?, outstanding_balance = ?, interest_balance = ?, principal_balance = ?,
                status = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->bind_param('ddddsi', $newBalance, $newBalance, $interestBalance, $principalBalance, $loanStatus, $loanId);
        $stmt->execute();
        $stmt->close();

        $status = 'SUCCESSFUL';
        $stmt = $conn->prepare('UPDATE payments SET status = ?, amount = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?');
        $stmt->bind_param('sdisi', $status, $amount, $actorId, $note, $paymentId);
        $stmt->execute();
        $stmt->close();

        payment_slip_issue_receipt($conn, $paymentId, $loanId, $clientId, $amount,
            (string)($payment['payment_method'] ?? ''), (string)($payment['transaction_reference'] ?? ''), $note, $actorId);

        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }

    $claimed = (float)$payment['amount'];
    loan_notify_client($conn, $clientId, $loanId, 'payment_confirmed',
        'Payment confirmed - Nsimbi Impact',
        'We confirmed your payment of ' . loan_money($amount) . '.'
        . (abs($claimed - $amount) > 0.009 ? ' (You entered ' . loan_money($claimed) . '; the slip shows ' . loan_money($amount) . '.)' : '')
        . ($newBalance <= 0 ? ' Your loan is fully repaid. Thank you!' : ' Your loan balance is now ' . loan_money($newBalance) . '.'));

    return $result + ['status' => 'SUCCESSFUL', 'amount' => $amount, 'new_balance' => $newBalance, 'loan_status' => $loanStatus];
}

/** Receipt row, when the receipts table exists (same as admin Record Repayment). */
function payment_slip_issue_receipt(mysqli $conn, int $paymentId, int $loanId, int $clientId, float $amount,
                                    string $method, string $reference, string $notes, int $actorId): void
{
    try {
        $res = $conn->query("SHOW TABLES LIKE 'receipts'");
        if (!$res || $res->num_rows === 0) {
            return;
        }
        $cols = [];
        foreach ($conn->query('SHOW COLUMNS FROM receipts')->fetch_all(MYSQLI_ASSOC) as $c) {
            $cols[$c['Field']] = true;
        }
        $now = date('Y-m-d H:i:s');
        $no = 'RCT-' . date('Ymd') . '-' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT);
        $data = [
            'receipt_no' => $no, 'receipt_number' => $no, 'payment_id' => $paymentId, 'loan_id' => $loanId,
            'client_id' => $clientId, 'amount' => $amount, 'payment_method' => PAYMENT_METHODS[$method] ?? $method,
            'reference' => $reference, 'notes' => $notes, 'status' => 'PAID', 'created_by' => $actorId,
            'created_at' => $now, 'datetime' => $now, 'issued_at' => $now,
        ];
        $data = array_intersect_key($data, $cols);
        if (!$data) {
            return;
        }
        $types = '';
        foreach ($data as $v) {
            $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
        }
        $sql = 'INSERT INTO receipts (`' . implode('`,`', array_keys($data)) . '`) VALUES ('
            . implode(',', array_fill(0, count($data), '?')) . ')';
        $stmt = $conn->prepare($sql);
        $values = array_values($data);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('payment slip receipt failed: ' . $e->getMessage());
    }
}

function payment_slip_pending_count(mysqli $conn): int
{
    try {
        payment_slips_ensure_schema($conn);
        $r = $conn->query("SELECT COUNT(*) AS c FROM payments WHERE UPPER(status) = 'PENDING'");
        return (int)($r ? $r->fetch_assoc()['c'] : 0);
    } catch (Throwable $e) {
        return 0;
    }
}
