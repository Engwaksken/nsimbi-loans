<?php
$phead = "My Profile";

require "nav-head.php";
require_once __DIR__ . '/../includes/loan_workflow.php';

/*
 * Client profile. Edits go through modals:
 *   Edit Profile    -> edited_profile.php   (contact, payout, next of kin)
 *   Change Password -> user_settings.php
 * Identity, business and credit details are managed by staff (read-only here).
 * Styling uses loanpro.css; Bootstrap is not loaded in the client portal.
 */

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return 'UGX ' . number_format((float)$amount, 0);
}

function show($value, string $fallback = 'Not provided'): string
{
    $value = trim((string)$value);
    return $value === '' || $value === '0000-00-00' ? '<span class="pf-empty">' . esc($fallback) . '</span>' : esc($value);
}

function fmt_date($value, string $format = 'd M Y'): string
{
    $ts = $value ? strtotime((string)$value) : false;
    return $ts && $ts > 0 ? date($format, $ts) : '';
}

$profile = null;
$stats = ['loans' => 0, 'balance' => 0.0, 'paid' => 0.0];

try {
    $stmt = $conn->prepare("
        SELECT c.*, u.fname, u.lname, u.username, u.email AS user_email, u.status AS user_status
        FROM customers c
        INNER JOIN user u ON u.id = c.user_id
        WHERE c.user_id = ?
        ORDER BY c.id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($profile) {
        $stmt = $conn->prepare("
            SELECT
                SUM(UPPER(status) NOT IN ('PENDING','REJECTED','CANCELLED','DECLINED')) AS loans,
                COALESCE(SUM(CASE WHEN UPPER(status) IN ('OUTSTANDING','OVERDUE') THEN loan_balance END), 0) AS balance
            FROM loan
            WHERE client_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $stats['loans'] = (int)($row['loans'] ?? 0);
        $stats['balance'] = (float)($row['balance'] ?? 0);

        $status_filter = loan_table_has_column($conn, 'payments', 'status')
            ? "AND UPPER(COALESCE(p.status, '')) NOT IN ('PENDING','FAILED','REJECTED','CANCELLED','REVERSED','DECLINED')"
            : '';
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(p.amount), 0) AS paid
            FROM payments p
            INNER JOIN loan l ON l.id = p.loan_id
            WHERE l.client_id = ? {$status_filter}
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stats['paid'] = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    error_log('profile load failed: ' . $e->getMessage());
    echo '<div class="alert alert-danger">We could not load your profile right now. Please try again later.</div>';
    require "footer.php";
    exit;
}

if (!$profile) {
    echo '<div class="alert alert-danger">No customer profile was found for this account. Please contact the administrator.</div>';
    require "footer.php";
    exit;
}

$full_name   = trim(($profile['fname'] ?? '') . ' ' . ($profile['lname'] ?? '')) ?: ($profile['username'] ?? 'Client');
$initials    = strtoupper(substr((string)($profile['fname'] ?? ''), 0, 1) . substr((string)($profile['lname'] ?? ''), 0, 1)) ?: 'C';
$email       = $profile['user_email'] ?: ($profile['email'] ?? '');
$is_active   = (int)($profile['active'] ?? 0) === 1;
$is_new_onb  = ($profile['client_category'] ?? '') === 'new_onboarded';
$has_nok_rel = array_key_exists('nok_rel', $profile);
$member_since = fmt_date($profile['created_at'] ?? null, 'F Y');

// Values for the edit form: what was typed last time if saving failed.
$old = $_SESSION['profile_old'] ?? [];
unset($_SESSION['profile_old']);
$form = [];
foreach (['phone', 'district', 'location', 'bank_name', 'bank_account', 'nok_name', 'nok_tel', 'nok_rel'] as $field) {
    $form[$field] = (string)($old[$field] ?? ($profile[$field] ?? ''));
}

$flash_message = (string)($_SESSION['message'] ?? '');
$flash_type    = (string)($_SESSION['message_type'] ?? 'info');
$flash_target  = (string)($_SESSION['message_target'] ?? '');   // 'edit' | 'password' when a modal should reopen
unset($_SESSION['message'], $_SESSION['message_type'], $_SESSION['message_target']);

$reopen = '';
if ($flash_message !== '' && $flash_type !== 'success' && in_array($flash_target, ['edit', 'password'], true)) {
    $reopen = $flash_target;
} elseif (isset($_GET['edit'])) {
    $reopen = 'edit';
} elseif (isset($_GET['password'])) {
    $reopen = 'password';
}
?>

<style>
.pf-hero{display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;background:linear-gradient(135deg,var(--primary),var(--primary-dark,#1e3a8a));border-radius:var(--r-xl,18px);padding:1.5rem;color:#fff;margin-bottom:1.4rem;box-shadow:var(--shadow-md)}
.pf-avatar{width:76px;height:76px;border-radius:50%;background:rgba(255,255,255,.16);border:3px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-size:1.7rem;font-weight:800;flex-shrink:0}
.pf-hero-main{flex:1;min-width:220px}
.pf-hero h2{margin:0 0 .2rem;font-family:'Outfit',sans-serif;font-size:1.4rem;font-weight:800;color:#fff}
.pf-meta{display:flex;flex-wrap:wrap;gap:.4rem 1rem;font-size:.82rem;color:rgba(255,255,255,.82)}
.pf-meta i{opacity:.75;margin-right:.3rem}
.pf-tags{display:flex;gap:.4rem;margin-top:.65rem;flex-wrap:wrap}
.pf-tag{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:.25rem .6rem;border-radius:20px;background:rgba(255,255,255,.16)}
.pf-tag.ok{background:rgba(34,197,94,.25)}
.pf-tag.warn{background:rgba(245,158,11,.3)}
.pf-actions{display:flex;gap:.5rem;flex-wrap:wrap}
.pf-actions .btn{background:#fff;color:var(--primary);border-color:#fff}
.pf-actions .btn.btn-ghost{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.35)}
.pf-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1.1rem}
.pf-grid .card{margin:0}
.pf-card-head{display:flex;align-items:center;justify-content:space-between;gap:.5rem}
.pf-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem}
.pf-item{background:var(--body-bg);border:1px solid var(--border);border-radius:10px;padding:.65rem .85rem;min-width:0}
.pf-item.full{grid-column:1/-1}
.pf-item .lbl{font-size:.64rem;text-transform:uppercase;letter-spacing:.065em;color:var(--text-muted);margin-bottom:.2rem}
.pf-item .val{font-size:.88rem;font-weight:600;color:var(--text-dark);overflow-wrap:anywhere}
.pf-empty{color:var(--text-muted);font-weight:500;font-style:italic}
.pf-lock{font-size:.72rem;color:var(--text-muted)}
.pf-section{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--primary);margin:0 0 .75rem;display:flex;align-items:center;gap:.4rem}
.pf-section{margin-top:1.1rem;padding-top:1.1rem;border-top:1px dashed var(--border)}
.pf-section.first{margin-top:0;padding-top:0;border-top:0}
.pf-note{font-size:.76rem;color:var(--text-muted);background:var(--body-bg);border:1px solid var(--border);border-radius:10px;padding:.65rem .8rem;margin-top:.4rem}
.form-control.is-invalid{border-color:var(--danger)}
.pf-pass{position:relative}
.pf-pass .form-control{padding-right:2.6rem}
.pf-pass button{position:absolute;right:.4rem;top:50%;transform:translateY(-50%);border:0;background:transparent;color:var(--text-muted);cursor:pointer;padding:.3rem .45rem}
#editProfileModal .modal-box{max-width:720px}
@media(max-width:900px){.pf-grid{grid-template-columns:1fr}}
@media(max-width:520px){.pf-list{grid-template-columns:1fr}.pf-hero{padding:1.15rem}.pf-actions{width:100%}.pf-actions .btn{flex:1}}
</style>

<?php if ($flash_message !== '' && $reopen === ''): ?>
    <div id="_flash" data-type="<?= esc($flash_type); ?>" style="display:none"><?= esc($flash_message); ?></div>
<?php endif; ?>

<div class="pf-hero">
    <div class="pf-avatar"><?= esc($initials); ?></div>
    <div class="pf-hero-main">
        <h2><?= esc($full_name); ?></h2>
        <div class="pf-meta">
            <?php if ($email): ?><span><i class="fas fa-envelope"></i><?= esc($email); ?></span><?php endif; ?>
            <?php if (!empty($profile['phone'])): ?><span><i class="fas fa-phone"></i><?= esc($profile['phone']); ?></span><?php endif; ?>
            <?php if ($member_since): ?><span><i class="fas fa-calendar"></i>Member since <?= esc($member_since); ?></span><?php endif; ?>
        </div>
        <div class="pf-tags">
            <span class="pf-tag <?= $is_active ? 'ok' : 'warn'; ?>"><?= $is_active ? 'Active' : 'Inactive'; ?></span>
            <span class="pf-tag"><?= $is_new_onb ? 'New onboarded client' : 'Business client'; ?></span>
            <?php if (!empty($profile['username'])): ?><span class="pf-tag">@<?= esc($profile['username']); ?></span><?php endif; ?>
        </div>
    </div>
    <div class="pf-actions">
        <button type="button" class="btn btn-sm" data-open="editProfileModal"><i class="fas fa-user-edit"></i> Edit Profile</button>
        <button type="button" class="btn btn-sm btn-ghost" data-open="passwordModal"><i class="fas fa-key"></i> Change Password</button>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card c-green">
        <div class="stat-head">
            <div><div class="stat-label">Loan Limit</div><div class="stat-value sm"><?= (float)($profile['loan_limit'] ?? 0) > 0 ? money($profile['loan_limit']) : 'Not set'; ?></div></div>
            <div class="stat-ico c-green"><i class="fas fa-shield-alt"></i></div>
        </div>
    </div>
    <div class="stat-card c-blue">
        <div class="stat-head">
            <div><div class="stat-label">Loans Taken</div><div class="stat-value"><?= number_format($stats['loans']); ?></div></div>
            <div class="stat-ico c-blue"><i class="fas fa-file-invoice-dollar"></i></div>
        </div>
    </div>
    <div class="stat-card c-purple">
        <div class="stat-head">
            <div><div class="stat-label">Total Repaid</div><div class="stat-value sm"><?= money($stats['paid']); ?></div></div>
            <div class="stat-ico c-purple"><i class="fas fa-hand-holding-usd"></i></div>
        </div>
    </div>
    <div class="stat-card c-orange">
        <div class="stat-head">
            <div><div class="stat-label">Outstanding Balance</div><div class="stat-value sm"><?= money($stats['balance']); ?></div></div>
            <div class="stat-ico c-orange"><i class="fas fa-balance-scale"></i></div>
        </div>
    </div>
</div>

<div class="pf-grid">
    <div class="card">
        <div class="card-header pf-card-head">
            <div class="card-title"><span class="card-dot"></span> Personal details</div>
            <span class="pf-lock"><i class="fas fa-lock"></i> Managed by staff</span>
        </div>
        <div class="card-body">
            <div class="pf-list">
                <div class="pf-item"><div class="lbl">First name</div><div class="val"><?= show($profile['fname'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Last name</div><div class="val"><?= show($profile['lname'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Gender</div><div class="val"><?= show(ucfirst(strtolower((string)($profile['gender'] ?? '')))); ?></div></div>
                <div class="pf-item"><div class="lbl">Date of birth</div><div class="val"><?= show(fmt_date($profile['dob'] ?? null)); ?></div></div>
                <div class="pf-item"><div class="lbl">National ID (NIN)</div><div class="val"><?= show($profile['nin'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Email</div><div class="val"><?= show($email); ?></div></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header pf-card-head">
            <div class="card-title"><span class="card-dot"></span> Contact &amp; address</div>
            <button type="button" class="btn btn-sm btn-outline" data-open="editProfileModal"><i class="fas fa-pen"></i> Edit</button>
        </div>
        <div class="card-body">
            <div class="pf-list">
                <div class="pf-item"><div class="lbl">Phone / Mobile Money</div><div class="val"><?= show($profile['phone'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">District</div><div class="val"><?= show($profile['district'] ?? ''); ?></div></div>
                <div class="pf-item full"><div class="lbl">Location / address</div><div class="val"><?= show($profile['location'] ?? ''); ?></div></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header pf-card-head">
            <div class="card-title"><span class="card-dot"></span> Payout details</div>
            <button type="button" class="btn btn-sm btn-outline" data-open="editProfileModal"><i class="fas fa-pen"></i> Edit</button>
        </div>
        <div class="card-body">
            <div class="pf-list">
                <div class="pf-item"><div class="lbl">Bank name</div><div class="val"><?= show($profile['bank_name'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Account number</div><div class="val"><?= show($profile['bank_account'] ?? ''); ?></div></div>
                <div class="pf-item full"><div class="lbl">Mobile Money number</div><div class="val"><?= show($profile['phone'] ?? ''); ?></div></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header pf-card-head">
            <div class="card-title"><span class="card-dot"></span> Next of kin</div>
            <button type="button" class="btn btn-sm btn-outline" data-open="editProfileModal"><i class="fas fa-pen"></i> Edit</button>
        </div>
        <div class="card-body">
            <div class="pf-list">
                <div class="pf-item"><div class="lbl">Name</div><div class="val"><?= show($profile['nok_name'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Phone</div><div class="val"><?= show($profile['nok_tel'] ?? ''); ?></div></div>
                <?php if ($has_nok_rel): ?>
                    <div class="pf-item full"><div class="lbl">Relationship</div><div class="val"><?= show($profile['nok_rel'] ?? ''); ?></div></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (array_key_exists('business_name', $profile)): ?>
    <div class="card" style="grid-column:1/-1">
        <div class="card-header pf-card-head">
            <div class="card-title"><span class="card-dot"></span> Business</div>
            <span class="pf-lock"><i class="fas fa-lock"></i> Managed by staff</span>
        </div>
        <div class="card-body">
            <div class="pf-list" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
                <div class="pf-item"><div class="lbl">Business name</div><div class="val"><?= show($profile['business_name'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Business type</div><div class="val"><?= show($profile['business_type'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Years in business</div><div class="val"><?= show($profile['business_period'] ?? ''); ?></div></div>
                <?php if (array_key_exists('credit_score', $profile)): ?>
                    <div class="pf-item"><div class="lbl">Credit score</div><div class="val"><?= show($profile['credit_score'] ?? ''); ?></div></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Edit Profile Modal -->
<div class="modal-overlay" id="editProfileModal">
    <div class="modal-box md">
        <form method="post" action="edited_profile.php" id="editProfileForm" novalidate>
            <div class="modal-header">
                <div class="modal-title">
                    <span class="modal-ico"><i class="fas fa-user-edit"></i></span>
                    Edit Profile
                </div>
                <button type="button" class="modal-close" data-close aria-label="Close"><i class="fas fa-times"></i></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger js-error" <?= $reopen === 'edit' && $flash_message !== '' ? '' : 'style="display:none"'; ?>>
                    <i class="fas fa-exclamation-circle"></i><span><?= $reopen === 'edit' ? esc($flash_message) : ''; ?></span>
                </div>

                <div class="pf-section first"><i class="fas fa-address-book"></i> Contact &amp; address</div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="pf_phone">Phone / Mobile Money</label>
                        <input type="tel" class="form-control" id="pf_phone" name="phone" value="<?= esc($form['phone']); ?>" placeholder="e.g. 0772 123456" maxlength="20" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="pf_district">District</label>
                        <input type="text" class="form-control" id="pf_district" name="district" value="<?= esc($form['district']); ?>" placeholder="e.g. Kampala" maxlength="100">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="pf_location">Location / address</label>
                    <input type="text" class="form-control" id="pf_location" name="location" value="<?= esc($form['location']); ?>" placeholder="e.g. Ntinda, Kiwatule Road" maxlength="255" required>
                </div>

                <div class="pf-section"><i class="fas fa-university"></i> Bank payout (optional)</div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="pf_bank_name">Bank name</label>
                        <input type="text" class="form-control" id="pf_bank_name" name="bank_name" value="<?= esc($form['bank_name']); ?>" placeholder="e.g. Stanbic Bank" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="pf_bank_account">Account number</label>
                        <input type="text" class="form-control" id="pf_bank_account" name="bank_account" value="<?= esc($form['bank_account']); ?>" placeholder="e.g. 9030012345678" inputmode="numeric" maxlength="34">
                    </div>
                </div>

                <div class="pf-section"><i class="fas fa-user-friends"></i> Next of kin</div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="pf_nok_name">Full name</label>
                        <input type="text" class="form-control" id="pf_nok_name" name="nok_name" value="<?= esc($form['nok_name']); ?>" maxlength="150" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="pf_nok_tel">Phone</label>
                        <input type="tel" class="form-control" id="pf_nok_tel" name="nok_tel" value="<?= esc($form['nok_tel']); ?>" maxlength="20" required>
                    </div>
                </div>
                <?php if ($has_nok_rel): ?>
                    <div class="form-group">
                        <label class="form-label" for="pf_nok_rel">Relationship</label>
                        <input type="text" class="form-control" id="pf_nok_rel" name="nok_rel" value="<?= esc($form['nok_rel']); ?>" list="pf_rel_options" placeholder="e.g. Spouse" maxlength="50">
                        <datalist id="pf_rel_options">
                            <option value="Spouse"><option value="Parent"><option value="Sibling"><option value="Child"><option value="Relative"><option value="Friend">
                        </datalist>
                    </div>
                <?php endif; ?>

                <div class="pf-note"><i class="fas fa-info-circle"></i> To change your name, NIN, date of birth or business details, please contact our office.</div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-close>Cancel</button>
                <button type="submit" class="btn btn-primary js-submit"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal-overlay" id="passwordModal">
    <div class="modal-box sm">
        <form method="post" action="user_settings.php" id="passwordForm" novalidate autocomplete="off">
            <input type="hidden" name="submit" value="1">
            <div class="modal-header">
                <div class="modal-title">
                    <span class="modal-ico"><i class="fas fa-key"></i></span>
                    Change Password
                </div>
                <button type="button" class="modal-close" data-close aria-label="Close"><i class="fas fa-times"></i></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger js-error" <?= $reopen === 'password' && $flash_message !== '' ? '' : 'style="display:none"'; ?>>
                    <i class="fas fa-exclamation-circle"></i><span><?= $reopen === 'password' ? esc($flash_message) : ''; ?></span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="pw_current">Current password</label>
                    <div class="pf-pass">
                        <input type="password" class="form-control" id="pw_current" name="current_password" autocomplete="current-password" required>
                        <button type="button" data-toggle-pass aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="pw_new">New password</label>
                    <div class="pf-pass">
                        <input type="password" class="form-control" id="pw_new" name="password" autocomplete="new-password" minlength="8" required>
                        <button type="button" data-toggle-pass aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                    <div class="form-help">At least 8 characters.</div>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label" for="pw_confirm">Confirm new password</label>
                    <div class="pf-pass">
                        <input type="password" class="form-control" id="pw_confirm" name="con_password" autocomplete="new-password" required>
                        <button type="button" data-toggle-pass aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-close>Cancel</button>
                <button type="submit" class="btn btn-primary js-submit"><i class="fas fa-check"></i> Update Password</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    /* Self-contained modal handling (does not depend on loanpro.js load order). */
    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        const first = modal.querySelector('input:not([type=hidden])');
        if (first) setTimeout(function () { first.focus(); }, 150);
    }

    function closeModal(modal) {
        modal.classList.remove('open');
        if (!document.querySelector('.modal-overlay.open')) document.body.style.overflow = '';
    }

    document.addEventListener('click', function (e) {
        const opener = e.target.closest('[data-open]');
        if (opener) { e.preventDefault(); openModal(opener.dataset.open); return; }

        const overlay = e.target.closest('.modal-overlay');
        if (overlay && (e.target === overlay || e.target.closest('[data-close]'))) { e.preventDefault(); closeModal(overlay); return; }

        const toggle = e.target.closest('[data-toggle-pass]');
        if (toggle) {
            const input = toggle.parentElement.querySelector('input');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            toggle.innerHTML = '<i class="fas fa-eye' + (show ? '-slash' : '') + '"></i>';
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(closeModal);
    });

    function fail(form, message, field) {
        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        const box = form.querySelector('.js-error');
        box.querySelector('span').textContent = message;
        box.style.display = '';
        if (field) { field.classList.add('is-invalid'); field.focus(); }
    }

    function busy(form) {
        const btn = form.querySelector('.js-submit');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    }

    const phoneOk = function (v) { const d = v.replace(/\D/g, ''); return d.length >= 9 && d.length <= 15; };

    document.getElementById('editProfileForm').addEventListener('submit', function (e) {
        const f = this, $ = function (id) { return document.getElementById(id); };
        const checks = [
            [!phoneOk($('pf_phone').value), 'Please enter a valid phone number.', $('pf_phone')],
            [!$('pf_location').value.trim(), 'Please enter your location.', $('pf_location')],
            [!!$('pf_bank_account').value.trim() !== !!$('pf_bank_name').value.trim(), 'Enter both bank name and account number, or leave both empty.', $('pf_bank_name').value.trim() ? $('pf_bank_account') : $('pf_bank_name')],
            [!$('pf_nok_name').value.trim(), 'Please enter your next of kin\'s name.', $('pf_nok_name')],
            [!phoneOk($('pf_nok_tel').value), 'Please enter a valid next of kin phone number.', $('pf_nok_tel')],
        ];
        for (const [bad, msg, field] of checks) {
            if (bad) { e.preventDefault(); fail(f, msg, field); return; }
        }
        busy(f);
    });

    document.getElementById('passwordForm').addEventListener('submit', function (e) {
        const f = this, cur = document.getElementById('pw_current'), pw = document.getElementById('pw_new'), cf = document.getElementById('pw_confirm');
        if (!cur.value) { e.preventDefault(); return fail(f, 'Please enter your current password.', cur); }
        if (pw.value.length < 8) { e.preventDefault(); return fail(f, 'New password must be at least 8 characters.', pw); }
        if (pw.value !== cf.value) { e.preventDefault(); return fail(f, 'The new passwords do not match.', cf); }
        busy(f);
    });

    // Back-button restores from cache with buttons still disabled.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('.js-submit').forEach(function (btn) { btn.disabled = false; });
        const e = document.querySelector('#editProfileForm .js-submit'); if (e) e.innerHTML = '<i class="fas fa-save"></i> Save Changes';
        const p = document.querySelector('#passwordForm .js-submit'); if (p) p.innerHTML = '<i class="fas fa-check"></i> Update Password';
    });

    <?php if ($reopen !== ''): ?>
    openModal(<?= json_encode($reopen === 'edit' ? 'editProfileModal' : 'passwordModal'); ?>);
    if (window.history && history.replaceState && location.search) history.replaceState(null, '', location.pathname);
    <?php endif; ?>
})();
</script>

<?php require "footer.php"; ?>
