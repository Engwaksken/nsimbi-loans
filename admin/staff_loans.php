<?php 
require "nav-head.php";
require "services_check.php";

$table_name = "Loans";
$phead = "STAFF LOANS";

if (!isset($conn)) {
    die("Database connection not established.");
}

$lget = mysqli_query($conn, "
  SELECT loan.*, user.fname, user.lname
  FROM loan
  JOIN members ON loan.client_id = members.user_id
  JOIN user ON members.user_id = user.id
  WHERE loan.status != 'PENDING'
  ORDER BY loan.id ASC
");
?>
<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center pb-0">
          <h6 class="mb-0"><?php echo $phead; ?></h6>
          <button class="btn btn-sm bg-gradient-success" onclick="download_table_as_csv('report')">
            <i class="fas fa-file-csv me-1"></i> Export CSV
          </button>
        </div>
        <div class="card-body px-0 pt-0 pb-2">
          <div class="table-responsive p-0">
            <table class="table align-items-center mb-0" id="report">
              <thead>
                <tr>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">#</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Staff</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Amount</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Interest</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Balance</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Issue Date</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Due Date</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $i = 1;
                while ($lfect = mysqli_fetch_assoc($lget)):
                ?>
                <tr>
                  <td>
                    <h6 class="mb-0 text-sm"><?php echo $i++; ?></h6>
                  </td>

                  <td>
                    <h6 class="mb-0 text-sm"><?php echo $lfect['fname'] . " " . $lfect['lname']; ?></h6>
                  </td>

                  <td><p class="text-xs font-weight-bold mb-0"><?php echo number_format($lfect['amount']); ?></p></td>
                  <td><p class="text-xs font-weight-bold mb-0"><?php echo number_format($lfect['interest']); ?></p></td>
                  <td><p class="text-xs font-weight-bold mb-0"><?php echo number_format($lfect['loan_balance']); ?></p></td>

                  <td class="align-middle text-center text-sm">
                    <span class="badge badge-sm bg-gradient-<?php 
                      if ($lfect['status'] == 'CLEARED') echo 'success'; 
                      elseif ($lfect['status'] == 'OVERDUE') echo 'danger'; 
                      else echo 'secondary'; 
                    ?>">
                      <?php echo $lfect['status']; ?>
                    </span>
                  </td>

                  <td class="align-middle text-center">
                    <span class="text-secondary text-xs font-weight-bold"><?php echo $lfect['datetime']; ?></span>
                  </td>

                  <td class="align-middle text-center">
                    <span class="text-secondary text-xs font-weight-bold"><?php echo $lfect['due_date']; ?></span>
                  </td>

                  <td class="text-center">
    <a href="view_loan.php?id=<?php echo urlencode($lfect['id']); ?>" class="badge badge-sm bg-info">View</a>
    
   
  <a href="staff_loan_topup.php?id=<?= urlencode($lfect['client_id']); ?>&loan=<?= urlencode($lfect['id']); ?>" 
   class="badge badge-sm bg-warning">
    TopUp
</a>



    <?php if (strtoupper($lfect['status']) !== 'CLEARED'): ?>
        <a href="repayment_plan.php?id=<?php echo urlencode($lfect['id']); ?>" class="badge badge-sm bg-success">Plan</a>
    <?php else: ?>
        <span class="badge badge-sm bg-secondary" title="Loan already cleared">Plan</span>
    <?php endif; ?>
</td>

                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<?php require "footer.php"; ?>
