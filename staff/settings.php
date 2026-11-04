<?php 
require "nav-head.php";

if (!isset($_SESSION['logged_in'])) {
    die("Access denied.");
}

$user_id = mysqli_real_escape_string($conn, $_SESSION['logged_in']);

$stmt = $conn->prepare("SELECT * FROM user WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$lget = $stmt->get_result();
$lfect = $lget->fetch_assoc();
$stmt->close(); 

?>

<div class="row justify-content-center mt-4">
    <div class="col-lg-6 col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-gradient-primary text-white">
                <h6 class="mb-0">Update Password</h6>
            </div>
            <?php

if (isset($_SESSION['message'])):
    $message = $_SESSION['message'];
    $type = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['message'], $_SESSION['message_type']);
?>
    <div id="session-alert" class="alert <?= $type === 'success' ? 'alert-success' : 'alert-danger'; ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <script>
        setTimeout(() => {
            const alertBox = document.getElementById('session-alert');
            if (alertBox) alertBox.style.display = 'none';
        }, 3000);
    </script>
<?php endif; ?>

            <div class="card-body px-4 py-4">
                <form method="post" action="user_settings.php">
 <input type="hidden" name="user_id" value="<?= $user_id ?>">

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="text" class="form-control" id="password" name="password" placeholder="New Password" required>
                       
                    </div>
<div class="mb-3">
                        <label for="con_password" class="form-label">Confirm Password</label>
                        <input type="text" class="form-control" id="con_password" name="con_password" placeholder="Confirm Password" required>
                       
                    </div>
                   
                    <div class="d-grid mt-4">
                        <button type="submit" name="submit" class="btn btn-primary">Save Changes</button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
<br>
<br>
<?php require "footer.php"; ?>