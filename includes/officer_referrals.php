<?php
declare(strict_types=1);

/*
 * Loan officer referrals.
 *
 * On the /apply sign-up form a client can say which loan officer referred
 * them (optional). It is stored on customers.referred_by (a user id, role 2).
 * Officers can also share /apply?ref=<their user id>, which pre-selects them.
 *
 * Admin > Loans > Officer Referrals ranks officers by what their referred
 * clients went on to do: sign up, apply, get a loan paid out, repay.
 */

/** Loan statuses that mean the money was paid out. */
const REFERRAL_DISBURSED_STATUSES = ['ACTIVE', 'OUTSTANDING', 'OVERDUE', 'CLEARED'];

function referral_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $res = $conn->query("SHOW COLUMNS FROM customers LIKE 'referred_by'");
    if ($res instanceof mysqli_result && $res->num_rows === 0) {
        $conn->query('ALTER TABLE customers ADD COLUMN referred_by INT NULL DEFAULT NULL, ADD INDEX idx_customers_referred_by (referred_by)');
    }
}

/** Active loan officers a client can pick, by name. */
function referral_officers(mysqli $conn): array
{
    $res = $conn->query('SELECT id, fname, lname FROM user WHERE role = 2 AND status = 1 ORDER BY fname, lname');
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** The officer id if it belongs to an active loan officer, otherwise null. */
function referral_valid_officer(mysqli $conn, $id): ?int
{
    $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        return null;
    }
    $s = $conn->prepare('SELECT id FROM user WHERE id = ? AND role = 2 AND status = 1 LIMIT 1');
    $s->bind_param('i', $id);
    $s->execute();
    $ok = (bool)$s->get_result()->fetch_assoc();
    $s->close();
    return $ok ? (int)$id : null;
}

/** "AND l.client_type ..." when the loan table records the client type, so a loan only counts for customers. */
function referral_loan_type_clause(mysqli $conn): string
{
    static $clause = null;
    if ($clause === null) {
        $res = $conn->query("SHOW COLUMNS FROM loan LIKE 'client_type'");
        $clause = ($res instanceof mysqli_result && $res->num_rows > 0)
            ? " AND LOWER(TRIM(COALESCE(l.client_type, ''))) IN ('', 'customer')"
            : '';
    }
    return $clause;
}

/**
 * Column tying a client to an officer: 'assigned' = the officer who owns the
 * client (customers.assigned_officer_id), 'referred' = the officer the client
 * named at sign-up (customers.referred_by).
 */
function referral_owner_column(mysqli $conn, string $basis): string
{
    if ($basis === 'assigned') {
        $res = $conn->query("SHOW COLUMNS FROM customers LIKE 'assigned_officer_id'");
        if ($res && $res->num_rows > 0) {
            return 'assigned_officer_id';
        }
    }
    return 'referred_by';
}

/**
 * One row per loan officer (and anyone else who has clients on $basis), for
 * clients who signed up between $from and $to (Y-m-d, inclusive; null = no limit).
 */
function referral_leaderboard(mysqli $conn, ?string $from, ?string $to, string $basis = 'referred'): array
{
    referral_ensure_schema($conn);
    $owner = referral_owner_column($conn, $basis);
    $paid = "'" . implode("','", REFERRAL_DISBURSED_STATUSES) . "'";
    $range = '';
    $types = '';
    $args = [];
    if ($from !== null) {
        $range .= ' AND c.created_at >= ?';
        $types .= 's';
        $args[] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $range .= ' AND c.created_at <= ?';
        $types .= 's';
        $args[] = $to . ' 23:59:59';
    }
    $loanType = referral_loan_type_clause($conn);

    $sql = "
        SELECT u.id, u.fname, u.lname, u.role, u.status,
            COUNT(DISTINCT c.user_id) AS clients,
            COUNT(DISTINCT CASE WHEN l.id IS NOT NULL THEN c.user_id END) AS clients_applied,
            COUNT(DISTINCT CASE WHEN UPPER(l.status) IN ($paid) THEN c.user_id END) AS clients_funded,
            COUNT(DISTINCT CASE WHEN UPPER(l.status) IN ($paid) THEN l.id END) AS loans_disbursed,
            COALESCE(SUM(CASE WHEN UPPER(l.status) IN ($paid) THEN l.amount END), 0) AS amount_disbursed,
            COUNT(DISTINCT CASE WHEN UPPER(l.status) = 'CLEARED' THEN l.id END) AS loans_cleared,
            COUNT(DISTINCT CASE WHEN UPPER(l.status) = 'OVERDUE' THEN l.id END) AS loans_overdue,
            COALESCE(SUM(CASE WHEN UPPER(l.status) = 'OVERDUE' THEN l.loan_balance END), 0) AS overdue_balance
        FROM user u
        LEFT JOIN customers c ON c.{$owner} = u.id $range
        LEFT JOIN loan l ON l.client_id = c.user_id
            AND UPPER(COALESCE(l.status, '')) NOT IN ('DELETED', '')
            $loanType
        WHERE u.role = 2 OR u.id IN (SELECT {$owner} FROM customers WHERE {$owner} IS NOT NULL AND {$owner} <> 0)
        GROUP BY u.id, u.fname, u.lname, u.role, u.status
    ";
    $s = $conn->prepare($sql);
    if ($args) {
        $s->bind_param($types, ...$args);
    }
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();

    foreach ($rows as &$r) {
        foreach (['clients', 'clients_applied', 'clients_funded', 'loans_disbursed', 'loans_cleared', 'loans_overdue'] as $k) {
            $r[$k] = (int)$r[$k];
        }
        $r['amount_disbursed'] = (float)$r['amount_disbursed'];
        $r['overdue_balance'] = (float)$r['overdue_balance'];
        // Share of referred clients who got a loan paid out.
        $r['conversion'] = $r['clients'] > 0 ? $r['clients_funded'] / $r['clients'] : 0.0;
        // Share of paid-out loans now overdue (lower is better).
        $r['overdue_rate'] = $r['loans_disbursed'] > 0 ? $r['loans_overdue'] / $r['loans_disbursed'] : 0.0;
    }
    unset($r);
    return $rows;
}

/** Sign-ups in the period with no officer on $basis (unassigned / unreferred). */
function referral_unreferred_count(mysqli $conn, ?string $from, ?string $to, string $basis = 'referred'): int
{
    referral_ensure_schema($conn);
    $owner = referral_owner_column($conn, $basis);
    $sql = "SELECT COUNT(*) AS n FROM customers c WHERE (c.{$owner} IS NULL OR c.{$owner} = 0)";
    $types = '';
    $args = [];
    if ($from !== null) {
        $sql .= ' AND c.created_at >= ?';
        $types .= 's';
        $args[] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $sql .= ' AND c.created_at <= ?';
        $types .= 's';
        $args[] = $to . ' 23:59:59';
    }
    $s = $conn->prepare($sql);
    if ($args) {
        $s->bind_param($types, ...$args);
    }
    $s->execute();
    $n = (int)($s->get_result()->fetch_assoc()['n'] ?? 0);
    $s->close();
    return $n;
}

/** One officer's clients (assigned or referred) in the period, with how their loans stand. */
function referral_clients_of(mysqli $conn, int $officerId, ?string $from, ?string $to, string $basis = 'referred'): array
{
    referral_ensure_schema($conn);
    $owner = referral_owner_column($conn, $basis);
    $paid = "'" . implode("','", REFERRAL_DISBURSED_STATUSES) . "'";
    $loanType = referral_loan_type_clause($conn);
    $hasOfficer = loan_table_has_column($conn, 'customers', 'assigned_officer_id');
    $officerCol = $hasOfficer ? 'c.assigned_officer_id' : 'NULL';
    $officerJoin = $hasOfficer ? 'LEFT JOIN user ao ON ao.id = c.assigned_officer_id' : '';
    $sql = "
        SELECT c.user_id, c.phone, c.business_name, c.created_at, u.fname, u.lname,
            {$officerCol} AS assigned_officer_id,
            " . ($hasOfficer ? "MAX(TRIM(CONCAT(COALESCE(ao.fname, ''), ' ', COALESCE(ao.lname, ''))))" : "''") . " AS assigned_officer_name,
            COUNT(DISTINCT l.id) AS loans,
            COALESCE(SUM(CASE WHEN UPPER(l.status) IN ($paid) THEN l.amount END), 0) AS disbursed,
            MAX(CASE WHEN UPPER(l.status) = 'OVERDUE' THEN 1 ELSE 0 END) AS has_overdue,
            SUBSTRING_INDEX(GROUP_CONCAT(UPPER(l.status) ORDER BY l.id DESC), ',', 1) AS latest_status
        FROM customers c
        JOIN user u ON u.id = c.user_id
        {$officerJoin}
        LEFT JOIN loan l ON l.client_id = c.user_id
            AND UPPER(COALESCE(l.status, '')) NOT IN ('DELETED', '')
            $loanType
        WHERE c.{$owner} = ?";
    $types = 'i';
    $args = [$officerId];
    if ($from !== null) {
        $sql .= ' AND c.created_at >= ?';
        $types .= 's';
        $args[] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $sql .= ' AND c.created_at <= ?';
        $types .= 's';
        $args[] = $to . ' 23:59:59';
    }
    $sql .= ' GROUP BY c.user_id, c.phone, c.business_name, c.created_at, u.fname, u.lname' . ($hasOfficer ? ', c.assigned_officer_id' : '') . ' ORDER BY c.created_at DESC';
    $s = $conn->prepare($sql);
    $s->bind_param($types, ...$args);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}
