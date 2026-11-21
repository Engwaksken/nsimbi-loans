<?php
declare(strict_types=1);

/*
 * Loans > Loan Settings: the processing fee taken from each payout, plus a
 * read-only view of the repayment terms and interest rates the app offers.
 * Reviewers (role 1) only.
 */

$phead = 'Loan Settings';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/loan_offers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    header('Location: dashboard.php');
    exit();
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['loan_settings_csrf'])) {
    $_SESSION['loan_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['loan_settings_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['Your session expired. Please try again.', 'error'];
    if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $raw = preg_replace('/[^0-9.]/', '', (string)($_POST['processing_fee'] ?? ''));
        if ($raw === '' || !is_numeric($raw) || (float)$raw < 0 || (float)$raw > 1000000) {
            $flash = ['Enter a fee between UGX 0 and UGX 1,000,000.', 'error'];
        } else {
            app_setting_set($conn, 'loan_processing_fee', (string)round((float)$raw), (int)($_SESSION['user_id'] ?? 0));
            $flash = ['Processing fee saved. It applies to offers sent from now on.', 'success'];
        }
    }
    $_SESSION['loan_settings_flash'] = $flash;
    header('Location: loan_settings.php');
    exit;
}

$fee = loan_processing_fee($conn);
[$flashMsg, $flashType] = $_SESSION['loan_settings_flash'] ?? ['', ''];
unset($_SESSION['loan_settings_flash']);

require "nav-head.php";
?>

<style>
.ls-page { padding: 1rem; max-width: 760px; }
.ls-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.1rem; margin-bottom: 1rem; }
.ls-card h5 { font-weight: 800; margin: 0 0 .35rem; }
.ls-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
.ls-table th, .ls-table td { padding: .45rem .5rem; border-bottom: 1px solid #f0f1f3; text-align: left; }
</style>

<div class="ls-page">
    <?php if ($flashMsg !== ''): ?>
        <div class="alert <?= $flashType === 'error' ? 'alert-danger' : 'alert-success' ?>"><?= h($flashMsg) ?></div>
    <?php endif; ?>

    <div class="ls-card">
        <h5><i class="fas fa-receipt text-primary me-2"></i>Loan processing fee</h5>
        <p class="fs-sm text-muted">
            Taken from the payout when a client accepts an offer. Example: an approved loan of UGX 300,000
            with a UGX 5,000 fee sends UGX 295,000 to the client, who still repays UGX 300,000 plus interest.
        </p>
        <form method="POST" class="d-flex gap-2 align-items-end flex-wrap">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div>
                <label class="form-label fs-sm" for="processing_fee">Fee (UGX)</label>
                <input type="number" min="0" step="500" id="processing_fee" name="processing_fee"
                       class="form-control" value="<?= h((string)(int)$fee) ?>" required style="max-width:200px">
            </div>
            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>

    <div class="ls-card">
        <h5><i class="fas fa-calendar-alt text-primary me-2"></i>Repayment terms in the app</h5>
        <p class="fs-sm text-muted">
            Interest is a flat rate on the approved amount for the whole term
            (includes/loan_offers.php, <code>loan_interest_rate()</code>).
        </p>
        <table class="ls-table">
            <thead><tr><th>Term</th><th>Interest</th><th>On UGX 300,000 you repay</th></tr></thead>
            <tbody>
            <?php foreach (loan_term_options() as $opt):
                $rate = loan_interest_rate($opt['count'], $opt['unit']); ?>
                <tr>
                    <td><?= h($opt['label']) ?></td>
                    <td><?= h(rtrim(rtrim(number_format($rate, 2), '0'), '.')) ?>%</td>
                    <td><?= h(loan_money(300000 + 300000 * $rate / 100)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require "footer.php"; ?>
