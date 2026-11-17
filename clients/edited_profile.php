<?php
/**
 * Saves the client's own profile edits (Edit Profile modal on profile.php).
 *
 * The customer is always the logged-in user; nothing in the form decides
 * whose record is updated. Only contact, payout and next-of-kin fields are
 * editable here; identity, business and credit details are staff-managed.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require "db.php";
require_once __DIR__ . '/../includes/loan_workflow.php';

function back(string $message, string $type = 'error', array $old = []): void
{
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    $_SESSION['message_target'] = 'edit';
    if ($old) {
        $_SESSION['profile_old'] = $old;
    }
    header('Location: profile.php');
    exit;
}

function field(string $name, int $max): string
{
    $value = trim(preg_replace('/\s+/', ' ', (string)($_POST[$name] ?? '')));
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

function valid_phone(string $value): bool
{
    $digits = preg_replace('/\D/', '', $value);
    return strlen($digits) >= 9 && strlen($digits) <= 15 && preg_match('/^\+?[\d\s()-]+$/', $value) === 1;
}

$user_id   = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
$user_role = (int)($_SESSION['role'] ?? 0);

if ($user_id <= 0 || $user_role !== 3) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php');
    exit;
}

$data = [
    'phone'        => field('phone', 20),
    'district'     => field('district', 100),
    'location'     => field('location', 255),
    'bank_name'    => field('bank_name', 100),
    'bank_account' => preg_replace('/[\s-]/', '', field('bank_account', 34)),
    'nok_name'     => field('nok_name', 150),
    'nok_tel'      => field('nok_tel', 20),
    'nok_rel'      => field('nok_rel', 50),
];

if (!valid_phone($data['phone'])) {
    back('Please enter a valid phone number.', 'error', $data);
}
if ($data['location'] === '') {
    back('Please enter your location.', 'error', $data);
}
if (($data['bank_name'] === '') !== ($data['bank_account'] === '')) {
    back('Enter both bank name and account number, or leave both empty.', 'error', $data);
}
if ($data['bank_account'] !== '' && !preg_match('/^[A-Za-z0-9]{5,34}$/', $data['bank_account'])) {
    back('Bank account number should contain only letters and digits.', 'error', $data);
}
if ($data['nok_name'] === '') {
    back("Please enter your next of kin's name.", 'error', $data);
}
if (!valid_phone($data['nok_tel'])) {
    back('Please enter a valid next of kin phone number.', 'error', $data);
}

try {
    // Only update columns this database has (nok_rel and district are optional).
    $sets = [];
    $types = '';
    $values = [];
    foreach ($data as $column => $value) {
        if (loan_table_has_column($conn, 'customers', $column)) {
            $sets[] = "{$column} = ?";
            $types .= 's';
            $values[] = $value;
        }
    }

    if (!$sets) {
        throw new RuntimeException('No editable customer columns found.');
    }

    $types .= 'i';
    $values[] = $user_id;

    $stmt = $conn->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE user_id = ? LIMIT 1");
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    error_log('Client profile update failed for user ' . $user_id . ': ' . $e->getMessage());
    back('Your profile could not be saved. Please try again.', 'error', $data);
}

$_SESSION['message'] = 'Profile updated successfully.';
$_SESSION['message_type'] = 'success';
header('Location: profile.php');
exit;
