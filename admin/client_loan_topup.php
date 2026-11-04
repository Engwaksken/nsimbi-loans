<?php
require "nav-head.php";

// GET client_id and loan_id from URL
$clientId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$loanId   = isset($_GET['loan']) ? intval($_GET['loan']) : 0;

if ($clientId <= 0 || $loanId <= 0) {
    echo "<div class='alert alert-danger'>Invalid client or Loan ID provided.</div>";
    exit;
}

// Fetch client details
$client = null;
$loan = null;

$clientQuery = "
    SELECT c.*, u.fname, u.lname
    FROM customers c
    LEFT JOIN user u ON c.user_id = u.id
    WHERE c.user_id = ?
";

if ($stmt = $conn->prepare($clientQuery)) {
    $stmt->bind_param("i", $clientId);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Fetch the specific outstanding loan
$loanQuery = "
    SELECT *
    FROM loan
    WHERE id = ? AND client_id = ? AND status = 'OUTSTANDING'
    LIMIT 1
";

if ($stmt2 = $conn->prepare($loanQuery)) {
    $stmt2->bind_param("ii", $loanId, $clientId);
    $stmt2->execute();
    $loan = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
}
?>

<div class="row">

    <!-- client Information -->
    <div class="col-md-7 mt-4">
        <div class="card">
            <div class="card-header pb-0 px-3">
                <h6 class="mb-0">client Information</h6>
            </div>

            <div class="card-body pt-4 p-3">
                <ul class="list-group">
                    <li class="list-group-item border-0 d-flex p-4 mb-2 bg-gray-100 border-radius-lg">

                        <?php if ($client): ?>
                            <div class="d-flex flex-column w-100">
                                <span class="mb-2"><strong>First Name:</strong> <?= htmlspecialchars($client['fname']) ?></span><hr>
                                <span class="mb-2"><strong>Last Name:</strong> <?= htmlspecialchars($client['lname']) ?></span><hr>
                                <span class="mb-2"><strong>Phone:</strong> <?= htmlspecialchars($client['phone']) ?></span><hr>
                                <span class="mb-2"><strong>Loan Limit:</strong> <?= number_format($client['loan_limit']) ?></span><hr>
                                <span class="mb-2"><strong>Account Created:</strong> <?= htmlspecialchars($client['created_at']) ?></span><hr>

                                <?php if ($loan): ?>
                                    <h6 class="text-primary mt-3">Current Loan Details (Top-up Eligible)</h6>
                                    <span class="mb-2"><strong>Current Amount:</strong> <?= number_format($loan['amount']) ?></span>
                                    <span class="mb-2"><strong>Interest:</strong> <?= number_format($loan['interest']) ?></span>
                                    <span class="mb-2"><strong>Balance:</strong> <?= number_format($loan['loan_balance']) ?></span>
                                    <span class="mb-2"><strong>Loan Period:</strong> <?= $loan['loan_period'] ?> months</span>
                                    <span class="mb-2"><strong>Due Date:</strong> <?= htmlspecialchars($loan['due_date']) ?></span>
                                    <hr>
                                <?php else: ?>
                                    <span class="text-warning">This client has no outstanding loan.</span><hr>
                                <?php endif; ?>

                            </div>
                        <?php else: ?>
                            <span class="text-danger">client information not found.</span>
                        <?php endif; ?>

                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Loan Top-up Form -->
    <div class="col-md-5 mt-4">
        <div class="card h-100 mb-4">
            <div class="card-header pb-0 px-3">
                <h6 class="mb-0">Loan Top-Up</h6>
            </div>

            <div class="card-body">

                <?php if (!$loan): ?>
                    <div class="alert alert-info">
                        This client has no outstanding loan to top up.
                    </div>
                <?php else: ?>

                <form method="post" action="process_client_topup.php">

                    <input type="hidden" name="user_id" value="<?= $clientId ?>">
                    <input type="hidden" name="loan_id" value="<?= $loanId ?>">

                    <div class="row">
                        <div class="col-md-6">
                            <label>Top-up Amount</label>
                            <input type="number" class="form-control" name="amount" placeholder="1000..." required>
                        </div>

                        <div class="col-md-6">
                            <label>Additional Loan Period</label>
                            <select class="form-control" name="loan_period" required>
                                <option value="1">1 Month</option>
                                <option value="2">2 Months</option>
                                <option value="3">3 Months</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label>New Interest Rate</label>
                            <select class="form-control" name="interest" required>
                                <option value="10">10%</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label>Top-up Date</label>
                            <input type="datetime-local" class="form-control" name="loan_date" required>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button class="btn bg-gradient-primary w-100" type="submit">Apply Top-Up</button>
                    </div>

                </form>

                <?php endif; ?>

            </div>
        </div>
    </div>

</div>

<?php require "footer.php"; ?>
