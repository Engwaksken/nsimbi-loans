<?php 

require"nav-head.php";

$table_name = "Loans";
if (!isset($_SESSION['logged_in'])) {
    exit("User not logged in.");
}

$client_id = mysqli_real_escape_string($conn, $_SESSION['logged_in']);

                  if($_REQUEST['check']==3){

                    $lget = mysqli_query($conn, 'select * from loan where status="OVERDUE" order by id desc');
                     $phead = "OVERDUE LOANS"; }
 ?>
    <div class="container-fluid py-4">
      <div class="row">
        <div class="col-12">
          <div class="card mb-4">
            <div class="card-header pb-0">
              <h6><?php echo $phead; ?> </h6>
            </div>

            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">          
              
                <div class="row">
                <div class="col-8">
                  <div class="numbers">
        
        <span class="pull-right">
                     <button class="btn bg-gradient-success" onclick="download_table_as_csv('report')" >Export CSV</button>
                    <span>
                  </div>                
              
              
            </div>
          </div>
        </div>

            <div class="card-body px-0 pt-0 pb-2">
              <div class="table-responsive p-0" >
                <table class="table align-items-center mb-0 " id="report" >
                  <thead>
                  <?php  $counter =1; ?>
                    <tr>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">#</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Amount</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Interest</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Balance</th>               
                      <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Issue Date</th>
                       <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Due Date</th>
                     <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">ACtion</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php

                    
                     while($lfect = mysqli_fetch_assoc($lget)){

                      $client_get = mysqli_query($conn, 'select * from customers where user_id="'.$client_id.'" ');
                      $cfect = mysqli_fetch_assoc($client_get);
                    ?>
                    <tr>
                      <td>
                        <div class="d-flex px-2 py-1">
                          
                          <div class="d-flex flex-column justify-content-center">
                            <h6 class="mb-0 text-sm"><?php echo $counter++; ?></h6>
                            
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
          </div>
        </div>
      </div>
      
  <?php require"footer.php"; ?>
