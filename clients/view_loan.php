<?php 
require "nav-head.php";

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if (!$id || !$user_id) {
    echo "<p class='text-danger'>Missing loan or user ID.</p>";
    require "footer.php";
    exit;
}

// Fetch loan data
$lget = mysqli_query($conn, "SELECT * FROM loan WHERE id = '$id'");
$lfect = mysqli_fetch_assoc($lget);

// Fetch client data
$client_get = mysqli_query($conn, "SELECT * FROM customers WHERE user_id = '$user_id'");
$cfect = mysqli_fetch_assoc($client_get);
?>

<div class="row">
    <!-- Loan Information -->
    <div class="col-md-7 mt-4">
        <div class="card">
            <div class="card-header pb-0 px-3">
                <h6 class="mb-0">Loan Information</h6>
            </div>
            <div class="card-body pt-4 p-3">
                <ul class="list-group">
                    <li class="list-group-item border-0 d-flex p-4 mb-2 bg-gray-100 border-radius-lg">
                        <div class="d-flex flex-column">
                            <span class="mb-2 text-xs">Phone Number: <span class="text-dark ms-sm-2 font-weight-bold"><?php echo htmlspecialchars($cfect['phone']); ?></span></span><hr/>
                            <span class="text-xs">ID Number: <span class="text-dark ms-sm-2 font-weight-bold"><?php echo htmlspecialchars($cfect['nin']); ?></span></span><hr/>
                            <span class="text-xs">Loan Amount: <span class="text-dark ms-sm-2 font-weight-bold"><?php echo number_format($lfect['amount']); ?></span></span><hr/>
                            <span class="text-xs">Loan Balance: <span class="text-dark ms-sm-2 font-weight-bold"><?php echo number_format($lfect['loan_balance']); ?></span></span><hr/>
                            <span class="text-xs">Issue Date: <span class="text-dark ms-sm-2 font-weight-bold"><?php echo htmlspecialchars($lfect['datetime']); ?></span></span><hr/>
                            <span class="text-xs">Due Date: <span class="text-dark ms-sm-2 font-weight-bold"><?php echo htmlspecialchars($lfect['due_date']); ?></span></span><hr/>
                        </div>
                        <div class="ms-auto text-end">
                            <a class="btn btn-link text-info text-gradient px-3 mb-0" href="javascript:;"><i class="far fa-trash-alt me-2"></i>Status</a>
                            <a class="btn btn-link text-dark px-3 mb-0" href="javascript:;"><i class="fas fa-pencil-alt text-dark me-2" aria-hidden="true"></i><?php echo htmlspecialchars($lfect['status']); ?></a>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Add Payment Form -->
    <div class="col-md-5 mt-4">
        <div class="card h-100 mb-4">
            <div class="card-header pb-0 px-3">
                <h6 class="mb-0">Add Payment</h6>
            </div>
            <div class="card-body p-3">
                <?php if ($lfect['status'] === "CLEARED"): ?>
                    <h5 class="text-success">Loan Cleared</h5>
                    <p>Payment options not available</p>
                <?php else: ?>
                    <form method="post" action="added_payment.php">
                        <div class="mb-3">
                            <label for="amount" class="form-label">Enter Amount</label>
                            <input type="text" class="form-control" id="amount" name="amount" placeholder="10000" required>
                            <input type="hidden" name="loan" value="<?php echo htmlspecialchars($lfect['id']); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="rec" class="form-label">Transaction Number</label>
                            <input type="text" class="form-control" id="rec" name="rec" placeholder="12345" required>
                            <input type="hidden" name="client" value="<?php echo htmlspecialchars($cfect['user_id']); ?>">
                        </div>

                        <div class="mb-3">
                            <input type="submit" name="add" class="btn bg-gradient-info" value="Add Payment">
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require "footer.php"; ?>
