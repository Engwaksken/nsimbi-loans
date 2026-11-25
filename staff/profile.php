<?php 
require "nav-head.php";  

if (!isset($_SESSION['logged_in'])) {
    exit("User not logged in.");
}

require "db.php"; 

$user_id = mysqli_real_escape_string($conn, $_SESSION['logged_in']);

$sql = "SELECT user.fname, user.lname, user.email, user.status, members.* 
        FROM members 
        INNER JOIN user ON members.user_id = user.id 
        WHERE members.user_id = '$user_id' 
        ORDER BY members.id DESC 
        LIMIT 1";

$lget = mysqli_query($conn, $sql);

if (!$lget) {
    exit("Database query failed: " . mysqli_error($conn));
}

$lfect = mysqli_fetch_assoc($lget);

$clog = mysqli_query($conn, "SELECT COUNT(*) AS loan_count FROM loan WHERE client_id = '$user_id'");
$logfetch = mysqli_fetch_assoc($clog);

// Profile photo (selfie): shown here and changed with staff/profile_photo_action.php.
require_once dirname(__DIR__) . '/includes/profile_photo.php';
$photoPath = profile_photo_path($conn, (int)$user_id);
$photoInitials = strtoupper(substr((string)($lfect['fname'] ?? ''), 0, 1) . substr((string)($lfect['lname'] ?? ''), 0, 1));
if (empty($_SESSION['staff_photo_csrf'])) {
    $_SESSION['staff_photo_csrf'] = bin2hex(random_bytes(16));
}
[$photoFlash, $photoFlashType] = $_SESSION['staff_photo_flash'] ?? ['', ''];
unset($_SESSION['staff_photo_flash']);
?>
<style>
.staff-photo{width:96px;height:96px;border-radius:50%;background:#123299;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:800;flex-shrink:0}
</style>
<div class="row">
  <div class="col-12 mt-4">
    <div class="card">
      <div class="card-header pb-0 px-3"><h6 class="mb-0">Profile Photo</h6></div>
      <div class="card-body pt-3 p-3">
        <?php if ($photoFlash !== ''): ?>
          <div class="alert <?= $photoFlashType === 'success' ? 'alert-success' : 'alert-danger' ?> text-white"><?= htmlspecialchars($photoFlash) ?></div>
        <?php endif; ?>
        <div class="d-flex flex-wrap align-items-center gap-3">
          <?= profile_avatar($photoPath, $photoInitials, 'staff-photo') ?>
          <form method="post" action="profile_photo_action.php" enctype="multipart/form-data" id="photoForm" class="flex-grow-1" style="min-width:260px" novalidate>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['staff_photo_csrf']) ?>">
            <div class="selfie-box" data-selfie>
              <div class="selfie-preview"><img alt="Your selfie" hidden><i class="fas fa-user"></i></div>
              <div class="selfie-body">
                <button type="button" class="btn btn-sm btn-primary mb-0" data-selfie-start><i class="fas fa-camera"></i> <?= $photoPath !== '' ? 'Take a new selfie' : 'Take a selfie' ?></button>
                <div class="selfie-help" data-selfie-help>Face the camera in good light, with no sunglasses or hat.</div>
              </div>
              <input type="file" class="selfie-input" name="profile_img" accept="image/jpeg,image/png" capture="user" required aria-label="Your selfie">
            </div>
            <button type="submit" class="btn bg-gradient-info btn-sm mt-2 mb-0" id="photoSave" disabled>Save Photo</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="../assets/js/selfie-capture.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/js/selfie-capture.js') ?>"></script>
<script>
document.getElementById('photoForm').addEventListener('selfie:change', function () {
  document.getElementById('photoSave').disabled = false;
});
</script>

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
                    <span class="text-xs">Personal Email: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['email']) ?></span></span><hr/>
              <span class="text-xs">Work Email: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['work_email']) ?></span></span><hr/>
                 <span class="text-xs">NIN Number: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['nin']) ?></span></span><hr/>
                 <span class="text-xs">Home Address: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['home_address']) ?></span></span><hr/>
              <span class="text-xs">Gross Salary: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['gross_salary']) ?></span></span><hr/>
                <span class="text-xs">Net Salary: 
                <span class="text-dark ms-sm-2 font-weight-bold"><?= htmlspecialchars($lfect['net_salary']) ?></span></span><hr/>
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
                <label>Gross Salary</label>
                <input type="text" class="form-control" name="gross_salary" value="<?= htmlspecialchars($lfect['gross_salary']) ?>" required>
                <input type="hidden" name="admin" value="<?= $user_id ?>">
                <input type="hidden" name="staff" value="<?= $lfect['user_id'] ?>">
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-group">
                <label>Net Salary</label>
                <input type="text" class="form-control" name="net_salary" value="<?= htmlspecialchars($lfect['net_salary']) ?>" required>
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
                <label>Home Address</label>
                <input type="text" class="form-control" name="home_address" value="<?= htmlspecialchars($lfect['home_address']) ?>" required>
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