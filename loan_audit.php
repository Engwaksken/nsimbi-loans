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
            (loan_id, actor_id, actor_role, action, comments, reason, old_status, new_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "iiissss",
        $loanId,
        $actorId,
        $actorRole,
        $action,
        $comments,
        $reason,
        $oldStatus,
        $newStatus
    );

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}


function getLoanAuditTrail(mysqli $conn, int $loanId): array
{
    $stmt = $conn->prepare("
        SELECT at.*, u.fname, u.lname
        FROM loan_audit_trail at
        LEFT JOIN user u ON u.id = at.actor_id
        WHERE at.loan_id = ?
        ORDER BY at.created_at DESC, at.id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $loanId);
    $stmt->execute();
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
    $loanIds = array_values(array_unique(array_map('intval', $loanIds)));

    if (empty($loanIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($loanIds), '?'));
    $types = str_repeat('i', count($loanIds));

    $stmt = $conn->prepare("
        SELECT at.*, u.fname, u.lname
        FROM loan_audit_trail at
        LEFT JOIN user u ON u.id = at.actor_id
        WHERE at.loan_id IN ($placeholders)
        ORDER BY at.created_at DESC, at.id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$loanIds);
    $stmt->execute();
    $result = $stmt->get_result();

    $grouped = [];
    while ($row = $result->fetch_assoc()) {
        $grouped[(int)$row['loan_id']][] = $row;
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
        INSERT INTO loan_messages (user_id, loan_id, message_type, message, is_read)
        VALUES (?, ?, ?, ?, 0)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("iiss", $userId, $loanId, $messageType, $message);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
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
            (loan_id, topup_id, actor_id, actor_role, action, comments, reason, old_status, new_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "iiiissss",
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

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}


function getTopupAuditTrail(mysqli $conn, int $topupId): array
{
    $stmt = $conn->prepare("
        SELECT at.*, u.fname, u.lname
        FROM loan_audit_trail at
        LEFT JOIN user u ON u.id = at.actor_id
        WHERE at.topup_id = ?
        ORDER BY at.created_at DESC, at.id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $topupId);
    $stmt->execute();
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
    $topupIds = array_values(array_unique(array_map('intval', $topupIds)));

    if (empty($topupIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($topupIds), '?'));
    $types = str_repeat('i', count($topupIds));

    $stmt = $conn->prepare("
        SELECT at.*, u.fname, u.lname
        FROM loan_audit_trail at
        LEFT JOIN user u ON u.id = at.actor_id
        WHERE at.topup_id IN ($placeholders)
        ORDER BY at.created_at DESC, at.id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$topupIds);
    $stmt->execute();
    $result = $stmt->get_result();

    $grouped = [];
    while ($row = $result->fetch_assoc()) {
        $grouped[(int)$row['topup_id']][] = $row;
    }

    $stmt->close();

    return $grouped;
}


function getActiveTopupsByLoan(mysqli $conn, array $loanIds): array
{
    $loanIds = array_values(array_unique(array_map('intval', $loanIds)));

    if (empty($loanIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($loanIds), '?'));
    $types = str_repeat('i', count($loanIds));

    $stmt = $conn->prepare("
        SELECT t.*, o.fname AS officer_fname, o.lname AS officer_lname
        FROM loan_topups t
        LEFT JOIN user o ON o.id = t.assigned_officer_id
        WHERE t.loan_id IN ($placeholders)
          AND t.status NOT IN ('REJECTED', 'DISBURSED')
        ORDER BY t.created_at DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$loanIds);
    $stmt->execute();
    $result = $stmt->get_result();

    $byLoan = [];
    while ($row = $result->fetch_assoc()) {
        $loanId = (int)$row['loan_id'];
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
        SELECT id, fname, lname
        FROM user
        WHERE role = 2
        ORDER BY fname, lname
    ");

    if (!$result) {
        return [];
    }

    $officers = [];
    while ($row = $result->fetch_assoc()) {
        $officers[] = $row;
    }

    return $officers;
}