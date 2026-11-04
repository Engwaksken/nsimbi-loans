<?php 
require "nav-head.php"; 

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
if ($role !== 1) {
    echo '<script>
            alert("Not Authorized for this Action");
            window.location.replace("add_user.php");
          </script>';
    exit;
}

$logged_in = isset($_SESSION['logged_in']) ? $_SESSION['logged_in'] : 'unknown_user'; 
?>

<div class="container py-5">
    <div class="card mx-auto" style="max-width: 600px;">
        <div class="card-header bg-primary text-white">
            <h4><i class="fas fa-user-plus"></i> Add New Admin</h4>
        </div>
        <?php

if (!empty($_SESSION['message'])) {
    $msg = $_SESSION['message'];
    echo '<div class="container mt-3">
            <div class="alert alert-' . htmlspecialchars($msg['type']) . ' alert-dismissible fade show" role="alert">
                ' . htmlspecialchars($msg['text']) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          </div>';
    unset($_SESSION['message']);
}
?>

        <div class="card-body">
            <form method="post" action="added_user.php">
            
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="fname">First Name</label>
                            <input type="text" class="form-control" id="fname" name="fname" placeholder="User First Name" required>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="lname">Last Name</label>
                            <input type="text" class="form-control" id="lname" name="lname" placeholder="User Last Name" required>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="admin" value="<?php echo htmlspecialchars($logged_in); ?>">
                
               <div class="row">
               <div class="col-md-12">
              <div class="form-group">
            <label for="role">Select User Role</label>
            <select class="form-control" id="role" name="role" required>
                <option value="" disabled selected>Select User Role</option>
                <option value="1">Admin</option>
                <option value="2">Loan Officer</option>
            </select>
              </div>
                </div>
                  </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="User Email"  required>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" class="form-control" id="password" name="password" placeholder="User Password" required>
                        </div>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <button type="submit" name="add" class="btn bg-gradient-primary">
                                <i class="fas fa-user-plus"></i> Add New User </button>
                        </div>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>
<p class="mt-6"></p>

<?php require "footer.php"; ?>