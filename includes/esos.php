<?php
declare(strict_types=1);

/*
 * ESOs (Enterprise Support Organisations) and how a client says who referred
 * them.
 *
 * Admin > People > ESOs adds and edits ESOs. Active ones are listed on the
 * /apply sign-up form next to the loan officers under "Who referred you?",
 * with "Other" (the client types a name) and "None".
 *
 * Stored on customers:
 *   referral_source  'officer' | 'eso' | 'other' | 'none' (NULL = not asked)
 *   referred_by      loan officer user id (existing column, officer leaderboard)
 *   referred_eso_id  esos.id
 *   referral_other   the name typed for "Other"
 */

require_once __DIR__ . '/officer_referrals.php';

const REFERRAL_SOURCES = ['officer', 'eso', 'other', 'none'];

function esos_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS esos (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                contact_person VARCHAR(190) NOT NULL DEFAULT '',
                phone VARCHAR(40) NOT NULL DEFAULT '',
                email VARCHAR(190) NOT NULL DEFAULT '',
                location VARCHAR(190) NOT NULL DEFAULT '',
                services TEXT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_esos_status_name (status, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        referral_ensure_schema($conn);
        $columns = [
            'referral_source' => 'ADD COLUMN referral_source VARCHAR(20) NULL DEFAULT NULL',
            'referred_eso_id' => 'ADD COLUMN referred_eso_id INT UNSIGNED NULL DEFAULT NULL, ADD INDEX idx_customers_referred_eso (referred_eso_id)',
            'referral_other'  => 'ADD COLUMN referral_other VARCHAR(190) NULL DEFAULT NULL',
        ];
        foreach ($columns as $column => $ddl) {
            $res = $conn->query("SHOW COLUMNS FROM customers LIKE '{$column}'");
            if ($res instanceof mysqli_result && $res->num_rows === 0) {
                $conn->query("ALTER TABLE customers {$ddl}");
            }
        }
    } catch (Throwable $e) {
        error_log('ESO schema setup failed: ' . $e->getMessage());
    }
}

/** ESOs by name; only active ones unless $all. */
function esos_list(mysqli $conn, bool $all = false): array
{
    esos_ensure_schema($conn);
    try {
        $res = $conn->query('SELECT * FROM esos' . ($all ? '' : " WHERE status = 'active'") . ' ORDER BY name');
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable $e) {
        error_log('esos_list: ' . $e->getMessage());
        return [];
    }
}

function eso_find(mysqli $conn, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    esos_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM esos WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * Read the sign-up form's "Who referred you?" answer.
 * $choice is 'officer:<user id>', 'eso:<id>', 'other' or 'none'; anything
 * invalid (an inactive officer or ESO, an empty "Other") counts as not
 * answered rather than failing the sign-up.
 *
 * @return array{source: ?string, officer: ?int, eso: ?int, other: ?string}
 */
function referral_parse_choice(mysqli $conn, string $choice, string $otherText): array
{
    $none = ['source' => null, 'officer' => null, 'eso' => null, 'other' => null];
    $choice = trim($choice);

    // Older form / ?ref links sent a bare officer id.
    if (ctype_digit($choice)) {
        $choice = 'officer:' . $choice;
    }

    if (str_starts_with($choice, 'officer:')) {
        $officer = referral_valid_officer($conn, substr($choice, 8));
        return $officer !== null ? ['source' => 'officer', 'officer' => $officer] + $none : $none;
    }
    if (str_starts_with($choice, 'eso:')) {
        $eso = eso_find($conn, (int)substr($choice, 4));
        return ($eso && $eso['status'] === 'active') ? ['source' => 'eso', 'eso' => (int)$eso['id']] + $none : $none;
    }
    if ($choice === 'other') {
        $other = mb_substr(trim(preg_replace('/\s+/', ' ', $otherText)), 0, 190, 'UTF-8');
        return $other !== '' ? ['source' => 'other', 'other' => $other] + $none : $none;
    }
    if ($choice === 'none') {
        return ['source' => 'none'] + $none;
    }
    return $none;
}

/** Save a parsed referral on a customers row (customers.id). */
function referral_save(mysqli $conn, int $customerId, array $referral): void
{
    if ($referral['source'] === null) {
        return;
    }
    esos_ensure_schema($conn);
    $stmt = $conn->prepare('UPDATE customers SET referral_source = ?, referred_by = ?, referred_eso_id = ?, referral_other = ? WHERE id = ?');
    $stmt->bind_param('siisi', $referral['source'], $referral['officer'], $referral['eso'], $referral['other'], $customerId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Who referred a client (by user id), for profiles:
 * ['type' => 'Loan officer'|'ESO'|'Other'|'None'|'Not recorded', 'name' => string].
 */
function referral_describe(mysqli $conn, int $userId): array
{
    esos_ensure_schema($conn);
    try {
        $stmt = $conn->prepare("
            SELECT c.referral_source, c.referral_other,
                   TRIM(CONCAT(COALESCE(o.fname, ''), ' ', COALESCE(o.lname, ''))) AS officer_name,
                   e.name AS eso_name
            FROM customers c
            LEFT JOIN user o ON o.id = c.referred_by
            LEFT JOIN esos e ON e.id = c.referred_eso_id
            WHERE c.user_id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    } catch (Throwable $e) {
        error_log('referral_describe: ' . $e->getMessage());
        return ['type' => 'Not recorded', 'name' => ''];
    }

    $officer = trim((string)($row['officer_name'] ?? ''));
    return match ((string)($row['referral_source'] ?? '')) {
        'eso'   => ['type' => 'ESO', 'name' => (string)($row['eso_name'] ?? '')],
        'other' => ['type' => 'Other', 'name' => (string)($row['referral_other'] ?? '')],
        'none'  => ['type' => 'None', 'name' => ''],
        // Clients from before this change have only referred_by.
        default => $officer !== '' ? ['type' => 'Loan officer', 'name' => $officer] : ['type' => 'Not recorded', 'name' => ''],
    };
}

/** Clients each ESO referred, keyed by ESO id. */
function eso_client_counts(mysqli $conn): array
{
    esos_ensure_schema($conn);
    $counts = [];
    try {
        $res = $conn->query("SELECT referred_eso_id AS id, COUNT(*) AS n FROM customers WHERE referral_source = 'eso' AND referred_eso_id IS NOT NULL GROUP BY referred_eso_id");
        while ($res && ($row = $res->fetch_assoc())) {
            $counts[(int)$row['id']] = (int)$row['n'];
        }
    } catch (Throwable $e) {
        error_log('eso_client_counts: ' . $e->getMessage());
    }
    return $counts;
}
