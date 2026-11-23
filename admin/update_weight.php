<?php
declare(strict_types=1);

/*
 * Score weights API for manage-score-weights.php. Reviewers (role 1) only.
 *
 *   {"action":"save","weights":{"sectorRisk":0.3, ...}}  save every weight; must total 1.00
 *   {"action":"normalize"}                              scale the current weights to total 1.00
 *
 * Both also tidy the table: one row per component, no unused rows
 * (includes/score_weights.php).
 */

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/score_weights.php';

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

$action = (string)($data['action'] ?? '');

try {
    if ($action === 'normalize') {
        $state = score_weights_state($conn);
        $new = score_weights_balance($state['weights']);
    } elseif ($action === 'save') {
        $new = is_array($data['weights'] ?? null) ? $data['weights'] : [];
    } else {
        weights_respond(false, 'Unknown action.', [], 422);
    }

    $result = score_weights_save($conn, $new);
} catch (DomainException $e) {
    weights_respond(false, $e->getMessage(), [], 422);
} catch (Throwable $e) {
    error_log('Score weights: ' . $e->getMessage());
    weights_respond(false, 'Could not save the weights. Please try again.', [], 500);
}

$message = $action === 'normalize' ? 'Weights balanced to a total of 1.00.' : 'Weights saved. Total is 1.00.';
if ($result['removed'] > 0) {
    $message .= ' Removed ' . $result['removed'] . ' duplicate or unused row(s).';
}
if ($result['added'] > 0) {
    $message .= ' Added ' . $result['added'] . ' missing component(s).';
}
weights_respond(true, $message, ['weights' => score_weights_state($conn)['weights']]);
