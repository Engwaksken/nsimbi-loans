<?php
declare(strict_types=1);

/**
 * Automated loan assessment shown to reviewers.
 *
 * When a customer registers (website or mobile app) the system scores their
 * business type (1-10) and works out a recommended loan limit from the
 * turnover they declared. The app refuses requests above that limit. This
 * helper turns those stored values into a recommendation that reviewers can
 * see next to each application before they approve it.
 */

if (!function_exists('loan_assessment')) {
    /**
     * @param array|null $customer Row with credit_score, loan_limit and, optionally,
     *                             business_type, business_period, amount_last, amount_this.
     *                             Pass null for staff members / startups (no automated scoring).
     */
    function loan_assessment(?array $customer, float $requested): array
    {
        if ($customer === null) {
            return [
                'available' => false,
                'tone'      => 'secondary',
                'headline'  => 'No automated assessment',
                'detail'    => 'Automatic scoring only runs for customers who registered through the website or app.',
            ];
        }

        $score = (float)($customer['credit_score'] ?? 0);
        $limit = (float)($customer['loan_limit'] ?? 0);

        $band = match (true) {
            $score >= 7 => ['Strong', 'success'],
            $score >= 5 => ['Moderate', 'warning'],
            $score > 0  => ['Weak', 'danger'],
            default     => ['Not scored', 'secondary'],
        };

        if ($limit <= 0) {
            $tone = 'warning';
            $headline = 'No recommended limit on file';
            $detail = 'The client did not declare turnover, so no limit was calculated. Review manually.';
        } elseif ($requested <= $limit) {
            $tone = 'success';
            $headline = 'Recommend: approve up to ' . loan_assessment_money($limit);
            $detail = 'Requested amount is within the recommended limit ('
                . round(($requested / $limit) * 100) . '% of limit).';
        } else {
            $tone = 'danger';
            $headline = 'Recommend: reduce to ' . loan_assessment_money($limit);
            $detail = 'Requested amount exceeds the recommended limit by '
                . loan_assessment_money($requested - $limit) . '.';
        }

        return [
            'available'       => true,
            'tone'            => $tone,
            'headline'        => $headline,
            'detail'          => $detail,
            'score'           => $score,
            'score_band'      => $band[0],
            'score_tone'      => $band[1],
            'limit'           => $limit,
            'requested'       => $requested,
            'business_type'   => (string)($customer['business_type'] ?? ''),
            'business_period' => (string)($customer['business_period'] ?? ''),
            'amount_last'     => (string)($customer['amount_last'] ?? ''),
            'amount_this'     => (string)($customer['amount_this'] ?? ''),
        ];
    }

    function loan_assessment_money(float $amount): string
    {
        return 'UGX ' . number_format($amount);
    }

    /** Turnover is stored as a number (app) or a range label (website). */
    function loan_assessment_turnover(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Not given';
        }
        return is_numeric($value) ? loan_assessment_money((float)$value) : 'UGX ' . $value;
    }
}
