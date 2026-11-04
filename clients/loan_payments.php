<?php
require "nav-head.php";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    exit("Database connection not established.");
}

if (!isset($_SESSION['logged_in'])) {
    exit("User not logged in.");
}

$client_id = mysqli_real_escape_string($conn, $_SESSION['logged_in']);
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h6>LOAN PAYMENTS</h6>
                    <button class="btn bg-gradient-success btn-sm" onclick="download_table_as_csv('report')">
                        <i class="fa fa-download me-1"></i> Export CSV
                    </button>
                </div>

                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0" id="report">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Paid Amount</th>
                                    <th>Balance</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Datetime</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $result = mysqli_query($conn, "SELECT * FROM payments WHERE user_id='$client_id' ORDER BY id ASC");
                                
                                if (mysqli_num_rows($result) > 0):
                                    $counter = 1;
                                    while ($payment = mysqli_fetch_assoc($result)):
                                        $loan_id = $payment['loan_id'];
                                        $loan_result = mysqli_query($conn, "SELECT * FROM loan WHERE id='$loan_id'");
                                        $loan = mysqli_fetch_assoc($loan_result);

                                        $loan_status = $loan['status'] ?? 'UNKNOWN';
                                        $status_badge = match ($loan_status) {
                                            'CLEARED' => 'success',
                                            'OVERDUE' => 'danger',
                                            'OUTSTANDING' => 'warning',
                                            default => 'secondary',
                                        };
                                ?>
                                <tr>
                                    <td><h6 class="mb-0 text-sm"><?= $counter++; ?></h6></td>
                                    <td><p class="text-xs font-weight-bold mb-0"><?= number_format($payment['amount']); ?></p></td>
                                    <td><p class="text-xs font-weight-bold mb-0"><?= number_format($loan['loan_balance'] ?? 0); ?></p></td>
                                    <td class="align-middle text-center text-sm">
                                        <span class="badge badge-sm bg-gradient-<?= $status_badge ?>">
                                            <?= htmlspecialchars($loan_status); ?>
                                        </span>
                                    </td>
                                    <td class="align-middle text-center">
                                        <span class="text-secondary text-xs font-weight-bold"><?= htmlspecialchars($payment['datetime']); ?></span>
                                    </td>
                                    <td class="align-middle text-center text-sm">
                                        <a href="view_loan.php?id=<?= $loan_id ?>&user_id=<?= urlencode($client_id); ?>" class="badge badge-sm bg-info">
                                            <i class="fa fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No payment record found.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require "footer.php"; ?>
