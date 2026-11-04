<?php
require "nav-head.php";
require "services_check.php";

$table_name = "Loans";
$phead = "Due Clients Loans";

$sql = "SELECT * FROM loan 
        WHERE status = 'OUTSTANDING' 
          AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
        ORDER BY due_date ASC, id DESC";

$lget = mysqli_query($conn, $sql);

if (!$lget) {
    die("Query failed: " . mysqli_error($conn));
}
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
                  <th>#</th>
                  <th>Client</th>
                  <th>Amount</th>
                  <th>Interest</th>
                  <th>Balance</th>
                  <th class="text-center">Status</th>
                  <th class="text-center">Issue Date</th>
                  <th class="text-center">Due Date</th>
                  <th class="text-center">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $i = 1;
                while ($lfect = mysqli_fetch_assoc($lget)) {
                    
                    $client_id = (int)$lfect['client_id'];
                    $sql1 = "SELECT u.fname, u.lname 
                             FROM customers c 
                             INNER JOIN user u ON c.user_id = u.id 
                             WHERE c.user_id = $client_id LIMIT 1";
                    $result1 = mysqli_query($conn, $sql1);

                    if ($result1 && mysqli_num_rows($result1) > 0) {
                        $cfect = mysqli_fetch_assoc($result1);
                    } else {
                        $sql2 = "SELECT u.fname, u.lname 
                                 FROM members m 
                                 INNER JOIN user u ON m.user_id = u.id 
                                 WHERE m.user_id = $client_id LIMIT 1";
                        $result2 = mysqli_query($conn, $sql2);
                        $cfect = ($result2 && mysqli_num_rows($result2) > 0)
                            ? mysqli_fetch_assoc($result2)
                            : [];
                    }

                    $full_name = (!empty($cfect['fname']) && !empty($cfect['lname']))
                        ? $cfect['fname'] . ' ' . $cfect['lname']
                        : 'N/A';
                    ?>
                    <tr>
                      <td><h6 class="mb-0 text-sm"><?php echo $i++; ?></h6></td>
                      <td><h6 class="mb-0 text-sm"><?php echo htmlspecialchars($full_name); ?></h6></td>
                      <td><p class="text-xs font-weight-bold mb-0"><?php echo number_format($lfect['amount'], 2); ?></p></td>
                      <td><p class="text-xs font-weight-bold mb-0"><?php echo number_format($lfect['interest'], 2); ?></p></td>
                      <td><p class="text-xs font-weight-bold mb-0"><?php echo number_format($lfect['loan_balance'], 2); ?></p></td>

                      <td class="align-middle text-center text-sm">
                        <span class="badge badge-sm bg-gradient-<?php 
                          echo ($lfect['status'] === 'CLEARED') ? 'success' : (($lfect['status'] === 'OVERDUE') ? 'danger' : 'secondary');
                        ?>">
                          <?php echo htmlspecialchars($lfect['status']); ?>
                        </span>
                      </td>

                      <td class="align-middle text-center">
                        <span class="text-secondary text-xs font-weight-bold"><?php echo htmlspecialchars($lfect['datetime']); ?></span>
                      </td>

                      <td class="align-middle text-center">
                        <span class="text-secondary text-xs font-weight-bold"><?php echo htmlspecialchars($lfect['due_date']); ?></span>
                      </td>

                      <td class="text-center">
                        <a href="view_loan.php?id=<?php echo urlencode($lfect['id']); ?>" class="badge badge-sm bg-info">View</a>
                         <a href="repayment_plan.php?id=<?php echo urlencode($lfect['id']); ?>" class="badge badge-sm bg-warning">Plan</a>
                      </td>
                    </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require "footer.php"; ?>
