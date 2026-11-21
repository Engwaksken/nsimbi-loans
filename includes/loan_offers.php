<?php
declare(strict_types=1);

/**
 * Loan application -> offer -> acceptance helpers, shared by api.php (mobile
 * app) and the admin pages.
 *
 * Flow:
 *   1. The client asks for the amount they want (requested_amount) and a term.
 *      The system-calculated limit is saved on the loan (system_amount) for
 *      staff only; the client is not shown it.
 *   2. A reviewer approves the requested amount, the system amount or a custom
 *      amount. The loan becomes OFFERED and the client is notified.
 *   3. The client accepts (-> APPROVED, waiting for disbursement) or rejects
 *      (-> DECLINED). On acceptance the processing fee is taken off the payout
 *      (net_disbursement); repayments stay based on the full approved amount.
 *
 * Columns and tables are added on first use, the same way credit_scoring.php
 * and client_documents.php do it.
 */

const LOAN_DEFAULT_PROCESSING_FEE = 5000.0;

/* ---------------------------------------------------------------------------
 | Schema
 * ------------------------------------------------------------------------- */

function loan_offers_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->query("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL DEFAULT '',
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Outbox of client-facing loan events. The app reads the current offer from
    // get_client; an SMS or push sender can later read the rows where
    // sms_sent_at / push_sent_at are still NULL (see loan_notify_client()).
    $conn->query("
        CREATE TABLE IF NOT EXISTS client_notifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            loan_id INT NULL,
            event VARCHAR(40) NOT NULL,
            title VARCHAR(150) NOT NULL,
            body TEXT NOT NULL,
            email_sent_at DATETIME NULL,
            sms_sent_at DATETIME NULL,
            push_sent_at DATETIME NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_client_notifications_user (user_id, read_at),
            KEY idx_client_notifications_sms (sms_sent_at),
            KEY idx_client_notifications_push (push_sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $loanColumns = [
        'requested_amount'  => 'ADD COLUMN requested_amount DECIMAL(15,2) NULL',
        'system_amount'     => 'ADD COLUMN system_amount DECIMAL(15,2) NULL',
        'offer_amount'      => 'ADD COLUMN offer_amount DECIMAL(15,2) NULL',
        'offer_choice'      => 'ADD COLUMN offer_choice VARCHAR(20) NULL',
        'offered_by'        => 'ADD COLUMN offered_by INT NULL',
        'offered_at'        => 'ADD COLUMN offered_at DATETIME NULL',
        'offer_response_at' => 'ADD COLUMN offer_response_at DATETIME NULL',
        'processing_fee'    => 'ADD COLUMN processing_fee DECIMAL(15,2) NULL',
        'net_disbursement'  => 'ADD COLUMN net_disbursement DECIMAL(15,2) NULL',
        'loan_period_unit'  => "ADD COLUMN loan_period_unit VARCHAR(10) NOT NULL DEFAULT 'month'",
    ];
    foreach ($loanColumns as $col => $ddl) {
        loan_offers_add_column($conn, 'loan', $col, $ddl);
    }

    $customerColumns = [
        'nin_verified_at'      => 'ADD COLUMN nin_verified_at DATETIME NULL',
        'nin_verified_by'      => 'ADD COLUMN nin_verified_by INT NULL',
        'nin_verification_ref' => 'ADD COLUMN nin_verification_ref VARCHAR(100) NULL',
        'stock_inside'         => 'ADD COLUMN stock_inside VARCHAR(255) NULL',
    ];
    foreach ($customerColumns as $col => $ddl) {
        loan_offers_add_column($conn, 'customers', $col, $ddl);
    }

    // New statuses (OFFERED, DECLINED) must fit. Older databases may still
    // have loan.status as an ENUM; widen it to a VARCHAR once.
    try {
        $res = $conn->query("SHOW COLUMNS FROM loan LIKE 'status'");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && stripos((string)$row['Type'], 'enum') === 0) {
            $conn->query("ALTER TABLE loan MODIFY status VARCHAR(30) NOT NULL DEFAULT 'PENDING'");
        }
    } catch (Throwable $e) {
        error_log('loan status widen failed: ' . $e->getMessage());
    }
}

function loan_offers_add_column(mysqli $conn, string $table, string $column, string $ddl): void
{
    try {
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE `{$table}` {$ddl}");
        }
    } catch (Throwable $e) {
        error_log("Adding {$table}.{$column} failed: " . $e->getMessage());
    }
}

/* ---------------------------------------------------------------------------
 | Settings
 * ------------------------------------------------------------------------- */

function app_setting_get(mysqli $conn, string $key, string $default = ''): string
{
    loan_offers_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['setting_value'] : $default;
}

function app_setting_set(mysqli $conn, string $key, string $value, int $actorId): void
{
    loan_offers_ensure_schema($conn);
    $stmt = $conn->prepare('
        INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
    ');
    $stmt->bind_param('ssi', $key, $value, $actorId);
    $stmt->execute();
    $stmt->close();
}

/** Loan processing fee in UGX, taken off the payout when an offer is accepted. */
function loan_processing_fee(mysqli $conn): float
{
    $raw = app_setting_get($conn, 'loan_processing_fee', (string)LOAN_DEFAULT_PROCESSING_FEE);
    return is_numeric($raw) ? max(0.0, (float)$raw) : LOAN_DEFAULT_PROCESSING_FEE;
}

/* ---------------------------------------------------------------------------
 | Repayment terms
 * ------------------------------------------------------------------------- */

/** Terms the app offers: 2 weeks minimum, 6 months maximum. */
function loan_term_options(): array
{
    return [
        ['count' => 2, 'unit' => 'week',  'label' => '2 weeks'],
        ['count' => 4, 'unit' => 'week',  'label' => '4 weeks'],
        ['count' => 1, 'unit' => 'month', 'label' => '1 month'],
        ['count' => 3, 'unit' => 'month', 'label' => '3 months'],
        ['count' => 6, 'unit' => 'month', 'label' => '6 months'],
    ];
}

function loan_term_unit(?string $unit): string
{
    $unit = strtolower(trim((string)$unit));
    return in_array($unit, ['week', 'weeks', 'w'], true) ? 'week' : 'month';
}

/**
 * Accepts the listed terms. 2 and 5 months are also accepted so requests from
 * older app versions (which offered 3-6 months) keep working.
 */
function loan_term_is_valid(int $count, string $unit): bool
{
    if ($unit === 'week') {
        return in_array($count, [2, 4], true);
    }
    return $count >= 1 && $count <= 6;
}

function loan_term_label(int $count, string $unit): string
{
    $unit = loan_term_unit($unit);
    return $count . ' ' . $unit . ($count === 1 ? '' : 's');
}

function loan_term_interval(int $count, string $unit): DateInterval
{
    $count = max(1, $count);
    return loan_term_unit($unit) === 'week'
        ? new DateInterval('P' . ($count * 7) . 'D')
        : new DateInterval('P' . $count . 'M');
}

function loan_term_due_date(int $count, string $unit, ?DateTimeImmutable $from = null): string
{
    $from = $from ?? new DateTimeImmutable('now');
    return $from->add(loan_term_interval($count, $unit))->format('Y-m-d H:i:s');
}

/**
 * Flat interest rate (%) on the principal for the whole term.
 * Same pricing as before: 10% for a term of one month or less, 6.6% for
 * longer terms. Short weekly terms are priced like one month.
 */
function loan_interest_rate(int $count, string $unit): float
{
    if (loan_term_unit($unit) === 'week') {
        return 10.0;
    }
    return $count <= 1 ? 10.0 : 6.6;
}

/** Number of installments: weekly for week terms, monthly otherwise. */
function loan_installment_count(int $count, string $unit): int
{
    return max(1, $count);
}

/** Interest, total repayable, installment and payout for a principal. */
function loan_quote(mysqli $conn, float $principal, int $count, string $unit): array
{
    $unit = loan_term_unit($unit);
    $rate = loan_interest_rate($count, $unit);
    $interest = round($principal * $rate / 100, 2);
    $total = $principal + $interest;
    $installments = loan_installment_count($count, $unit);
    $fee = loan_processing_fee($conn);
    return [
        'principal'          => $principal,
        'interest_rate'      => $rate,
        'interest'           => $interest,
        'total_repayment'    => $total,
        'installments'       => $installments,
        'installment_amount' => round($total / $installments, 0),
        'installment_unit'   => $unit,
        'processing_fee'     => $fee,
        'net_disbursement'   => max(0.0, $principal - $fee),
        'term_label'         => loan_term_label($count, $unit),
    ];
}

/* ---------------------------------------------------------------------------
 | Notifications
 * ------------------------------------------------------------------------- */

/**
 * Records a client-facing loan event and emails the client.
 *
 * SMS / push: add the sender in loan_notify_client_channels() below, or run a
 * worker that sends rows of client_notifications whose sms_sent_at /
 * push_sent_at is NULL and stamps them. Callers do not need to change.
 */
function loan_notify_client(mysqli $conn, int $userId, int $loanId, string $event, string $title, string $body): void
{
    if ($userId <= 0) {
        return;
    }
    loan_offers_ensure_schema($conn);

    $notificationId = 0;
    try {
        $stmt = $conn->prepare('INSERT INTO client_notifications (user_id, loan_id, event, title, body) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('iisss', $userId, $loanId, $event, $title, $body);
        $stmt->execute();
        $notificationId = (int)$stmt->insert_id;
        $stmt->close();
    } catch (Throwable $e) {
        error_log('client_notifications insert failed: ' . $e->getMessage());
    }

    loan_notify_client_channels($conn, $notificationId, $userId, $title, $body);
}

/** Delivery channels. Email now; SMS and push plug in here. */
function loan_notify_client_channels(mysqli $conn, int $notificationId, int $userId, string $title, string $body): void
{
    $email = '';
    try {
        $stmt = $conn->prepare('SELECT u.email AS user_email, c.email AS customer_email FROM user u LEFT JOIN customers c ON c.user_id = u.id WHERE u.id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $email = trim((string)($row['user_email'] ?? '')) ?: trim((string)($row['customer_email'] ?? ''));
    } catch (Throwable $e) {
        error_log('loan notify email lookup failed: ' . $e->getMessage());
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            if (!function_exists('sendEmail')) {
                require_once dirname(__DIR__) . '/php/mail-function.php';
            }
            $html = '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p><p>Nsimbi Impact</p>';
            sendEmail($email, $title, $html, $body);
            if ($notificationId > 0) {
                $conn->query('UPDATE client_notifications SET email_sent_at = NOW() WHERE id = ' . $notificationId);
            }
        } catch (Throwable $e) {
            error_log('loan notify email failed: ' . $e->getMessage());
        }
    }

    // TODO SMS: look up customers.phone and send $body, then set sms_sent_at.
    // TODO Push: send to the client's device token, then set push_sent_at.
}

function loan_money(float $amount): string
{
    return 'UGX ' . number_format($amount);
}

/* ---------------------------------------------------------------------------
 | Client answer to an offer (used by api.php and clients/loan_offer_action.php)
 * ------------------------------------------------------------------------- */

/**
 * Accept: OFFERED -> APPROVED (waits for disbursement); the processing fee is
 * taken off the payout and repayments stay on the full approved amount.
 * Reject: OFFERED -> DECLINED.
 *
 * @return array{ok:bool, code:int, message:string, data:array}
 */
function loan_offer_respond(mysqli $conn, int $clientId, int $loanId, string $decision): array
{
    loan_offers_ensure_schema($conn);
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['accept', 'reject'], true)) {
        return ['ok' => false, 'code' => 422, 'message' => 'Please accept or reject the offer', 'data' => []];
    }

    $fee = 0.0;
    $net = 0.0;
    $principal = 0.0;
    $newStatus = $decision === 'accept' ? 'APPROVED' : 'DECLINED';

    $conn->begin_transaction();
    try {
        $sql = "SELECT * FROM loan WHERE client_id = ? AND UPPER(COALESCE(status, '')) = 'OFFERED'"
            . ($loanId > 0 ? ' AND id = ?' : '')
            . ' ORDER BY id DESC LIMIT 1 FOR UPDATE';
        $stmt = $conn->prepare($sql);
        if ($loanId > 0) {
            $stmt->bind_param('ii', $clientId, $loanId);
        } else {
            $stmt->bind_param('i', $clientId);
        }
        $stmt->execute();
        $loan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$loan) {
            $conn->rollback();
            return ['ok' => false, 'code' => 404, 'message' => 'There is no loan offer waiting for you', 'data' => []];
        }

        $loanId = (int)$loan['id'];
        $principal = (float)($loan['offer_amount'] ?? 0) ?: (float)($loan['amount'] ?? 0);

        if ($decision === 'accept') {
            $fee = $loan['processing_fee'] !== null ? (float)$loan['processing_fee'] : loan_processing_fee($conn);
            $fee = min($fee, $principal);
            $net = $principal - $fee;
            $stmt = $conn->prepare("
                UPDATE loan
                SET status = ?, processing_fee = ?, net_disbursement = ?, offer_response_at = NOW(), updated_at = NOW()
                WHERE id = ? AND UPPER(status) = 'OFFERED'
            ");
            $stmt->bind_param('sddi', $newStatus, $fee, $net, $loanId);
        } else {
            $stmt = $conn->prepare("
                UPDATE loan SET status = ?, offer_response_at = NOW(), updated_at = NOW()
                WHERE id = ? AND UPPER(status) = 'OFFERED'
            ");
            $stmt->bind_param('si', $newStatus, $loanId);
        }
        $stmt->execute();
        $stmt->close();

        $event = $decision === 'accept' ? 'offer_accepted' : 'offer_declined';

        // Audit trail (same table as admin/loan_audit.php). Optional.
        try {
            $role = 3;
            $old = 'OFFERED';
            $audit = $conn->prepare('
                INSERT INTO loan_audit_trail (loan_id, actor_id, actor_role, action, old_status, new_status)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $audit->bind_param('iiisss', $loanId, $clientId, $role, $event, $old, $newStatus);
            $audit->execute();
            $audit->close();
        } catch (Throwable $e) {
            error_log('Offer audit failed: ' . $e->getMessage());
        }

        // Staff see it in admin Notifications. Optional.
        try {
            $msg = $conn->prepare("
                INSERT INTO loan_messages (user_id, loan_id, sender_name, message_type, is_read, created_at)
                SELECT u.id, ?, TRIM(CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, ''))), ?, 0, NOW()
                FROM user u WHERE u.id = ?
            ");
            $msg->bind_param('isi', $loanId, $event, $clientId);
            $msg->execute();
            $msg->close();
        } catch (Throwable $e) {
            error_log('Offer message failed: ' . $e->getMessage());
        }

        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('loan_offer_respond failed: ' . $e->getMessage());
        return ['ok' => false, 'code' => 500, 'message' => 'Could not save your answer. Please try again.', 'data' => []];
    }

    if ($decision === 'accept') {
        loan_notify_client(
            $conn, $clientId, $loanId, 'offer_accepted',
            'Loan offer accepted - Nsimbi Impact',
            'You accepted a loan of ' . loan_money($principal) . '. After the processing fee of '
            . loan_money($fee) . ', ' . loan_money($net) . ' will be sent to you.'
        );
        return [
            'ok' => true,
            'code' => 200,
            'message' => 'Offer accepted. ' . loan_money($net) . ' will be sent to you.',
            'data' => [
                'loan_id' => $loanId,
                'loan_status' => 'APPROVED',
                'approved_amount' => $principal,
                'processing_fee' => $fee,
                'net_disbursement' => $net,
            ],
        ];
    }

    return [
        'ok' => true,
        'code' => 200,
        'message' => 'Offer rejected. You can apply again at any time.',
        'data' => ['loan_id' => $loanId, 'loan_status' => 'DECLINED'],
    ];
}
