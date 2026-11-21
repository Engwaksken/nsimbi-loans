<?php
declare(strict_types=1);

/*
 * Business types that are not in the scoring table (loan_assessment_scores():
 * System > Manage Sectors, or the built-in list) score the lowest, 3. Most are spelling
 * mistakes ("Saloon", "Resturant") or local words ("mitumba", "boda boda").
 *
 * Nothing is corrected automatically: admin/business_types.php lists them
 * with a suggested match and staff confirm each correction. Every change is
 * logged in business_type_corrections with the original text, and only the
 * credit score is recalculated (hand-set loan limits are not touched).
 */

require_once __DIR__ . '/loan_assessment.php';

/** Local words and common names, mapped to a scoring table type. Suggestions only. */
const BT_KEYWORDS = [
    'saloon' => 'Beauty salon', 'salon' => 'Beauty salon', 'hair' => 'Beauty salon', 'barber' => 'Beauty salon',
    'cosmetic' => 'Beauty salon', 'makeup' => 'Beauty salon',
    'mitumba' => 'Sell second hand clothes', 'second hand' => 'Sell second hand clothes', 'secondhand' => 'Sell second hand clothes',
    'boda' => 'Transportation', 'taxi' => 'Transportation', 'transport' => 'Transportation',
    'clinic' => 'Healthcare', 'pharmacy' => 'Healthcare', 'drug shop' => 'Healthcare', 'drugshop' => 'Healthcare', 'health' => 'Healthcare',
    'school' => 'Education', 'nursery' => 'Education', 'tuition' => 'Education',
    'food' => 'Restaurant', 'eatery' => 'Restaurant', 'canteen' => 'Restaurant', 'catering' => 'Restaurant', 'chips' => 'Restaurant',
    'tailor' => 'Tailoring', 'sewing' => 'Tailoring', 'fashion' => 'Tailoring',
    'chicken' => 'Poultry', 'eggs' => 'Poultry', 'poultry' => 'Poultry',
    'farm' => 'Farming', 'agric' => 'Farming', 'crop' => 'Farming',
    'phone' => 'Electronics', 'electronic' => 'Electronics',
    'market' => 'Market stall', 'stall' => 'Market stall', 'vendor' => 'Market stall',
    'shop' => 'Retail shop', 'retail' => 'Retail shop', 'kiosk' => 'Retail shop', 'grocery' => 'Retail shop', 'supermarket' => 'Retail shop',
];

/** 6 or 6.5 */
function bt_fmt(float $score): string
{
    return rtrim(rtrim(number_format($score, 1), '0'), '.');
}

function bt_normalize(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z ]+/', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
}

/** Group key for a stored business type (case and spacing ignored). */
function bt_key(string $text): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
}

function bt_is_scored(string $type): bool
{
    return trim($type) !== '' && loan_assessment_business_score($type)[1] !== null;
}

/**
 * Suggested scoring-table type for an unscored business type.
 * 'spelling' = a near-identical spelling (safe to pre-select);
 * 'keyword'  = guessed from a word in it (staff should check).
 * @return array{name: string, score: int, how: string}|null
 */
function bt_suggest(string $type): ?array
{
    $input = bt_normalize($type);
    if ($input === '') {
        return null;
    }
    $words = array_values(array_filter(explode(' ', $input), static fn($w) => strlen($w) >= 4));

    // 1. Spelling: the whole text, or one of its words, is 1-2 letters off a table name or its main word.
    $best = null;
    foreach (loan_assessment_scores() as $name => $score) {
        $n = bt_normalize($name);
        $targets = array_merge([$n], array_filter(explode(' ', $n), static fn($w) => strlen($w) >= 5));
        $tries = array_merge([$input], $words);
        foreach ($targets as $t) {
            foreach ($tries as $w) {
                $d = levenshtein($w, $t);
                $len = max(strlen($w), strlen($t));
                if ($d > 0 && $d <= 2 && $len >= 5 && 1 - $d / $len >= 0.75) {
                    if ($best === null || $d < $best[0]) {
                        $best = [$d, $name];
                    }
                }
            }
        }
    }
    if ($best !== null) {
        return ['name' => $best[1], 'score' => loan_assessment_scores()[$best[1]], 'how' => 'spelling'];
    }

    // 2. Keyword: longest matching local word or common name.
    $hit = null;
    foreach (BT_KEYWORDS as $word => $name) {
        if (preg_match('/\b' . preg_quote($word, '/') . '/', $input) && ($hit === null || strlen($word) > strlen($hit))) {
            $hit = $word;
        }
    }
    if ($hit !== null) {
        // The sector may have been renamed or removed on Manage Sectors.
        [$score, $name] = loan_assessment_business_score(BT_KEYWORDS[$hit]);
        if ($name !== null) {
            return ['name' => $name, 'score' => $score, 'how' => 'keyword'];
        }
    }
    return null;
}

function bt_ensure_schema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS business_type_corrections (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            old_type VARCHAR(255) NOT NULL DEFAULT '',
            new_type VARCHAR(255) NOT NULL,
            old_score DECIMAL(6,2) NULL,
            new_score DECIMAL(6,2) NULL,
            corrected_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_btc_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Unscored business types grouped by text, most clients first.
 * @return array{groups: array<int, array{key: string, label: string, count: int, suggestion: ?array}>, blank: array<int, array>}
 */
function bt_unscored(mysqli $conn): array
{
    $groups = [];
    $blank = [];
    $res = $conn->query("SELECT c.user_id, c.business_type, u.fname, u.lname
                         FROM customers c JOIN user u ON u.id = c.user_id ORDER BY c.user_id DESC");
    while ($res && ($r = $res->fetch_assoc())) {
        $type = (string)($r['business_type'] ?? '');
        if (trim($type) === '') {
            $blank[] = $r;
            continue;
        }
        if (bt_is_scored($type)) {
            continue;
        }
        $key = bt_key($type);
        $groups[$key] ??= ['key' => $key, 'label' => trim($type), 'count' => 0, 'suggestion' => bt_suggest($type)];
        $groups[$key]['count']++;
    }
    usort($groups, static fn($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['key'], $b['key']));
    return ['groups' => $groups, 'blank' => $blank];
}

/** Number of clients whose business type is not in the scoring table (blank not counted). */
function bt_unscored_count(mysqli $conn): int
{
    $n = 0;
    $res = $conn->query("SELECT business_type, COUNT(*) AS c FROM customers WHERE TRIM(COALESCE(business_type, '')) <> '' GROUP BY business_type");
    while ($res && ($r = $res->fetch_assoc())) {
        if (!bt_is_scored((string)$r['business_type'])) {
            $n += (int)$r['c'];
        }
    }
    return $n;
}

/** Set the business type and recalculate the credit score only. Returns the new score. */
function bt_set_type(mysqli $conn, int $userId, string $newType, int $actorId): ?float
{
    if (!isset(loan_assessment_scores()[$newType])) {
        throw new DomainException('Choose a business type from the scoring table.');
    }
    bt_ensure_schema($conn);
    $s = $conn->prepare('SELECT business_type, credit_score FROM customers WHERE user_id = ? LIMIT 1');
    $s->bind_param('i', $userId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$row) {
        return null;
    }
    $oldType = (string)($row['business_type'] ?? '');
    $oldScore = $row['credit_score'] !== null ? (float)$row['credit_score'] : null;

    $s = $conn->prepare('UPDATE customers SET business_type = ? WHERE user_id = ?');
    $s->bind_param('si', $newType, $userId);
    $s->execute();
    $s->close();

    credit_cache_reset();
    $profile = credit_profile_for_user($conn, $userId);
    $newScore = $profile ? round((float)$profile['score'], 1) : null;
    if ($newScore !== null) {
        $s = $conn->prepare('UPDATE customers SET credit_score = ? WHERE user_id = ?');
        $s->bind_param('di', $newScore, $userId);
        $s->execute();
        $s->close();
    }

    $s = $conn->prepare('INSERT INTO business_type_corrections (user_id, old_type, new_type, old_score, new_score, corrected_by) VALUES (?, ?, ?, ?, ?, ?)');
    $s->bind_param('issddi', $userId, $oldType, $newType, $oldScore, $newScore, $actorId);
    $s->execute();
    $s->close();
    return $newScore;
}

/** Correct every client whose business type has this group key. Returns how many changed. */
function bt_apply_group(mysqli $conn, string $key, string $newType, int $actorId): int
{
    $ids = [];
    $res = $conn->query("SELECT user_id, business_type FROM customers WHERE TRIM(COALESCE(business_type, '')) <> ''");
    while ($res && ($r = $res->fetch_assoc())) {
        if (bt_key((string)$r['business_type']) === $key && !bt_is_scored((string)$r['business_type'])) {
            $ids[] = (int)$r['user_id'];
        }
    }
    foreach ($ids as $id) {
        bt_set_type($conn, $id, $newType, $actorId);
    }
    return count($ids);
}

/** Note for an import or edit: what an unscored type will score and the closest match. */
function bt_warning(string $type): string
{
    if (trim($type) === '') {
        return 'No business type given, so it scores ' . LOAN_ASSESSMENT_DEFAULT_SCORE . '.';
    }
    if (bt_is_scored($type)) {
        return '';
    }
    $s = bt_suggest($type);
    return 'Business type "' . trim($type) . '" is not in the scoring table, so it scores ' . LOAN_ASSESSMENT_DEFAULT_SCORE . '.'
        . ($s ? ' Did you mean "' . $s['name'] . '"? Fix it under System > Business Types.' : '');
}
