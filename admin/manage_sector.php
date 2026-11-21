<?php
declare(strict_types=1);

/*
 * JSON endpoint for System > Manage Sectors: add (POST), edit (PUT) and
 * delete (DELETE ?id=) a sector score. Reviewers (role 1) only.
 */

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/sector_scores.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

function sector_reply(bool $ok, string $message, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['success' => $ok, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['logged_in']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    sector_reply(false, 'Only reviewers can change sector scores.', 403);
}
// Same-origin AJAX only (the page sends this header; a cross-site form cannot).
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    sector_reply(false, 'Invalid request.', 400);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = json_decode((string)file_get_contents('php://input'), true);
$data = is_array($data) ? $data : [];

try {
    sector_ensure_table($conn);

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        $s = $conn->prepare('DELETE FROM sectorRiskScores WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $ok = $s->affected_rows === 1;
        $s->close();
        sector_reply($ok, $ok ? 'Sector deleted.' : 'Sector not found.', $ok ? 200 : 404);
    }

    if ($method !== 'POST' && $method !== 'PUT') {
        sector_reply(false, 'Method not allowed.', 405);
    }

    $name = trim(preg_replace('/\s+/', ' ', (string)($data['name'] ?? '')) ?? '');
    $score = $data['score'] ?? null;
    if ($name === '' || mb_strlen($name) > 150) {
        sector_reply(false, 'Enter a sector name (up to 150 characters).', 422);
    }
    if (!is_numeric($score) || (float)$score < 0 || (float)$score > 10) {
        sector_reply(false, 'Score must be between 0 and 10.', 422);
    }
    $score = round((float)$score, 1);
    $id = $method === 'PUT' ? (int)($data['id'] ?? 0) : 0;

    // No two sectors with the same name (case ignored).
    $s = $conn->prepare('SELECT id FROM sectorRiskScores WHERE LOWER(TRIM(name)) = LOWER(?) AND id <> ? LIMIT 1');
    $s->bind_param('si', $name, $id);
    $s->execute();
    $dup = $s->get_result()->fetch_assoc();
    $s->close();
    if ($dup) {
        sector_reply(false, 'A sector with this name already exists.', 422);
    }

    if ($method === 'POST') {
        $s = $conn->prepare('INSERT INTO sectorRiskScores (name, score) VALUES (?, ?)');
        $s->bind_param('sd', $name, $score);
        $s->execute();
        $s->close();
        sector_reply(true, 'Sector added.');
    }

    $s = $conn->prepare('SELECT id FROM sectorRiskScores WHERE id = ? LIMIT 1');
    $s->bind_param('i', $id);
    $s->execute();
    $exists = (bool)$s->get_result()->fetch_assoc();
    $s->close();
    if (!$exists) {
        sector_reply(false, 'Sector not found.', 404);
    }
    $s = $conn->prepare('UPDATE sectorRiskScores SET name = ?, score = ? WHERE id = ?');
    $s->bind_param('sdi', $name, $score, $id);
    $s->execute();
    $s->close();
    sector_reply(true, 'Sector saved.');
} catch (Throwable $e) {
    error_log('manage_sector: ' . $e->getMessage());
    sector_reply(false, 'Could not save the sector. Check the server log.', 500);
}
