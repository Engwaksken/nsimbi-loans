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
 * Website sign-ups declare turnover as a range label and the website formula
 * adjusts it (last × 0.9, this × 1.1) before averaging; app sign-ups declare
 * plain numbers. We mirror whichever form the client used.
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
        'Poultry'                  => 4,
        'Sell second hand clothes' => 4,
    ]);

    /** Score for anything not in the table ("Other", blank, free text). Conservative. */
    define('LOAN_ASSESSMENT_DEFAULT_SCORE', 3);

    /** Midpoints of the website's turnover range options. */
    define('LOAN_ASSESSMENT_RANGES', [
        '/^\s*10,?000\s*[-–—]\s*50,?000\s*$/i'          => 30000,
        '/^\s*50,?000\s*[-–—]\s*100,?000\s*$/i'         => 75000,
        '/^\s*100,?000\s*[-–—]\s*500,?000\s*$/i'        => 300000,
        '/^\s*500,?000\s*[-–—]\s*(1\s*m|1,000,000)\s*$/i' => 750000,
        '/^\s*1\s*[-–—]\s*2\s*m\s*$/i'                  => 1500000,
        '/^\s*(above|over|more than)\s*2\s*m\s*$/i'     => 2500000,
    ]);

    function loan_assessment_business_score(string $type): array
    {
        $type = trim($type);
        if ($type !== '') {
            foreach (LOAN_ASSESSMENT_SCORES as $name => $score) {
                if (stripos($type, $name) !== false || stripos($name, $type) !== false) {
                    return [$score, $name];
                }
            }
        }
        return [LOAN_ASSESSMENT_DEFAULT_SCORE, null];
    }

    /**
     * @return array{0: float, 1: string} [amount, 'number'|'range'|'none']
     */
    function loan_assessment_parse_turnover(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [0.0, 'none'];
        }
        $plain = str_replace([',', ' '], '', $value);
        if (is_numeric($plain)) {
            return [(float)$plain, 'number'];
        }
        foreach (LOAN_ASSESSMENT_RANGES as $pattern => $mid) {
            if (preg_match($pattern, $value)) {
                return [(float)$mid, 'range'];
            }
        }
        return [0.0, 'none'];
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

        [$last, $lastKind] = loan_assessment_parse_turnover((string)($customer['amount_last'] ?? ''));
        [$this_, $thisKind] = loan_assessment_parse_turnover((string)($customer['amount_this'] ?? ''));

        $hasTurnover = $last > 0 || $this_ > 0;
        $formulaLimit = 0.0;
        if ($hasTurnover) {
            if ($lastKind === 'range' || $thisKind === 'range') {
                $last *= 0.9;
                $this_ *= 1.1;
            }
            $avg = ($last + $this_) / 2;
            $formulaLimit = round($avg + $avg * $score / 10);
        }

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
            $headline = 'No recommendation — review manually';
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
            $warnings[] = 'Saved credit score is missing or 0 — it was never set or was cleared.';
        } elseif ($savedScore > 10) {
            $warnings[] = 'Saved credit score (' . loan_assessment_num($savedScore) . ') is on a different scale and was entered by hand.';
        } elseif (abs($savedScore - $score) >= 0.5) {
            $warnings[] = 'Saved credit score (' . loan_assessment_num($savedScore) . '/10) does not match the business-type score (' . $score . '/10).';
        }
        if ($hasTurnover && $savedLimit > 0) {
            $diff = $savedLimit - $formulaLimit;
            if ($diff > max(1000, $formulaLimit * 0.01)) {
                $warnings[] = 'Saved loan limit ' . loan_assessment_money($savedLimit) . ' was set manually — '
                    . loan_assessment_money($diff) . ' above the formula.';
            } elseif (-$diff > max(1000, $formulaLimit * 0.01)) {
                $warnings[] = 'Saved loan limit ' . loan_assessment_money($savedLimit) . ' is '
                    . loan_assessment_money(-$diff) . ' below the formula (the app blocks requests above the saved limit).';
            }
        } elseif (!$hasTurnover && $savedLimit > 0) {
            $warnings[] = 'Saved loan limit ' . loan_assessment_money($savedLimit)
                . ' cannot come from the formula (no turnover declared) — it was set manually.';
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
