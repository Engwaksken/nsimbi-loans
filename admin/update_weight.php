<?php
declare(strict_types=1);

/*
 * Score weights API for manage-score-weights.php.
 *
 *   {"action":"update","id":1,"weight":0.25}  change one weight
 *   {"action":"normalize"}                    scale all weights so they total 1.00
 *
 * The total of all weights may never go above 1.00. Reviewers (role 1) only.
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

function weights_respond(bool $ok, string $message, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['success' => $ok, 'message' => $message] + $extra);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    weights_respond(false, 'Only reviewers can change score weights.', [], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    weights_respond(false, 'Invalid request method.', [], 405);
}

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    weights_respond(false, 'Invalid input data.', [], 422);
}

/** @return array<int, float> id => weight */
function weights_load(mysqli $conn): array
{
    $out = [];
    $res = $conn->query('SELECT id, weight FROM scoreWeights ORDER BY id ASC');
    while ($res && ($r = $res->fetch_assoc())) {
        $out[(int)$r['id']] = (float)$r['weight'];
    }
    return $out;
}

$action = (string)($data['action'] ?? 'update');

if ($action === 'normalize') {
    $weights = weights_load($conn);
    if (!$weights) {
        weights_respond(false, 'No score weights found.');
    }

    $total = array_sum($weights);
    $count = count($weights);

    // Scale every weight by the same factor so their proportions stay the
    // same. If everything is 0, share equally.
    $new = [];
    foreach ($weights as $id => $w) {
        $new[$id] = $total > 0 ? round($w / $total, 2) : round(1 / $count, 2);
    }
    // Rounding can leave 0.99 or 1.01; put the difference on the largest weight.
    $diff = round(1.0 - array_sum($new), 2);
    if ($diff != 0.0) {
        $largest = array_keys($new, max($new))[0];
        $new[$largest] = round($new[$largest] + $diff, 2);
    }

    $stmt = $conn->prepare('UPDATE scoreWeights SET weight = ? WHERE id = ?');
    $conn->begin_transaction();
    try {
        foreach ($new as $id => $w) {
            $stmt->bind_param('di', $w, $id);
            $stmt->execute();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Normalize score weights failed: ' . $e->getMessage());
        weights_respond(false, 'Could not balance the weights. Please try again.', [], 500);
    }
    $stmt->close();

    weights_respond(true, 'Weights balanced to a total of 1.00.', ['weights' => $new]);
}

// ---- Update one weight ----
$id = (int)($data['id'] ?? 0);
$weight = round((float)($data['weight'] ?? -1), 2);

if ($id <= 0) {
    weights_respond(false, 'Invalid score component selected.', [], 422);
}
if ($weight < 0 || $weight > 1) {
    weights_respond(false, 'Weight must be between 0 and 1.', [], 422);
}

$weights = weights_load($conn);
if (!array_key_exists($id, $weights)) {
    weights_respond(false, 'Score component not found.', [], 404);
}

$othersTotal = array_sum($weights) - $weights[$id];
$newTotal = round($othersTotal + $weight, 2);
if ($newTotal > 1.0) {
    $max = max(0, round(1.0 - $othersTotal, 2));
    weights_respond(false, sprintf(
        'That would make the total %.2f. The total cannot go above 1.00, so this weight can be at most %.2f. Lower another component first.',
        $newTotal,
        $max
    ), [], 422);
}

$stmt = $conn->prepare('UPDATE scoreWeights SET weight = ? WHERE id = ?');
$stmt->bind_param('di', $weight, $id);
if (!$stmt->execute()) {
    error_log('Update score weight failed: ' . $stmt->error);
    weights_respond(false, 'Could not save the weight. Please try again.', [], 500);
}
$stmt->close();

weights_respond(true, sprintf('Weight updated. Total is now %.2f.', $newTotal), ['total' => $newTotal]);
