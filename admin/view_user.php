<?php
require "nav-head.php";
session_start();

$userId = isset($_GET['user']) ? (int)$_GET['user'] : 0;

if ($userId <= 0) {
    echo "<div class='alert alert-danger'>Invalid user.</div>";
    exit;
}

$stmt = $conn->prepare("SELECT id, fname, lname, email, role, status FROM user WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "<div class='alert alert-danger'>User not found.</div>";
    exit;
}
?>

<div class="row">
  <!-- User Information Card -->
  <div class="col-md-7 mt-4">
    <div class="card">
      <div class="card-header pb-0 px-3">
        <h6 class="mb-0">User Information</h6>
      </div>
      <div class="card-body pt-4 p-3">
        <ul class="list-group">
          <li class="list-group-item border-0 d-flex p-4 mb-2 bg-gray-100 border-radius-lg">
            <div class="d-flex flex-column">
              <span class="mb-2 text-xs">
                Name:
                <span class="text-dark font-weight-bold ms-sm-2">
                  <?= htmlspecialchars($user['fname'].' '.$user['lname']) ?>
                </span>
              </span><hr>

              <span class="mb-2 text-xs">
                User Role:
                <span class="text-dark font-weight-bold ms-sm-2">
                  <?= ($user['role'] == 1 ? "Admin" : ($user['role'] == 2 ? "Loan Officer" : "Unknown Role")) ?>
                </span>
              </span><hr>

              <span class="mb-2 text-xs">
                Email:
                <span class="text-dark font-weight-bold ms-sm-2">
                  <?= htmlspecialchars($user['email']) ?>
                </span>
              </span><hr>

              <span class="mb-2 text-xs">
                Status:
                <span class="text-dark font-weight-bold ms-sm-2">
                  <?= ((int)$user['status'] === 1) ? 'Active' : 'Blocked' ?>
                </span>
              </span><hr>
            </div>

            <div class="ms-auto text-end">
              <a href="status_user.php?user=<?= (int)$user['id'] ?>&admin=<?= (int)$userId ?>"
                 class="btn bg-gradient-<?= ((int)$user['status'] === 1) ? 'danger' : 'success' ?>">
                <i class="fas fa-pencil-alt text-dark me-2" aria-hidden="true"></i>
                <?= ((int)$user['status'] === 1) ? 'Block' : 'Activate' ?>
              </a>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <!-- Update User Details -->
  <div class="col-md-4 mt-4">
    <div class="card h-100">
      <div class="card-header pb-0 px-3">
        <h6 class="mb-0">Update User Details</h6>
      </div>

      <?php if (isset($_GET['msg'])): ?>
        <div id="message" class="alert <?= ($_GET['type'] ?? '') === 'success' ? 'alert-success' : 'alert-danger' ?>">
          <?= htmlspecialchars($_GET['msg']) ?>
        </div>
      <?php endif; ?>

      <div class="card-body pt-4 p-3">
        <form method="post" action="update_user.php" autocomplete="off">
          <div class="form-group mb-3">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= htmlspecialchars($user['email']) ?>" placeholder="User Email">
          </div>

          <div class="form-group mb-3">
            <label class="form-label">Role</label>
            <select class="form-control" id="role" name="role">
              <option value="1" <?= ((int)$user['role'] === 1) ? 'selected' : '' ?>>Admin</option>
              <option value="2" <?= ((int)$user['role'] === 2) ? 'selected' : '' ?>>Loan Officer</option>
            </select>
          </div>

          <div class="form-group mb-3">
            <label class="form-label">New Password (optional)</label>
            <input type="password" class="form-control" id="pass" name="pass" placeholder="Leave blank to keep current">
          </div>

          <input type="hidden" name="user" value="<?= (int)$user['id'] ?>">

          <div class="form-group mb-3">
            <button type="submit" name="update" class="btn bg-gradient-info w-100">
              Update User Details
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<p class="mt-6"></p>
<?php require "footer.php"; ?>

<script>
window.onload = function() {
  const msg = document.getElementById("message");
  if (msg) {
    setTimeout(() => {
      msg.style.transition = "opacity 1s ease";
      msg.style.opacity = "0";
      setTimeout(() => msg.remove(), 1000);
    }, 5000);
  }
};
</script>
