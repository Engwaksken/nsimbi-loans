<?php
/**
 * Shared sign-in and sign-up rules for the website (php/login.php,
 * clients/added_client.php) and the Android app (api.php).
 *
 * One account can sign in on both with the same password using any of:
 *   - email
 *   - username
 *   - phone number on the customer profile (clients only), matched on the
 *     last 9 digits so "0772 123456", "+256772123456" and "772-123-456" match.
 */

const AUTH_PHONE_DIGITS = 9; // Ugandan numbers: 9 digits after the 0 / +256 prefix

/** Last nine digits of a phone-like value, or '' when it is not a phone number. */
function auth_phone_key(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\+?[\d\s().-]+$/', $value)) {
        return '';
    }
    $digits = preg_replace('/\D/', '', $value);
    $length = strlen($digits);
    if ($length < AUTH_PHONE_DIGITS || $length > 15) {
        return '';
    }
    return substr($digits, -AUTH_PHONE_DIGITS);
}

/** SQL expression: a phone column with spaces and punctuation removed. */
function auth_phone_sql(string $column): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), '.', '')";
}

/** @return array<int, array<string, mixed>> */
function auth_candidates(mysqli $conn, string $identifier): array
{
    $stmt = $conn->prepare("
        SELECT id, fname, lname, username, email, role, password, status
        FROM user
        WHERE email = ? OR username = ?
        LIMIT 5
    ");
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($rows) {
        return $rows;
    }

    $phoneKey = auth_phone_key($identifier);
    if ($phoneKey === '') {
        return [];
    }

    $digits = AUTH_PHONE_DIGITS;
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id, u.fname, u.lname, u.username, u.email, u.role, u.password, u.status
        FROM customers c
        INNER JOIN user u ON u.id = c.user_id
        WHERE u.role = 3
          AND RIGHT(" . auth_phone_sql('c.phone') . ", ?) = ?
        LIMIT 10
    ");
    $stmt->bind_param('is', $digits, $phoneKey);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * Find the account for an identifier + password.
 *
 * The app has always trimmed passwords while the website did not, so both the
 * password as typed and its trimmed form are accepted.
 *
 * @return array{result: string, user?: array<string, mixed>}
 *   result: ok | invalid | inactive | ambiguous
 */
function auth_attempt(mysqli $conn, string $identifier, string $password): array
{
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return ['result' => 'invalid'];
    }

    $variants = array_values(array_unique([$password, trim($password)]));
    $matches = [];
    foreach (auth_candidates($conn, $identifier) as $user) {
        foreach ($variants as $variant) {
            if ($variant !== '' && password_verify($variant, (string)$user['password'])) {
                $matches[(int)$user['id']] = $user;
                break;
            }
        }
    }

    if (!$matches) {
        return ['result' => 'invalid'];
    }
    if (count($matches) > 1) {
        return ['result' => 'ambiguous'];
    }

    $user = reset($matches);
    if ((int)$user['status'] !== 1) {
        return ['result' => 'inactive', 'user' => $user];
    }

    unset($user['password']);
    return ['result' => 'ok', 'user' => $user];
}

/**
 * Sign-up check: which of username / email / phone already belong to
 * another account. Returns a message for the first clash, or '' if free.
 * A username may not equal another account's email (or vice versa), since
 * both are accepted at sign-in.
 */
function auth_identity_conflict(mysqli $conn, string $username, string $email, string $phone): string
{
    $username = trim($username);
    $email = trim($email);

    if ($username !== '') {
        $stmt = $conn->prepare("SELECT 1 FROM user WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($taken) {
            return 'This username is already taken. Please choose another one.';
        }
    }

    if ($email !== '') {
        $stmt = $conn->prepare("
            SELECT 1 FROM user WHERE email = ? OR username = ?
            UNION SELECT 1 FROM customers WHERE email = ?
            LIMIT 1
        ");
        $stmt->bind_param('sss', $email, $email, $email);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($taken) {
            return 'An account with this email already exists. Please sign in instead.';
        }
    }

    $phoneKey = auth_phone_key($phone);
    if ($phoneKey !== '') {
        $digits = AUTH_PHONE_DIGITS;
        $stmt = $conn->prepare("SELECT 1 FROM customers WHERE RIGHT(" . auth_phone_sql('phone') . ", ?) = ? LIMIT 1");
        $stmt->bind_param('is', $digits, $phoneKey);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($taken) {
            return 'An account with this phone number already exists. Please sign in instead.';
        }
    }

    return '';
}
