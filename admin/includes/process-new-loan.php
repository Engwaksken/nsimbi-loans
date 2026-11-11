<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| JSON response
|--------------------------------------------------------------------------
*/
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services_check.php';
require_once __DIR__ . '/../../includes/loan_workflow.php';

date_default_timezone_set('Africa/Kampala');

/*
|--------------------------------------------------------------------------
| Utility functions
|--------------------------------------------------------------------------
*/
function loanRespond(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $data
        ),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function loanPostString(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function loanPostInt(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? $default;

    return filter_var($value, FILTER_VALIDATE_INT) !== false
        ? (int)$value
        : $default;
}

function loanPostFloat(string $key, float $default = 0.0): float
{
    $value = $_POST[$key] ?? $default;

    if (is_string($value)) {
        $value = str_replace([',', 'UGX', 'ugx', ' '], '', $value);
    }

    return is_numeric($value) ? (float)$value : $default;
}

function loanTableHasColumn(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $sql = "
        SELECT COUNT(*) AS column_count
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $result = $stmt->get_result();
    $exists = false;

    if ($result && ($row = $result->fetch_assoc())) {
        $exists = (int)$row['column_count'] > 0;
    }

    $stmt->close();

    return $exists;
}

/**
 * Dynamically bind mysqli parameters by reference.
 *
 * @param array<int, mixed> $values
 */
function loanBindParams(
    mysqli_stmt $stmt,
    string $types,
    array &$values
): void {
    $references = [];
    $references[] = &$types;

    foreach ($values as &$value) {
        $references[] = &$value;
    }

    unset($value);

    if (!call_user_func_array([$stmt, 'bind_param'], $references)) {
        throw new RuntimeException(
            'Unable to bind database parameters.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Validate request
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    loanRespond(
        false,
        'Invalid request method. Please submit the loan form.',
        [],
        405
    );
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    loanRespond(
        false,
        'Database connection was not established.',
        [],
        500
    );
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Read submitted fields
|--------------------------------------------------------------------------
*/
$clientType = strtolower(loanPostString('client_type'));

$clientId = loanPostInt('client_id');

if ($clientId <= 0) {
    // Support older forms that submit user_id instead.
    $clientId = loanPostInt('user_id');
}

$amount     = loanPostFloat('amount');
$loanPeriod = loanPostInt('loan_period');
$notes      = loanPostString('notes');

$allowedClientTypes = [
    'customer',
    'member',
    'startup',
    'organisation',
];

/*
|--------------------------------------------------------------------------
| Basic validation
|--------------------------------------------------------------------------
*/
if (!in_array($clientType, $allowedClientTypes, true)) {
    loanRespond(
        false,
        'Please select a valid client type.'
    );
}

if ($clientId <= 0) {
    loanRespond(
        false,
        'Please select a valid client.'
    );
}

if ($amount <= 0) {
    loanRespond(
        false,
        'The loan amount must be greater than zero.'
    );
}

if ($loanPeriod <= 0) {
    loanRespond(
        false,
        'Please select a valid loan period.'
    );
}

if ($loanPeriod > 120) {
    loanRespond(
        false,
        'The selected loan period is too long.'
    );
}

/*
|--------------------------------------------------------------------------
| Determine interest rate
|--------------------------------------------------------------------------
*/
$interestRate = 0.0;

switch ($clientType) {
    case 'member':
        if ($loanPeriod !== 3) {
            loanRespond(
                false,
                'Staff and member loans must have a three-month period.'
            );
        }

        $interestRate = 10.0;
        break;

    case 'customer':
        if ($loanPeriod < 1 || $loanPeriod > 6) {
            loanRespond(
                false,
                'Customer loans must have a period between 1 and 6 months.'
            );
        }

        $interestRate = $loanPeriod === 1
            ? 10.0
            : 6.6;
        break;

    case 'startup':
    case 'organisation':
        if ($loanPeriod < 24 || $loanPeriod > 36) {
            loanRespond(
                false,
                'Startup loans must have a period between 24 and 36 months (2 to 3 years).'
            );
        }

        $interestRate = 10.0;
        break;
}

/*
|--------------------------------------------------------------------------
| Calculate loan amounts and dates
|--------------------------------------------------------------------------
*/
$interest = round(
    $amount * ($interestRate / 100),
    2
);

$loanBalance = round(
    $amount + $interest,
    2
);

$status = 'PENDING';

$timezone = new DateTimeZone('Africa/Kampala');
$now      = new DateTimeImmutable('now', $timezone);

$dueDateObject = $now->modify(
    sprintf('+%d months', $loanPeriod)
);

$createdAt = $now->format('Y-m-d H:i:s');
$dueDate   = $dueDateObject->format('Y-m-d H:i:s');

/*
|--------------------------------------------------------------------------
| Find selected client
|--------------------------------------------------------------------------
*/
switch ($clientType) {
    case 'customer':
        $clientSql = "
            SELECT
                c.user_id AS client_id,
                TRIM(CONCAT(
                    COALESCE(u.fname, ''),
                    ' ',
                    COALESCE(u.lname, '')
                )) AS client_name,
                COALESCE(c.loan_limit, 0) AS loan_limit,
                COALESCE(c.phone, '') AS phone,
                COALESCE(c.email, u.email, '') AS email,
                COALESCE(c.business_name, '') AS organisation
            FROM customers c
            INNER JOIN user u
                ON u.id = c.user_id
            WHERE c.user_id = ?
            LIMIT 1
        ";
        break;

    case 'member':
        $clientSql = "
            SELECT
                m.user_id AS client_id,
                TRIM(CONCAT(
                    COALESCE(u.fname, ''),
                    ' ',
                    COALESCE(u.lname, '')
                )) AS client_name,
                COALESCE(m.loan_limit, 0) AS loan_limit,
                COALESCE(m.phone, '') AS phone,
                COALESCE(m.work_email, u.email, '') AS email,
                COALESCE(m.employer, '') AS organisation
            FROM members m
            INNER JOIN user u
                ON u.id = m.user_id
            WHERE m.user_id = ?
            LIMIT 1
        ";
        break;

    case 'startup':
        $clientSql = "
            SELECT
                s.id AS client_id,
                COALESCE(
                    NULLIF(s.company_name, ''),
                    NULLIF(s.ceo_name, ''),
                    CONCAT('Startup #', s.id)
                ) AS client_name,
                0 AS loan_limit,
                COALESCE(s.phone, '') AS phone,
                COALESCE(s.email, '') AS email,
                COALESCE(s.company_name, '') AS organisation
            FROM startups s
            WHERE s.id = ?
            LIMIT 1
        ";
        break;

    case 'organisation':
        $clientSql = "SELECT id AS client_id, company_name AS client_name, 0 AS loan_limit, phone, email, company_name AS organisation FROM organisations WHERE id = ? AND status = 'active' LIMIT 1";
        break;

    default:
        loanRespond(
            false,
            'Invalid client type selected.'
        );
}

$clientStatement = $conn->prepare($clientSql);

if (!$clientStatement) {
    error_log(
        '[New Loan] Client lookup prepare failed: ' .
        $conn->error
    );

    loanRespond(
        false,
        'The selected client could not be checked.',
        [],
        500
    );
}

$clientStatement->bind_param(
    'i',
    $clientId
);

if (!$clientStatement->execute()) {
    $error = $clientStatement->error;
    $clientStatement->close();

    error_log(
        '[New Loan] Client lookup failed: ' .
        $error
    );

    loanRespond(
        false,
        'The selected client could not be loaded.',
        [],
        500
    );
}

$clientResult = $clientStatement->get_result();

if (!$clientResult || $clientResult->num_rows === 0) {
    $clientStatement->close();

    loanRespond(
        false,
        'The selected client was not found.'
    );
}

$client = $clientResult->fetch_assoc();
$clientStatement->close();

$loanLimit = (float)($client['loan_limit'] ?? 0);

/*
|--------------------------------------------------------------------------
| Enforce loan limit
|--------------------------------------------------------------------------
*/
if (
    !in_array($clientType, ['startup', 'organisation'], true) &&
    $loanLimit > 0 &&
    $amount > $loanLimit
) {
    loanRespond(
        false,
        sprintf(
            'The requested amount exceeds the client loan limit of UGX %s.',
            number_format($loanLimit, 0)
        )
    );
}

/*
|--------------------------------------------------------------------------
| Detect optional loan table columns
|--------------------------------------------------------------------------
*/
$hasClientTypeColumn = loanTableHasColumn(
    $conn,
    'loan',
    'client_type'
);

$hasNotesColumn = loanTableHasColumn(
    $conn,
    'loan',
    'notes'
);

/*
|--------------------------------------------------------------------------
| Create loan
|--------------------------------------------------------------------------
*/
try {
    $conn->begin_transaction();

    /*
    |--------------------------------------------------------------------------
    | Lock and check for an existing open loan
    |--------------------------------------------------------------------------
    */
    $blockedStatuses = [
        'PENDING',
        'OUTSTANDING',
        'OVERDUE',
        'ACTIVE',
        'APPROVED',
    ];

    if ($hasClientTypeColumn) {
        $duplicateSql = "
            SELECT id, status
            FROM loan
            WHERE client_id = ?
              AND client_type = ?
              AND status IN (?, ?, ?, ?, ?)
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ";

        $duplicateValues = [
            $clientId,
            $clientType,
            $blockedStatuses[0],
            $blockedStatuses[1],
            $blockedStatuses[2],
            $blockedStatuses[3],
            $blockedStatuses[4],
        ];

        $duplicateTypes = 'issssss';
    } else {
        $duplicateSql = "
            SELECT id, status
            FROM loan
            WHERE client_id = ?
              AND status IN (?, ?, ?, ?, ?)
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ";

        $duplicateValues = [
            $clientId,
            $blockedStatuses[0],
            $blockedStatuses[1],
            $blockedStatuses[2],
            $blockedStatuses[3],
            $blockedStatuses[4],
        ];

        $duplicateTypes = 'isssss';
    }

    $duplicateStatement = $conn->prepare(
        $duplicateSql
    );

    if (!$duplicateStatement) {
        throw new RuntimeException(
            'The existing-loan check could not be prepared.'
        );
    }

    loanBindParams(
        $duplicateStatement,
        $duplicateTypes,
        $duplicateValues
    );

    if (!$duplicateStatement->execute()) {
        throw new RuntimeException(
            'The existing-loan check failed.'
        );
    }

    $duplicateResult = $duplicateStatement->get_result();

    if (
        $duplicateResult &&
        $duplicateResult->num_rows > 0
    ) {
        $existingLoan = $duplicateResult->fetch_assoc();
        $duplicateStatement->close();

        $conn->rollback();

        loanRespond(
            false,
            sprintf(
                'This client already has loan #%d with status %s.',
                (int)$existingLoan['id'],
                strtoupper((string)$existingLoan['status'])
            ),
            [
                'existing_loan_id' =>
                    (int)$existingLoan['id'],

                'existing_status' =>
                    strtoupper((string)$existingLoan['status']),
            ]
        );
    }

    $duplicateStatement->close();

    /*
    |--------------------------------------------------------------------------
    | Build INSERT statement
    |--------------------------------------------------------------------------
    */
    $insertColumns = [
        'amount',
        'interest_rate',
        'client_id',
    ];

    $insertTypes = 'ddi';

    $insertValues = [
        $amount,
        $interestRate,
        $clientId,
    ];

    if ($hasClientTypeColumn) {
        $insertColumns[] = 'client_type';
        $insertTypes    .= 's';
        $insertValues[]  = $clientType;
    }

    $insertColumns[] = 'status';
    $insertTypes    .= 's';
    $insertValues[]  = $status;

    $insertColumns[] = 'interest';
    $insertTypes    .= 'd';
    $insertValues[]  = $interest;

    $insertColumns[] = 'due_date';
    $insertTypes    .= 's';
    $insertValues[]  = $dueDate;

    $insertColumns[] = 'loan_balance';
    $insertTypes    .= 'd';
    $insertValues[]  = $loanBalance;

    $insertColumns[] = 'loan_period';
    $insertTypes    .= 'i';
    $insertValues[]  = $loanPeriod;

    $insertColumns[] = 'datetime';
    $insertTypes    .= 's';
    $insertValues[]  = $createdAt;

    if ($hasNotesColumn) {
        $insertColumns[] = 'notes';
        $insertTypes    .= 's';
        $insertValues[]  = $notes;
    }

    $placeholders = implode(
        ', ',
        array_fill(
            0,
            count($insertColumns),
            '?'
        )
    );

    $insertSql = sprintf(
        'INSERT INTO loan (%s) VALUES (%s)',
        implode(', ', $insertColumns),
        $placeholders
    );

    $insertStatement = $conn->prepare(
        $insertSql
    );

    if (!$insertStatement) {
        throw new RuntimeException(
            'The loan record could not be prepared: ' .
            $conn->error
        );
    }

    loanBindParams(
        $insertStatement,
        $insertTypes,
        $insertValues
    );

    if (!$insertStatement->execute()) {
        throw new RuntimeException(
            'The loan could not be saved: ' .
            $insertStatement->error
        );
    }

    $loanId = (int)$insertStatement->insert_id;

    $insertStatement->close();

    if ($loanId <= 0) {
        throw new RuntimeException(
            'The loan was not assigned a valid record number.'
        );
    }

    // Automatically assign the least-busy loan officer so the new request does
    // not sit unassigned in the reviewer queue.
    $assignedOfficerId = loan_finalize_new_request($conn, $loanId, $clientType);

    $conn->commit();

    loanRespond(
        true,
        'Loan successfully added and submitted for approval.',
        [
            'loan_id'       => $loanId,
            'client_type'   => $clientType,
            'client_id'     => $clientId,
            'client_name'   =>
                (string)($client['client_name'] ?? ''),

            'amount'        => $amount,
            'interest_rate' => $interestRate,
            'interest'      => $interest,
            'loan_balance'  => $loanBalance,
            'loan_period'   => $loanPeriod,
            'status'        => $status,
            'due_date'      => $dueDate,

           
           'redirect' => '/loans/admin/pending_loans.php',
        ],
        201
    );
} catch (Throwable $exception) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        error_log(
            '[New Loan] Rollback failed: ' .
            $rollbackException->getMessage()
        );
    }

    error_log(
        '[New Loan] ' .
        $exception->getMessage()
    );

    loanRespond(
        false,
        $exception->getMessage(),
        [],
        500
    );
}
