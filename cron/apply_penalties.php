<?php
declare(strict_types=1);

/**
 * Charge late penalties on overdue product loans.
 *
 * Penalties also run at most once an hour when staff or the app use the
 * system; schedule this for exact timing, e.g. daily in cPanel > Cron Jobs:
 *   15 0 * * * /usr/local/bin/php /home/<account>/public_html/cron/apply_penalties.php
 * or, where only a web address works (needs CRON_KEY in .env):
 *   15 0 * * * wget -q -O /dev/null "https://<site>/cron/apply_penalties.php?key=<CRON_KEY>"
 * Admin > System > Cron Jobs shows the exact commands for this server.
 */
require_once dirname(__DIR__) . '/includes/env.php';
require_once dirname(__DIR__) . '/includes/cron_jobs.php';
$via = cron_authorise();

require_once dirname(__DIR__) . '/includes/database.php';
echo cron_job_run($conn, 'apply_penalties', $via) . "\n";
