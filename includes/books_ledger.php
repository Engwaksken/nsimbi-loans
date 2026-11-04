<?php
declare(strict_types=1);

/**
 * Single source of truth for how book_entries types roll up into money in and
 * money out. Shared by the mobile API (books_api.php), the admin reports
 * under loans/admin/, and the client books dashboard so the surfaces cannot
 * drift apart.
 */

const BOOKS_MONEY_IN_TYPES = ['sale'];
const BOOKS_MONEY_OUT_TYPES = ['purchase', 'expense'];
const BOOKS_ENTRY_TYPES = ['sale', 'purchase', 'expense'];

function books_is_money_in(string $type): bool
{
    return in_array($type, BOOKS_MONEY_IN_TYPES, true);
}

function books_is_money_out(string $type): bool
{
    return in_array($type, BOOKS_MONEY_OUT_TYPES, true);
}

/**
 * Quotes a code-defined type list for use in an IN (...) clause. Never call
 * this with user input; the values come from the constants above.
 */
function books_sql_type_list(array $types): string
{
    return "'" . implode("', '", $types) . "'";
}

/**
 * SELECT fragment producing money_in and money_out columns over book_entries.
 * $alias is the table alias used by the surrounding query, if any.
 */
function books_money_select_sql(string $alias = ''): string
{
    $col = $alias === '' ? '' : $alias . '.';
    $in = books_sql_type_list(BOOKS_MONEY_IN_TYPES);
    $out = books_sql_type_list(BOOKS_MONEY_OUT_TYPES);

    return "COALESCE(SUM(CASE WHEN {$col}type IN ({$in}) THEN {$col}amount ELSE 0 END), 0) AS money_in,\n"
        . "        COALESCE(SUM(CASE WHEN {$col}type IN ({$out}) THEN {$col}amount ELSE 0 END), 0) AS money_out";
}

/**
 * Validates a YYYY-MM month, falling back to the current month. Rejects values
 * like 2026-13 that createFromFormat would otherwise roll over.
 */
function books_normalize_month(?string $month): string
{
    $month = trim((string)$month);

    if (preg_match('/^\d{4}-\d{2}$/', $month)) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00');

        if ($parsed && $parsed->format('Y-m') === $month) {
            return $month;
        }
    }

    return date('Y-m');
}

/**
 * Half-open [start, end) bounds for a month, so queries filtering entry_date
 * can use an index on the column.
 */
function books_month_range(string $month): array
{
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00');

    return [$start, $start->modify('+1 month')];
}

function books_month_label(string $month): string
{
    return books_month_range($month)[0]->format('F Y');
}

/**
 * Rolls a map of type => amount into money in, money out and net, so callers
 * that already have a per-type breakdown don't restate the classification.
 */
function books_roll_up(array $amountsByType): array
{
    $moneyIn = 0.0;
    $moneyOut = 0.0;

    foreach ($amountsByType as $type => $amount) {
        if (books_is_money_in((string)$type)) {
            $moneyIn += (float)$amount;
        } elseif (books_is_money_out((string)$type)) {
            $moneyOut += (float)$amount;
        }
    }

    return [
        'money_in' => $moneyIn,
        'money_out' => $moneyOut,
        'net' => $moneyIn - $moneyOut,
    ];
}

/* ═══ CADENCE PERIOD BOUNDARIES ═══════════════════════════════════════════ */

const BOOKS_CADENCES = ['weekly', 'biweekly', 'monthly', 'annual'];

/**
 * Half-open [start, end) date range for a given cadence and offset.
 *
 * $offset is 0 for the current period, -1 for the previous, etc.
 * Positive offsets (future) are allowed but callers typically clamp at 0.
 *
 * Biweekly: pairs ISO weeks starting from W1 (W1-W2, W3-W4, …). Odd-count
 * years (53 weeks) make the last "pair" a single week — acceptable edge case.
 */
function books_cadence_range(string $cadence, int $offset = 0, string $weekStart = 'monday'): array
{
    $today = new DateTimeImmutable('today');

    switch ($cadence) {
        case 'weekly':
            $start = $today->modify("{$weekStart} this week");
            if ($start > $today) {
                $start = $start->modify('-7 days');
            }
            $start = $start->modify(($offset * 7) . ' days');
            return [$start, $start->modify('+7 days')];

        case 'biweekly':
            $yearStart = $today->modify('monday this week');
            if ($yearStart > $today) {
                $yearStart = $yearStart->modify('-7 days');
            }
            $isoWeek = (int)$yearStart->format('W');
            $pairIndex = intdiv($isoWeek - 1, 2);
            $jan1 = new DateTimeImmutable($today->format('Y') . '-01-01');
            $firstMonday = $jan1->modify('monday this week');
            if ($firstMonday > $jan1) {
                $firstMonday = $jan1->modify('next monday');
            }
            $currentPairStart = $firstMonday->modify((($pairIndex) * 14) . ' days');
            $start = $currentPairStart->modify(($offset * 14) . ' days');
            return [$start, $start->modify('+14 days')];

        case 'annual':
            $year = (int)$today->format('Y') + $offset;
            $start = new DateTimeImmutable("{$year}-01-01");
            return [$start, $start->modify('+1 year')];

        case 'monthly':
        default:
            $start = $today->modify('first day of this month')->setTime(0, 0);
            if ($offset !== 0) {
                $start = $start->modify("{$offset} months");
            }
            return [$start, $start->modify('+1 month')];
    }
}

/**
 * Human-readable label for a cadence period.
 */
function books_cadence_label(string $cadence, DateTimeImmutable $start, DateTimeImmutable $end): string
{
    switch ($cadence) {
        case 'weekly':
            $last = $end->modify('-1 day');
            if ($start->format('Y') !== $last->format('Y')) {
                return $start->format('M j, Y') . ' – ' . $last->format('M j, Y');
            }
            if ($start->format('M') !== $last->format('M')) {
                return $start->format('M j') . ' – ' . $last->format('M j, Y');
            }
            return $start->format('M j') . ' – ' . $last->format('j, Y');

        case 'biweekly':
            $last = $end->modify('-1 day');
            if ($start->format('Y') !== $last->format('Y')) {
                return $start->format('M j, Y') . ' – ' . $last->format('M j, Y');
            }
            if ($start->format('M') !== $last->format('M')) {
                return $start->format('M j') . ' – ' . $last->format('M j, Y');
            }
            return $start->format('M j') . ' – ' . $last->format('j, Y');

        case 'annual':
            return $start->format('Y');

        case 'monthly':
        default:
            return $start->format('F Y');
    }
}

/**
 * Returns navigation metadata for period browsing: prev/next offsets and
 * whether next should be disabled (can't browse into the future).
 */
function books_cadence_nav(string $cadence, int $offset): array
{
    return [
        'prev_offset' => $offset - 1,
        'next_offset' => $offset + 1,
        'can_next' => $offset < 0,
    ];
}
