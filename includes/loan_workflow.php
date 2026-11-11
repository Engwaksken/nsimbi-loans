<?php
declare(strict_types=1);

/**
 * Shared loan workflow helpers.
 *
 * New loan requests must:
 *  - be recorded with the correct client type (customer, member or startup),
 *  - be assigned to a loan officer automatically, and
 *  - remain PENDING until a reviewer approves them.
 *
 * The helpers below are defensive: they check for optional columns first so an
 * older database can still create loans instead of throwing a fatal error.
 */

function loan_table_has_column(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];

    $key = strtolower($table . '.' . $column);

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return $cache[$key] = false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $cache[$key] = $exists;
}

/**
 * Detect what kind of client a loan belongs to.
 * Only used when the caller does not already know the type.
 */
function loan_resolve_client_type(mysqli $conn, int $clientId): string
{
    if ($clientId <= 0) {
        return 'unknown';
    }

    $checks = [
        'customer' => ["SELECT 1 FROM customers WHERE user_id = ? LIMIT 1", 'user_id'],
        'member'   => ["SELECT 1 FROM members WHERE user_id = ? LIMIT 1", 'user_id'],
        'startup'  => ["SELECT 1 FROM startups WHERE %s = ? LIMIT 1", 'startup_key'],
    ];

    foreach ($checks as $type => $check) {
        [$sql, $kind] = $check;

        if ($kind === 'startup_key') {
            $column = loan_table_has_column($conn, 'startups', 'startup_id')
                ? 'startup_id'
                : (loan_table_has_column($conn, 'startups', 'id') ? 'id' : '');

            if ($column === '') {
                continue;
            }

            $sql = sprintf($sql, $column);
        }

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            continue;
        }

        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($found) {
            return $type;
        }
    }

    return 'unknown';
}

/**
 * Assign a loan to the loan officer with the smallest active workload.
 * Existing manual assignments are never overwritten.
 *
 * @return int The officer id now assigned to the loan (0 when none exists).
 */
function loan_assign_officer(mysqli $conn, int $loanId): int
{
    if ($loanId <= 0) {
        return 0;
    }

    if (!loan_table_has_column($conn, 'loan', 'assigned_officer_id')) {
        return 0;
    }

    $activeStatuses = "'PENDING','INFO_REQUESTED','INFO_PROVIDED','OUTSTANDING','OVERDUE','ACTIVE','APPROVED'";
    $userStatusSql = loan_table_has_column($conn, 'user', 'status')
        ? ' AND COALESCE(u.status, 1) = 1'
        : '';

    $stmt = $conn->prepare("
        SELECT u.id
        FROM user u
        LEFT JOIN loan l
            ON l.assigned_officer_id = u.id
           AND UPPER(TRIM(COALESCE(l.status, ''))) IN ({$activeStatuses})
        WHERE u.role = 2{$userStatusSql}
        GROUP BY u.id
        ORDER BY COUNT(l.id) ASC, u.id ASC
        LIMIT 1
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $officerId = (int)($row['id'] ?? 0);

    if ($officerId <= 0) {
        return 0;
    }

    $update = $conn->prepare("
        UPDATE loan
        SET assigned_officer_id = ?
        WHERE id = ?
          AND (assigned_officer_id IS NULL OR assigned_officer_id = 0)
    ");

    if (!$update) {
        return 0;
    }

    $update->bind_param('ii', $officerId, $loanId);
    $update->execute();
    $assigned = $update->affected_rows > 0;
    $update->close();

    if ($assigned) {
        return $officerId;
    }

    // If the loan already had an officer, return that officer instead.
    $current = $conn->prepare("SELECT assigned_officer_id FROM loan WHERE id = ? LIMIT 1");

    if (!$current) {
        return 0;
    }

    $current->bind_param('i', $loanId);
    $current->execute();
    $existing = $current->get_result()->fetch_assoc();
    $current->close();

    return (int)($existing['assigned_officer_id'] ?? 0);
}

/**
 * Assign any pending loan requests that were created before automatic
 * assignment was enabled. Safe to call on every page load: once every loan
 * has an owner the lookup returns no rows.
 */
function loan_backfill_pending_assignments(mysqli $conn, int $limit = 200): int
{
    if (!loan_table_has_column($conn, 'loan', 'assigned_officer_id')) {
        return 0;
    }

    $limit = max(1, min(1000, $limit));

    $stmt = $conn->prepare("
        SELECT id
        FROM loan
        WHERE UPPER(TRIM(COALESCE(status, ''))) = 'PENDING'
          AND (assigned_officer_id IS NULL OR assigned_officer_id = 0)
        ORDER BY id ASC
        LIMIT {$limit}
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];

    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }

    $stmt->close();

    $assigned = 0;

    foreach ($ids as $loanId) {
        if (loan_assign_officer($conn, $loanId) > 0) {
            $assigned++;
        }
    }

    return $assigned;
}

/**
 * Finish setting up a newly created loan request.
 *
 * @param string $clientType customer|member|startup
 * @return int Assigned officer id (0 when no officer could be assigned).
 */
function loan_finalize_new_request(mysqli $conn, int $loanId, string $clientType = ''): int
{
    if ($loanId <= 0) {
        return 0;
    }

    $clientType = strtolower(trim($clientType));

    if (!in_array($clientType, ['customer', 'member', 'startup'], true)) {
        $clientType = '';
    }

    if ($clientType !== '' && loan_table_has_column($conn, 'loan', 'client_type')) {
        $update = $conn->prepare("
            UPDATE loan
            SET client_type = ?
            WHERE id = ?
              AND (client_type IS NULL OR TRIM(client_type) = '')
        ");

        if ($update) {
            $update->bind_param('si', $clientType, $loanId);
            $update->execute();
            $update->close();
        }
    }

    return loan_assign_officer($conn, $loanId);
}
