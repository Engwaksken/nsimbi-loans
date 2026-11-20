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
        <h6 class="mb-0">Upload Transaction Documents</h6>
      </div>
      <div class="card-body pt-4 p-3">
<p class="text-sm mb-3">Upload your mobile money statement, utility bill and business records to raise your credit score and loan limit. Our team reviews each document before your score changes.</p>
<a href="profile.php#credit" class="btn bg-gradient-info"><i class="fas fa-upload me-1"></i> Upload documents</a>
      </div>
    </div>
  </div>
</div>
<br>
<br>

<?php require "footer.php"; ?>

<script>
document.querySelector('form').addEventListener('submit', function(e) {
    const fileInputs = this.querySelectorAll('input[type="file"]');
    let hasFiles = false;
    
    fileInputs.forEach(input => {
        if (input.files.length > 0) {
            hasFiles = true;
    
            if (input.files[0].size > 5 * 1024 * 1024) {
                e.preventDefault();
                alert(`File ${input.files[0].name} is too large. Maximum size is 5MB.`);
                return false;
            }
        }
    });
    
    if (!hasFiles) {
        e.preventDefault();
        alert('Please select at least one file to upload.');
        return false;
    }
    
    const submitBtn = this.querySelector('input[type="submit"]');
    submitBtn.value = 'Uploading...';
    submitBtn.disabled = true;
});
</script>