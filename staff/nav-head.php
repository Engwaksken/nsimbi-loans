<?php
session_start();
require "db.php";
require_once dirname(__DIR__) . '/includes/site_cms.php';
$brand = site_settings($conn);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] != '4') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['logged_in'];
$fname = $_SESSION['fname'] ?? '';
$lname = $_SESSION['lname'] ?? '';
$uname = htmlspecialchars($fname . ' ' . $lname);



$messages = [];
$sql_messages = "SELECT id, sender_name AS sender
                 FROM loan_messages 
                 WHERE user_id = ? AND is_read=0
                   AND message_type IN ('loan_approved', 'loan_rejected', 'payment_approved')
                 ORDER BY created_at DESC 
                 LIMIT 5";

$stmt = $conn->prepare($sql_messages);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$messages = $result->fetch_all(MYSQLI_ASSOC);
$new_messages = count($messages); 

$alerts = [];
$sql_alerts = "SELECT CONCAT('Payment for Loan #', id, ' is due on ', due_date) AS message,
                      DATE_FORMAT(due_date, '%b %d, %Y') AS time
               FROM loan
               WHERE client_id = ? 
                 AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
               ORDER BY due_date ASC";
$stmt = $conn->prepare($sql_alerts);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$alerts = $result->fetch_all(MYSQLI_ASSOC);
$alerts_count = count($alerts);

$page_active = "active";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <?= site_favicon_tags() ?>
  <title>
  </title>
  <!--     Fonts and icons     -->
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
  <!-- Nucleo Icons -->
  <link href="assets/css/nucleo-icons.css" rel="stylesheet" />
  <link href="assets/css/nucleo-svg.css" rel="stylesheet" />
  <!-- Font Awesome Icons -->
  <script src="42d5adcbca.js" crossorigin="anonymous"></script>
  <link href="assets/css/nucleo-svg.css" rel="stylesheet" />
  <!-- CSS Files -->
  <link id="pagestyle" href="assets/css/loanpro.css" rel="stylesheet" /> 
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <?= site_panel_font_tags() ?>

</head>

<body class="g-sidenav-show  bg-gray-100">
  <aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-3 " id="sidenav-main">
    <div class="sidenav-header">
      <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
      <a class="navbar-brand m-0" href="dashboard.php">
        <img src="<?= site_e($brand['logo']) ?>" class="navbar-brand-img h-auto" alt="">
       
      </a>
    </div><br>
    <div class="collapse navbar-collapse  w-auto  max-height-vh-100 h-100" id="sidenav-collapse-main">
      <ul class="navbar-nav">
       <li class="nav-item">
  <a class="nav-link <?php echo $page_active; ?>" href="dashboard.php">
    <div class="icon icon-shape icon-sm shadow border-radius-md bg-success text-center me-2 d-flex align-items-center justify-content-center">
      <i class="ni ni-chart-bar-32 text-lg text-white" aria-hidden="true"></i>
    </div>
    <span class="nav-link-text ms-1">Dashboard</span>
  </a>
</li>
 <li class="nav-item">
   <a class="nav-link <?php echo $page_active; ?>" href="loans.php">
    <div class="icon icon-shape icon-sm shadow border-radius-md bg-success text-center me-2 d-flex align-items-center justify-content-center">
      <i class="ni ni-collection text-lg text-white" aria-hidden="true"></i>
    </div>
    <span class="nav-link-text ms-1">Loans History</span>
  </a>
</li>

<li class="nav-item">
   <a class="nav-link <?php echo $page_active; ?>" href="requesting_loan.php">
    <div class="icon icon-shape icon-sm shadow border-radius-md bg-success text-center me-2 d-flex align-items-center justify-content-center">
      <i class="ni ni-bullet-list-67 text-lg text-white" aria-hidden="true"></i>
    </div>
    <span class="nav-link-text ms-1">Apply for a Loan</span>
  </a>
</li>
<li class="nav-item">
   <a class="nav-link <?php echo $page_active; ?>" href="loan_calculator.php">
    <div class="icon icon-shape icon-sm shadow border-radius-md bg-success text-center me-2 d-flex align-items-center justify-content-center">
      <i class="fas fa-calculator text-lg text-white" aria-hidden="true"></i>

    </div>
    <span class="nav-link-text ms-1">Loan Calculator</span>
  </a>
</li>
<li class="nav-item">
   <a class="nav-link <?php echo $page_active; ?>" href="repayment_schedule.php">
    <div class="icon icon-shape icon-sm shadow border-radius-md bg-success text-center me-2 d-flex align-items-center justify-content-center">
      <i class="fas fa-coins text-lg text-white" aria-hidden="true"></i>

    </div>
    <span class="nav-link-text ms-1">Repayment Schedule</span>
  </a>
</li>
<li class="nav-item">
   <a class="nav-link <?php echo $page_active; ?>" href="pending_loans.php">
    <div class="icon icon-shape icon-sm shadow border-radius-md bg-success text-center me-2 d-flex align-items-center justify-content-center">
      <i class="ni ni-time-alarm text-lg text-white" aria-hidden="true"></i>
    </div>
    <span class="nav-link-text ms-1">My Pending Loans</span>
  </a>
</li>
      

       <li class="nav-item">
   <a class="nav-link <?php echo $page_active; ?>" href="loan_payments.php">
    <div class="icon icon-shape icon-sm shadow border-radius-md bg-success text-center me-2 d-flex align-items-center justify-content-center">
      <i class="ni ni-credit-card text-lg text-white" aria-hidden="true"></i>
    </div>
    <span class="nav-link-text ms-1">My Loan Payments</span>
  </a>
</li>
        
        <li class="nav-item">
          <a class="nav-link" href="logout.php">
           <div class="icon icon-shape icon-sm shadow border-radius-md bg-danger text-center me-2 d-flex align-items-center justify-content-center">
              <i class="ni ni-user-run text-lg text-white" aria-hidden="true"></i>
            </div>
            <span class="nav-link-text ms-1">Logout</span>
          </a>
        </li>
      </ul>
    </div>
  </aside>

  <!-- Main Content --> 
<main class="main-content position-relative max-height-vh-100 h-100 overflow-y-auto">
  <!-- Navbar -->
  <nav class="navbar navbar-main navbar-expand-lg px-4 py-2 shadow-sm" id="navbarBlur">
    <div class="container-fluid py-1 px-3">
      <!-- Breadcrumb -->
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
          <li class="breadcrumb-item text-sm">
            <a class="opacity-5 text-dark" href="#">Nsimbi Impact</a>
          </li>
          <li class="breadcrumb-item text-sm text-dark active" aria-current="page">
            <?php echo ucfirst($page_active); ?>
          </li>
        </ol>
      </nav>

<!-- Right-side Navbar -->
<ul class="navbar-nav ms-auto d-flex align-items-center">

  <!-- Messages Icon -->
  <li class="nav-item dropdown mx-2">
    <a class="nav-link text-body p-0" href="#" id="messagesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-envelope fs-5 position-relative">
        <?php if ($new_messages > 0): ?>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
            <?php echo $new_messages; ?>
          </span>
        <?php endif; ?>
      </i>
    </a>
    <ul class="dropdown-menu dropdown-menu-end px-2 py-3" aria-labelledby="messagesDropdown">
      <li class="dropdown-header">Messages</li>
      <?php foreach ($messages as $message): ?>
        <li>
          <a class="dropdown-item" href="loan_messages.php?id=<?php echo $message['id']; ?>">
            <div class="d-flex align-items-center">
              <div>
                <i class="bi bi-person-circle me-2"></i>
              </div>
              <div>
                <span class="d-block text-sm fw-bold"><?php echo $message['sender']; ?></span>
              </div>
            </div>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </li>

  <!-- Notifications Icon -->
  <li class="nav-item dropdown mx-2">
    <a class="nav-link text-body p-0" href="#" id="notificationsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-bell fs-5 position-relative">
        <?php if ($alerts_count > 0): ?>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark">
            <?php echo $alerts_count; ?>
          </span>
        <?php endif; ?>
      </i>
    </a>
    <ul class="dropdown-menu dropdown-menu-end px-2 py-3" aria-labelledby="notificationsDropdown">
      <li class="dropdown-header">Notifications</li>
      <?php foreach ($alerts as $alert): ?>
        <li>
          <a class="dropdown-item" href="loan_notifications.php">
            <div class="d-flex align-items-center">
              <div>
                <i class="bi bi-info-circle text-primary me-2"></i>
              </div>
              <div>
                <span class="text-sm"><?php echo $alert['message']; ?></span>
                <small class="text-muted d-block"><?php echo $alert['time']; ?></small>
              </div>
            </div>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </li>


        <li class="nav-item dropdown">
          <a class="nav-link text-body d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
           <img src="<?php echo !empty($user_photo) ? $user_photo : 'assets/img/avatar.jpg'; ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">

            <span class="d-sm-inline d-none"><?php echo $uname; ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
            <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </li>
       
        <li class="nav-item d-xl-none ps-3 d-flex align-items-center">
          <a href="#" class="nav-link text-body p-0" id="iconNavbarSidenav">
            <div class="sidenav-toggler-inner">
              <i class="sidenav-toggler-line"></i>
              <i class="sidenav-toggler-line"></i>
              <i class="sidenav-toggler-line"></i>
            </div>
          </a>
        </li>

      </ul>
    </div>
  </nav>


  
  <!-- Sidenav toggle script -->
  <script>
    document.getElementById('iconNavbarSidenav')?.addEventListener('click', function () {
      document.getElementById('sidenav-main')?.classList.toggle('active');
    });
  </script>
   

