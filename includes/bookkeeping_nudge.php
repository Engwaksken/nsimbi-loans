<?php
declare(strict_types=1);

/*
 * Weekly bookkeeping reminder to clients.
 *
 * Once a week every active client (role 3) gets a short message encouraging
 * them to record sales and expenses in My Books, because good records help
 * raise their credit score for the next loan. It goes through
 * loan_notify_client() (client_notifications row + email). client_nudges
 * records who got which week's message, so nobody gets it twice.
 *
 * Sending runs in small batches after a staff page has responded (at most
 * once every 15 minutes, see bookkeeping_nudge_maybe_run in admin/nav-head.php),
 * or all at once from cron/weekly_bookkeeping_nudge.php.
 */

require_once __DIR__ . '/loan_offers.php';
require_once __DIR__ . '/loan_notifications.php';

const BOOKKEEPING_NUDGE_BATCH = 25;

function bookkeeping_nudge_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $conn->query("
        CREATE TABLE IF NOT EXISTS client_nudges (
            user_id INT NOT NULL,
            kind VARCHAR(30) NOT NULL,
            week_key CHAR(8) NOT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, kind, week_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** ISO week, e.g. 2026-W48. */
function bookkeeping_nudge_week(?int $time = null): string
{
    return date('o-\WW', $time ?? time());
}

/** Active clients who haven't had this week's message yet. */
function bookkeeping_nudge_due(mysqli $conn, string $week, int $limit): array
{
    bookkeeping_nudge_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT u.id, u.fname
        FROM user u
        JOIN customers c ON c.user_id = u.id
        LEFT JOIN client_nudges n ON n.user_id = u.id AND n.kind = 'bookkeeping' AND n.week_key = ?
        WHERE u.role = 3 AND COALESCE(u.status, 1) = 1 AND n.user_id IS NULL
        GROUP BY u.id, u.fname
        ORDER BY u.id
        LIMIT ?
    ");
    $stmt->bind_param('si', $week, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Book entries the client recorded in the last 7 days (null if unknown). */
function bookkeeping_nudge_recent_entries(mysqli $conn, int $userId): ?int
{
    try {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM book_entries WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $count;
    } catch (Throwable $e) {
        return null;
    }
}

/** The message for one client: [title, body]. Tips rotate by week. */
function bookkeeping_nudge_message(string $firstName, ?int $recentEntries, string $week): array
{
    $tips = [
        'Write down every sale on the day you make it, even small ones.',
        'Record your expenses too: stock, rent, transport and airtime all count.',
        'Keep business money and home money apart, and record what you take out for home.',
        'At the end of each week, check your total sales against what you have in cash and mobile money.',
    ];
    $tip = $tips[(int)substr($week, -2) % count($tips)];
    $name = trim($firstName) !== '' ? trim($firstName) : 'there';

    if ($recentEntries !== null && $recentEntries > 0) {
        $title = 'Great work keeping your books';
        $intro = "Hello {$name}, you recorded {$recentEntries} entr" . ($recentEntries === 1 ? 'y' : 'ies') . " in My Books this week. Keep it up!";
    } else {
        $title = 'Record your sales this week';
        $intro = "Hello {$name}, this is your weekly reminder to record your sales and expenses in My Books.";
    }
    $body = $intro . "\n\n"
        . "Clients who keep regular books show us how their business is doing. That helps raise your credit score and can increase the amount you qualify for on your next loan request.\n\n"
        . "This week's tip: {$tip}\n\n"
        . "Open the Nsimbi app or log in to your account and go to My Books.";
    return [$title, $body];
}

/**
 * Send this week's message to one client (a row from bookkeeping_nudge_due).
 * Claims the client first, so two runs at once can't message them twice.
 */
function bookkeeping_nudge_send_one(mysqli $conn, array $client, string $week): bool
{
    $userId = (int)$client['id'];
    $claim = $conn->prepare("INSERT IGNORE INTO client_nudges (user_id, kind, week_key) VALUES (?, 'bookkeeping', ?)");
    $claim->bind_param('is', $userId, $week);
    $claim->execute();
    $claimed = $claim->affected_rows > 0;
    $claim->close();
    if (!$claimed) {
        return false;
    }
    try {
        [$title, $body] = bookkeeping_nudge_message((string)$client['fname'], bookkeeping_nudge_recent_entries($conn, $userId), $week);
        loan_notify_client($conn, $userId, 0, 'bookkeeping_tip', $title, $body);
        return true;
    } catch (Throwable $e) {
        error_log("Bookkeeping nudge to #{$userId} failed: " . $e->getMessage());
        return false;
    }
}

/** Send this week's message to up to $limit clients now; returns how many were sent. */
function bookkeeping_nudge_send(mysqli $conn, int $limit = BOOKKEEPING_NUDGE_BATCH): int
{
    $week = bookkeeping_nudge_week();
    $sent = 0;
    foreach (bookkeeping_nudge_due($conn, $week, $limit) as $client) {
        $sent += bookkeeping_nudge_send_one($conn, $client, $week) ? 1 : 0;
    }
    return $sent;
}

/**
 * From staff page loads: at most every 15 minutes, send a batch after the
 * page has gone to the browser. A week's messages go out over the first
 * page loads of the week.
 */
function bookkeeping_nudge_maybe_run(mysqli $conn): void
{
    try {
        $last = (int)app_setting_get($conn, 'bookkeeping_nudge_last_run', '0');
        if ($last > time() - 900) {
            return;
        }
        app_setting_set($conn, 'bookkeeping_nudge_last_run', (string)time(), 0);
        $week = bookkeeping_nudge_week();
        // One deferred job per client: the runner allows ~10 seconds per job.
        foreach (bookkeeping_nudge_due($conn, $week, BOOKKEEPING_NUDGE_BATCH) as $client) {
            loan_notify_defer(static function () use ($conn, $client, $week): void {
                bookkeeping_nudge_send_one($conn, $client, $week);
            });
        }
    } catch (Throwable $e) {
        error_log('Bookkeeping nudge check failed: ' . $e->getMessage());
    }
}
