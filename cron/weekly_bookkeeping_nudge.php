<?php
declare(strict_types=1);

/**
 * Send this week's bookkeeping reminder to every client who hasn't had it.
 *
 * Reminders also go out in small batches as staff use the system; schedule
 * this for a set day and time, e.g. Monday 8am in cPanel > Cron Jobs:
 *   0 8 * * 1 php /home/<account>/public_html/cron/weekly_bookkeeping_nudge.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/bookkeeping_nudge.php';

$total = 0;
$week = bookkeeping_nudge_week();
while (bookkeeping_nudge_due($conn, $week, 1)) {
    $total += bookkeeping_nudge_send($conn, 100);
}
echo date('Y-m-d H:i:s') . " bookkeeping reminder sent to {$total} client(s) for " . bookkeeping_nudge_week() . "\n";
