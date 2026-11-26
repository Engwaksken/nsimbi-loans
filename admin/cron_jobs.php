<?php
declare(strict_types=1);

/*
 * System > Cron Jobs: the scheduled jobs, the exact commands to paste into
 * cPanel > Cron Jobs on this server, when each last ran, and "Run now".
 * See includes/cron_jobs.php.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/db.php';
if (empty($_SESSION['logged_in']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    header('Location: ../login.php');
    exit;
}
require_once dirname(__DIR__) . '/includes/cron_jobs.php';

if (empty($_SESSION['cron_csrf'])) {
    $_SESSION['cron_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['cron_csrf'];

function cj_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $job = (string)($_POST['job'] ?? '');
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['cron_flash'] = ['danger', 'Your session expired. Please try again.'];
    } elseif (!isset(CRON_JOBS[$job])) {
        $_SESSION['cron_flash'] = ['danger', 'Unknown job.'];
    } else {
        @set_time_limit(600);
        $summary = cron_job_run($conn, $job, 'manual');
        $last = cron_job_last($conn, $job);
        $_SESSION['cron_flash'] = [($last['ok'] ?? false) ? 'success' : 'danger', CRON_JOBS[$job]['title'] . ': ' . $summary];
    }
    header('Location: cron_jobs.php', true, 303);
    exit;
}

$flash = $_SESSION['cron_flash'] ?? null;
unset($_SESSION['cron_flash']);

$cronKey = (string)(getenv('CRON_KEY') ?: '');
$hasKey = strlen($cronKey) >= 16;
$siteUrl = cron_site_url();
$suggestedKey = bin2hex(random_bytes(20));

$phead = 'Cron Jobs';
require 'nav-head.php';
?>

<style>
.cj-page { padding: 1rem; }
.cj-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.1rem; margin-bottom: 1rem; }
.cj-muted { color: #64748b; font-size: .82rem; }
.cj-cmd { display: flex; gap: .4rem; align-items: stretch; margin: .3rem 0 .8rem; }
.cj-cmd code { flex: 1; display: block; background: #0f172a; color: #e2e8f0; border-radius: 8px; padding: .55rem .75rem; font-size: .8rem; overflow-x: auto; white-space: nowrap; }
.cj-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
.cj-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; }
.cj-steps { margin: 0; padding-left: 1.2rem; font-size: .88rem; }
.cj-steps li { margin-bottom: .3rem; }
</style>

<div class="cj-page">
    <div class="mb-3">
        <h4 class="mb-0 fw-bold">Cron Jobs</h4>
        <div class="cj-muted">Scheduled jobs that run on their own. Add each one in cPanel so it runs on time, even when nobody is using the system.</div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= cj_e($flash[0]) ?>"><?= cj_e($flash[1]) ?></div>
    <?php endif; ?>

    <div class="cj-card">
        <h6 class="fw-bold mb-2"><i class="fas fa-list-ol me-1"></i> How to add a job in cPanel</h6>
        <ol class="cj-steps">
            <li>Log in to cPanel and open <strong>Advanced &gt; Cron Jobs</strong>.</li>
            <li>Under <strong>Add New Cron Job</strong>, choose the timing shown for the job (or type the five schedule values: minute, hour, day, month, weekday).</li>
            <li>Paste the job's <strong>Command</strong> from below and click <strong>Add New Cron Job</strong>.</li>
            <li>Come back here the next day: <strong>Last run</strong> shows it worked. If it never runs, try the web address command instead.</li>
        </ol>
        <div class="cj-muted mt-2">The PHP command uses <code>/usr/local/bin/php</code>, the usual path on cPanel. If your host uses another path (cPanel shows it in <em>Cron Jobs</em> examples), change that part.</div>
    </div>

    <?php foreach (CRON_JOBS as $job => $info):
        $last = cron_job_last($conn, $job);
        $cliCommand = '/usr/local/bin/php -q ' . cron_job_path($job) . ' >/dev/null 2>&1';
        $webCommand = 'wget -q -O /dev/null "' . $siteUrl . '/cron/' . $job . '.php?key=' . ($hasKey ? $cronKey : 'YOUR_CRON_KEY') . '"';
        [$min, $hour, $day, $month, $weekday] = explode(' ', $info['schedule']);
    ?>
        <div class="cj-card">
            <div class="cj-head">
                <div>
                    <h6 class="fw-bold mb-1"><?= cj_e($info['title']) ?></h6>
                    <div class="cj-muted"><?= cj_e($info['description']) ?></div>
                </div>
                <form method="post" onsubmit="return confirm('Run <?= cj_e($info['title']) ?> now?')">
                    <input type="hidden" name="csrf_token" value="<?= cj_e($csrf) ?>">
                    <input type="hidden" name="job" value="<?= cj_e($job) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-play me-1"></i> Run now</button>
                </form>
            </div>

            <div class="row mt-3 g-3">
                <div class="col-md-4">
                    <div class="cj-label">Recommended timing</div>
                    <div><strong><?= cj_e($info['when']) ?></strong></div>
                    <div class="cj-muted">Minute <code><?= cj_e($min) ?></code>, Hour <code><?= cj_e($hour) ?></code>, Day <code><?= cj_e($day) ?></code>, Month <code><?= cj_e($month) ?></code>, Weekday <code><?= cj_e($weekday) ?></code></div>
                </div>
                <div class="col-md-8">
                    <div class="cj-label">Last run</div>
                    <?php if ($last): ?>
                        <div>
                            <span class="badge <?= !empty($last['ok']) ? 'bg-success' : 'bg-danger' ?>"><?= !empty($last['ok']) ? 'OK' : 'Failed' ?></span>
                            <?= cj_e(date('M j, Y g:i A', (int)$last['time'])) ?>
                            <span class="cj-muted">(<?= cj_e(['cli' => 'cron command', 'web' => 'cron web address', 'manual' => 'Run now'][$last['via']] ?? $last['via']) ?>, <?= cj_e($last['seconds']) ?>s)</span>
                        </div>
                        <div class="cj-muted"><?= cj_e($last['summary']) ?></div>
                    <?php else: ?>
                        <div class="cj-muted">Never run as a cron job yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-3">
                <div class="cj-label">Command (recommended)</div>
                <div class="cj-cmd">
                    <code><?= cj_e($cliCommand) ?></code>
                    <button type="button" class="btn btn-sm btn-light" data-copy="<?= cj_e($cliCommand) ?>"><i class="fas fa-copy"></i> Copy</button>
                </div>
                <div class="cj-label">Or, if PHP commands don't run on your host: web address</div>
                <div class="cj-cmd">
                    <code><?= cj_e($webCommand) ?></code>
                    <button type="button" class="btn btn-sm btn-light" data-copy="<?= cj_e($webCommand) ?>" <?= $hasKey ? '' : 'disabled' ?>><i class="fas fa-copy"></i> Copy</button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$hasKey): ?>
        <div class="cj-card">
            <h6 class="fw-bold mb-1"><i class="fas fa-key me-1"></i> Turn on the web address option</h6>
            <div class="cj-muted mb-2">The web address commands need a secret key, so nobody else can start the jobs. Add this line to the <code>.env</code> file in the site's main folder (cPanel &gt; File Manager), then reload this page:</div>
            <div class="cj-cmd">
                <code>CRON_KEY=<?= cj_e($suggestedKey) ?></code>
                <button type="button" class="btn btn-sm btn-light" data-copy="CRON_KEY=<?= cj_e($suggestedKey) ?>"><i class="fas fa-copy"></i> Copy</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var text = btn.getAttribute('data-copy');
    var done = function () {
        var old = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied';
        setTimeout(function () { btn.innerHTML = old; }, 1500);
    };
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(done);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); ta.remove(); done();
    }
});
</script>

<?php require 'footer.php'; ?>
