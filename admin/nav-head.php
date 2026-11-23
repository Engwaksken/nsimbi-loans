<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/site_cms.php';
$brand = site_settings($conn);
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['1','2',1,2])) {
    header('Location: ../login.php'); exit();
}
$user_id  = $_SESSION['logged_in'] ?? null;
$role     = $_SESSION['role'];
$fname    = $_SESSION['fname'] ?? '';
$lname    = $_SESSION['lname'] ?? '';
$uname    = htmlspecialchars(trim("$fname $lname"));
$initials = strtoupper(substr($fname,0,1).substr($lname,0,1));
$admin_id = $_SESSION['user_id'] ?? 0;

$notifications = []; $unread_count = 0; $new_messages = 0; $count_pending = 0;
if ($admin_id) {
    $q = $conn->prepare("SELECT COUNT(*) AS t FROM notifications WHERE (user_id=? OR user_id IS NULL) AND status='unread'");
    $q->bind_param("i",$admin_id); $q->execute();
    $unread_count = $q->get_result()->fetch_assoc()['t'] ?? 0; $q->close();
    $q2 = $conn->prepare("SELECT id,title,message,type,status,DATE_FORMAT(created_at,'%b %d, %h:%i %p') AS t FROM notifications WHERE (user_id=? OR user_id IS NULL) ORDER BY created_at DESC LIMIT 8");
    $q2->bind_param("i",$admin_id); $q2->execute(); $nr = $q2->get_result();
    while ($r = $nr->fetch_assoc()) $notifications[] = $r; $q2->close();
}
$mr = $conn->query("SELECT COUNT(*) AS c FROM loan_messages WHERE is_read=0");
if ($mr) $new_messages = $mr->fetch_assoc()['c'] ?? 0;
$mail_unread = 0;
if ($admin_id) {
    require_once dirname(__DIR__) . '/includes/portal_mail.php';
    $mail_unread = pm_unread_count($conn, (int)$admin_id);
}
$pr = $conn->query("SELECT COUNT(*) AS c FROM loan WHERE status='PENDING'");
if ($pr) $count_pending = $pr->fetch_assoc()['c'] ?? 0;

$cp = basename($_SERVER['PHP_SELF']);
function nav_cls($p, $c) { return $p === $c ? ' active' : ''; }

/**
 * .htaccess serves css/js as immutable for a year, so edited assets need a new
 * URL or browsers keep the stale copy. Version them by modification time.
 */
function asset_url(string $relative): string {
    $relative = ltrim($relative, '/');
    $absolute = __DIR__ . '/../' . $relative;
    $stamp = is_file($absolute) ? filemtime($absolute) : false;

    return '../' . $relative . ($stamp ? '?v=' . $stamp : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($phead ?? 'Admin') ?> - <?= htmlspecialchars($brand['site_name']) ?></title>
  <?= site_favicon_tags() ?>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/loanpro.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="../datatables2/datatables.min.css">
  <?= site_panel_style_tags() ?>
</head>
<body>

<!-- === SIDEBAR ============================================== -->
<aside class="sidebar" id="sidebar">

  <div class="sidebar-logo">
    <img src="<?= htmlspecialchars($brand['logo']) ?>" alt="Logo" onerror="this.style.display='none'">
    <div class="brand-text"><?= str_replace(['<b>', '</b>'], ['<span>', '</span>'], site_wordmark_html()) ?></div>
  </div>

  <div class="sidebar-user">
    <div class="s-avatar"><?= $initials ?></div>
    <div>
      <div class="s-name"><?= $uname ?></div>
      <div class="s-role"><?= $role == 1 ? 'Administrator' : 'Manager' ?></div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <a href="dashboard" class="nav-link<?= nav_cls('dashboard.php',$cp) ?>"><span class="ni"><i class="fas fa-th-large"></i></span>Dashboard</a>
<?php
$new_enquiries = 0;
try {
  $enq = $conn->query("SELECT COUNT(*) AS c FROM site_enquiries WHERE status='new'");
  if ($enq) $new_enquiries = (int)$enq->fetch_assoc()['c'];
} catch (Throwable $ignored) {
  // Table appears once an admin opens the website CMS.
}
$credit_docs_pending = 0;
try {
  $cd = $conn->query("SELECT COUNT(*) AS c FROM credit_evidence WHERE status IN ('analyzed','failed')");
  if ($cd) $credit_docs_pending = (int)$cd->fetch_assoc()['c'];
} catch (Throwable $ignored) {
  // Table appears with the first credit document upload.
}
$payment_slips_pending = 0;
try {
  require_once dirname(__DIR__) . '/includes/payment_slips.php';
  if (in_array((int)$role, PAYMENT_SLIP_REVIEW_ROLES, true)) {
    $payment_slips_pending = payment_slip_pending_count($conn);
  }
} catch (Throwable $ignored) {
}
$bt_unscored = 0;
try {
  require_once dirname(__DIR__) . '/includes/business_types.php';
  $bt_unscored = bt_unscored_count($conn);
} catch (Throwable $ignored) {
}
// Staff HR: admins review; everyone with a staff profile submits monthly.
$hr_review_count = 0;
$hr_my_due = 0;
try {
  require_once dirname(__DIR__) . '/includes/staff_hr.php';
  if ((int)$role === 1) {
    $hr_review_count = hr_pending_review_count($conn);
  }
  $hr_my_profile = $admin_id ? hr_profile_for_user($conn, (int)$admin_id) : null;
  if ($hr_my_profile) {
    $hr_my_due = hr_my_due_count($conn, $hr_my_profile);
  }
} catch (Throwable $ignored) {
  // Staff HR tables are created on first use.
}
$hrLinks = (int)$role === 1
  ? [
      ['hr_staff.php', 'fa-id-badge', 'Staff Profiles', 0, '', ['hr_staff_profile.php']],
      ['hr_submissions.php', 'fa-calendar-check', 'Monthly Submissions', $hr_review_count, 'red', ['hr_review.php']],
      ['my_staff_portal.php', 'fa-clipboard-list', 'My Staff Portal', $hr_my_due, 'red'],
    ]
  : [
      ['my_staff_portal.php', 'fa-clipboard-list', 'My Staff Portal', $hr_my_due, 'red'],
    ];
// [label, [[href, icon, text, badge, badge class, other pages that mark it active]]]
$navSections = [
  'loans' => ['Loans', [
    ['loans.php', 'fa-file-invoice-dollar', 'All Loans'],
    ['pending_loans.php', 'fa-hourglass-half', 'Pending', (int)$count_pending, 'red'],
    ['payment_slips.php', 'fa-receipt', 'Payment Slips', $payment_slips_pending, 'red', ['payment_slip_file.php']],
    ['outstanding.php', 'fa-exclamation-circle', 'Outstanding'],
    ['overdue.php', 'fa-clock', 'Overdue'],
    ['cleared.php', 'fa-check-circle', 'Cleared'],
    ['rejected_loans.php', 'fa-times-circle', 'Rejected'],
    ['credit_evidence.php', 'fa-file-invoice-dollar', 'Credit Documents', $credit_docs_pending, 'red', ['credit_evidence_file.php']],
    ...((int)$role === 1 ? [['officer_referrals.php', 'fa-trophy', 'Officer Referrals']] : []),
    ['loan_products.php', 'fa-box-open', 'Loan Products'],
    ['loan_settings.php', 'fa-sliders-h', 'Loan Settings'],
  ]],
  'people' => ['People', [
    ['clients.php', 'fa-users', 'Clients'],
    ['staff.php', 'fa-user-tie', 'Staff'],
    ['startups.php', 'fa-building', 'Startups'],
    ['users.php', 'fa-user-shield', 'Users'],
  ]],
  'staffhr' => ['Staff HR', $hrLinks],
  'finance' => ['Finance', [
    ['loan_payments.php', 'fa-money-bill-wave', 'Payments'],
    ['income_expenses.php', 'fa-chart-bar', 'Income & Expenses'],
    ['expenses.php', 'fa-receipt', 'Expenses'],
    ['all_loans_report.php', 'fa-file-export', 'Reports'],
    ['books_history.php', 'fa-book', 'Books History'],
  ]],
  'website' => ['Website', [
    ['pages.php', 'fa-globe', 'Frontend Pages', 0, '', ['page_builder.php']],
    ['enquiries.php', 'fa-inbox', 'Enquiries', $new_enquiries, 'red'],
    ['site_settings.php', 'fa-palette', 'Branding & Fonts'],
  ]],
  'system' => ['System', [
    ['mailbox.php', 'fa-envelope-open-text', 'Mailbox', (int)$mail_unread, 'red'],
    ['loan_messages.php', 'fa-envelope', 'Notifications', (int)$new_messages, ''],
    ['recalculate_limits.php', 'fa-calculator', 'Recalculate Loan Limits'],
    ['ai_settings.php', 'fa-microchip', 'AI Settings'],
    ['manage-score-weights.php', 'fa-briefcase', 'Manage Score Weights'],
    ['manage-sectors.php', 'fa-industry', 'Manage Sectors'],
    ['business_types.php', 'fa-tags', 'Business Types', $bt_unscored, ''],
    ['profile.php', 'fa-user-circle', 'My Profile'],
  ]],
];
foreach ($navSections as $key => [$groupLabel, $links]):
  $isOpen = false; $groupBadge = 0;
  foreach ($links as $link) {
    if ($cp === $link[0] || in_array($cp, $link[5] ?? [], true)) $isOpen = true;
    $groupBadge += (int)($link[3] ?? 0);
  }
?>
    <div class="nav-section<?= $isOpen ? ' open' : '' ?>" data-nav-section="<?= $key ?>">
      <button type="button" class="nav-group nav-group-toggle" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-controls="nav-<?= $key ?>">
        <span><?= htmlspecialchars($groupLabel) ?></span>
        <?php if ($groupBadge > 0): ?><span class="nav-pill red"><?= $groupBadge ?></span><?php endif; ?>
        <i class="fas fa-chevron-down chev" aria-hidden="true"></i>
      </button>
      <div class="nav-items" id="nav-<?= $key ?>"><div>
<?php foreach ($links as $link):
  $active = $cp === $link[0] || in_array($cp, $link[5] ?? [], true); ?>
        <a href="<?= substr($link[0], 0, -4) ?>" class="nav-link<?= $active ? ' active' : '' ?>"<?= $isOpen ? '' : ' tabindex="-1"' ?>><span class="ni"><i class="fas <?= $link[1] ?>"></i></span><?= htmlspecialchars($link[2]) ?>
          <?php if (!empty($link[3])): ?><span class="nav-pill <?= $link[4] ?? '' ?>"><?= (int)$link[3] ?></span><?php endif; ?>
        </a>
<?php endforeach; ?>
      </div></div>
    </div>
<?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="logout" class="nav-link"><span class="ni"><i class="fas fa-sign-out-alt"></i></span>Sign Out</a>
  </div>
</aside>

<!-- === MAIN ================================================== -->
<div class="main-wrapper">

<!-- TOPBAR -->
<header class="topbar">
  <div class="topbar-left">
    <button class="hamburger" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
    <div>
      <div class="topbar-title"><?= htmlspecialchars($phead ?? 'Dashboard') ?></div>
      <div class="topbar-sub"><?= date('l, F j Y') ?></div>
    </div>
    <div class="topbar-search" style="margin-left:.5rem">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Quick search..." id="global-search">
    </div>
  </div>

  <div class="topbar-right">
    <!-- Notifications -->
    <div class="dropdown" style="position:relative">
      <button class="t-btn" data-dd onclick="toggleNotifications()">
        <i class="fas fa-bell"></i>
        <?php if ($unread_count > 0): ?><span class="t-dot"></span><?php endif; ?>
      </button>
      <div class="notif-panel" id="notif-panel" style="display:none">
        <div class="notif-head">
          <span class="notif-head-title">Notifications</span>
          <?php if ($unread_count > 0): ?><span class="badge badge-warning"><?= $unread_count ?> new</span><?php endif; ?>
        </div>
        <?php if (empty($notifications)): ?>
          <div style="padding:1.8rem;text-align:center;color:var(--text-muted);font-size:.82rem"><i class="fas fa-bell-slash" style="display:block;font-size:1.5rem;margin-bottom:.5rem;opacity:.3"></i>No notifications</div>
        <?php else: foreach ($notifications as $n): ?>
          <div class="notif-item <?= $n['status']==='unread'?'unread':'' ?>">
            <div class="notif-item-title"><?= htmlspecialchars($n['title']) ?></div>
            <div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div>
            <div class="notif-item-time"><?= $n['t'] ?></div>
          </div>
        <?php endforeach; endif; ?>
        <div class="notif-foot"><a href="mark_all_read">Mark all as read</a></div>
      </div>
    </div>

    <!-- Messages -->
    <a href="loan_messages" class="t-btn" title="Messages">
      <i class="fas fa-envelope"></i>
      <?php if ($new_messages > 0): ?><span class="t-dot"></span><?php endif; ?>
    </a>

    <!-- Profile -->
    <a href="profile" class="t-avatar" title="My Profile"><?= $initials ?></a>
  </div>
</header>

<!-- PAGE -->
<main class="page-content">
<?php if (isset($_SESSION['flash'])): ?>
  <div id="_flash" data-type="<?= $_SESSION['flash']['type']??'info' ?>" style="display:none"><?= htmlspecialchars($_SESSION['flash']['message']??'') ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
