<?php
declare(strict_types=1);

/**
 * Send this week's bookkeeping reminder to every client who hasn't had it.
 *
 * Reminders also go out in small batches as staff use the system; schedule
 * this for a set day and time, e.g. Monday 8am in cPanel > Cron Jobs:
 *   0 8 * * 1 /usr/local/bin/php /home/<account>/public_html/cron/weekly_bookkeeping_nudge.php
 * or, where only a web address works (needs CRON_KEY in .env):
 *   0 8 * * 1 wget -q -O /dev/null "https://<site>/cron/weekly_bookkeeping_nudge.php?key=<CRON_KEY>"
 * Admin > System > Cron Jobs shows the exact commands for this server.
 */
require_once dirname(__DIR__) . '/includes/env.php';
require_once dirname(__DIR__) . '/includes/cron_jobs.php';
$via = cron_authorise();

require_once dirname(__DIR__) . '/includes/database.php';
echo cron_job_run($conn, 'weekly_bookkeeping_nudge', $via) . "\n";
