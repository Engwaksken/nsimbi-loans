<?php
require "nav-head.php";

$clientId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;


$lfect = null;
$logfetch = ['loan_count' => 0];

if ($clientId > 0) {
    
    $query = "
        SELECT members.*, user.fname AS fname, user.lname AS lname
        FROM members 
        JOIN user ON members.user_id = user.id 
        WHERE members.user_id = ?
    ";
    if ($stmt = $conn->prepare($query)) {
        $stmt->bind_param("i", $clientId);
        $stmt->execute();
        $result = $stmt->get_result();
        $lfect = $result->fetch_assoc();
        $stmt->close();
    }

    $loanQuery = "SELECT COUNT(*) AS loan_count FROM loan WHERE client_id = ?";
    if ($stmt2 = $conn->prepare($loanQuery)) {
        $stmt2->bind_param("i", $clientId);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        $logfetch = $result2->fetch_assoc();
        $stmt2->close();
    }

} else {
    echo "<div class='alert alert-danger'>Invalid Staff ID provided.</div>";
}
?>


<div class="row">
  <div class="col-md-7 mt-4">
    <div class="card">
      <div class="card-header pb-0 px-3">
        <h6 class="mb-0">Staff Information</h6>
      </div>
      <div class="card-body pt-4 p-3">
        <ul class="list-group">
          <li class="list-group-item border-0 d-flex p-4 mb-2 bg-gray-100 border-radius-lg">
            <div class="d-flex flex-column">

              <?php if (!empty($lfect)): ?>
                <span class="mb-2 text-xs">
                  First Name: <span class="text-dark font-weight-bold ms-sm-2"><?= htmlspecialchars($lfect['fname']) ?></span>
                </span><hr/>

                <span class="mb-2 text-xs">
                  Last Name: <span class="text-dark font-weight-bold ms-sm-2"><?= htmlspecialchars($lfect['lname']) ?></span>
                </span><hr/>

                <span class="mb-2 text-xs">
                  Phone: <span class="text-dark font-weight-bold ms-sm-2"><?= htmlspecialchars($lfect['phone']) ?></span>
                </span><hr/>

                <span class="mb-2 text-xs">
                  Loan Limit: <span class="text-dark font-weight-bold ms-sm-2"><?= number_format($lfect['loan_limit']) ?></span>
                </span><hr/>

                <span class="mb-2 text-xs">
                  No. of Loans: <span class="text-dark font-weight-bold ms-sm-2"><?= $logfetch['loan_count'] ?? 0 ?></span>
                </span><hr/>

                <span class="mb-2 text-xs">
                  Created: <span class="text-dark font-weight-bold ms-sm-2"><?= htmlspecialchars($lfect['created_at']) ?></span>
                </span><hr/>
              <?php else: ?>
                <span class="text-danger">Staff information not found.</span>
              <?php endif; ?>

            </div>
          </li>
        </ul>
      </div>
    </div>
  </div>


       <div class="col-md-5 mt-4">
          <div class="card h-100 mb-4">
            <div class="card-header pb-0 px-3">
              <div class="row">
                <div class="col-md-6">
                  <h6 class="mb-0">Enter New Loan</h6>
                </div>                 
                <form method="post" action="added_staff_loan.php">
                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label>Enter Amount</label>
                      <input type="text" class="form-control" id="amount" name="amount" placeholder="1000...." required>
                      <input type="hidden" class="form-control" id="user_id" name="user_id" value="<?php echo $_REQUEST['user_id'];?>" required>
                    </div>
                  </div>
                    <div class="col-md-6">
                    <div class="form-group">
                      <label>Loan Period</label>
                      <select class="form-control" name="loan_period" id="loan_period" required>
                        <option value="1">1 Month</option>
                        <option value="2">2 Months</option>
                        <option value="3">3 Months</option>
                                  
                      </select>
                    </div>
                  </div>
                  </div>
                
 <div class="row">
                 <div class="col-md-6">
                    <div class="form-group">
                      <label>Interest Rate</label>
                      <select class="form-control" name="interest" id="interest" required>
                        <option value="10">10%</option> 
                      </select>
                    </div>
                  </div>
                <div class="col-md-6">
                    <div class="form-group">
                      <label>Loan Date</label>
                        <input type="datetime-local" class="form-control" id="loan_date" name="loan_date" required>
                  
                    </div>
                  </div>
                  </div>
                </div>
                  <div class="row">
                <div class="col-md-8">
                 
                   <div class="form-group">
                     <input type="submit" name="add" type="button" class="btn bg-gradient-primary" value="Add New Loan" required>
                  </div>
                 </div>
</div>
              </form>
                
              
            </div>
          </div>
        </div>
      </div> 
    </div>



      <?php require"footer.php"; ?>
