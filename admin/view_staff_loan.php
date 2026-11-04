<?php 
require"nav-head.php" ;

   $lget = mysqli_query($conn, 'select * from loan where id="'.$_REQUEST['id'].'" ');
   $lfect = mysqli_fetch_assoc($lget);
   $clientId = mysqli_real_escape_string($conn, $lfect['client_id']);

$client_get = mysqli_query($conn, "
    SELECT members.*, user.fname AS fname, user.lname AS lname
        FROM members 
        JOIN user ON members.user_id = user.id 
        WHERE members.user_id = '$clientId'");

$cfect = mysqli_fetch_assoc($client_get);


  ?>
<div class="row">
  <div class="col-md-7 mt-4">
    <div class="card">
      <div class="card-header pb-0 px-3">
        <h6 class="mb-0">Loan Information</h6>
      </div>
      <div class="card-body pt-4 p-3">
        <ul class="list-group">
          <li class="list-group-item border-0 d-flex p-4 mb-2 bg-gray-100 border-radius-lg">
            <div class="d-flex flex-column">
              <h6 class="mb-3 text-sm text-secondary">
                Staff Name: <?php echo htmlspecialchars($cfect['fname'] . ' ' . $cfect['lname']); ?>
              </h6>
              <hr />
              
              <span class="mb-2 text-xs">
                Phone Number: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?php echo htmlspecialchars($cfect['phone']); ?></span>
              </span>
              <hr />

              <span class="text-xs">
                ID Number: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?php echo htmlspecialchars($cfect['nin']); ?></span>
              </span>
              <hr />

              <span class="text-xs">
                Loan Amount: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?php echo number_format($lfect['amount']); ?></span>
              </span>
              <hr />

              <span class="text-xs">
                Loan Balance: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?php echo number_format($lfect['loan_balance']); ?></span>
              </span>
              <hr />

              <span class="text-xs">
                Issue Date: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?php echo htmlspecialchars($lfect['datetime']); ?></span>
              </span>
              <hr />

              <span class="text-xs">
                Due Date: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?php echo htmlspecialchars($lfect['due_date']); ?></span>
              </span>
              <hr />
            </div>

            <div class="ms-auto text-end">
              <a class="btn btn-link text-info text-gradient px-3 mb-0" href="#">
                <i class="far fa-info-circle me-2"></i>Status
              </a>
              <a class="btn btn-link text-dark px-3 mb-0" href="#">
                <i class="fas fa-pencil-alt text-dark me-2" aria-hidden="true"></i>
                <?php echo htmlspecialchars($lfect['status']); ?>
              </a>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </div>


        <div class="col-md-4 mt-4">
          <div class="card h-100 mb-4">
            <div class="card-header pb-0 px-3">
              <div class="row">
                <div class="col-md-6">
                  <h6 class="mb-0">Add Payment</h6>
                </div>
              
                 <?php if($lfect['status']=="CLEARED"){?>
                  <h3>Loan Cleared<h3/>
                    <p>Payment options not available</p>
                  <?php }else{ ?>
                <form method="post" action="added_payment.php">
                <div class="row">
                  <div class="col-md-6 ">
                    <div class="form-group">
                      <label>Enter Amount</label>
                      <input type="text" class="form-control" id="amount" name="amount" placeholder="1000...." required>
                      <input type="hidden" class="form-control" id="loan" name="loan" value="<?php echo $lfect['id']?>" required>
                       <input type="hidden" class="form-control" id="client_id" name="client_id" value="<?php echo $lfect['client_id']?>" required>
                    </div>
                  </div>
                  </div>
                 
                 <div class="row">
                <div class="col-md-6">
                   <div class="form-group">
                     <input type="submit" name="add" type="button" class="btn bg-gradient-info" value="Add Payment" required>
                  </div>
                 </div>
</div>
              </form>
                <?php  }?>
              
            </div>
          </div>
        </div>
      </div>
    </div>


    



      <?php require"footer.php"; ?>