<?php require"nav-head.php";

$table_name = "MEMBERS";

  $lget = mysqli_query($conn, " SELECT user.fname, user.lname, user.email, user.status, members.* FROM members
    INNER JOIN user ON members.user_id = user.id AND user.status=0
    ORDER BY members.id DESC
");

  $phead = "BLOCKED STAFF MEMBERS";

 
 ?>

    <div class="container-fluid py-4">

      <div class="row">
        <div class="col-12">
          <div class="card mb-4">
            <div class="card-header pb-0">
              <h6><?php echo $phead; ?></h6>
            </div>

            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">          
              
                <div class="row">
                <div class="col-8">
                  <div class="numbers">
                    
                    <span class="pull-right">
                     <button class="btn bg-gradient-success" onclick="download_table_as_csv('report')" >Export CSV</button>
                    </span>
                  </div>                
             
            </div>
          </div>
        </div>

            <div class="card-body px-0 pt-0 pb-2">
              <div class="table-responsive p-0" >
                <table class="table align-items-center mb-0 " id="report" >
                  <thead>
                    <tr>

                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">#ID</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">First Name</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Last Name</th>
                      <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Phone</th>
                      <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Loan Limit</th>
                     <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                     <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actione</th>
                    </tr>
                  </thead>
                  <tbody>

                    <?php 
                     
                     while($lfect = mysqli_fetch_assoc($lget)){
                    ?>
                    <tr>
                      <td>
                        <div class="d-flex px-2 py-1">
                          
                          <div class="d-flex flex-column justify-content-center">
                            <h6 class="mb-0 text-sm"><?php echo $lfect['user_id']; ?></h6>
                            
                          </div>
                        </div>
                      </td>
                      <td>
                        <div class="d-flex px-2 py-1">
                          
                          <div class="d-flex flex-column justify-content-center">
                            <h6 class="mb-0 text-sm"><?php echo $lfect['fname']; ?></h6>
                            
                          </div>
                        </div>
                      </td>
                      <td>
                        <p class="text-xs font-weight-bold mb-0"><?php echo $lfect['lname']; ?></p>
                        
                      </td>
                      <td class="align-middle text-center text-sm">
                        <span class="mb-0 text-sm"><?php echo $lfect['phone'];  ?></span>
                      </td>
                      <td class="align-middle text-center text-sm">
                        <span class="mb-0 text-sm"><?php echo number_format($lfect['loan_limit']);  ?></span>
                      </td>
                      <td class="align-middle text-center">
                        <span class="badge badge-sm bg-gradient-<?php if($lfect['status']=='1'){ echo 'success'; } else{ echo 'secondary'; } ?>"><?php if($lfect['status']==1){ echo "Active"; } else{ echo "Blocked"; };  ?></span>
                      </td>
                      <td class="align-middle text-center text-sm">

                        <a href="view_staff.php?user_id=<?php echo $lfect['user_id']; ?>" class="badge badge-sm bg-info" >
                        <i class="fa fa-eye"></i>
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
      
  <?php require"footer.php" ?>
