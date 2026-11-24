<?php
declare(strict_types=1);

/**
 * Charge late penalties on overdue product loans.
 *
 * Penalties also run at most once an hour when staff or the app use the
 * system; schedule this for exact timing, e.g. daily in cPanel > Cron Jobs:
 *   15 0 * * * php /home/<account>/public_html/cron/apply_penalties.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/loan_products.php';

$charged = loan_penalties_run($conn);
app_setting_set($conn, 'loan_penalties_last_run', (string)time(), 0);
echo date('Y-m-d H:i:s') . " penalties charged on {$charged} loan(s)\n";
