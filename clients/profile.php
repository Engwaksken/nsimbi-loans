<?php 
require "nav-head.php";  
if (!isset($_SESSION['logged_in'])) {
    exit("User not logged in.");
}



$user_id = mysqli_real_escape_string($conn, $_SESSION['logged_in']);

$sql = "SELECT user.fname, user.lname, user.email, user.status, customers.* 
        FROM customers 
        INNER JOIN user ON customers.user_id = user.id 
        WHERE customers.user_id = '$user_id' 
        ORDER BY customers.id DESC LIMIT 1";

$lget = mysqli_query($conn, $sql);

if (!$lget) {
    exit("Database query failed: " . mysqli_error($conn));
}

$lfect = mysqli_fetch_assoc($lget);
if (!$lfect) {
    exit("No customer record found for user ID: " . $user_id);
}

$clog = mysqli_query($conn, "SELECT COUNT(*) AS loan_count FROM loan WHERE client_id = '$user_id'");
$logfetch = mysqli_fetch_assoc($clog);
?>

<div class="row">
  <div class="col-md-7 mt-4">
    <div class="card">
      <div class="card-header pb-0 px-3">
        <h6 class="mb-0">Profile Information</h6>
      </div>
      <div class="card-body pt-4 p-3">
        <ul class="list-group">
          <li class="list-group-item border-0 d-flex p-4 mb-2 bg-gray-100 border-radius-lg">
            <div class="d-flex flex-column">
              <span class="mb-2 text-xs">First Name: 
                <span class="text-dark font-weight-bold ms-sm-2"><?= htmlspecialchars($lfect['fname']) ?></span></span><hr/>
              <span class="mb-2 text-xs">Last Name: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['lname']) ?></span></span><hr/>
              <span class="text-xs">Phone: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['phone']) ?></span></span><hr/>
              <span class="text-xs">Status: 
                <span class="text-dark ms-sm-2 font-weight-bold">
                  <?= $lfect['status'] == 1 ? 'Active' : 'Blocked' ?>
                </span></span><hr/>
              <span class="text-xs">Created: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['created_at']) ?></span></span><hr/>
              <span class="text-xs">Loan Limit: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['loan_limit']) ?></span></span><hr/>
              <span class="text-xs">Loans: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= $logfetch['loan_count'] ?? 0 ?></span></span><hr/>
            </div>
         <div class="ms-auto text-end">
                    <span class="text-xs">Email Address: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['email']) ?></span></span><hr/>
              <span class="text-xs">Location: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['location']) ?></span></span><hr/>
                
                 <span class="text-xs">District: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['district']) ?></span></span><hr/>
                 <span class="text-xs">NIN Number: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['nin']) ?></span></span><hr/>
              <span class="text-xs">Next of Kin Name: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['nok_name']) ?></span></span><hr/>
                <span class="text-xs">Next of Kin Phone: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['nok_tel']) ?></span></span><hr/>
                  </div>
                </li>
                
              </ul>
            </div>
          </div>
        </div>

  <div class="col-md-5 mt-4">
    <div class="card h-100 mb-4">
      <div class="card-header pb-0 px-3">
        <h6 class="mb-0">Edit Profile</h6>
      </div>
      <div class="card-body pt-4 p-3">
        <form method="post" action="edited_profile.php">
          <div class="row">
            <div class="col-md-6">
              <div class="form-group">
                <label>Net of Kin Name</label>
                <input type="text" class="form-control" name="nok_name" value="<?= htmlspecialchars($lfect['nok_name']) ?>" required>
                <input type="hidden" name="client" value="<?= $lfect['user_id'] ?>">
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-group">
                <label>Next of Kin Phone</label>
                <input type="text" class="form-control" name="nok_tel" value="<?= htmlspecialchars($lfect['nok_tel']) ?>" required>
              </div>
            </div>
          </div>

          <div class="row mt-2">
            <div class="col-md-6">
              <div class="form-group">
                <label>Phone</label>
                <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($lfect['phone']) ?>" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-group">
                <label>Location</label>
                <input type="text" class="form-control" name="home_address" value="<?= htmlspecialchars($lfect['location']) ?>" required>
              </div>
            </div>
          </div>
          
           <div class="row mt-2">
            <div class="col-md-6">
              <div class="form-group">
                <label>Bank Name</label>
                <input type="text" class="form-control" name="bank_name" value="<?= htmlspecialchars($lfect['bank_name']) ?>" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-group">
                <label>Bank Account</label>
                <input type="number" class="form-control" name="bank_account" value="<?= htmlspecialchars($lfect['bank_account']) ?>" required>
              </div>
            </div>
          </div>


          <div class="row mt-3">
            <div class="col-md-12">
              <div class="form-group text-end">
                <input type="submit" name="update_profile" class="btn bg-gradient-info" value="Update Profile">
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<br>
<br>
<?php require "footer.php"; ?>