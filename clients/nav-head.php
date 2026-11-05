<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../php/db.php';
require_once dirname(__DIR__) . '/includes/site_cms.php';
$brand = site_settings($conn);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] != '3') {
    header('Location: ../login.php'); exit();
}
$user_id  = (int)$_SESSION['logged_in'];
$fname    = $_SESSION['fname'] ?? '';
$lname    = $_SESSION['lname'] ?? '';
$uname    = htmlspecialchars(trim("$fname $lname"));
$initials = strtoupper(substr($fname,0,1).substr($lname,0,1));
$cp       = basename($_SERVER['PHP_SELF']);
function cn($p,$c){ return $p===$c?' active':''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($phead??'My Loans') ?> - <?= site_e($brand['site_name']) ?></title>
  <?= site_favicon_tags() ?>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/css/loanpro.css">
  <?= site_panel_style_tags() ?>
 <!-- <link rel="stylesheet" href="../datatables2/datatables.min.css"> -->
  <!--<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"> -->

</head>
<body>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <img src="<?= site_e($brand['logo']) ?>" alt="Logo" onerror="this.style.display='none'">
    <div class="brand-text"><?= str_replace(['<b>', '</b>'], ['<span>', '</span>'], site_wordmark_html()) ?></div>
  </div>
  <div class="sidebar-user">
    <div class="s-avatar"><?= $initials ?></div>
    <div><div class="s-name"><?= $uname ?></div><div class="s-role">Client Portal</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-group">My Account</div>
    <a href="dashboard" class="nav-link<?= cn('dashboard.php',$cp) ?>"><span class="ni"><i class="fas fa-th-large"></i></span>Dashboard</a>
     <a href="requesting_loan"  class="nav-link<?= cn('requesting_loan.php',$cp)  ?>"><span class="ni"><i class="fas fa-file-invoice-dollar"></i></span>Request a Loan</a> 
    <a href="my_loans"  class="nav-link<?= cn('my_loans.php',$cp)  ?>"><span class="ni"><i class="fas fa-file-invoice-dollar"></i></span>My Loans</a>
    <a href="loan_payments" class="nav-link<?= cn('loan_payments.php',$cp) ?>"><span class="ni"><i class="fas fa-money-bill-wave"></i></span>Payment History</a>
    
     <a href="books" class="nav-link<?= cn('books.php',$cp) ?>"><span class="ni"><i class="fas fa-book"></i></span>My Books</a>
    <a href="repayment_schedule" class="nav-link<?= cn('repayment_schedule.php',$cp) ?>"><span class="ni"><i class="fas fa-calendar-alt"></i></span>Schedules</a>
    <div class="nav-group">Support</div>
    <a href="messages" class="nav-link<?= cn('messages.php',$cp) ?>"><span class="ni"><i class="fas fa-envelope"></i></span>Messages</a>
    <a href="profile"  class="nav-link<?= cn('profile.php',$cp) ?>"><span class="ni"><i class="fas fa-user-circle"></i></span>My Profile</a>
  </nav>
  <div class="sidebar-footer">
    <a href="logout" class="nav-link"><span class="ni"><i class="fas fa-sign-out-alt"></i></span>Sign Out</a>
  </div>
</aside>

<div class="main-wrapper">
<header class="topbar">
  <div class="topbar-left">
    <button class="hamburger" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
    <div>
      <div class="topbar-title"><?= htmlspecialchars($phead??'Dashboard') ?></div>
      <div class="topbar-sub"><?= date('l, F j Y') ?></div>
    </div>
  </div>
  <div class="topbar-right">
    <a href="profile" class="t-avatar"><?= $initials ?></a>
  </div>
</header>
<main class="page-content">
