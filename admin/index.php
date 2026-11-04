<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== '1') {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$uname = htmlspecialchars($_SESSION['fname'] . ' ' . $_SESSION['lname'], ENT_QUOTES, 'UTF-8');

 $page_active="active";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Dashboard Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
</head>

<body class="bg-light">
  <div class="d-flex">
    <!-- Sidebar -->
    <nav class="flex-shrink-0 p-3 bg-white vh-100" style="width: 250px;">
      <a href="dashboard.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-decoration-none">
        <img src="assets/img/logo.png" alt="Logo" class="me-2" style="height:40px">
        <span class="fs-5 fw-bold">Admin Panel</span>
      </a>
      <hr>
      <ul class="nav nav-pills flex-column">
        <?php
        $menu = [
          'index.php' => ['icon'=>'bi-speedometer','label'=>'Dashboard'],
          'loans.php' => ['icon'=>'bi-currency-dollar','label'=>'Loans'],
          'clients.php' => ['icon'=>'bi-people','label'=>'Clients'],
          'loan_payments.php' => ['icon'=>'bi-credit-card','label'=>'Payments'],
          'users.php' => ['icon'=>'bi-person-badge','label'=>'Users'],
          'logout.php' => ['icon'=>'bi-box-arrow-right','label'=>'Log Out'],
        ];
        foreach ($menu as $file => $info):
        ?>
          <li class="nav-item">
            <a href="<?= $file ?>"
               class="nav-link <?= ($page_active === $file) ? 'active' : 'text-dark' ?>">
              <i class="bi <?= $info['icon'] ?> me-2"></i>
              <?= $info['label'] ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <!-- Main Content -->
    <div class="flex-grow-1">
      <!-- Navbar -->
      <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container-fluid">
          <span class="navbar-text">Welcome, <?= $uname ?></span>
        </div>
      </nav>

      <main class="container my-4">
      <div class="container-fluid py-4">
      <div class="row">

        
        <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
         <a href="loans.php"> <div class="card">
            <div class="card-body p-3">
              <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Loans (UGX:)</p>
                    <h5 class="font-weight-bolder mb-0">
                      <?php echo number_format($sumedL); ?>
                      
                    </h5>
                  </div>
                </div>
                <div class="col-4 text-end">
                  <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                    <i class="ni ni-money-coins text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div></a>
        </div>  
        <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
         <a href="loans.php"> <div class="card">
            <div class="card-body p-3">
              <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Interest (UGX:)</p>
                    <h5 class="font-weight-bolder mb-0">
                      <?php echo number_format($sumedI); ?>
                      
                    </h5>
                  </div>
                </div>
                <div class="col-4 text-end">
                  <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                    <i class="ni ni-money-coins text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div></a>
        </div>



        <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
         <a href="outstanding.php?check=2"> <div class="card">
            <div class="card-body p-3">
              <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Outstanding (UGX:)</p>
                    <h5 class="font-weight-bolder mb-0">
                     <?php echo number_format($sumedLO); ?>
                      
                    </h5>
                  </div>
                </div>
                <div class="col-4 text-end">
                  <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                    <i class="ni ni-world text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div></a>
        </div>
        <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
          <a href="cleared.php?check=1"><div class="card">
            <div class="card-body p-3">
              <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Cleared Loans(UGX:)</p>
                    <h5 class="font-weight-bolder mb-0">
                      <?php echo number_format($sumedLC); ?>
                      
                    </h5>
                  </div>
                </div>
                <div class="col-4 text-end">
                  <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                    <i class="ni ni-satisfied text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div></a>
        </div>
  <hr/>
         <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
         <a href="overdue.php?check=3"> <div class="card">
            <div class="card-body p-3">
              <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold">OVERDUE LOANS</p>
                    <h5 class="font-weight-bolder mb-0">
                      <?php echo number_format($sumedLV); ?>
                    </h5>
                  </div>
                </div>
                <div class="col-4 text-end">
                  <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                    <i class="ni ni-world text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div></a>
        </div>

         <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
          <a href="loan_payments.php"><div class="card">
            <div class="card-body p-3">
              <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Paid Loans (UGX:)</p>
                    <h5 class="font-weight-bolder mb-0">
                      <?php echo number_format($sumedpay); ?>
                    </h5>
                  </div>
                </div>
                <div class="col-4 text-end">
                  <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                    <i class="ni ni-credit-card text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div></a>
        </div>
        
        <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
         <a href="loans.php"> <div class="card">
            <div class="card-body p-3">
              <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Paid Interest (UGX:)</p>
                    <h5 class="font-weight-bolder mb-0">
                      <?php echo number_format($sumedIP); ?>
                      
                    </h5>
                  </div>
                </div>
                <div class="col-4 text-end">
                  <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                    <i class="ni ni-money-coins text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div></a>
        </div>

 <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
         <a href="pending_loans.php?check=0"> <div class="card">
            <div class="card-body p-3">
              <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold">PENDING LOANS</p>
                    <h5 class="font-weight-bolder mb-0">
                      <?php echo number_format($count_pending); ?>
                    </h5>
                  </div>
                </div>
                <div class="col-4 text-end">
                  <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                    <i class="ni ni-world text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div></a>
        </div>
       
  <hr/>
     
      <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
          <a href="active_clients.php?check=1"><div class="card">
            <div class="card-body p-3">
              <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Active Clients</p>
                    <h5 class="font-weight-bolder mb-0">
                      <?php echo number_format($sumedCA); ?>
                    </h5>
                  </div>
                </div>
                <div class="col-4 text-end">
                  <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                    <i class="ni ni-paper-diploma text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div></a>
        </div>
        
        <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
          <a href="blocked_clients.php?check=2"><div class="card">
            <div class="card-body p-3">
              <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Blocked Clients</p>
                    <h5 class="font-weight-bolder mb-0">
                      <?php echo number_format($sumedCD); ?>
                    </h5>
                  </div>
                </div>
                <div class="col-4 text-end">
                  <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                    <i class="ni ni-user-run text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div></a>
        </div>

      
     
      <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
         <a href="users.php"> <div class="card">
            <div class="card-body p-3">
              <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Users</p>
                    <h5 class="font-weight-bolder mb-0">
                      <?php echo number_format($count_user); ?>
                    </h5>
                  </div>
                </div>
                <div class="col-4 text-end">
                  <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                    <i class="ni ni-single-02 text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div></a>
        </div>
 
 
        <br/>
        <hr>
        <h3>Recent Loans</h3>
        </hr>
            <div class="card-body px-0 pt-0 pb-2">
              <div class="table-responsive p-0">
                <table class="table align-items-center mb-0">
                  <thead>
                    <tr>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Amount</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Interest</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Balance</th>
                      <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                      <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Datetime</th>
                         <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Due Date</th>
                     <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">ACtion</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php 
                     $lget = mysqli_query($d_db, 'select * from loan order by id desc limit 5');
                     while($lfect = mysqli_fetch_assoc($lget)){

                      $client_get = mysqli_query($d_db, 'select * from customers where id="'.$lfect['client_id'].'" ');
                      $cfect = mysqli_fetch_assoc($client_get);

                    ?>
                    <tr>
                      <td>
                        <div class="d-flex px-2 py-1">
                          
                          <div class="d-flex flex-column justify-content-center">
                            <h6 class="mb-0 text-sm"><?php echo $cfect['fname']." ".$cfect['lname']; ?></h6>
                            
                          </div>
                        </div>
                      </td>
                      <td>
                        <p class="text-xs font-weight-bold mb-0"><?php echo number_format($lfect['amount']); ?></p>
                        
                      </td>
                      <td>
                        <p class="text-xs font-weight-bold mb-0"><?php echo number_format($lfect['interest']); ?></p>
                        
                      </td>
                      <td>
                        <p class="text-xs font-weight-bold mb-0"><?php echo number_format($lfect['loan_balance']); ?></p>
                        
                      </td>

                      <td class="align-middle text-center text-sm">
                        <span class="badge badge-sm bg-gradient-<?php if($lfect['status']=='CLEARED'){ echo 'success'; } elseif( $lfect['status']=='OVERDUE' ){ echo 'danger'; }else{ echo 'secondary'; } ?>"><?php echo $lfect['status'];  ?></span>
                      </td>
                      <td class="align-middle text-center">
                        <span class="text-secondary text-xs font-weight-bold"><?php echo $lfect['datetime'];  ?></span>
                      </td>
                       <td class="align-middle text-center">
                        <span class="text-secondary text-xs font-weight-bold"><?php echo $lfect['due_date'];  ?></span>
                      </td>
                      <td class="align-middle text-center text-sm">

                        <a href="view_loan.php?id=<?php echo $lfect['id']; ?>" class="badge badge-sm bg-gradient-info" >
                          View
                        </a>
                      </td>
                    </tr>

                  <?php } ?>
                    
                  </tbody>
                </table>
              </div>
            </div>
       
      
  <!--   Core JS Files   -->
  <script src="assets/js/core/popper.min.js"></script>
  <script src="assets/js/core/bootstrap.min.js"></script>
  <script src="assets/js/plugins/perfect-scrollbar.min.js"></script>
  <script src="assets/js/plugins/smooth-scrollbar.min.js"></script>
  <script src="assets/js/plugins/chartjs.min.js"></script>
  <script>
    var ctx = document.getElementById("chart-bars").getContext("2d");

    new Chart(ctx, {
      type: "bar",
      data: {
        labels: ["Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
        datasets: [{
          label: "Sales",
          tension: 0.4,
          borderWidth: 0,
          borderRadius: 4,
          borderSkipped: false,
          backgroundColor: "#fff",
          data: [450, 200, 100, 220, 500, 100, 400, 230, 500],
          maxBarThickness: 6
        }, ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false,
          }
        },
        interaction: {
          intersect: false,
          mode: 'index',
        },
        scales: {
          y: {
            grid: {
              drawBorder: false,
              display: false,
              drawOnChartArea: false,
              drawTicks: false,
            },
            ticks: {
              suggestedMin: 0,
              suggestedMax: 500,
              beginAtZero: true,
              padding: 15,
              font: {
                size: 14,
                family: "Open Sans",
                style: 'normal',
                lineHeight: 2
              },
              color: "#fff"
            },
          },
          x: {
            grid: {
              drawBorder: false,
              display: false,
              drawOnChartArea: false,
              drawTicks: false
            },
            ticks: {
              display: false
            },
          },
        },
      },
    });


    var ctx2 = document.getElementById("chart-line").getContext("2d");

    var gradientStroke1 = ctx2.createLinearGradient(0, 230, 0, 50);

    gradientStroke1.addColorStop(1, 'rgba(203,12,159,0.2)');
    gradientStroke1.addColorStop(0.2, 'rgba(72,72,176,0.0)');
    gradientStroke1.addColorStop(0, 'rgba(203,12,159,0)'); //purple colors

    var gradientStroke2 = ctx2.createLinearGradient(0, 230, 0, 50);

    gradientStroke2.addColorStop(1, 'rgba(20,23,39,0.2)');
    gradientStroke2.addColorStop(0.2, 'rgba(72,72,176,0.0)');
    gradientStroke2.addColorStop(0, 'rgba(20,23,39,0)'); //purple colors

    new Chart(ctx2, {
      type: "line",
      data: {
        labels: ["Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
        datasets: [{
            label: "Mobile apps",
            tension: 0.4,
            borderWidth: 0,
            pointRadius: 0,
            borderColor: "#cb0c9f",
            borderWidth: 3,
            backgroundColor: gradientStroke1,
            fill: true,
            data: [50, 40, 300, 220, 500, 250, 400, 230, 500],
            maxBarThickness: 6

          },
          {
            label: "Websites",
            tension: 0.4,
            borderWidth: 0,
            pointRadius: 0,
            borderColor: "#3A416F",
            borderWidth: 3,
            backgroundColor: gradientStroke2,
            fill: true,
            data: [30, 90, 40, 140, 290, 290, 340, 230, 400],
            maxBarThickness: 6
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false,
          }
        },
        interaction: {
          intersect: false,
          mode: 'index',
        },
        scales: {
          y: {
            grid: {
              drawBorder: false,
              display: true,
              drawOnChartArea: true,
              drawTicks: false,
              borderDash: [5, 5]
            },
            ticks: {
              display: true,
              padding: 10,
              color: '#b2b9bf',
              font: {
                size: 11,
                family: "Open Sans",
                style: 'normal',
                lineHeight: 2
              },
            }
          },
          x: {
            grid: {
              drawBorder: false,
              display: false,
              drawOnChartArea: false,
              drawTicks: false,
              borderDash: [5, 5]
            },
            ticks: {
              display: true,
              color: '#b2b9bf',
              padding: 20,
              font: {
                size: 11,
                family: "Open Sans",
                style: 'normal',
                lineHeight: 2
              },
            }
          },
        },
      },
    });
  </script>
  <script>
    var win = navigator.platform.indexOf('Win') > -1;
    if (win && document.querySelector('#sidenav-scrollbar')) {
      var options = {
        damping: '0.5'
      }
      Scrollbar.init(document.querySelector('#sidenav-scrollbar'), options);
    }
  </script>
  <!-- Github buttons -->
  <script async defer src="https://buttons.github.io/buttons.js"></script>
  <!-- Control Center for Soft Dashboard: parallax effects, scripts for the example pages etc -->
  <script src="assets/js/soft-ui-dashboard.min.js?v=1.0.3"></script>
</body>

</html>
      </main>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
