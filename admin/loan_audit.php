<?php
declare(strict_types=1);

function logLoanAudit(
    mysqli $conn,
    int $loanId,
    int $actorId,
    int $actorRole,
    string $action,
    ?string $comments = null,
    ?string $reason = null,
    ?string $oldStatus = null,
    ?string $newStatus = null
): bool {
    $stmt = $conn->prepare("
        INSERT INTO loan_audit_trail
            (
                loan_id,
                actor_id,
                actor_role,
                action,
                comments,
                reason,
                old_status,
                new_status
            )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log('logLoanAudit prepare failed: ' . $conn->error);
        return false;
    }

    /*
     * 8 variables:
     * i = loanId
     * i = actorId
     * i = actorRole
     * s = action
     * s = comments
     * s = reason
     * s = oldStatus
     * s = newStatus
     */
    $stmt->bind_param(
        'iiisssss',
        $loanId,
        $actorId,
        $actorRole,
        $action,
        $comments,
        $reason,
        $oldStatus,
        $newStatus
    );

    if (!$stmt->execute()) {
        error_log('logLoanAudit execute failed: ' . $stmt->error);
        $stmt->close();
        return false;
    }

    $stmt->close();

    return true;
}


function getLoanAuditTrail(mysqli $conn, int $loanId): array
{
    $stmt = $conn->prepare("
        SELECT
            at.*,
            u.fname,
            u.lname
        FROM loan_audit_trail AS at
        LEFT JOIN user AS u
            ON u.id = at.actor_id
        WHERE at.loan_id = ?
        ORDER BY at.created_at DESC, at.id DESC
    ");

    if (!$stmt) {
        error_log('getLoanAuditTrail prepare failed: ' . $conn->error);
        return [];
    }

    $stmt->bind_param('i', $loanId);

    if (!$stmt->execute()) {
        error_log('getLoanAuditTrail execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}


function getLoanAuditTrailBulk(mysqli $conn, array $loanIds): array
{
    $loanIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $loanIds),
                static fn(int $id): bool => $id > 0
            )
        )
    );

    if ($loanIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($loanIds), '?'));
    $types = str_repeat('i', count($loanIds));

    $stmt = $conn->prepare("
        SELECT
            at.*,
            u.fname,
            u.lname
        FROM loan_audit_trail AS at
        LEFT JOIN user AS u
            ON u.id = at.actor_id
        WHERE at.loan_id IN ($placeholders)
        ORDER BY at.created_at DESC, at.id DESC
    ");

    if (!$stmt) {
        error_log('getLoanAuditTrailBulk prepare failed: ' . $conn->error);
        return [];
    }

    $stmt->bind_param($types, ...$loanIds);

    if (!$stmt->execute()) {
        error_log('getLoanAuditTrailBulk execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $grouped = [];

    while ($row = $result->fetch_assoc()) {
        $loanId = (int) $row['loan_id'];
        $grouped[$loanId][] = $row;
    }

    $stmt->close();

    return $grouped;
}


function notifyUser(
    mysqli $conn,
    int $userId,
    int $loanId,
    string $messageType,
    string $message
): bool {
    $stmt = $conn->prepare("
        INSERT INTO loan_messages
            (
                user_id,
                loan_id,
                message_type,
                message,
                is_read
            )
        VALUES (?, ?, ?, ?, 0)
    ");

    if (!$stmt) {
        error_log('notifyUser prepare failed: ' . $conn->error);
        return false;
    }

    $stmt->bind_param(
        'iiss',
        $userId,
        $loanId,
        $messageType,
        $message
    );

    if (!$stmt->execute()) {
        error_log('notifyUser execute failed: ' . $stmt->error);
        $stmt->close();
        return false;
    }

    $stmt->close();

    return true;
}


function logTopupAudit(
    mysqli $conn,
    int $loanId,
    int $topupId,
    int $actorId,
    int $actorRole,
    string $action,
    ?string $comments = null,
    ?string $reason = null,
    ?string $oldStatus = null,
    ?string $newStatus = null
): bool {
    $stmt = $conn->prepare("
        INSERT INTO loan_audit_trail
            (
                loan_id,
                topup_id,
                actor_id,
                actor_role,
                action,
                comments,
                reason,
                old_status,
                new_status
            )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log('logTopupAudit prepare failed: ' . $conn->error);
        return false;
    }

    /*
     * 9 variables:
     * i = loanId
     * i = topupId
     * i = actorId
     * i = actorRole
     * s = action
     * s = comments
     * s = reason
     * s = oldStatus
     * s = newStatus
     */
    $stmt->bind_param(
        'iiiisssss',
        $loanId,
        $topupId,
        $actorId,
        $actorRole,
        $action,
        $comments,
        $reason,
        $oldStatus,
        $newStatus
    );

    if (!$stmt->execute()) {
        error_log('logTopupAudit execute failed: ' . $stmt->error);
        $stmt->close();
        return false;
    }

    $stmt->close();

    return true;
}


function getTopupAuditTrail(mysqli $conn, int $topupId): array
{
    $stmt = $conn->prepare("
        SELECT
            at.*,
            u.fname,
            u.lname
        FROM loan_audit_trail AS at
        LEFT JOIN user AS u
            ON u.id = at.actor_id
        WHERE at.topup_id = ?
        ORDER BY at.created_at DESC, at.id DESC
    ");

    if (!$stmt) {
        error_log('getTopupAuditTrail prepare failed: ' . $conn->error);
        return [];
    }

    $stmt->bind_param('i', $topupId);

    if (!$stmt->execute()) {
        error_log('getTopupAuditTrail execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}


function getTopupAuditTrailBulk(mysqli $conn, array $topupIds): array
{
    $topupIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $topupIds),
                static fn(int $id): bool => $id > 0
            )
        )
    );

    if ($topupIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($topupIds), '?'));
    $types = str_repeat('i', count($topupIds));

    $stmt = $conn->prepare("
        SELECT
            at.*,
            u.fname,
            u.lname
        FROM loan_audit_trail AS at
        LEFT JOIN user AS u
            ON u.id = at.actor_id
        WHERE at.topup_id IN ($placeholders)
        ORDER BY at.created_at DESC, at.id DESC
    ");

    if (!$stmt) {
        error_log('getTopupAuditTrailBulk prepare failed: ' . $conn->error);
        return [];
    }

    $stmt->bind_param($types, ...$topupIds);

    if (!$stmt->execute()) {
        error_log('getTopupAuditTrailBulk execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $grouped = [];

    while ($row = $result->fetch_assoc()) {
        $topupId = (int) $row['topup_id'];
        $grouped[$topupId][] = $row;
    }

    $stmt->close();

    return $grouped;
}


function getActiveTopupsByLoan(mysqli $conn, array $loanIds): array
{
    $loanIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $loanIds),
                static fn(int $id): bool => $id > 0
            )
        )
    );

    if ($loanIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($loanIds), '?'));
    $types = str_repeat('i', count($loanIds));

    $stmt = $conn->prepare("
        SELECT
            t.*,
            o.fname AS officer_fname,
            o.lname AS officer_lname
        FROM loan_topup_requests AS t
        LEFT JOIN user AS o
            ON o.id = t.assigned_officer_id
        WHERE t.loan_id IN ($placeholders)
          AND t.status NOT IN ('REJECTED', 'DISBURSED')
        ORDER BY t.created_at DESC
    ");

    if (!$stmt) {
        error_log('getActiveTopupsByLoan prepare failed: ' . $conn->error);
        return [];
    }

    $stmt->bind_param($types, ...$loanIds);

    if (!$stmt->execute()) {
        error_log('getActiveTopupsByLoan execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $byLoan = [];

    while ($row = $result->fetch_assoc()) {
        $loanId = (int) $row['loan_id'];

        // Keep only the newest active top-up request for each loan.
        if (!isset($byLoan[$loanId])) {
            $byLoan[$loanId] = $row;
        }
    }

    $stmt->close();

    return $byLoan;
}


function getLoanOfficers(mysqli $conn): array
{
    $result = $conn->query("
        SELECT
            id,
            fname,
            lname
        FROM user
        WHERE role = 2
        ORDER BY fname ASC, lname ASC
    ");

    if (!$result) {
        error_log('getLoanOfficers query failed: ' . $conn->error);
        return [];
    }

    $officers = [];

    while ($row = $result->fetch_assoc()) {
        $officers[] = $row;
    }

    $result->free();

    return $officers;
}