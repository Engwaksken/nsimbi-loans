<?php
declare(strict_types=1);

/*
 * Score weights (table scoreWeights): how much each credit score component
 * counts. Used by admin/manage-score-weights.php and admin/update_weight.php.
 *
 * The weights must total 1.00. Scoring itself (credit_weights() in
 * includes/credit_scoring.php) reads one row per known component, the newest
 * if a name is stored twice, and ignores names it does not know. This file
 * reads the table the same way, reports anything untidy (duplicates, unused
 * rows, missing components, a total other than 1.00) and can put it right.
 */

require_once __DIR__ . '/loan_assessment.php'; // also loads credit_scoring.php

/** Plain-language description of each component, for the admin page. */
const SCORE_WEIGHT_HELP = [
    'sectorRisk'              => 'The business-type score (System > Manage Sectors).',
    'esoRecommendation'       => 'Not collected yet; counts at the business-type score.',
    'mobileMoneyTransactions' => 'Approved mobile money statement; 6 months for the top score.',
    'personalRecords'         => 'Water or electricity bills, 6 months in a row on the same account.',
    'businessRecords'         => 'URSB registration, trading licence, TIN, permits, sales records.',
    'repaymentHistory'        => 'How the client paid earlier and current loans. Can lower the score.',
    'documentation'           => 'ID, photos, licence and signature given at registration.',
];

/**
 * The table as scoring sees it.
 *
 * @return array{
 *   weights: array<string, float>,  component => stored weight (0 if missing)
 *   ids: array<string, int>,        component => id of the row scoring uses
 *   total: float,                   total of the weights scoring uses
 *   duplicates: int,                extra rows for a component already stored
 *   unused: string[],               names scoring does not know
 *   missing: string[],              known components with no row
 *   raw_total: float,               total of every row, as the old page showed
 * }
 */
function score_weights_state(mysqli $conn): array
{
    credit_ensure_weights($conn);
    $known = array_keys(CREDIT_COMPONENTS);
    $weights = array_fill_keys($known, 0.0);
    $ids = [];
    $seen = [];
    $duplicates = 0;
    $unused = [];
    $rawTotal = 0.0;

    $res = $conn->query('SELECT id, name, weight FROM scoreWeights ORDER BY id ASC');
    while ($res && ($r = $res->fetch_assoc())) {
        $name = (string)$r['name'];
        $rawTotal += (float)$r['weight'];
        if (!array_key_exists($name, $weights)) {
            $unused[] = $name;
            continue;
        }
        if (isset($seen[$name])) {
            $duplicates++;
        }
        $seen[$name] = true;
        // Newest row wins, as in credit_weights().
        $weights[$name] = max(0.0, (float)$r['weight']);
        $ids[$name] = (int)$r['id'];
    }

    return [
        'weights'    => $weights,
        'ids'        => $ids,
        'total'      => round(array_sum($weights), 4),
        'duplicates' => $duplicates,
        'unused'     => array_values(array_unique($unused)),
        'missing'    => array_values(array_diff($known, array_keys($seen))),
        'raw_total'  => round($rawTotal, 4),
    ];
}

/** True when there is exactly one row per component and they total 1.00. */
function score_weights_ok(array $state): bool
{
    return abs($state['total'] - 1.0) < 0.001
        && $state['duplicates'] === 0
        && !$state['unused']
        && !$state['missing'];
}

/**
 * Scale weights so they total exactly 1.00 in steps of 0.01, keeping each
 * one's share. Rounding leftovers go to the largest remainders. All zero:
 * shared equally.
 *
 * @param array<string, float> $weights
 * @return array<string, float>
 */
function score_weights_balance(array $weights): array
{
    $total = array_sum(array_map(static fn($w) => max(0.0, (float)$w), $weights));
    $n = count($weights);
    if ($n === 0) {
        return [];
    }
    $hundredths = [];
    $remainders = [];
    foreach ($weights as $name => $w) {
        $exact = $total > 0 ? max(0.0, (float)$w) / $total * 100 : 100 / $n;
        $hundredths[$name] = (int)floor($exact + 1e-9);
        $remainders[$name] = $exact - $hundredths[$name];
    }
    $left = 100 - array_sum($hundredths);
    arsort($remainders);
    foreach (array_keys($remainders) as $name) {
        if ($left <= 0) {
            break;
        }
        $hundredths[$name]++;
        $left--;
    }
    return array_map(static fn($h) => $h / 100, $hundredths);
}

/**
 * Write one weight per component, remove duplicate and unused rows, add any
 * missing component, and stop names being stored twice again.
 *
 * @param array<string, float> $weights every known component => weight; must total 1.00
 * @return array{removed: int, added: int}
 */
function score_weights_save(mysqli $conn, array $weights): array
{
    $known = array_keys(CREDIT_COMPONENTS);
    $clean = [];
    foreach ($known as $name) {
        if (!array_key_exists($name, $weights)) {
            throw new DomainException('A weight is missing for ' . CREDIT_COMPONENTS[$name]['label'] . '.');
        }
        $w = round((float)$weights[$name], 2);
        if ($w < 0 || $w > 1) {
            throw new DomainException(CREDIT_COMPONENTS[$name]['label'] . ' must be between 0 and 1.');
        }
        $clean[$name] = $w;
    }
    $total = round(array_sum($clean), 2);
    if (abs($total - 1.0) > 0.001) {
        throw new DomainException(sprintf('The weights add up to %.2f. They must add up to exactly 1.00.', $total));
    }

    $state = score_weights_state($conn);
    $removed = 0;
    $added = 0;

    $conn->begin_transaction();
    try {
        $update = $conn->prepare('UPDATE scoreWeights SET weight = ? WHERE id = ?');
        $insert = $conn->prepare('INSERT INTO scoreWeights (name, weight) VALUES (?, ?)');
        foreach ($clean as $name => $w) {
            if (isset($state['ids'][$name])) {
                $id = $state['ids'][$name];
                $update->bind_param('di', $w, $id);
                $update->execute();
            } else {
                $insert->bind_param('sd', $name, $w);
                $insert->execute();
                $state['ids'][$name] = (int)$insert->insert_id;
                $added++;
            }
        }
        $update->close();
        $insert->close();

        // Keep only the row per component that holds the weight; drop names
        // scoring does not use.
        $keep = array_values($state['ids']);
        $placeholders = implode(',', array_fill(0, count($keep), '?'));
        $delete = $conn->prepare("DELETE FROM scoreWeights WHERE id NOT IN ($placeholders)");
        $delete->bind_param(str_repeat('i', count($keep)), ...$keep);
        $delete->execute();
        $removed = $delete->affected_rows;
        $delete->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    // One row per name from now on (a unique key needs the duplicates gone first).
    try {
        $idx = $conn->query("SHOW INDEX FROM scoreWeights WHERE Key_name = 'uq_scoreweights_name'");
        if ($idx instanceof mysqli_result && $idx->num_rows === 0) {
            $conn->query('ALTER TABLE scoreWeights ADD UNIQUE KEY uq_scoreweights_name (name)');
        }
    } catch (Throwable $e) {
        error_log('score weights unique key: ' . $e->getMessage());
    }

    return ['removed' => max(0, $removed), 'added' => $added];
}
