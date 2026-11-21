<?php
$phead = "My Profile";

require "nav-head.php";
require_once __DIR__ . '/../includes/loan_workflow.php';
require_once __DIR__ . '/../includes/client_documents.php';
require_once __DIR__ . '/../includes/credit_scoring.php';

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

// Supporting documents and loan-limit review.
$docs_ready = true;
$documents = [];
$limit_requests = [];
try {
    $documents = client_docs_for_user($conn, $user_id);
    $limit_requests = client_limit_requests($conn, $user_id);
} catch (Throwable $e) {
    error_log('profile documents load failed: ' . $e->getMessage());
    $docs_ready = false;
}
$doc_summary    = client_docs_summary($documents, $profile);
$other_docs     = array_values(array_filter($documents, static fn($d) => $d['doc_type'] === 'other'));
$docs_missing   = client_docs_missing_required($doc_summary);
$limit_pending  = client_limit_pending($limit_requests);
$limit_last     = $limit_requests[0] ?? null;
// Credit documents (mobile money, utility bill, business records) and score make-up.
$credit_docs = [];
$credit_profile = null;
try {
    $credit_docs = credit_evidence_for_user($conn, $user_id);
    $credit_profile = credit_profile_for_user($conn, $user_id);
} catch (Throwable $e) {
    error_log('profile credit evidence load failed: ' . $e->getMessage());
}
$credit_latest = [];
foreach ($credit_docs as $cd) {
    $credit_latest[$cd['doc_type']] ??= $cd;
}
$docs_attention = count($docs_missing) + count(array_filter($documents, static fn($d) => $d['status'] === 'rejected'));
if (empty($_SESSION['docs_csrf'])) {
    $_SESSION['docs_csrf'] = bin2hex(random_bytes(16));
}
$docs_csrf = $_SESSION['docs_csrf'];

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
.pf-card-head{display:flex;align-items:center;justify-content:space-between;gap:.5rem}
.pf-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.7rem}
.pf-tabs .profile-tab-btn{font-weight:600;font-size:.84rem;cursor:pointer}
.pf-tabs .profile-tab-btn:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
.pf-tabs .profile-tab-btn.active{background:var(--primary);border-color:var(--primary)}
.pf-pane-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1rem}
.pf-pane-head h3{margin:0 0 .2rem;font-family:'Outfit',sans-serif;font-size:1.02rem;font-weight:800;color:var(--text-dark)}
.pf-pane-head p{margin:0;font-size:.8rem;color:var(--text-muted)}
.pf-row{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:1rem;padding:1rem;border:1px solid var(--border);border-radius:12px}
.pf-row-ico{width:42px;height:42px;border-radius:10px;background:var(--primary-xlight);color:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.pf-row-main{flex:1;min-width:200px}
.pf-row-main h4{margin:0 0 .15rem;font-size:.9rem;color:var(--text-dark)}
.pf-row-main p{margin:0;font-size:.78rem;color:var(--text-muted)}
.pf-item{background:var(--body-bg);border:1px solid var(--border);border-radius:10px;padding:.65rem .85rem;min-width:0}
.pf-item.full{grid-column:1/-1}
.pf-item .lbl{font-size:.64rem;text-transform:uppercase;letter-spacing:.065em;color:var(--text-muted);margin-bottom:.2rem}
.pf-item .val{font-size:.88rem;font-weight:600;color:var(--text-dark);overflow-wrap:anywhere}
.pf-empty{color:var(--text-muted);font-weight:500;font-style:italic}
.pf-lock{font-size:.72rem;color:var(--text-muted)}
.pf-section{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--primary);margin:0 0 .75rem;display:flex;align-items:center;gap:.4rem}
.pf-section{margin-top:1.1rem;padding-top:1.1rem;border-top:1px dashed var(--border)}
.pf-section.first{margin-top:0;padding-top:0;border-top:0}
.pf-note{font-size:.76rem;color:var(--text-muted);background:var(--body-bg);border:1px solid var(--border);border-radius:10px;padding:.65rem .8rem;margin-top:1rem}
.form-control.is-invalid{border-color:var(--danger)}
.pf-pass{position:relative}
.pf-pass .form-control{padding-right:2.6rem}
.pf-pass button{position:absolute;right:.4rem;top:50%;transform:translateY(-50%);border:0;background:transparent;color:var(--text-muted);cursor:pointer;padding:.3rem .45rem}
#editProfileModal .modal-box{max-width:720px}
.pf-tab-dot{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:var(--danger,#dc2626);color:#fff;font-size:.65rem;font-weight:800;margin-left:.15rem}
.pf-docs{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.75rem}
.pf-doc{display:grid;grid-template-columns:42px minmax(0,1fr);align-items:start;gap:.85rem;padding:.95rem;border:1px solid var(--border);border-radius:12px;background:#fff}
.pf-doc-ico{width:42px;height:42px;border-radius:10px;background:var(--body-bg);color:var(--text-muted);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.05rem}
.pf-doc-approved .pf-doc-ico,.pf-doc-on_file .pf-doc-ico{background:var(--secondary-xlight,#dcfce7);color:var(--success,#16a34a)}
.pf-doc-pending .pf-doc-ico{background:var(--warning-bg,#fef3c7);color:var(--warning,#d97706)}
.pf-doc-rejected{border-color:var(--danger,#dc2626)}
.pf-doc-rejected .pf-doc-ico{background:var(--danger-bg,#fee2e2);color:var(--danger,#dc2626)}
.pf-doc-missing{border-style:dashed}
.pf-doc-main{flex:1;min-width:0}
.pf-doc-title{font-weight:700;font-size:.88rem;color:var(--text-dark);display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.pf-doc-sub{font-size:.75rem;color:var(--text-muted);margin-top:.15rem}
.pf-doc-actions{grid-column:1/-1;display:flex;justify-content:flex-end;gap:.4rem;padding-top:.7rem;border-top:1px dashed var(--border)}
#uploadDocModal .modal-body{min-height:0}
.cr-score{display:flex;gap:1rem;flex-wrap:wrap;background:linear-gradient(135deg,var(--primary),#2563eb);color:#fff;border-radius:14px;padding:1rem 1.2rem;margin-bottom:1rem}
.cr-score>div{flex:1;min-width:150px}.cr-score .lbl{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;opacity:.85}
.cr-score .val{font-size:2rem;font-weight:800;line-height:1.1}.cr-score .val small{font-size:1rem;opacity:.8}.cr-score .val.sm{font-size:1.3rem}
.cr-parts{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.7rem;margin-bottom:.5rem}
.cr-part{border:1px solid var(--border,#e5e7eb);border-radius:12px;padding:.7rem .8rem}
.cr-part-top{display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.35rem}
.cr-bar{height:7px;border-radius:99px;background:#e5e7eb;overflow:hidden}.cr-bar div{height:100%;background:var(--primary)}
.cr-part-sub{font-size:.72rem;color:var(--text-muted);margin-top:.35rem}
.cr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:.8rem}
.cr-card{border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:1rem;display:flex;flex-direction:column;gap:.4rem;margin:0}
.cr-card-head{display:flex;gap:.7rem;align-items:flex-start}.cr-card-head>i{font-size:1.4rem;color:var(--primary);margin-top:.15rem}
.cr-card-head strong{display:block}.cr-hint{font-size:.82rem;color:var(--text-muted);margin:0}
.cr-note{font-size:.8rem;color:var(--danger,#b91c1c);margin:0}
.cr-msg{font-size:.82rem}.cr-msg.ok{color:#15803d}.cr-msg.err{color:var(--danger,#b91c1c)}
.cr-history{display:flex;flex-direction:column;gap:.4rem}
.cr-row{display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap;border-bottom:1px solid var(--border,#eef0f3);padding:.4rem 0;font-size:.86rem}
.pf-subhead{margin:1.4rem 0 .6rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);display:flex;align-items:center;gap:.4rem}
.pf-subhead span{background:var(--body-bg);border:1px solid var(--border);border-radius:9px;padding:0 .45rem;font-size:.68rem}
.pf-empty-box{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;padding:1rem;border:1px dashed var(--border);border-radius:12px;font-size:.8rem;color:var(--text-muted)}
.pf-empty-box > i{font-size:1.4rem;opacity:.5}
.pf-empty-box > div{flex:1;min-width:200px}
.pf-files{border:1px solid var(--border);border-radius:12px;overflow:hidden}
.pf-file{display:flex;align-items:center;gap:.7rem;padding:.65rem .85rem;border-top:1px solid var(--border)}
.pf-file:first-child{border-top:0}
.pf-file form{margin:0}
.pf-file-ico{font-size:1.25rem;color:var(--primary);width:22px;text-align:center}
.pf-file-main{flex:1;min-width:0}
.pf-file-name{font-weight:600;font-size:.86rem;color:var(--text-dark);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pf-file-meta{font-size:.72rem;color:var(--text-muted)}
.pf-limit{margin-top:1.4rem;padding:1.1rem;border:1px solid var(--border);border-radius:14px;background:linear-gradient(180deg,var(--body-bg),#fff)}
.pf-limit-head{display:flex;align-items:center;gap:.85rem;margin-bottom:1rem}
.pf-limit-head h4{margin:0 0 .1rem;font-size:.95rem;color:var(--text-dark)}
.pf-limit-head p{margin:0;font-size:.8rem;color:var(--text-muted)}
.pf-checklist{display:grid;gap:.4rem;font-size:.84rem}
.pf-checklist-title{font-weight:600;color:var(--text-dark);margin-bottom:.15rem}
.pf-check{color:var(--text-muted)}
.pf-check i{color:var(--border);margin-right:.35rem}
.pf-check.done{color:var(--text-dark)}
.pf-check.done i{color:var(--success,#16a34a)}
.pf-ready{font-size:.84rem;color:var(--success,#16a34a);font-weight:600;padding:.62rem 0}
.pf-drop{position:relative;display:flex;flex-direction:column;align-items:center;gap:.3rem;padding:1.4rem 1rem;border:2px dashed var(--border);border-radius:12px;text-align:center;cursor:pointer;transition:border-color .15s,background .15s;margin:0}
.pf-drop:hover,.pf-drop.drag{border-color:var(--primary);background:var(--primary-xlight)}
.pf-drop.has-file{border-style:solid;border-color:var(--success,#16a34a)}
.pf-drop.is-invalid{border-color:var(--danger)}
.pf-drop input{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
.pf-drop > i{font-size:1.8rem;color:var(--primary)}
.pf-drop-text{font-size:.86rem;color:var(--text-dark);word-break:break-all}
.pf-drop-sub{font-size:.72rem;color:var(--text-muted)}
@media(max-width:520px){.pf-docs{grid-template-columns:1fr}.pf-doc-actions .btn{flex:1}}
@media(max-width:520px){.pf-pane-head{flex-direction:column}.pf-list{grid-template-columns:1fr}.pf-hero{padding:1.15rem}.pf-actions{width:100%}.pf-actions .btn{flex:1}}
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

<?php
$has_business = array_key_exists('business_name', $profile);
$tabs = [
    'personal' => ['Personal', 'fa-id-card'],
    'contact'  => ['Contact & Address', 'fa-map-marker-alt'],
    'payout'   => ['Payout', 'fa-wallet'],
    'kin'      => ['Next of Kin', 'fa-user-friends'],
];
if ($has_business) {
    $tabs['business'] = ['Business', 'fa-briefcase'];
}
$tabs['credit'] = ['Credit score', 'fa-chart-line'];
$tabs['documents'] = ['Documents', 'fa-folder-open'];
$tabs['security'] = ['Security', 'fa-lock'];

$edit_btn = '<button type="button" class="btn btn-sm btn-outline" data-open="editProfileModal"><i class="fas fa-pen"></i> Edit</button>';
$locked   = '<span class="pf-lock"><i class="fas fa-lock"></i> Managed by staff</span>';
?>
<div class="profile-tab-card pf-tabs">
    <div class="profile-tabs-horizontal" role="tablist" aria-label="Profile sections">
        <?php $first = true; foreach ($tabs as $key => [$label, $icon]): ?>
            <button type="button" class="profile-tab-btn<?= $first ? ' active' : ''; ?>" role="tab"
                    id="tab-<?= $key; ?>" data-tab="<?= $key; ?>" aria-controls="pane-<?= $key; ?>"
                    aria-selected="<?= $first ? 'true' : 'false'; ?>" tabindex="<?= $first ? '0' : '-1'; ?>">
                <i class="fas <?= $icon; ?>"></i> <?= esc($label); ?>
                <?php if ($key === 'documents' && $docs_attention > 0): ?><span class="pf-tab-dot" title="Needs attention"><?= $docs_attention; ?></span><?php endif; ?>
            </button>
        <?php $first = false; endforeach; ?>
    </div>

    <div class="profile-tab-content">
        <section class="profile-tab-pane active" id="pane-personal" role="tabpanel" aria-labelledby="tab-personal">
            <div class="pf-pane-head">
                <div><h3>Personal details</h3><p>Your identity as registered with us.</p></div>
                <?= $locked; ?>
            </div>
            <div class="pf-list">
                <div class="pf-item"><div class="lbl">First name</div><div class="val"><?= show($profile['fname'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Last name</div><div class="val"><?= show($profile['lname'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Gender</div><div class="val"><?= show(ucfirst(strtolower((string)($profile['gender'] ?? '')))); ?></div></div>
                <div class="pf-item"><div class="lbl">Date of birth</div><div class="val"><?= show(fmt_date($profile['dob'] ?? null)); ?></div></div>
                <div class="pf-item"><div class="lbl">National ID (NIN)</div><div class="val"><?= show($profile['nin'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Email</div><div class="val"><?= show($email); ?></div></div>
            </div>
            <div class="pf-note"><i class="fas fa-info-circle"></i> To correct your name, NIN or date of birth, please contact our office with your ID.</div>
        </section>

        <section class="profile-tab-pane" id="pane-contact" role="tabpanel" aria-labelledby="tab-contact" hidden>
            <div class="pf-pane-head">
                <div><h3>Contact &amp; address</h3><p>How we reach you and where you are based.</p></div>
                <?= $edit_btn; ?>
            </div>
            <div class="pf-list">
                <div class="pf-item"><div class="lbl">Phone / Mobile Money</div><div class="val"><?= show($profile['phone'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">District</div><div class="val"><?= show($profile['district'] ?? ''); ?></div></div>
                <div class="pf-item full"><div class="lbl">Location / address</div><div class="val"><?= show($profile['location'] ?? ''); ?></div></div>
            </div>
        </section>

        <section class="profile-tab-pane" id="pane-payout" role="tabpanel" aria-labelledby="tab-payout" hidden>
            <div class="pf-pane-head">
                <div><h3>Payout details</h3><p>Where approved loans are sent. Used to pre-fill your loan requests.</p></div>
                <?= $edit_btn; ?>
            </div>
            <div class="pf-list">
                <div class="pf-item"><div class="lbl">Mobile Money number</div><div class="val"><?= show($profile['phone'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Bank name</div><div class="val"><?= show($profile['bank_name'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Account number</div><div class="val"><?= show($profile['bank_account'] ?? ''); ?></div></div>
            </div>
        </section>

        <section class="profile-tab-pane" id="pane-kin" role="tabpanel" aria-labelledby="tab-kin" hidden>
            <div class="pf-pane-head">
                <div><h3>Next of kin</h3><p>Someone we can contact if we cannot reach you.</p></div>
                <?= $edit_btn; ?>
            </div>
            <div class="pf-list">
                <div class="pf-item"><div class="lbl">Name</div><div class="val"><?= show($profile['nok_name'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Phone</div><div class="val"><?= show($profile['nok_tel'] ?? ''); ?></div></div>
                <?php if ($has_nok_rel): ?>
                    <div class="pf-item"><div class="lbl">Relationship</div><div class="val"><?= show($profile['nok_rel'] ?? ''); ?></div></div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($has_business): ?>
        <section class="profile-tab-pane" id="pane-business" role="tabpanel" aria-labelledby="tab-business" hidden>
            <div class="pf-pane-head">
                <div><h3>Business</h3><p>Used by our loan officers when assessing requests.</p></div>
                <?= $locked; ?>
            </div>
            <div class="pf-list">
                <div class="pf-item"><div class="lbl">Business name</div><div class="val"><?= show($profile['business_name'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Business type</div><div class="val"><?= show($profile['business_type'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Years in business</div><div class="val"><?= show($profile['business_period'] ?? ''); ?></div></div>
                <?php if (array_key_exists('credit_score', $profile)): ?>
                    <div class="pf-item"><div class="lbl">Credit score</div><div class="val"><?= show($profile['credit_score'] ?? ''); ?></div></div>
                <?php endif; ?>
                <div class="pf-item"><div class="lbl">Client type</div><div class="val"><?= $is_new_onb ? 'New onboarded' : 'Business'; ?></div></div>
                <div class="pf-item"><div class="lbl">Loan limit</div><div class="val"><?= (float)($profile['loan_limit'] ?? 0) > 0 ? money($profile['loan_limit']) : '<span class="pf-empty">Not set</span>'; ?></div></div>
            </div>
        </section>
        <?php endif; ?>

        <section class="profile-tab-pane" id="pane-documents" role="tabpanel" aria-labelledby="tab-documents" hidden>
            <div class="pf-pane-head">
                <div><h3>Documents</h3><p>Upload supporting documents. Complete documents help us review a higher loan limit.</p></div>
                <button type="button" class="btn btn-sm btn-primary" data-upload="other"><i class="fas fa-upload"></i> Upload file</button>
            </div>

            <?php if (!$docs_ready): ?>
                <div class="alert alert-warning">Document uploads are not available right now. Please try again later.</div>
            <?php else: ?>
                <div class="pf-docs">
                    <?php foreach ($doc_summary as $type => $item): ?>
                        <?php
                        $state = $item['state'];
                        $latest = $item['latest'];
                        [$badge_class, $badge_text] = [
                            'approved' => ['badge-success', 'Approved'],
                            'pending'  => ['badge-warning', 'Pending review'],
                            'on_file'  => ['badge-info', 'On file'],
                            'rejected' => ['badge-danger', 'Rejected'],
                            'missing'  => ['badge-muted', $item['required'] ? 'Required' : 'Optional'],
                        ][$state];
                        ?>
                        <div class="pf-doc pf-doc-<?= esc($state); ?>">
                            <div class="pf-doc-ico"><i class="fas <?= esc($item['icon']); ?>"></i></div>
                            <div class="pf-doc-main">
                                <div class="pf-doc-title"><?= esc($item['label']); ?> <span class="badge <?= $badge_class; ?>"><?= esc($badge_text); ?></span></div>
                                <div class="pf-doc-sub">
                                    <?php if ($state === 'on_file'): ?>
                                        Provided at registration.
                                    <?php elseif ($latest && $state !== 'missing'): ?>
                                        Uploaded <?= esc(fmt_date($latest['created_at'], 'd M Y')); ?>
                                        <?php if ($state === 'rejected' && !empty($latest['review_note'])): ?>
                                            | <span class="text-danger"><?= esc($latest['review_note']); ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= esc($item['hint']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="pf-doc-actions">
                                <?php if ($latest && $state !== 'missing' && $state !== 'on_file'): ?>
                                    <a class="btn btn-sm btn-ghost" href="document.php?id=<?= (int)$latest['id']; ?>" target="_blank" rel="noopener"><i class="fas fa-eye"></i> View</a>
                                <?php endif; ?>
                                <?php if ($state !== 'pending'): ?>
                                    <button type="button" class="btn btn-sm btn-outline" data-upload="<?= esc($type); ?>">
                                        <i class="fas fa-upload"></i> <?= in_array($state, ['missing'], true) ? 'Upload' : 'Replace'; ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="pf-subhead">Other files <span><?= count($other_docs); ?></span></div>
                <?php if (!$other_docs): ?>
                    <div class="pf-empty-box">
                        <i class="fas fa-folder-open"></i>
                        <div>Bank or Mobile Money statements, receipts, land titles or anything else that supports your application.</div>
                        <button type="button" class="btn btn-sm btn-outline" data-upload="other"><i class="fas fa-plus"></i> Add a file</button>
                    </div>
                <?php else: ?>
                    <div class="pf-files">
                        <?php foreach ($other_docs as $doc): ?>
                            <?php $b = ['approved' => 'badge-success', 'pending' => 'badge-warning', 'rejected' => 'badge-danger'][$doc['status']] ?? 'badge-muted'; ?>
                            <div class="pf-file">
                                <i class="fas <?= $doc['mime_type'] === 'application/pdf' ? 'fa-file-pdf' : 'fa-file-image'; ?> pf-file-ico"></i>
                                <div class="pf-file-main">
                                    <div class="pf-file-name"><?= esc($doc['title']); ?></div>
                                    <div class="pf-file-meta">
                                        <?= esc(fmt_date($doc['created_at'], 'd M Y')); ?> | <?= number_format(max(1, (int)$doc['file_size'] / 1024)); ?> KB
                                        <?php if ($doc['status'] === 'rejected' && !empty($doc['review_note'])): ?>| <span class="text-danger"><?= esc($doc['review_note']); ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge <?= $b; ?>"><?= esc(ucfirst($doc['status'])); ?></span>
                                <a class="btn btn-sm btn-ghost btn-icon" href="document.php?id=<?= (int)$doc['id']; ?>" target="_blank" rel="noopener" title="View"><i class="fas fa-eye"></i></a>
                                <?php if ($doc['status'] === 'pending'): ?>
                                    <form method="post" action="document_action.php" class="js-remove-doc">
                                        <input type="hidden" name="csrf" value="<?= esc($docs_csrf); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="doc_id" value="<?= (int)$doc['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-ghost btn-icon" title="Remove"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="pf-limit">
                    <div class="pf-limit-head">
                        <div class="pf-row-ico"><i class="fas fa-chart-line"></i></div>
                        <div>
                            <h4>Loan limit review</h4>
                            <p>Current limit: <strong><?= (float)($profile['loan_limit'] ?? 0) > 0 ? money($profile['loan_limit']) : 'Not set'; ?></strong></p>
                        </div>
                    </div>

                    <?php if ($limit_pending): ?>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-hourglass-half"></i>
                            <span>Your request for <strong><?= money($limit_pending['requested_limit']); ?></strong>, sent <?= esc(fmt_date($limit_pending['created_at'], 'd M Y')); ?>, is being reviewed. We'll email you once it's decided.</span>
                        </div>
                    <?php else: ?>
                        <?php if ($limit_last): ?>
                            <div class="alert <?= $limit_last['status'] === 'approved' ? 'alert-success' : 'alert-warning'; ?>">
                                <i class="fas <?= $limit_last['status'] === 'approved' ? 'fa-check-circle' : 'fa-info-circle'; ?>"></i>
                                <span>
                                    Last request (<?= esc(fmt_date($limit_last['created_at'], 'd M Y')); ?>):
                                    <strong><?= esc(ucfirst($limit_last['status'])); ?></strong><?= $limit_last['status'] === 'approved' && $limit_last['approved_limit'] !== null ? ' at ' . money($limit_last['approved_limit']) : ''; ?>.
                                    <?php if (!empty($limit_last['staff_note'])): ?><em><?= esc($limit_last['staff_note']); ?></em><?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ($docs_missing): ?>
                            <div class="pf-checklist">
                                <div class="pf-checklist-title">Before you can request a review, upload:</div>
                                <?php foreach ($doc_summary as $type => $item): ?>
                                    <?php if (!$item['required']) continue; $done = !isset($docs_missing[$type]); ?>
                                    <div class="pf-check <?= $done ? 'done' : ''; ?>"><i class="fas <?= $done ? 'fa-check-circle' : 'fa-circle'; ?>"></i> <?= esc($item['label']); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif (!$is_active): ?>
                            <div class="alert alert-warning mb-0">Your profile is inactive. Please contact the office.</div>
                        <?php else: ?>
                            <form method="post" action="document_action.php" id="limitForm" novalidate>
                                <input type="hidden" name="csrf" value="<?= esc($docs_csrf); ?>">
                                <input type="hidden" name="action" value="limit_request">
                                <div class="alert alert-danger js-error" style="display:none"><i class="fas fa-exclamation-circle"></i><span></span></div>
                                <div class="grid-2">
                                    <div class="form-group">
                                        <label class="form-label" for="lim_amount">Requested limit (UGX)</label>
                                        <input type="text" class="form-control" id="lim_amount" name="requested_limit" inputmode="numeric" placeholder="e.g. 3,000,000" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Documents</label>
                                        <div class="pf-ready"><i class="fas fa-check-circle"></i> Required documents provided</div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="lim_reason">Why do you need a higher limit?</label>
                                    <textarea class="form-control" id="lim_reason" name="reason" rows="3" maxlength="2000" placeholder="e.g. My sales have grown and I want to buy more stock for the festive season." required></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary js-submit"><i class="fas fa-paper-plane"></i> Submit for review</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="profile-tab-pane" id="pane-credit" role="tabpanel" aria-labelledby="tab-credit" hidden>
            <div class="pf-pane-head">
                <div><h3>Raise your credit score</h3><p>Upload proof of your income and stability. Our team checks each document; once approved, your credit score and loan limit are updated.</p></div>
            </div>

            <div class="cr-score">
                <div>
                    <div class="lbl">Your credit score</div>
                    <div class="val"><?= esc(rtrim(rtrim(number_format((float)($profile['credit_score'] ?? 0), 1), '0'), '.')); ?><small>/10</small></div>
                </div>
                <div>
                    <div class="lbl">Loan limit</div>
                    <div class="val sm"><?= (float)($profile['loan_limit'] ?? 0) > 0 ? money($profile['loan_limit']) : 'Not set'; ?></div>
                </div>
            </div>

            <?php if ($credit_profile): ?>
                <h4 class="pf-subhead"><i class="fas fa-balance-scale"></i> What makes up your score</h4>
                <div class="cr-parts">
                    <?php foreach ($credit_profile['components'] as $comp): ?>
                        <?php if ($comp['weight'] <= 0) continue; ?>
                        <div class="cr-part">
                            <div class="cr-part-top"><span><?= esc($comp['label']); ?></span><strong><?= esc(number_format($comp['score'], 1)); ?></strong></div>
                            <div class="cr-bar"><div style="width:<?= (int)round($comp['score'] * 10); ?>%"></div></div>
                            <div class="cr-part-sub">Counts for <?= (int)round($comp['weight'] * 100); ?>%<?= ($comp['note'] ?? '') !== '' ? ' | ' . esc($comp['note']) : ''; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (($credit_profile['repayment_factor'] ?? 1) < 1): ?>
                    <div class="cr-part-sub" style="margin-top:.6rem">
                        Your loan limit is lower than usual because of late or missed repayments.
                        Paying your next loans on time brings it back to the full amount.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <h4 class="pf-subhead"><i class="fas fa-upload"></i> Upload a document</h4>
            <div class="cr-grid">
                <?php foreach (CREDIT_EVIDENCE_TYPES as $ctype => $cmeta): ?>
                    <?php
                    $last = $credit_latest[$ctype] ?? null;
                    [$stText, $stClass] = $last ? credit_evidence_status_label((string)$last['status']) : ['Not uploaded', 'badge-muted'];
                    ?>
                    <form class="cr-card js-credit-upload" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="csrf" value="<?= esc($docs_csrf); ?>">
                        <input type="hidden" name="doc_type" value="<?= esc($ctype); ?>">
                        <div class="cr-card-head">
                            <i class="fas <?= esc($cmeta['icon']); ?>"></i>
                            <div><strong><?= esc($cmeta['label']); ?></strong><span class="badge <?= esc($stClass); ?>"><?= esc($stText); ?></span></div>
                        </div>
                        <p class="cr-hint"><?= esc($cmeta['hint']); ?></p>
                        <?php if ($last && $last['status'] === 'rejected' && !empty($last['review_note'])): ?>
                            <p class="cr-note"><i class="fas fa-info-circle"></i> <?= esc($last['review_note']); ?></p>
                        <?php endif; ?>
                        <input type="file" name="file" class="form-control" accept="application/pdf,image/jpeg,image/png,image/webp" required>
                        <small class="text-muted">PDF or a clear photo (JPG, PNG), up to 10 MB.</small>
                        <button type="submit" class="btn btn-primary btn-sm mt-2"><i class="fas fa-upload"></i> Upload</button>
                        <div class="cr-msg" role="status" aria-live="polite"></div>
                    </form>
                <?php endforeach; ?>
            </div>

            <?php if ($credit_docs): ?>
                <h4 class="pf-subhead"><i class="fas fa-history"></i> Your uploads</h4>
                <div class="cr-history">
                    <?php foreach (array_slice($credit_docs, 0, 15) as $cd): ?>
                        <?php [$stText, $stClass] = credit_evidence_status_label((string)$cd['status']); ?>
                        <div class="cr-row">
                            <span><?= esc(CREDIT_EVIDENCE_TYPES[$cd['doc_type']]['label'] ?? $cd['doc_type']); ?></span>
                            <span class="text-muted"><?= esc(fmt_date($cd['created_at'], 'd M Y')); ?></span>
                            <span class="badge <?= esc($stClass); ?>"><?= esc($stText); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="profile-tab-pane" id="pane-security" role="tabpanel" aria-labelledby="tab-security" hidden>
            <div class="pf-pane-head">
                <div><h3>Login &amp; security</h3><p>Keep your account safe.</p></div>
            </div>
            <div class="pf-list">
                <div class="pf-item"><div class="lbl">Username</div><div class="val"><?= show($profile['username'] ?? ''); ?></div></div>
                <div class="pf-item"><div class="lbl">Login email</div><div class="val"><?= show($email); ?></div></div>
                <div class="pf-item"><div class="lbl">Profile status</div><div class="val"><?= $is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-warning">Inactive</span>'; ?></div></div>
            </div>
            <div class="pf-row">
                <div class="pf-row-ico"><i class="fas fa-key"></i></div>
                <div class="pf-row-main">
                    <h4>Password</h4>
                    <p>Use at least 8 characters and don't reuse a password from another site.</p>
                </div>
                <button type="button" class="btn btn-primary btn-sm" data-open="passwordModal"><i class="fas fa-key"></i> Change Password</button>
            </div>
        </section>
    </div>
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

<!-- Upload Document Modal -->
<div class="modal-overlay" id="uploadDocModal">
    <div class="modal-box sm">
        <form method="post" action="document_action.php" enctype="multipart/form-data" id="uploadDocForm" novalidate>
            <input type="hidden" name="csrf" value="<?= esc($docs_csrf); ?>">
            <input type="hidden" name="action" value="upload">
            <div class="modal-header">
                <div class="modal-title">
                    <span class="modal-ico"><i class="fas fa-upload"></i></span>
                    Upload Document
                </div>
                <button type="button" class="modal-close" data-close aria-label="Close"><i class="fas fa-times"></i></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger js-error" style="display:none"><i class="fas fa-exclamation-circle"></i><span></span></div>

                <div class="form-group">
                    <label class="form-label" for="up_type">Document type</label>
                    <select class="form-select" id="up_type" name="doc_type" required>
                        <?php foreach (client_doc_types() as $type => $meta): ?>
                            <option value="<?= esc($type); ?>" data-hint="<?= esc($meta['hint']); ?>"><?= esc($meta['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-help" id="up_hint"></div>
                </div>

                <div class="form-group" id="up_title_group">
                    <label class="form-label" for="up_title">Title</label>
                    <input type="text" class="form-control" id="up_title" name="title" maxlength="150" placeholder="e.g. MTN statement Jan-Mar">
                </div>

                <label class="pf-drop" for="up_file" id="up_drop">
                    <input type="file" id="up_file" name="document" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" required>
                    <i class="fas fa-cloud-upload-alt"></i>
                    <span class="pf-drop-text" id="up_file_text"><strong>Choose a file</strong> or drag it here</span>
                    <span class="pf-drop-sub">JPG, PNG, WebP or PDF | up to 5 MB</span>
                </label>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-close>Cancel</button>
                <button type="submit" class="btn btn-primary js-submit"><i class="fas fa-upload"></i> Upload</button>
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
    /* ---------- Tabs ---------- */
    const tabButtons = Array.from(document.querySelectorAll('.pf-tabs [role=tab]'));
    const TAB_KEY = 'clientProfileTab';

    function showTab(key, focus) {
        const btn = tabButtons.find(function (b) { return b.dataset.tab === key; });
        if (!btn) return false;
        tabButtons.forEach(function (b) {
            const on = b === btn;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            b.tabIndex = on ? 0 : -1;
            const pane = document.getElementById(b.getAttribute('aria-controls'));
            pane.classList.toggle('active', on);
            pane.hidden = !on;
        });
        if (focus) btn.focus();
        btn.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        try { sessionStorage.setItem(TAB_KEY, key); } catch (e) {}
        return true;
    }

    tabButtons.forEach(function (btn, i) {
        btn.addEventListener('click', function () {
            showTab(btn.dataset.tab);
            if (window.history && history.replaceState) history.replaceState(null, '', '#' + btn.dataset.tab);
        });
        btn.addEventListener('keydown', function (e) {
            let next = null;
            if (e.key === 'ArrowRight') next = tabButtons[(i + 1) % tabButtons.length];
            else if (e.key === 'ArrowLeft') next = tabButtons[(i - 1 + tabButtons.length) % tabButtons.length];
            else if (e.key === 'Home') next = tabButtons[0];
            else if (e.key === 'End') next = tabButtons[tabButtons.length - 1];
            if (next) { e.preventDefault(); showTab(next.dataset.tab, true); }
        });
    });

    (function initialTab() {
        const fromHash = location.hash.replace('#', '');
        if (fromHash && showTab(fromHash)) return;
        let saved = null;
        try { saved = sessionStorage.getItem(TAB_KEY); } catch (e) {}
        if (saved) showTab(saved);
    })();

    /* ---------- Documents ---------- */
    const MAX_DOC_BYTES = 5 * 1024 * 1024;
    const DOC_MIME = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    const upForm = document.getElementById('uploadDocForm');

    if (upForm) {
        const typeSel = document.getElementById('up_type');
        const hint = document.getElementById('up_hint');
        const titleGroup = document.getElementById('up_title_group');
        const titleInput = document.getElementById('up_title');
        const fileInput = document.getElementById('up_file');
        const drop = document.getElementById('up_drop');
        const fileText = document.getElementById('up_file_text');

        const syncType = function () {
            const opt = typeSel.options[typeSel.selectedIndex];
            hint.textContent = opt ? opt.dataset.hint : '';
            titleGroup.style.display = typeSel.value === 'other' ? '' : 'none';
        };
        const syncFile = function () {
            const f = fileInput.files[0];
            drop.classList.toggle('has-file', !!f);
            drop.classList.remove('is-invalid');
            if (f) {
                fileText.innerHTML = '<strong></strong> (' + Math.max(1, Math.round(f.size / 1024)).toLocaleString() + ' KB)';
                fileText.querySelector('strong').textContent = f.name;
            } else {
                fileText.innerHTML = '<strong>Choose a file</strong> or drag it here';
            }
        };

        typeSel.addEventListener('change', syncType);
        fileInput.addEventListener('change', syncFile);
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('drag'); });
        });
        drop.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                syncFile();
            }
        });

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-upload]');
            if (!btn) return;
            e.preventDefault();
            upForm.reset();
            typeSel.value = btn.dataset.upload;
            syncType();
            syncFile();
            upForm.querySelector('.js-error').style.display = 'none';
            openModal('uploadDocModal');
        });

        upForm.addEventListener('submit', function (e) {
            const f = fileInput.files[0];
            if (typeSel.value === 'other' && !titleInput.value.trim()) {
                e.preventDefault(); return fail(upForm, 'Please give this document a short title.', titleInput);
            }
            if (!f) { e.preventDefault(); drop.classList.add('is-invalid'); return fail(upForm, 'Please choose a file to upload.'); }
            if (DOC_MIME.indexOf(f.type) === -1) { e.preventDefault(); drop.classList.add('is-invalid'); return fail(upForm, 'Only JPG, PNG, WebP images or PDF files are accepted.'); }
            if (f.size > MAX_DOC_BYTES) { e.preventDefault(); drop.classList.add('is-invalid'); return fail(upForm, 'The file is too large. The maximum size is 5 MB.'); }
            const btn = upForm.querySelector('.js-submit');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        });

        syncType();
    }

    document.querySelectorAll('.js-remove-doc').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm('Remove this document?')) e.preventDefault();
        });
    });

    const limitForm = document.getElementById('limitForm');
    if (limitForm) {
        const amount = document.getElementById('lim_amount');
        const reason = document.getElementById('lim_reason');
        const currentLimit = <?= json_encode((float)($profile['loan_limit'] ?? 0)); ?>;
        amount.addEventListener('input', function () {
            const digits = this.value.replace(/[^\d]/g, '');
            this.value = digits ? Number(digits).toLocaleString('en-US') : '';
        });
        limitForm.addEventListener('submit', function (e) {
            const value = Number(amount.value.replace(/[^\d]/g, ''));
            if (!value || value <= currentLimit) { e.preventDefault(); return fail(limitForm, 'The requested limit must be higher than your current limit.', amount); }
            if (reason.value.trim().length < 10) { e.preventDefault(); return fail(limitForm, 'Please tell us briefly why you need a higher limit.', reason); }
            amount.value = String(value);
            const btn = limitForm.querySelector('.js-submit');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });
    }

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
    if (window.history && history.replaceState && location.search) history.replaceState(null, '', location.pathname + location.hash);
    <?php endif; ?>
})();
document.querySelectorAll('.js-credit-upload').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var msg = form.querySelector('.cr-msg');
        var btn = form.querySelector('button[type=submit]');
        var input = form.querySelector('input[type=file]');
        if (!input.files.length) { msg.className = 'cr-msg err'; msg.textContent = 'Please choose a file first.'; return; }
        if (input.files[0].size > 10 * 1024 * 1024) { msg.className = 'cr-msg err'; msg.textContent = 'The file is too large. The maximum size is 10 MB.'; return; }
        var old = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reading your document...';
        msg.className = 'cr-msg'; msg.textContent = 'This can take up to a minute. Please keep this page open.';
        fetch('credit_evidence_action.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected response. Please try again.' }; }); })
            .then(function (data) {
                msg.className = 'cr-msg ' + (data.success ? 'ok' : 'err');
                msg.textContent = data.message || (data.success ? 'Uploaded.' : 'Upload failed.');
                if (data.success) { setTimeout(function () { location.hash = 'credit'; location.reload(); }, 2500); }
            })
            .catch(function () { msg.className = 'cr-msg err'; msg.textContent = 'Network error. Please try again.'; })
            .finally(function () { btn.disabled = false; btn.innerHTML = old; });
    });
});
</script>

<?php require "footer.php"; ?>
