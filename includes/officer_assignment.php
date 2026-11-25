<?php
declare(strict_types=1);

/**
 * Loan officers own clients by district.
 *
 * A client who named a referring officer at sign-up (customers.referred_by,
 * Officer Referrals) belongs to that officer while they are an active loan
 * officer. Everyone else follows the district rules.
 *
 * Rules (Loans > Loan Settings, stored in app_settings 'officer_district_rules'):
 *   {"districts": {"Mbarara": 12}, "default": 15}
 * A client in a listed district goes to that officer; everyone else goes to
 * the default officer. The district comes from the client's district field,
 * or a known district found in their address (includes/districts.php).
 *
 * Each client (customers, members, startups, organisations) has
 * assigned_officer_id, and each loan has loan.assigned_officer_id = the
 * client's officer. Loan officers (role 2) only see their own clients and
 * loans; administrators see everything.
 */

require_once __DIR__ . '/loan_offers.php';   // app_setting_get/set
require_once __DIR__ . '/loan_workflow.php';  // loan_table_has_column, loan_resolve_client_type
require_once __DIR__ . '/districts.php';
require_once __DIR__ . '/organisations.php';

const OFFICER_ROLE = 2;
const OFFICER_CLIENT_TABLES = [
    'customer'     => 'customers',
    'member'       => 'members',
    'startup'      => 'startups',
    'organisation' => 'organisations',
];

/* ---------------------------------------------------------------------------
 | Schema
 * ------------------------------------------------------------------------- */

function officer_assignment_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    organisations_ensure_table($conn);
    foreach (OFFICER_CLIENT_TABLES as $table) {
        try {
            $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'assigned_officer_id'");
            if ($res && $res->num_rows === 0) {
                $conn->query("ALTER TABLE `{$table}` ADD COLUMN assigned_officer_id INT NULL, ADD KEY idx_{$table}_officer (assigned_officer_id)");
            }
        } catch (Throwable $e) {
            error_log("Adding {$table}.assigned_officer_id failed: " . $e->getMessage());
        }
    }
}

/* ---------------------------------------------------------------------------
 | Rules
 * ------------------------------------------------------------------------- */

/** @return array{districts: array<string,int>, default: int} */
function officer_rules(mysqli $conn): array
{
    $raw = json_decode(app_setting_get($conn, 'officer_district_rules', ''), true);
    $rules = ['districts' => [], 'default' => 0];
    if (is_array($raw)) {
        foreach ((array)($raw['districts'] ?? []) as $district => $officerId) {
            $name = location_district((string)$district);
            if ($name !== '' && (int)$officerId > 0) {
                $rules['districts'][$name] = (int)$officerId;
            }
        }
        $rules['default'] = max(0, (int)($raw['default'] ?? 0));
    }
    return $rules;
}

function officer_rules_configured(array $rules): bool
{
    return $rules['default'] > 0 || $rules['districts'];
}

function officer_rules_save(mysqli $conn, array $rules, int $actorId): void
{
    app_setting_set($conn, 'officer_district_rules', (string)json_encode([
        'districts' => (object)$rules['districts'],
        'default'   => (int)$rules['default'],
    ]), $actorId);
}

/** Active loan officers: [id => 'First Last']. */
function officer_list(mysqli $conn): array
{
    $statusSql = loan_table_has_column($conn, 'user', 'status') ? ' AND COALESCE(status, 1) = 1' : '';
    $result = $conn->query('SELECT id, fname, lname FROM user WHERE role = ' . OFFICER_ROLE . $statusSql . ' ORDER BY fname, lname');
    $list = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $list[(int)$row['id']] = trim((string)$row['fname'] . ' ' . (string)$row['lname']) ?: 'Officer #' . $row['id'];
    }
    return $list;
}

/** Officer id by full name (case-insensitive), 0 when none or more than one match. */
function officer_find_by_name(mysqli $conn, string $fullName): int
{
    $want = strtolower(preg_replace('/\s+/', ' ', trim($fullName)));
    $found = [];
    foreach (officer_list($conn) as $id => $name) {
        if (strtolower(preg_replace('/\s+/', ' ', $name)) === $want) {
            $found[] = $id;
        }
    }
    return count($found) === 1 ? $found[0] : 0;
}

/**
 * Officer for a client row (from officer_client_rows): their referring
 * officer while active, otherwise the district rules.
 */
function officer_for_client_row(mysqli $conn, array $rules, array $row): int
{
    static $active = null;
    $active ??= officer_list($conn);
    $referrer = (int)($row['referred_by'] ?? 0);
    if ($referrer > 0 && isset($active[$referrer])) {
        return $referrer;
    }
    return officer_for_place($rules, (string)$row['district'], (string)$row['location']);
}

/** Officer for a place under the rules (0 when no rule applies). */
function officer_for_place(array $rules, string $district, string $location = ''): int
{
    $name = location_district($district, $location);
    if ($name !== '' && isset($rules['districts'][$name])) {
        return $rules['districts'][$name];
    }
    return $rules['default'];
}

/* ---------------------------------------------------------------------------
 | Clients and loans
 * ------------------------------------------------------------------------- */

/** Rows of one client table with their place: [id, district, location, assigned_officer_id]. */
function officer_client_rows(mysqli $conn, string $kind, string $where = '1 = 1', int $limit = 0): array
{
    // Before the columns exist (first run), treat everyone as unassigned.
    $officerCol = officer_has_column($conn, $kind) ? 'assigned_officer_id' : 'NULL AS assigned_officer_id';
    if (!officer_has_column($conn, $kind)) {
        $where = str_replace(['(assigned_officer_id IS NULL OR assigned_officer_id = 0)'], ['1 = 1'], $where);
    }
    $referredCol = $kind === 'customer' && loan_table_has_column($conn, 'customers', 'referred_by')
        ? 'referred_by' : 'NULL AS referred_by';
    $sql = match ($kind) {
        'customer'     => "SELECT user_id AS id, COALESCE(district, '') AS district, COALESCE(location, '') AS location, {$officerCol}, {$referredCol} FROM customers",
        'member'       => "SELECT user_id AS id, '' AS district, COALESCE(home_address, '') AS location, {$officerCol}, NULL AS referred_by FROM members",
        'startup'      => "SELECT id, '' AS district, COALESCE(company_location, '') AS location, {$officerCol}, NULL AS referred_by FROM startups",
        'organisation' => "SELECT id, '' AS district, COALESCE(company_location, '') AS location, {$officerCol}, NULL AS referred_by FROM organisations",
    };
    $sql .= ' WHERE ' . $where . ($limit > 0 ? ' LIMIT ' . $limit : '');
    try {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable $e) {
        error_log("officer_client_rows({$kind}) failed: " . $e->getMessage());
        return [];
    }
}

/** Whether this client table has assigned_officer_id yet. */
function officer_has_column(mysqli $conn, string $kind): bool
{
    static $known = [];
    if (!isset($known[$kind])) {
        try {
            $res = $conn->query('SHOW COLUMNS FROM `' . OFFICER_CLIENT_TABLES[$kind] . "` LIKE 'assigned_officer_id'");
            $known[$kind] = $res && $res->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }
        if (!$known[$kind]) {
            unset($known[$kind]); // look again: the column may be added later this request
            return false;
        }
    }
    return true;
}

function officer_set_client(mysqli $conn, string $kind, int $clientId, int $officerId): void
{
    if (!officer_has_column($conn, $kind)) {
        return;
    }
    $table = OFFICER_CLIENT_TABLES[$kind];
    $key = in_array($kind, ['customer', 'member'], true) ? 'user_id' : 'id';
    $stmt = $conn->prepare("UPDATE `{$table}` SET assigned_officer_id = ? WHERE {$key} = ?");
    $stmt->bind_param('ii', $officerId, $clientId);
    $stmt->execute();
    $stmt->close();
}

/** Which client a loan belongs to: [kind, id] (kind '' when unknown). */
function officer_loan_client(mysqli $conn, array $loan): array
{
    if ((int)($loan['organisation_id'] ?? 0) > 0) {
        return ['organisation', (int)$loan['organisation_id']];
    }
    $clientId = (int)($loan['client_id'] ?? 0);
    $type = strtolower(trim((string)($loan['client_type'] ?? '')));
    if (!in_array($type, ['customer', 'member', 'startup'], true)) {
        $type = loan_resolve_client_type($conn, $clientId);
    }
    if ($type === 'startup') {
        // Loans store startups.id, or startups.startup_id on older records.
        $stmt = $conn->prepare('SELECT id FROM startups WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row && loan_table_has_column($conn, 'startups', 'startup_id')) {
            $stmt = $conn->prepare('SELECT id FROM startups WHERE startup_id = ? LIMIT 1');
            $stmt->bind_param('i', $clientId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        return $row ? ['startup', (int)$row['id']] : ['', 0];
    }
    return in_array($type, ['customer', 'member'], true) ? [$type, $clientId] : ['', 0];
}

/** The client's officer, assigning one by the rules when missing. 0 when none applies. */
function officer_of_client(mysqli $conn, string $kind, int $clientId, ?array $rules = null): int
{
    if ($kind === '' || $clientId <= 0) {
        return 0;
    }
    $key = in_array($kind, ['customer', 'member'], true) ? 'user_id' : 'id';
    $rows = officer_client_rows($conn, $kind, "{$key} = " . $clientId, 1);
    if (!$rows) {
        return 0;
    }
    if ((int)$rows[0]['assigned_officer_id'] > 0) {
        return (int)$rows[0]['assigned_officer_id'];
    }
    $officerId = officer_for_client_row($conn, $rules ?? officer_rules($conn), $rows[0]);
    if ($officerId > 0) {
        officer_set_client($conn, $kind, $clientId, $officerId);
    }
    return $officerId;
}

/**
 * Assign a loan to its client's officer. Returns the officer id, or 0 when no
 * rule applies (the caller can then fall back to another method).
 */
function officer_assign_loan(mysqli $conn, int $loanId, bool $overwrite = false): int
{
    // No schema changes here: this runs inside loan transactions.
    $rules = officer_rules($conn);
    if (!officer_rules_configured($rules)) {
        return 0;
    }
    $stmt = $conn->prepare('SELECT * FROM loan WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $loan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$loan) {
        return 0;
    }
    if (!$overwrite && (int)($loan['assigned_officer_id'] ?? 0) > 0) {
        return (int)$loan['assigned_officer_id'];
    }
    [$kind, $clientId] = officer_loan_client($conn, $loan);
    $officerId = officer_of_client($conn, $kind, $clientId, $rules) ?: $rules['default'];
    if ($officerId > 0) {
        $stmt = $conn->prepare('UPDATE loan SET assigned_officer_id = ? WHERE id = ?');
        $stmt->bind_param('ii', $officerId, $loanId);
        $stmt->execute();
        $stmt->close();
    }
    return $officerId;
}

/**
 * Assign clients and loans that have no officer yet (new sign-ups from any
 * route). With $overwrite every client and loan is reassigned by the rules.
 * @return array{clients: int, loans: int}
 */
function officer_sync(mysqli $conn, bool $overwrite = false): array
{
    officer_assignment_ensure_schema($conn);
    $rules = officer_rules($conn);
    $counts = ['clients' => 0, 'loans' => 0];
    if (!officer_rules_configured($rules)) {
        return $counts;
    }
    $where = $overwrite ? '1 = 1' : '(assigned_officer_id IS NULL OR assigned_officer_id = 0)';
    foreach (array_keys(OFFICER_CLIENT_TABLES) as $kind) {
        foreach (officer_client_rows($conn, $kind, $where, $overwrite ? 0 : 500) as $row) {
            $officerId = officer_for_client_row($conn, $rules, $row);
            if ($officerId > 0 && $officerId !== (int)$row['assigned_officer_id']) {
                officer_set_client($conn, $kind, (int)$row['id'], $officerId);
                $counts['clients']++;
            }
        }
    }
    $result = $conn->query('SELECT id FROM loan WHERE ' . ($overwrite ? '1 = 1' : '(assigned_officer_id IS NULL OR assigned_officer_id = 0)')
        . ($overwrite ? '' : ' LIMIT 500'));
    while ($result && ($row = $result->fetch_assoc())) {
        if (officer_assign_loan($conn, (int)$row['id'], $overwrite) > 0) {
            $counts['loans']++;
        }
    }
    return $counts;
}

/**
 * First setup: Mbarara to Ruth Musiimenta, every other district to Esther
 * Kayondo, then reassign every existing client and loan once. Skipped (and
 * left for Loan Settings) when either officer can't be found by name.
 */
function officer_assignment_bootstrap(mysqli $conn): void
{
    if (app_setting_get($conn, 'officer_assignment_bootstrap', '') !== '') {
        return;
    }
    $ruth = officer_find_by_name($conn, 'Ruth Musiimenta');
    $esther = officer_find_by_name($conn, 'Esther Kayondo');
    if ($ruth <= 0 || $esther <= 0) {
        app_setting_set($conn, 'officer_assignment_bootstrap', 'officers-not-found', 0);
        error_log('Officer assignment: Ruth Musiimenta or Esther Kayondo not found as a loan officer; set the rules in Loan Settings.');
        return;
    }
    if (!officer_rules_configured(officer_rules($conn))) {
        officer_rules_save($conn, ['districts' => ['Mbarara' => $ruth], 'default' => $esther], 0);
    }
    $counts = officer_sync($conn, true);
    app_setting_set($conn, 'officer_assignment_bootstrap', 'done ' . date('Y-m-d H:i') . " ({$counts['clients']} clients, {$counts['loans']} loans)", 0);
}

/**
 * Once: clients who named a referring officer are moved (with their loans)
 * to that officer. Later sign-ups get their referrer through officer_sync().
 */
function officer_apply_referrals_once(mysqli $conn): void
{
    if (app_setting_get($conn, 'officer_referral_assignment', '') !== ''
        || !loan_table_has_column($conn, 'customers', 'referred_by')
        || !officer_has_column($conn, 'customer')) {
        return;
    }
    $active = officer_list($conn);
    $moved = 0;
    $loans = 0;
    $result = $conn->query('SELECT user_id, referred_by FROM customers WHERE referred_by IS NOT NULL AND referred_by > 0');
    while ($result && ($row = $result->fetch_assoc())) {
        $officerId = (int)$row['referred_by'];
        $userId = (int)$row['user_id'];
        if (!isset($active[$officerId])) {
            continue;
        }
        officer_set_client($conn, 'customer', $userId, $officerId);
        // Their loans follow (officer_assign_loan resolves whose loan it is).
        $stmt = $conn->prepare('SELECT id FROM loan WHERE client_id = ? AND organisation_id IS NULL');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $stmt->close();
        foreach ($ids as $loanId) {
            $loanRow = (array)$conn->query('SELECT * FROM loan WHERE id = ' . (int)$loanId)->fetch_assoc();
            [$kind, $ownerId] = officer_loan_client($conn, $loanRow);
            if ($kind === 'customer' && $ownerId === $userId && officer_assign_loan($conn, (int)$loanId, true) === $officerId) {
                $loans++;
            }
        }
        $moved++;
    }
    app_setting_set($conn, 'officer_referral_assignment', 'done ' . date('Y-m-d H:i') . " ({$moved} clients, {$loans} loans)", 0);
}

/* ---------------------------------------------------------------------------
 | What a loan officer may see
 * ------------------------------------------------------------------------- */

/** The signed-in loan officer's id, or 0 for everyone else (who see everything). */
function officer_scope_id(): int
{
    return (int)($_SESSION['role'] ?? 0) === OFFICER_ROLE ? (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0) : 0;
}

/** SQL to add to a WHERE on loans: '' or " AND {alias}.assigned_officer_id = N". */
function officer_loan_sql(string $alias = 'l'): string
{
    $id = officer_scope_id();
    return $id > 0 ? " AND {$alias}.assigned_officer_id = {$id}" : '';
}

/** SQL to add to a WHERE on a client table (customers/members/startups/organisations). */
function officer_client_sql(string $alias): string
{
    $id = officer_scope_id();
    return $id > 0 ? " AND {$alias}.assigned_officer_id = {$id}" : '';
}

/** Stop the page when a loan officer opens a loan that isn't theirs. */
function officer_require_loan(mysqli $conn, int $loanId): void
{
    $id = officer_scope_id();
    if ($id <= 0) {
        return;
    }
    $stmt = $conn->prepare('SELECT assigned_officer_id FROM loan WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && (int)$row['assigned_officer_id'] !== $id) {
        officer_forbidden();
    }
}

/**
 * Stop the page unless the client is the signed-in officer's own (no-op for
 * everyone else). A record that isn't a client of this kind is refused too, so
 * officers can't act on admins, other officers or unknown ids.
 */
function officer_require_client(mysqli $conn, string $kind, int $clientId): void
{
    if (!officer_may_access_client($conn, $kind, $clientId)) {
        officer_forbidden();
    }
}

/** True for non-officers, or when the client is the signed-in officer's own. */
function officer_may_access_client(mysqli $conn, string $kind, int $clientId): bool
{
    $id = officer_scope_id();
    if ($id <= 0) {
        return true;
    }
    if (!isset(OFFICER_CLIENT_TABLES[$kind])) {
        return false;
    }
    $key = in_array($kind, ['customer', 'member'], true) ? 'user_id' : 'id';
    $rows = officer_client_rows($conn, $kind, "{$key} = " . $clientId, 1);
    return $rows && (int)$rows[0]['assigned_officer_id'] === $id;
}

/** For actions on any user id: an officer may only act on their own customer or staff borrower. */
function officer_require_user(mysqli $conn, int $userId): void
{
    $id = officer_scope_id();
    if ($id <= 0) {
        return;
    }
    foreach (['customer', 'member'] as $kind) {
        $rows = officer_client_rows($conn, $kind, 'user_id = ' . $userId, 1);
        if ($rows && (int)$rows[0]['assigned_officer_id'] === $id) {
            return;
        }
    }
    officer_forbidden();
}

function officer_forbidden(): never
{
    http_response_code(403);
    exit('<div style="font-family:sans-serif;padding:2rem"><h3>Not your client</h3><p>This client is assigned to another loan officer.</p><p><a href="dashboard.php">Back to dashboard</a></p></div>');
}
