<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../db.php';
require_once dirname(__DIR__, 2) . '/includes/loan_products.php';

function back(string $msg = '', bool $ok = false): never
{
    $url = '../loan_products.php';
    if ($msg !== '') {
        $url .= ($ok ? '?success=' : '?error=') . urlencode($msg);
    } elseif ($ok) {
        $url .= '?success=1';
    }
    header("Location: $url", true, 303);
    exit;
}

// Products price every loan: administrators only.
if (!isset($_SESSION['logged_in']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    back('Only administrators can change loan products.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('Invalid request.');
}
$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['loan_product_csrf']) || !hash_equals((string)$_SESSION['loan_product_csrf'], $csrf)) {
    back('Your session expired. Reload the page and try again.');
}

loan_products_ensure_schema($conn);
$action = (string)($_POST['action'] ?? '');

function product_input(): array
{
    $text = static fn(string $k, int $max): string => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max, 'UTF-8');
    $num = static fn(string $k): float => max(0.0, (float)preg_replace('/[^0-9.]/', '', (string)($_POST[$k] ?? '0')));

    $data = [
        'product_code'        => $text('product_code', 50),
        'product_name'        => $text('product_name', 150),
        'description'         => $text('description', 2000),
        'loan_period_months'  => (int)($_POST['loan_period_months'] ?? 0),
        'period_unit'         => loan_term_unit((string)($_POST['period_unit'] ?? 'month')),
        'interest_rate'       => $num('interest_rate'),
        'minimum_amount'      => $num('minimum_amount'),
        'maximum_amount'      => $num('maximum_amount'),
        'processing_fee'      => $num('processing_fee'),
        'penalty_rate'        => $num('penalty_rate'),
        'repayment_frequency' => (string)($_POST['repayment_frequency'] ?? 'monthly'),
        'interest_type'       => ($_POST['interest_type'] ?? '') === 'reducing' ? 'reducing' : 'flat',
        'client_category'     => (string)($_POST['client_category'] ?? 'all'),
        'status'              => ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active',
    ];

    if ($data['product_code'] === '' || $data['product_name'] === '') {
        back('Product code and name are required.');
    }
    if ($data['loan_period_months'] < 1 || $data['loan_period_months'] > 120) {
        back('Enter a loan period between 1 and 120.');
    }
    if (!isset(LOAN_PRODUCT_FREQUENCIES[$data['repayment_frequency']])) {
        back('Choose a valid repayment frequency.');
    }
    if (!isset(LOAN_PRODUCT_CATEGORIES[$data['client_category']])) {
        back('Choose who can use this product.');
    }
    if ($data['interest_rate'] > 100 || $data['penalty_rate'] > 100) {
        back('Interest and penalty rates must be between 0 and 100%.');
    }
    if ($data['maximum_amount'] > 0 && $data['maximum_amount'] < $data['minimum_amount']) {
        back('The maximum amount must be at least the minimum amount (or 0 for no maximum).');
    }
    return $data;
}

try {
    if ($action === 'create' || $action === 'update') {
        $d = product_input();
        $values = [
            $d['product_code'], $d['product_name'], $d['description'], $d['loan_period_months'], $d['period_unit'],
            $d['interest_rate'], $d['minimum_amount'], $d['maximum_amount'], $d['processing_fee'], $d['penalty_rate'],
            $d['repayment_frequency'], $d['interest_type'], $d['client_category'], $d['status'],
        ];
        $types = 'sssisdddddssss';

        if ($action === 'create') {
            $stmt = $conn->prepare('
                INSERT INTO loan_products (
                    product_code, product_name, description, loan_period_months, period_unit,
                    interest_rate, minimum_amount, maximum_amount, processing_fee, penalty_rate,
                    repayment_frequency, interest_type, client_category, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->bind_param($types, ...$values);
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                back('Product not found.');
            }
            $stmt = $conn->prepare('
                UPDATE loan_products SET
                    product_code = ?, product_name = ?, description = ?, loan_period_months = ?, period_unit = ?,
                    interest_rate = ?, minimum_amount = ?, maximum_amount = ?, processing_fee = ?, penalty_rate = ?,
                    repayment_frequency = ?, interest_type = ?, client_category = ?, status = ?
                WHERE id = ?
            ');
            $values[] = $id;
            $stmt->bind_param($types . 'i', ...$values);
        }
        $stmt->execute();
        $stmt->close();
        back($action === 'create' ? 'Loan product added.' : 'Loan product updated. Loans already requested keep the terms they were given.', true);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM loan WHERE loan_product_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $used = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        if ($used > 0) {
            back("This product is used by {$used} loan(s). Set it to Inactive instead so clients can no longer pick it.");
        }
        $stmt = $conn->prepare('DELETE FROM loan_products WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        back('Loan product deleted.', true);
    }
} catch (Throwable $e) {
    error_log('Loan product ' . $action . ' failed: ' . $e->getMessage());
    back('Could not save the loan product. Check that the product code is not already used.');
}

back('Invalid action.');
