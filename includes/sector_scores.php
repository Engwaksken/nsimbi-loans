<?php
declare(strict_types=1);

/*
 * Business sector scores (0-10, higher = a safer business to lend to).
 *
 * They are kept in the sectorRiskScores table and edited on System > Manage
 * Sectors. Until an admin switches it on there, credit scoring keeps using the
 * built-in list LOAN_ASSESSMENT_SCORES (includes/loan_assessment.php), so
 * deploying this changes no client's score. Switching on either copies the
 * built-in scores into the table first (no score changes) or uses the table
 * as it is.
 */

const SECTOR_SETTING_KEY = 'sector_scores_from_table';

function sector_table_exists(mysqli $conn): bool
{
    static $exists = null;
    if ($exists === null) {
        try {
            $r = $conn->query("SHOW TABLES LIKE 'sectorRiskScores'");
            $exists = $r && $r->num_rows > 0;
        } catch (Throwable $e) {
            $exists = false;
        }
    }
    return $exists;
}

function sector_ensure_table(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS sectorRiskScores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            score DECIMAL(4,1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** @return array<string, float> name => score from the table, best first */
function sector_table_scores(mysqli $conn): array
{
    if (!sector_table_exists($conn)) {
        return [];
    }
    $out = [];
    try {
        $res = $conn->query('SELECT name, score FROM sectorRiskScores ORDER BY score DESC, name');
        while ($res && ($r = $res->fetch_assoc())) {
            $name = trim((string)$r['name']);
            if ($name !== '' && !isset($out[$name])) {
                $out[$name] = round(max(0.0, min(10.0, (float)$r['score'])), 1);
            }
        }
    } catch (Throwable $e) {
        error_log('sector_table_scores: ' . $e->getMessage());
    }
    return $out;
}

function sector_use_table(mysqli $conn): bool
{
    return function_exists('credit_setting') && credit_setting($conn, SECTOR_SETTING_KEY, '0') === '1';
}

function sector_set_use_table(mysqli $conn, bool $on): void
{
    credit_setting_set($conn, SECTOR_SETTING_KEY, $on ? '1' : '0');
}

/**
 * Where the table and the built-in list disagree.
 * @return array<int, array{name: string, table: ?float, builtin: ?float}>
 */
function sector_differences(mysqli $conn): array
{
    $table = sector_table_scores($conn);
    $tableLower = [];
    foreach ($table as $n => $s) {
        $tableLower[strtolower($n)] = [$n, $s];
    }
    $diff = [];
    foreach (LOAN_ASSESSMENT_SCORES as $name => $score) {
        $t = $tableLower[strtolower($name)] ?? null;
        if ($t === null || abs($t[1] - $score) >= 0.05) {
            $diff[] = ['name' => $name, 'table' => $t[1] ?? null, 'builtin' => (float)$score];
        }
        unset($tableLower[strtolower($name)]);
    }
    foreach ($tableLower as [$n, $s]) {
        $diff[] = ['name' => $n, 'table' => $s, 'builtin' => null];
    }
    return $diff;
}

/** Put every built-in score into the table (adds missing sectors, overwrites differing scores). */
function sector_copy_builtin(mysqli $conn): int
{
    sector_ensure_table($conn);
    $changed = 0;
    foreach (LOAN_ASSESSMENT_SCORES as $name => $score) {
        $score = (float)$score;
        $s = $conn->prepare('SELECT id, score FROM sectorRiskScores WHERE LOWER(TRIM(name)) = LOWER(?) ORDER BY id LIMIT 1');
        $s->bind_param('s', $name);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if ($row) {
            if (abs((float)$row['score'] - $score) >= 0.05) {
                $id = (int)$row['id'];
                $u = $conn->prepare('UPDATE sectorRiskScores SET score = ? WHERE id = ?');
                $u->bind_param('di', $score, $id);
                $u->execute();
                $u->close();
                $changed++;
            }
        } else {
            $i = $conn->prepare('INSERT INTO sectorRiskScores (name, score) VALUES (?, ?)');
            $i->bind_param('sd', $name, $score);
            $i->execute();
            $i->close();
            $changed++;
        }
    }
    return $changed;
}
