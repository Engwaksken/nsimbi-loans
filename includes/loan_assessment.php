<?php
declare(strict_types=1);

/**
 * Automated loan assessment shown to reviewers.
 *
 * The recommendation is RECALCULATED every time from what the client declared
 * (business type + turnover), using the same formula the sign-up flows use:
 *
 *   average   = (turnover last month + turnover this month) / 2
 *   limit     = average + average × (business score ÷ 10)
 *
 * This is the ONE formula for every path: app sign-up and profile update
 * (api.php), website sign-up (clients/added_client.php), admin add client and
 * bulk import, and the AI recommended amount on the loan pages. Use
 * loan_assessment_limit() rather than re-implementing it.
 *
 * The score/limit SAVED on the customer record can be edited by hand in several
 * admin screens, so they are only shown for comparison and flagged when they
 * disagree with the formula. They never drive the recommendation.
 */

if (!function_exists('loan_assessment')) {
    define('LOAN_ASSESSMENT_SCORES', [
        'Healthcare'               => 9,
        'Education'                => 8,
        'Market stall'             => 8,
        'Restaurant'               => 7,
        'Tailoring'                => 7,
        'Beauty salon'             => 6,
        'Retail shop'              => 6,
        'Farming'                  => 5,
        'Transportation'           => 5,
        'Electronics'              => 4,
        'Poultry farming'          => 4,
        'Poultry keeping'          => 4,
        'Poultry'                  => 4,
        'Sell second hand clothes' => 4,
    ]);

    /** Score for anything not in the table ("Other", blank, free text). Conservative. */
    define('LOAN_ASSESSMENT_DEFAULT_SCORE', 3);

    function loan_assessment_business_score(string $type): array
    {
        $type = trim($type);
        if ($type === '') {
            return [LOAN_ASSESSMENT_DEFAULT_SCORE, null];
        }

        // 1. Exact name (any case).
        foreach (LOAN_ASSESSMENT_SCORES as $name => $score) {
            if (strcasecmp($type, $name) === 0) {
                return [$score, $name];
            }
        }

        // 2. Longest table name found inside the type, so "Poultry Farming"
        //    matches "Poultry" (4), not "Farming" (5).
        $best = null;
        foreach (LOAN_ASSESSMENT_SCORES as $name => $score) {
            if (stripos($type, $name) !== false && ($best === null || strlen($name) > strlen($best))) {
                $best = $name;
            }
        }
        if ($best === null && strlen($type) >= 4) {
            // 3. A shortened type such as "Retail" or "Healthc".
            foreach (LOAN_ASSESSMENT_SCORES as $name => $score) {
                if (stripos($name, $type) !== false) {
                    $best = $name;
                    break;
                }
            }
        }
        if ($best !== null) {
            return [LOAN_ASSESSMENT_SCORES[$best], $best];
        }

        // Not in the table: default score.
        return [LOAN_ASSESSMENT_DEFAULT_SCORE, null];
    }

    /**
     * Read one amount such as "450,000", "450 000", "450000.00", "200k",
     * "1.5M", "2 million", "UGX 300,000/=", "1.500.000" or "1,5m".
     *
     * @return array{0: float, 1: float} [value, unit multiplier written (1 if none)]
     */
    function loan_assessment_parse_amount_part(string $part): array
    {
        $p = strtolower(trim($part));
        if (!preg_match('/(\d[\d.,\s]*)\s*(billion|bn|b|million|mill|mil|mn|m|thousand|k)?\b/u', $p, $m)) {
            return [0.0, 1.0];
        }

        $num = preg_replace('/\s+/', '', $m[1]);
        $num = rtrim($num, '.,');
        if (str_contains($num, ',') && str_contains($num, '.')) {
            // "1,500,000.00" or "1.500.000,00": the last symbol is the decimal point.
            if (strrpos($num, ',') > strrpos($num, '.')) {
                $num = str_replace(',', '.', str_replace('.', '', $num));
            } else {
                $num = str_replace(',', '', $num);
            }
        } elseif (str_contains($num, ',')) {
            // "450,000" thousands; "1,5" decimal.
            $num = preg_match('/^\d{1,3}(,\d{3})+$/', $num) ? str_replace(',', '', $num) : str_replace(',', '.', $num);
        } elseif (substr_count($num, '.') > 1 || preg_match('/^\d{1,3}\.\d{3}$/', $num) && empty($m[2])) {
            // "1.500.000" or "450.000" written with dots as thousands.
            $num = str_replace('.', '', $num);
        }
        if (!is_numeric($num)) {
            return [0.0, 1.0];
        }

        $unit = match ($m[2] ?? '') {
            'billion', 'bn', 'b' => 1000000000.0,
            'million', 'mill', 'mil', 'mn', 'm' => 1000000.0,
            'thousand', 'k' => 1000.0,
            default => 1.0,
        };
        return [(float)$num * $unit, $unit];
    }

    /**
     * Read a declared turnover written in any common way:
     *   single amounts  450000 | 450,000 | UGX 450,000/= | 200k | 1.5M | 2 million
     *   ranges          100,000 - 500,000 | 50k-100k | 1 - 2M | 500,000 to 1M | between 1M and 2M
     *   open ranges     Above 2M | over 500k | 1M+ | more than 1 million (the amount itself)
     *                   Below 50,000 | under 100k | up to 1M | less than 50k (half the amount)
     * Ranges use their midpoint.
     *
     * @return array{0: float, 1: string} [amount, 'number'|'range'|'none']
     */
    function loan_assessment_parse_turnover(string $value): array
    {
        $v = strtolower(trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($v === '') {
            return [0.0, 'none'];
        }

        // Drop currency and filler words; unify dash characters.
        $v = str_replace(["\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}"], '-', $v);
        $v = preg_replace('#/=|/-#', ' ', $v);
        $v = preg_replace('/\b(ugx|ushs?|shs?|shillings?|uganda|approx(imately)?|about|around|roughly|estimated|per\s+month|monthly|a\s+month|p\.?m\.?)\b|~/u', ' ', $v);
        $v = trim(preg_replace('/\s+/', ' ', $v));

        // Open-ended upwards: "above 2m", "over 500k", "1m+", "2m and above".
        $openUp = null;
        if (preg_match('/^(above|over|more\s+than|greater\s+than|at\s+least|min(imum)?)\s+(.+)$/', $v, $m)) {
            $openUp = $m[3];
        } elseif (preg_match('/^(.+?)\s*(\+|and\s+above|or\s+more|and\s+over|plus)$/', $v, $m)) {
            $openUp = $m[1];
        }
        if ($openUp !== null) {
            [$amount] = loan_assessment_parse_amount_part($openUp);
            return $amount > 0 ? [round($amount), 'range'] : [0.0, 'none'];
        }

        // Open-ended downwards: "below 50,000", "under 100k", "up to 1m".
        if (preg_match('/^(below|under|less\s+than|up\s+to|not\s+more\s+than|max(imum)?|at\s+most)\s+(.+)$/', $v, $m)) {
            [$amount] = loan_assessment_parse_amount_part($m[3]);
            return $amount > 0 ? [round($amount / 2), 'range'] : [0.0, 'none'];
        }

        // Ranges: "a - b", "a to b", "between a and b", "from a to b".
        $v = preg_replace('/^(between|from)\s+/', '', $v);
        if (preg_match('/^(.*?\d.*?)\s*(?:-|\bto\b|\band\b|\/)\s*(\d.*)$/', $v, $m)) {
            [$low, $lowUnit] = loan_assessment_parse_amount_part($m[1]);
            [$high, $highUnit] = loan_assessment_parse_amount_part($m[2]);
            // "1 - 2M": the unit on the right also applies to a bare small number on the left.
            if ($lowUnit === 1.0 && $highUnit > 1.0 && $low > 0 && $low < 1000) {
                $low *= $highUnit;
            }
            if ($low > 0 && $high > 0) {
                return [round(($low + $high) / 2), 'range'];
            }
            if ($low > 0 || $high > 0) {
                return [max($low, $high), 'number'];
            }
            return [0.0, 'none'];
        }

        [$amount] = loan_assessment_parse_amount_part($v);
        return $amount > 0 ? [$amount, 'number'] : [0.0, 'none'];
    }

    /**
     * THE loan limit formula, shared by every sign-up path and the loan pages:
     *
     *   score   = business-type score (1-10, unknown types score 3)
     *   average = (turnover last month + turnover this month) / 2
     *   limit   = average + average * (score / 10)
     *
     * Turnover may be a number, "200k", a range ("100,000 - 500,000") etc.
     *
     * @return array{score:int, limit:float, last:float, this:float, has_turnover:bool}
     */
    function loan_assessment_limit(string $businessType, $amountLast, $amountThis, ?int $userId = null): array
    {
        // With a client ID, approved credit documents (statements, bills,
        // records) also count: see includes/credit_scoring.php.
        if ($userId !== null && $userId > 0 && function_exists('credit_profile_compute') && ($db = credit_db()) !== null) {
            $p = credit_profile_compute($db, $businessType, $amountLast, $amountThis, credit_approved_evidence($db, $userId));
            if ($p['verified_income'] > 0 || $p['score'] > $p['base_score']) {
                return [
                    'score'        => $p['score'],
                    'limit'        => $p['limit'],
                    'last'         => $p['income_used'],
                    'this'         => $p['income_used'],
                    'has_turnover' => $p['has_turnover'],
                ];
            }
        }

        [$score] = loan_assessment_business_score($businessType);
        [$last] = loan_assessment_parse_turnover((string)$amountLast);
        [$this_] = loan_assessment_parse_turnover((string)$amountThis);
        $has = $last > 0 || $this_ > 0;
        $avg = ($last + $this_) / 2;
        return [
            'score'        => $score,
            'limit'        => $has ? round($avg + $avg * $score / 10) : 0.0,
            'last'         => $last,
            'this'         => $this_,
            'has_turnover' => $has,
        ];
    }

    /**
     * @param array|null $customer Row with business_type, amount_last, amount_this and
     *                             (for comparison) credit_score, loan_limit, business_period.
     *                             Pass null for staff members / startups (no automated scoring).
     */
    function loan_assessment(?array $customer, float $requested): array
    {
        if ($customer === null) {
            return [
                'available' => false,
                'show'      => true,
                'tone'      => 'secondary',
                'headline'  => 'No automated assessment',
                'detail'    => 'Automatic scoring only runs for customers who registered through the website or app.',
                'warnings'  => [],
            ];
        }

        $businessType = (string)($customer['business_type'] ?? '');
        [$score, $matchedType] = loan_assessment_business_score($businessType);

        $calc = loan_assessment_limit(
            $businessType,
            (string)($customer['amount_last'] ?? ''),
            (string)($customer['amount_this'] ?? ''),
            isset($customer['user_id']) ? (int)$customer['user_id'] : null
        );
        $score = $calc['score'];
        $hasTurnover = $calc['has_turnover'];
        $formulaLimit = $calc['limit'];

        $band = match (true) {
            $score >= 7 => ['Strong', 'success'],
            $score >= 5 => ['Moderate', 'warning'],
            default     => ['Weak', 'danger'],
        };

        $savedScoreRaw = $customer['credit_score'] ?? null;
        $savedScore = is_numeric($savedScoreRaw) ? (float)$savedScoreRaw : null;
        $savedLimit = (float)($customer['loan_limit'] ?? 0);

        // ---- Recommendation (formula only) ----
        if (!$hasTurnover) {
            $tone = 'warning';
            $headline = 'No recommendation - review manually';
            $detail = 'The client did not declare turnover we can read, so the formula cannot be applied.';
        } elseif ($requested <= $formulaLimit) {
            $tone = 'success';
            $headline = 'Recommend: approve up to ' . loan_assessment_money($formulaLimit);
            $detail = 'Requested amount is within the formula limit ('
                . round(($requested / $formulaLimit) * 100) . '% of limit).';
        } else {
            $tone = 'danger';
            $headline = 'Recommend: reduce to ' . loan_assessment_money($formulaLimit);
            $detail = 'Requested amount is ' . loan_assessment_money($requested - $formulaLimit)
                . ' above the formula limit.';
        }

        // ---- Warnings about the saved record ----
        $warnings = [];
        if ($matchedType === null) {
            $warnings[] = 'Business type "' . ($businessType !== '' ? $businessType : 'not given')
                . '" is not in the scoring table, so the lowest score (' . LOAN_ASSESSMENT_DEFAULT_SCORE . ') was used.';
        }
        if ($savedScore === null || $savedScore <= 0) {
            $warnings[] = 'Saved credit score is missing or 0 - it was never set or was cleared.';
        } elseif ($savedScore > 10) {
            $warnings[] = 'Saved credit score (' . loan_assessment_num($savedScore) . ') is on a different scale and was entered by hand.';
        } elseif (abs($savedScore - $score) >= 0.5) {
            $warnings[] = 'Saved credit score (' . loan_assessment_num($savedScore) . '/10) does not match the business-type score (' . $score . '/10).';
        }
        if ($hasTurnover && $savedLimit > 0) {
            $diff = $savedLimit - $formulaLimit;
            if ($diff > max(1000, $formulaLimit * 0.01)) {
                $warnings[] = 'Saved loan limit ' . loan_assessment_money($savedLimit) . ' was set manually - '
                    . loan_assessment_money($diff) . ' above the formula.';
            } elseif (-$diff > max(1000, $formulaLimit * 0.01)) {
                $warnings[] = 'Saved loan limit ' . loan_assessment_money($savedLimit) . ' is '
                    . loan_assessment_money(-$diff) . ' below the formula (the app blocks requests above the saved limit).';
            }
        } elseif (!$hasTurnover && $savedLimit > 0) {
            $warnings[] = 'Saved loan limit ' . loan_assessment_money($savedLimit)
                . ' cannot come from the formula (no turnover declared) - it was set manually.';
        }

        return [
            'available'       => true,
            // Nothing useful to say without turnover, so reviewers don't see it.
            'show'            => $hasTurnover,
            'tone'            => $tone,
            'headline'        => $headline,
            'detail'          => $detail,
            'warnings'        => $warnings,
            'score'           => $score,
            'score_band'      => $band[0],
            'score_tone'      => $band[1],
            'limit'           => $formulaLimit,
            'saved_score'     => $savedScore,
            'saved_limit'     => $savedLimit,
            'requested'       => $requested,
            'business_type'   => $businessType,
            'business_period' => (string)($customer['business_period'] ?? ''),
            'amount_last'     => (string)($customer['amount_last'] ?? ''),
            'amount_this'     => (string)($customer['amount_this'] ?? ''),
        ];
    }

    /**
     * AI recommended amount for a list row that carries assess_business_type /
     * assess_amount_last / assess_amount_this (customers only). 0 = nothing to show.
     */
    function ai_recommended_amount(array $row): float
    {
        if (!array_key_exists('assess_business_type', $row) || $row['assess_business_type'] === null) {
            return 0.0;
        }
        $a = loan_assessment([
            'user_id'       => (int)($row['client_id'] ?? 0),
            'business_type' => (string)$row['assess_business_type'],
            'amount_last'   => (string)($row['assess_amount_last'] ?? ''),
            'amount_this'   => (string)($row['assess_amount_this'] ?? ''),
        ], (float)($row['amount'] ?? 0));
        return $a['available'] ? (float)$a['limit'] : 0.0;
    }

    function loan_assessment_money(float $amount): string
    {
        return 'UGX ' . number_format($amount);
    }

    function loan_assessment_num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2), '0'), '.');
    }

    /** Turnover is stored as a number (app) or a range label (website). */
    function loan_assessment_turnover(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Not given';
        }
        $plain = str_replace([',', ' '], '', $value);
        return is_numeric($plain) ? loan_assessment_money((float)$plain) : 'UGX ' . $value;
    }
}

require_once __DIR__ . '/credit_scoring.php';
