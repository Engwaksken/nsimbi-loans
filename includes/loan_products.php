<?php
declare(strict_types=1);

/**
 * Loan products: what a client picks when requesting a loan, and what prices it.
 *
 * A product (Admin > Loan Products) sets:
 *  - the loan period (count + weeks/months),
 *  - the interest rate, a percentage PER MONTH (2 weeks = half a month),
 *  - flat or reducing-balance interest and the repayment frequency,
 *  - the minimum / maximum amount (maximum 0 = no maximum),
 *  - a flat processing fee in UGX, taken off the payout,
 *  - a penalty rate: % of the unpaid balance (excluding earlier penalties),
 *    charged the day after the due date and again every 30 days while unpaid,
 *  - which clients may use it (all, business, new_onboarded).
 *
 * The product's terms are copied onto the loan when it is requested
 * (loan_product_id, interest_rate, processing_fee, penalty_rate, ...), so
 * later edits to a product never change loans that already exist. Loans
 * without a product keep the older built-in pricing (loan_quote()).
 */

require_once __DIR__ . '/loan_offers.php';
require_once __DIR__ . '/loan_workflow.php';

const LOAN_PRODUCT_FREQUENCIES = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];
const LOAN_PRODUCT_CATEGORIES  = ['all' => 'All clients', 'business' => 'Business clients', 'new_onboarded' => 'New onboarded clients'];
const LOAN_PENALTY_PERIOD_DAYS = 30;

/* ---------------------------------------------------------------------------
 | Schema
 * ------------------------------------------------------------------------- */

function loan_products_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    loan_offers_ensure_schema($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS loan_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_code VARCHAR(50) NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            loan_period_months INT NOT NULL DEFAULT 1,
            interest_rate DECIMAL(8,2) NOT NULL DEFAULT 0,
            minimum_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            maximum_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            processing_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
            penalty_rate DECIMAL(8,2) NOT NULL DEFAULT 0,
            repayment_frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
            interest_type VARCHAR(20) NOT NULL DEFAULT 'flat',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // loan_period_months holds the period COUNT; period_unit says weeks or months.
    loan_offers_add_column($conn, 'loan_products', 'period_unit', "ADD COLUMN period_unit VARCHAR(10) NOT NULL DEFAULT 'month'");
    loan_offers_add_column($conn, 'loan_products', 'client_category', "ADD COLUMN client_category VARCHAR(20) NOT NULL DEFAULT 'all'");

    $loanColumns = [
        'loan_product_id'         => 'ADD COLUMN loan_product_id INT NULL',
        'loan_product_name'       => 'ADD COLUMN loan_product_name VARCHAR(150) NULL',
        'interest_type'           => 'ADD COLUMN interest_type VARCHAR(20) NULL',
        'repayment_frequency'     => 'ADD COLUMN repayment_frequency VARCHAR(20) NULL',
        'penalty_rate'            => 'ADD COLUMN penalty_rate DECIMAL(8,2) NULL',
        'installment_count'       => 'ADD COLUMN installment_count INT NULL',
        'installment_amount'      => 'ADD COLUMN installment_amount DECIMAL(15,2) NULL',
        'sur_charge'              => 'ADD COLUMN sur_charge DECIMAL(15,2) NOT NULL DEFAULT 0',
        'penalty_periods_charged' => 'ADD COLUMN penalty_periods_charged INT NOT NULL DEFAULT 0',
        'penalty_last_at'         => 'ADD COLUMN penalty_last_at DATETIME NULL',
    ];
    foreach ($loanColumns as $col => $ddl) {
        loan_offers_add_column($conn, 'loan', $col, $ddl);
    }
}

/* ---------------------------------------------------------------------------
 | Products
 * ------------------------------------------------------------------------- */

/** Active products a client of this category may pick, cheapest period first. */
function loan_products_for_client(mysqli $conn, string $clientCategory): array
{
    loan_products_ensure_schema($conn);
    $category = $clientCategory === 'new_onboarded' ? 'new_onboarded' : 'business';
    $stmt = $conn->prepare("
        SELECT * FROM loan_products
        WHERE LOWER(status) = 'active' AND client_category IN ('all', ?)
        ORDER BY (CASE WHEN period_unit = 'week' THEN loan_period_months ELSE loan_period_months * 4 END), product_name
    ");
    $stmt->bind_param('s', $category);
    $stmt->execute();
    $result = $stmt->get_result();
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = loan_product_normalize($row);
    }
    $stmt->close();
    return $products;
}

function loan_product_get(mysqli $conn, int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }
    loan_products_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM loan_products WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? loan_product_normalize($row) : null;
}

/** The product if this client may request it right now, else null. */
function loan_product_for_request(mysqli $conn, int $productId, string $clientCategory): ?array
{
    foreach (loan_products_for_client($conn, $clientCategory) as $product) {
        if ($product['id'] === $productId) {
            return $product;
        }
    }
    return null;
}

/** For app versions that still send a period instead of a product: the matching product, if any. */
function loan_product_matching_term(mysqli $conn, string $clientCategory, int $count, string $unit): ?array
{
    $unit = loan_term_unit($unit);
    foreach (loan_products_for_client($conn, $clientCategory) as $product) {
        if ($product['period'] === $count && $product['period_unit'] === $unit) {
            return $product;
        }
    }
    return null;
}

/** Typed product row with the keys the pricing helpers use. */
function loan_product_normalize(array $row): array
{
    $frequency = strtolower(trim((string)($row['repayment_frequency'] ?? 'monthly')));
    $category = strtolower(trim((string)($row['client_category'] ?? 'all')));
    return [
        'id'                  => (int)$row['id'],
        'code'                => (string)($row['product_code'] ?? ''),
        'name'                => (string)($row['product_name'] ?? ''),
        'description'         => (string)($row['description'] ?? ''),
        'period'              => max(1, (int)($row['loan_period_months'] ?? 1)),
        'period_unit'         => loan_term_unit((string)($row['period_unit'] ?? 'month')),
        'interest_rate'       => max(0.0, (float)($row['interest_rate'] ?? 0)),
        'interest_type'       => strtolower((string)($row['interest_type'] ?? '')) === 'reducing' ? 'reducing' : 'flat',
        'repayment_frequency' => isset(LOAN_PRODUCT_FREQUENCIES[$frequency]) ? $frequency : 'monthly',
        'minimum_amount'      => max(0.0, (float)($row['minimum_amount'] ?? 0)),
        'maximum_amount'      => max(0.0, (float)($row['maximum_amount'] ?? 0)),
        'processing_fee'      => max(0.0, (float)($row['processing_fee'] ?? 0)),
        'penalty_rate'        => max(0.0, (float)($row['penalty_rate'] ?? 0)),
        'client_category'     => isset(LOAN_PRODUCT_CATEGORIES[$category]) ? $category : 'all',
        'status'              => strtolower((string)($row['status'] ?? 'active')),
    ];
}

/** '' when the amount fits the product, otherwise a message for the client or reviewer. */
function loan_product_amount_error(array $product, float $amount): string
{
    $min = max(10000.0, $product['minimum_amount']);
    if ($amount < $min) {
        return $product['name'] . ' starts at ' . loan_money($min) . '.';
    }
    if ($product['maximum_amount'] > 0 && $amount > $product['maximum_amount']) {
        return $product['name'] . ' allows at most ' . loan_money($product['maximum_amount']) . '.';
    }
    return '';
}

function loan_product_term_label(array $product): string
{
    return loan_term_label($product['period'], $product['period_unit']);
}

/** One line for selects: "3 Month Business Loan - 3 months, 5% per month". */
function loan_product_label(array $product): string
{
    return $product['name'] . ' - ' . loan_product_term_label($product) . ', '
        . rtrim(rtrim(number_format($product['interest_rate'], 2), '0'), '.') . '% per month';
}

/** Data the request forms and the app need to show a product and price it. */
function loan_product_public(array $product): array
{
    return [
        'id'                  => $product['id'],
        'code'                => $product['code'],
        'name'                => $product['name'],
        'description'         => $product['description'],
        'label'               => loan_product_label($product),
        'loan_period'         => $product['period'],
        'loan_period_unit'    => $product['period_unit'],
        'term_label'          => loan_product_term_label($product),
        'interest_rate'       => $product['interest_rate'],
        'interest_rate_basis' => 'per_month',
        'interest_type'       => $product['interest_type'],
        'repayment_frequency' => $product['repayment_frequency'],
        'minimum_amount'      => max(10000.0, $product['minimum_amount']),
        'maximum_amount'      => $product['maximum_amount'],
        'processing_fee'      => $product['processing_fee'],
        'penalty_rate'        => $product['penalty_rate'],
        'penalty_note'        => $product['penalty_rate'] > 0
            ? rtrim(rtrim(number_format($product['penalty_rate'], 2), '0'), '.') . '% of the unpaid balance for every ' . LOAN_PENALTY_PERIOD_DAYS . ' days the loan is overdue'
            : 'No late penalty',
    ];
}

/* ---------------------------------------------------------------------------
 | Pricing
 * ------------------------------------------------------------------------- */

/** Length of the term in months (4 weeks = 1 month). */
function loan_product_term_months(int $count, string $unit): float
{
    return loan_term_unit($unit) === 'week' ? $count / 4 : (float)$count;
}

function loan_product_installment_count(int $count, string $unit, string $frequency): int
{
    $weeks = loan_term_unit($unit) === 'week';
    $months = loan_product_term_months($count, $unit);
    $n = match ($frequency) {
        'daily'     => $weeks ? $count * 7 : $count * 30,
        'weekly'    => $weeks ? $count : $count * 4,
        'quarterly' => (int)ceil($months / 3),
        'yearly'    => (int)ceil($months / 12),
        default     => $weeks ? (int)ceil($count / 4) : $count,
    };
    return max(1, $n);
}

/**
 * Price a principal on a product's terms. $terms is a normalized product, or
 * the terms copied onto a loan (see loan_product_terms_from_loan()).
 * Returns the same keys as loan_quote() plus interest_type / frequency.
 */
function loan_product_quote(array $terms, float $principal): array
{
    $count = max(1, (int)$terms['period']);
    $unit = loan_term_unit((string)$terms['period_unit']);
    $frequency = (string)$terms['repayment_frequency'];
    $rate = (float)$terms['interest_rate'];
    $months = loan_product_term_months($count, $unit);
    $n = loan_product_installment_count($count, $unit, $frequency);

    if ($terms['interest_type'] === 'reducing') {
        $i = $rate / 100 * $months / $n; // rate per installment
        $installment = $i > 0 ? $principal * $i / (1 - (1 + $i) ** -$n) : $principal / $n;
        $total = round($installment * $n, 2);
        $interest = round($total - $principal, 2);
    } else {
        $interest = round($principal * $rate / 100 * $months, 2);
        $total = $principal + $interest;
    }
    $fee = min((float)$terms['processing_fee'], $principal);

    return [
        'principal'           => $principal,
        'interest_rate'       => $rate,
        'interest_rate_basis' => 'per_month',
        'interest'            => $interest,
        'total_repayment'     => $total,
        'installments'        => $n,
        'installment_amount'  => round($total / $n, 0),
        'installment_unit'    => $frequency,
        'repayment_frequency' => $frequency,
        'interest_type'       => $terms['interest_type'],
        'processing_fee'      => $fee,
        'net_disbursement'    => max(0.0, $principal - $fee),
        'penalty_rate'        => (float)($terms['penalty_rate'] ?? 0),
        'term_label'          => loan_term_label($count, $unit),
    ];
}

/** The product terms copied onto a loan, or null for a loan without a product. */
function loan_product_terms_from_loan(array $loan): ?array
{
    if ((int)($loan['loan_product_id'] ?? 0) <= 0) {
        return null;
    }
    return [
        'period'              => max(1, (int)($loan['loan_period'] ?? 1)),
        'period_unit'         => loan_term_unit((string)($loan['loan_period_unit'] ?? 'month')),
        'interest_rate'       => (float)($loan['interest_rate'] ?? 0),
        'interest_type'       => strtolower((string)($loan['interest_type'] ?? '')) === 'reducing' ? 'reducing' : 'flat',
        'repayment_frequency' => (string)($loan['repayment_frequency'] ?? '') ?: 'monthly',
        'processing_fee'      => (float)($loan['processing_fee'] ?? 0),
        'penalty_rate'        => (float)($loan['penalty_rate'] ?? 0),
    ];
}

/** Quote for any loan: its product terms when it has a product, the older pricing otherwise. */
function loan_quote_for_loan(mysqli $conn, array $loan, float $principal): array
{
    $terms = loan_product_terms_from_loan($loan);
    if ($terms) {
        return loan_product_quote($terms, $principal);
    }
    return loan_quote($conn, $principal, max(1, (int)($loan['loan_period'] ?? 1)), (string)($loan['loan_period_unit'] ?? 'month'));
}

/**
 * Columns to save on a new loan request for a product (column => [bind type, value]).
 * Callers insert only the ones their table has.
 */
function loan_product_request_columns(array $product, float $amount): array
{
    $quote = loan_product_quote($product, $amount);
    $due = loan_term_due_date($product['period'], $product['period_unit']);
    return [
        'loan_product_id'     => ['i', $product['id']],
        'loan_product_name'   => ['s', $product['name']],
        'loan_period'         => ['i', $product['period']],
        'loan_period_unit'    => ['s', $product['period_unit']],
        'interest_rate'       => ['d', $product['interest_rate']],
        'interest_type'       => ['s', $product['interest_type']],
        'repayment_frequency' => ['s', $product['repayment_frequency']],
        'processing_fee'      => ['d', $quote['processing_fee']],
        'penalty_rate'        => ['d', $product['penalty_rate']],
        'interest'            => ['d', $quote['interest']],
        'interest_balance'    => ['d', $quote['interest']],
        'principal_balance'   => ['d', $amount],
        'loan_balance'        => ['d', $quote['total_repayment']],
        'outstanding_balance' => ['d', $quote['total_repayment']],
        'installment_count'   => ['i', $quote['installments']],
        'installment_amount'  => ['d', $quote['installment_amount']],
        'due_date'            => ['s', $due],
    ];
}

/**
 * Keep only the columns this database's loan table has.
 * @param array $columns column => [bind type, value]
 * @return array{0: string[], 1: string, 2: array} column names, bind types, values
 */
function loan_product_existing_columns(mysqli $conn, array $columns): array
{
    $names = [];
    $types = '';
    $values = [];
    foreach ($columns as $name => [$type, $value]) {
        if (loan_table_has_column($conn, 'loan', $name)) {
            $names[] = $name;
            $types .= $type;
            $values[] = $value;
        }
    }
    return [$names, $types, $values];
}

/**
 * <script> giving the request forms the products and the same pricing as
 * loan_product_quote(): window.LoanProducts.get(id), .quote(product, amount).
 */
function loan_products_script(array $products): string
{
    $data = [];
    foreach ($products as $product) {
        $data[$product['id']] = loan_product_public($product);
    }
    $json = json_encode((object)$data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return <<<HTML
<script>
window.LoanProducts = (function () {
    const products = {$json};
    function termMonths(p) { return p.loan_period_unit === 'week' ? p.loan_period / 4 : p.loan_period; }
    function installments(p) {
        const weeks = p.loan_period_unit === 'week', c = p.loan_period, m = termMonths(p);
        let n;
        switch (p.repayment_frequency) {
            case 'daily': n = weeks ? c * 7 : c * 30; break;
            case 'weekly': n = weeks ? c : c * 4; break;
            case 'quarterly': n = Math.ceil(m / 3); break;
            case 'yearly': n = Math.ceil(m / 12); break;
            default: n = weeks ? Math.ceil(c / 4) : c;
        }
        return Math.max(1, n);
    }
    function quote(p, principal) {
        if (!p || !(principal > 0)) return null;
        const n = installments(p), m = termMonths(p), r = p.interest_rate / 100;
        let interest;
        if (p.interest_type === 'reducing') {
            const i = r * m / n;
            const inst = i > 0 ? principal * i / (1 - Math.pow(1 + i, -n)) : principal / n;
            interest = Math.round((inst * n - principal) * 100) / 100;
        } else {
            interest = Math.round(principal * r * m * 100) / 100;
        }
        const total = principal + interest, fee = Math.min(p.processing_fee, principal);
        return { interest: interest, total: total, installments: n, installment: Math.round(total / n),
                 fee: fee, net: Math.max(0, principal - fee) };
    }
    function amountError(p, amount) {
        const min = Math.max(10000, p.minimum_amount);
        if (amount < min) return p.name + ' starts at UGX ' + min.toLocaleString() + '.';
        if (p.maximum_amount > 0 && amount > p.maximum_amount) return p.name + ' allows at most UGX ' + Number(p.maximum_amount).toLocaleString() + '.';
        return '';
    }
    function rateLabel(p) { return (+p.interest_rate.toFixed(2)) + '% per month' + (p.interest_type === 'reducing' ? ' (reducing balance)' : ''); }
    return { all: products, get: function (id) { return products[id] || null; }, quote: quote, amountError: amountError, rateLabel: rateLabel };
})();
</script>
HTML;
}

/* ---------------------------------------------------------------------------
 | Repayment schedule
 * ------------------------------------------------------------------------- */

/**
 * Installments for a product loan: due date, principal, interest, payment and
 * balance after it. $start is when the term started (disbursement).
 */
function loan_product_schedule(array $terms, float $principal, DateTimeImmutable $start): array
{
    $quote = loan_product_quote($terms, $principal);
    $n = $quote['installments'];
    $count = max(1, (int)$terms['period']);
    $unit = loan_term_unit((string)$terms['period_unit']);
    $end = $start->add(loan_term_interval($count, $unit));
    $months = loan_product_term_months($count, $unit);
    $i = $terms['interest_type'] === 'reducing' ? (float)$terms['interest_rate'] / 100 * $months / $n : 0.0;
    $payment = $terms['interest_type'] === 'reducing' ? $quote['total_repayment'] / $n : 0.0;

    $rows = [];
    $balance = $principal;
    for ($k = 1; $k <= $n; $k++) {
        $due = match ($quote['repayment_frequency']) {
            'daily'     => $start->modify('+' . $k . ' days'),
            'weekly'    => $start->modify('+' . (7 * $k) . ' days'),
            'quarterly' => $start->modify('+' . (3 * $k) . ' months'),
            'yearly'    => $start->modify('+' . (12 * $k) . ' months'),
            default     => $start->modify('+' . $k . ' months'),
        };
        if ($k === $n || $due > $end) {
            $due = $end;
        }
        if ($terms['interest_type'] === 'reducing') {
            $interestPart = round($balance * $i, 2);
            $principalPart = $k === $n ? $balance : round($payment - $interestPart, 2);
        } else {
            $interestPart = round($quote['interest'] / $n, 2);
            $principalPart = $k === $n ? $balance : round($principal / $n, 2);
        }
        $balance = round(max(0.0, $balance - $principalPart), 2);
        $rows[] = [
            'number'    => $k,
            'due_date'  => $due->format('Y-m-d'),
            'principal' => $principalPart,
            'interest'  => $interestPart,
            'payment'   => round($principalPart + $interestPart, 2),
            'balance'   => $balance,
        ];
    }
    return $rows;
}

/* ---------------------------------------------------------------------------
 | Late penalties
 * ------------------------------------------------------------------------- */

/**
 * Charge penalties on overdue product loans. Each loan is charged once the day
 * after its due date and again every 30 days while a balance is left; missed
 * runs are caught up. The penalty is penalty_rate % of the unpaid balance,
 * not counting earlier penalties (payments go to interest and principal first).
 *
 * @return int Number of loans charged.
 */
function loan_penalties_run(mysqli $conn, ?DateTimeImmutable $now = null): int
{
    loan_products_ensure_schema($conn);
    $now = $now ?? new DateTimeImmutable('now');
    $nowSql = $now->format('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        SELECT id, client_id, status, due_date, loan_balance, sur_charge, penalty_rate, penalty_periods_charged
        FROM loan
        WHERE COALESCE(penalty_rate, 0) > 0
          AND COALESCE(loan_balance, 0) > 0
          AND due_date IS NOT NULL AND due_date < ?
          AND UPPER(COALESCE(status, '')) IN ('OUTSTANDING', 'OVERDUE', 'ACTIVE')
        LIMIT 500
    ");
    $stmt->bind_param('s', $nowSql);
    $stmt->execute();
    $result = $stmt->get_result();
    $loans = [];
    while ($row = $result->fetch_assoc()) {
        $loans[] = $row;
    }
    $stmt->close();

    $charged = 0;
    foreach ($loans as $loan) {
        $due = new DateTimeImmutable((string)$loan['due_date']);
        $daysLate = (int)floor(($now->getTimestamp() - $due->getTimestamp()) / 86400);
        $periodsDue = intdiv(max(0, $daysLate), LOAN_PENALTY_PERIOD_DAYS) + 1;
        $alreadyCharged = (int)$loan['penalty_periods_charged'];
        $newPeriods = $periodsDue - $alreadyCharged;
        $status = strtoupper((string)$loan['status']);

        if ($newPeriods <= 0) {
            if ($status !== 'OVERDUE') {
                $conn->query("UPDATE loan SET status = 'OVERDUE' WHERE id = " . (int)$loan['id'] . " AND COALESCE(loan_balance, 0) > 0");
            }
            continue;
        }

        $balance = (float)$loan['loan_balance'];
        $unpaidPenalties = min(max(0.0, (float)$loan['sur_charge']), $balance);
        $base = $balance - $unpaidPenalties;
        $penalty = round($base * (float)$loan['penalty_rate'] / 100 * $newPeriods, 0);

        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("
                UPDATE loan
                SET sur_charge = COALESCE(sur_charge, 0) + ?,
                    loan_balance = COALESCE(loan_balance, 0) + ?,
                    outstanding_balance = COALESCE(outstanding_balance, 0) + ?,
                    penalty_periods_charged = ?,
                    penalty_last_at = ?,
                    status = 'OVERDUE'
                WHERE id = ? AND penalty_periods_charged = ?
            ");
            $id = (int)$loan['id'];
            $upd->bind_param('dddisii', $penalty, $penalty, $penalty, $periodsDue, $nowSql, $id, $alreadyCharged);
            $upd->execute();
            $changed = $upd->affected_rows === 1;
            $upd->close();

            if ($changed && $penalty > 0) {
                try {
                    $reason = 'Late penalty ' . loan_money($penalty) . ' (' . $newPeriods . ' period' . ($newPeriods === 1 ? '' : 's')
                        . ' at ' . (float)$loan['penalty_rate'] . '% of ' . loan_money($base) . ')';
                    $action = 'penalty_charged';
                    $actor = 0;
                    $role = 0;
                    $audit = $conn->prepare('
                        INSERT INTO loan_audit_trail (loan_id, actor_id, actor_role, action, reason, old_status, new_status)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ');
                    $newStatus = 'OVERDUE';
                    $audit->bind_param('iiissss', $id, $actor, $role, $action, $reason, $status, $newStatus);
                    $audit->execute();
                    $audit->close();
                } catch (Throwable $e) {
                    error_log('Penalty audit failed for loan #' . $id . ': ' . $e->getMessage());
                }
            }
            $conn->commit();
            if ($changed) {
                $charged++;
            }
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Penalty for loan #' . (int)$loan['id'] . ' failed: ' . $e->getMessage());
        }
    }
    return $charged;
}

/** Run penalties at most once an hour; cheap to call on page loads. */
function loan_penalties_maybe_run(mysqli $conn): void
{
    try {
        $last = (int)app_setting_get($conn, 'loan_penalties_last_run', '0');
        if ($last > time() - 3600) {
            return;
        }
        app_setting_set($conn, 'loan_penalties_last_run', (string)time(), 0);
        loan_penalties_run($conn);
    } catch (Throwable $e) {
        error_log('Loan penalties run failed: ' . $e->getMessage());
    }
}
