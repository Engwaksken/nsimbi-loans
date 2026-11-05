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
  <?= site_panel_font_tags() ?>
</head>
<body>

<!-- ═══ SIDEBAR ══════════════════════════════════════════════ -->
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
    <a href="dashboard.php" class="nav-link<?= nav_cls('dashboard.php',$cp) ?>"><span class="ni"><i class="fas fa-th-large"></i></span>Dashboard</a>
<?php
// [label, [[href, icon, text, badge, badge class, other pages that mark it active]]]
$navSections = [
  'loans' => ['Loans', [
    ['loans.php', 'fa-file-invoice-dollar', 'All Loans'],
    ['pending_loans.php', 'fa-hourglass-half', 'Pending', (int)$count_pending, 'red'],
    ['outstanding.php', 'fa-exclamation-circle', 'Outstanding'],
    ['overdue.php', 'fa-clock', 'Overdue'],
    ['cleared.php', 'fa-check-circle', 'Cleared'],
    ['rejected_loans.php', 'fa-times-circle', 'Rejected'],
    ['loan_products.php', 'fa-box-open', 'Loan Products'],
  ]],
  'people' => ['People', [
    ['clients.php', 'fa-users', 'Clients'],
    ['staff.php', 'fa-user-tie', 'Staff'],
    ['startups.php', 'fa-building', 'Startups'],
    ['users.php', 'fa-user-shield', 'Users'],
  ]],
  'finance' => ['Finance', [
    ['loan_payments.php', 'fa-money-bill-wave', 'Payments'],
    ['income_expenses.php', 'fa-chart-bar', 'Income & Expenses'],
    ['expenses.php', 'fa-receipt', 'Expenses'],
    ['all_loans_report.php', 'fa-file-export', 'Reports'],
    ['books_history.php', 'fa-book', 'Books History'],
  ]],
  'website' => ['Website', [
    ['pages.php', 'fa-globe', 'Frontend Pages', 0, '', ['page_builder.php']],
    ['site_settings.php', 'fa-palette', 'Branding & Fonts'],
  ]],
  'system' => ['System', [
    ['loan_messages.php', 'fa-envelope', 'Messages', (int)$new_messages, ''],
    ['manage-score-weights.php', 'fa-briefcase', 'Manage Score Weights'],
    ['manage-sectors.php', 'fa-industry', 'Manage Sectors'],
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
        <a href="<?= $link[0] ?>" class="nav-link<?= $active ? ' active' : '' ?>"<?= $isOpen ? '' : ' tabindex="-1"' ?>><span class="ni"><i class="fas <?= $link[1] ?>"></i></span><?= htmlspecialchars($link[2]) ?>
          <?php if (!empty($link[3])): ?><span class="nav-pill <?= $link[4] ?? '' ?>"><?= (int)$link[3] ?></span><?php endif; ?>
        </a>
<?php endforeach; ?>
      </div></div>
    </div>
<?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="logout.php" class="nav-link"><span class="ni"><i class="fas fa-sign-out-alt"></i></span>Sign Out</a>
  </div>
</aside>

<!-- ═══ MAIN ══════════════════════════════════════════════════ -->
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
        <div class="notif-foot"><a href="mark_all_read.php">Mark all as read</a></div>
      </div>
    </div>

    <!-- Messages -->
    <a href="loan_messages.php" class="t-btn" title="Messages">
      <i class="fas fa-envelope"></i>
      <?php if ($new_messages > 0): ?><span class="t-dot"></span><?php endif; ?>
    </a>

    <!-- Profile -->
    <a href="profile.php" class="t-avatar" title="My Profile"><?= $initials ?></a>
  </div>
</header>

<!-- PAGE -->
<main class="page-content">
<?php if (isset($_SESSION['flash'])): ?>
  <div id="_flash" data-type="<?= $_SESSION['flash']['type']??'info' ?>" style="display:none"><?= htmlspecialchars($_SESSION['flash']['message']??'') ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
