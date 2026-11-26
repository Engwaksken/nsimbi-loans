<?php
declare(strict_types=1);

/*
 * Scheduled jobs (cPanel > Cron Jobs).
 *
 * Each job is a script in cron/ that can run two ways:
 *   - as a PHP command:   /usr/local/bin/php /home/<account>/public_html/cron/<job>.php
 *   - as a web address:   https://<site>/cron/<job>.php?key=<CRON_KEY>
 *     (for hosts where only wget/curl work; needs CRON_KEY in .env)
 * Every run is recorded, and System > Cron Jobs shows the commands for this
 * server, when each job last ran, and a "Run now" button.
 */

require_once __DIR__ . '/loan_offers.php';

const CRON_JOBS = [
    'apply_penalties' => [
        'title'       => 'Late penalties',
        'description' => 'Charges late penalties on overdue product loans. Also runs at most once an hour while staff use the system.',
        'schedule'    => '15 0 * * *',
        'when'        => 'Every day at 00:15',
    ],
    'weekly_bookkeeping_nudge' => [
        'title'       => 'Weekly bookkeeping reminder',
        'description' => 'Emails every active client a reminder to record sales and expenses in My Books. Also goes out in small batches while staff use the system.',
        'schedule'    => '0 8 * * 1',
        'when'        => 'Every Monday at 08:00',
    ],
];

/**
 * Stop unless this is a command-line run or a web request with the right
 * ?key=. Answers 404 otherwise, so the scripts look like they don't exist.
 * Returns 'cli' or 'web'.
 */
function cron_authorise(): string
{
    if (PHP_SAPI === 'cli') {
        return 'cli';
    }
    $key = (string)(getenv('CRON_KEY') ?: '');
    $given = (string)($_GET['key'] ?? '');
    if (strlen($key) < 16 || $given === '' || !hash_equals($key, $given)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    ignore_user_abort(true);
    @set_time_limit(600);
    return 'web';
}

/** Run one job now and record the run; returns a one-line summary. */
function cron_job_run(mysqli $conn, string $job, string $via): string
{
    if (!isset(CRON_JOBS[$job])) {
        throw new InvalidArgumentException("Unknown cron job: {$job}");
    }
    $started = microtime(true);
    try {
        $summary = match ($job) {
            'apply_penalties' => (static function () use ($conn): string {
                require_once __DIR__ . '/loan_products.php';
                $charged = loan_penalties_run($conn);
                app_setting_set($conn, 'loan_penalties_last_run', (string)time(), 0);
                return "Penalties charged on {$charged} loan(s).";
            })(),
            'weekly_bookkeeping_nudge' => (static function () use ($conn): string {
                require_once __DIR__ . '/bookkeeping_nudge.php';
                $week = bookkeeping_nudge_week();
                $total = 0;
                while (bookkeeping_nudge_due($conn, $week, 1)) {
                    $total += bookkeeping_nudge_send($conn, 100);
                }
                return "Bookkeeping reminder sent to {$total} client(s) for {$week}.";
            })(),
        };
        $ok = true;
    } catch (Throwable $e) {
        error_log("Cron job {$job} failed: " . $e->getMessage());
        $summary = 'Failed: ' . $e->getMessage();
        $ok = false;
    }
    $record = [
        'time'    => time(),
        'ok'      => $ok,
        'via'     => $via,
        'seconds' => round(microtime(true) - $started, 1),
        'summary' => $summary,
    ];
    try {
        app_setting_set($conn, 'cron_last_' . $job, (string)json_encode($record), 0);
    } catch (Throwable $e) {
        error_log("Cron job {$job}: could not record the run: " . $e->getMessage());
    }
    return date('Y-m-d H:i:s') . ' ' . $summary;
}

/** The last recorded run of a job, or null. */
function cron_job_last(mysqli $conn, string $job): ?array
{
    try {
        $raw = app_setting_get($conn, 'cron_last_' . $job, '');
    } catch (Throwable $e) {
        return null;
    }
    $data = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

/** Absolute path of a job's script on this server. */
function cron_job_path(string $job): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . $job . '.php';
}

/** The site's public address, from SITE_URL in .env or the current request. */
function cron_site_url(): string
{
    $url = trim((string)(getenv('SITE_URL') ?: ''));
    if ($url !== '' && !str_contains($url, 'example.com')) {
        return rtrim($url, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
