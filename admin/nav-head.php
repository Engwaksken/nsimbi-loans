<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['1','2',1,2])) {
    header('Location: ../index.php'); exit();
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
  <title><?= htmlspecialchars($phead ?? 'Admin') ?> - Nsimbi Impact</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/loanpro.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="../datatables2/datatables.min.css">
</head>
<body>

<!-- ═══ SIDEBAR ══════════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">

  <div class="sidebar-logo">
    <img src="../assets/img/logo.png" alt="Logo" onerror="this.style.display='none'">
    <div class="brand-text">Nsimbi<span> Impact</span></div>
  </div>

  <div class="sidebar-user">
    <div class="s-avatar"><?= $initials ?></div>
    <div>
      <div class="s-name"><?= $uname ?></div>
      <div class="s-role"><?= $role == 1 ? 'Administrator' : 'Manager' ?></div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-group">Overview</div>
    <a href="dashboard.php"   class="nav-link<?= nav_cls('dashboard.php',$cp) ?>"><span class="ni"><i class="fas fa-th-large"></i></span>Dashboard</a>

    <div class="nav-group">Loans</div>
    <a href="loans.php"       class="nav-link<?= nav_cls('loans.php',$cp) ?>"><span class="ni"><i class="fas fa-file-invoice-dollar"></i></span>All Loans</a>
    <a href="pending_loans.php" class="nav-link<?= nav_cls('pending_loans.php',$cp) ?>">
      <span class="ni"><i class="fas fa-hourglass-half"></i></span>Pending
      <?php if ($count_pending > 0): ?><span class="nav-pill red"><?= $count_pending ?></span><?php endif; ?>
    </a>
    <a href="outstanding.php" class="nav-link<?= nav_cls('outstanding.php',$cp) ?>"><span class="ni"><i class="fas fa-exclamation-circle"></i></span>Outstanding</a>
    <a href="overdue.php"     class="nav-link<?= nav_cls('overdue.php',$cp) ?>"><span class="ni"><i class="fas fa-clock"></i></span>Overdue</a>
    <a href="cleared.php"     class="nav-link<?= nav_cls('cleared.php',$cp) ?>"><span class="ni"><i class="fas fa-check-circle"></i></span>Cleared</a>
    <a href="rejected_loans.php" class="nav-link<?= nav_cls('rejected_loans.php',$cp) ?>"><span class="ni"><i class="fas fa-times-circle"></i></span>Rejected</a>
    <a href="loan_products.php" class="nav-link<?= nav_cls('loan_products.php',$cp) ?>"><span class="ni"><i class="fas fa-times-circle"></i></span>Loan Products</a>


    <div class="nav-group">People</div>
    <a href="clients.php"     class="nav-link<?= nav_cls('clients.php',$cp) ?>"><span class="ni"><i class="fas fa-users"></i></span>Clients</a>
    <a href="staff.php"       class="nav-link<?= nav_cls('staff.php',$cp) ?>"><span class="ni"><i class="fas fa-user-tie"></i></span>Staff</a>
    <a href="startups.php" class="nav-link<?= nav_cls('startups.php',$cp) ?>"><span class="ni"><i class="fas fa-building"></i></span>Startups</a>
    <a href="users.php"       class="nav-link<?= nav_cls('users.php',$cp) ?>"><span class="ni"><i class="fas fa-user-shield"></i></span>Users</a>

    <div class="nav-group">Finance</div>
    <a href="loan_payments.php"    class="nav-link<?= nav_cls('loan_payments.php',$cp) ?>"><span class="ni"><i class="fas fa-money-bill-wave"></i></span>Payments</a>
    <a href="income_expenses.php"  class="nav-link<?= nav_cls('income_expenses.php',$cp) ?>"><span class="ni"><i class="fas fa-chart-bar"></i></span>Income & Expenses</a>
    <a href="expenses.php"         class="nav-link<?= nav_cls('expenses.php',$cp) ?>"><span class="ni"><i class="fas fa-receipt"></i></span>Expenses</a>
    <a href="all_loans_report.php" class="nav-link<?= nav_cls('all_loans_report.php',$cp) ?>"><span class="ni"><i class="fas fa-file-export"></i></span>Reports</a>
    <a href="books_history.php" class="nav-link<?= nav_cls('books_history.php',$cp) ?>"><span class="ni"><i class="fas fa-book"></i></span>Books History</a>

    <div class="nav-group">System</div>
    <a href="loan_messages.php" class="nav-link<?= nav_cls('loan_messages.php',$cp) ?>">
      <span class="ni"><i class="fas fa-envelope"></i></span>Messages
      <?php if ($new_messages > 0): ?><span class="nav-pill"><?= $new_messages ?></span><?php endif; ?>
    </a>
     <a href="manage-score-weights.php" class="nav-link<?= nav_cls('manage-score-weights.php',$cp) ?>"><span class="ni"><i class="fas fa-briefcase"></i></span>Manage score Weights</a> 
       
     <a href="manage-sectors.php" class="nav-link<?= nav_cls('manage-sectors.php',$cp) ?>"><span class="ni"><i class="fas fa-building"></i></span>Manage Sectors</a>
   <!-- <a href="settings.php" class="nav-link<?= nav_cls('settings.php',$cp) ?>"><span class="ni"><i class="fas fa-cog"></i></span>Settings</a> -->
    <a href="profile.php"  class="nav-link<?= nav_cls('profile.php',$cp) ?>"><span class="ni"><i class="fas fa-user-circle"></i></span>My Profile</a>
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