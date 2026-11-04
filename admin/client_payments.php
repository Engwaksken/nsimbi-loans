<?php require "nav-head.php";
$table_name = "Loan_Payments";
$phead = "CLIENT LOAN PAYMENTS";
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
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Amount</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Loan ID</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Datetime</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $counter = 1;
                $successful_loans = [];
                $query = "
                  SELECT 
                    payments.*, 
                    loan.client_id AS client_id, 
                    loan.id AS loan_id,
                    user.fname, 
                    user.lname
                  FROM payments
                  JOIN loan ON payments.loan_id = loan.id
                  JOIN customers ON loan.client_id = customers.user_id
                  JOIN user ON customers.user_id = user.id
                  ORDER BY payments.id DESC
                ";

                $result = mysqli_query($conn, $query);

                while ($row = mysqli_fetch_assoc($result)) {
                  $loan_id = $row['loan_id'];
                  $status = $row['status'];
                  if ($status !== 'SUCCESSFUL' && in_array($loan_id, $successful_loans)) {
                    continue;
                  }

                  if ($status === 'SUCCESSFUL' && !in_array($loan_id, $successful_loans)) {
                    $successful_loans[] = $loan_id;
                  }

                ?>
                <tr>
                  <td><h6 class="mb-0 text-sm"><?php echo $counter++; ?></h6></td>
                  <td><h6 class="mb-0 text-sm"><?php echo $row['fname'] . " " . $row['lname']; ?></h6></td>
                  <td><p class="text-xs font-weight-bold mb-0"><?php echo number_format($row['amount']); ?></p></td>
                  <td><p class="text-xs font-weight-bold mb-0"><?php echo $loan_id; ?></p></td>
                  <td class="align-middle text-center text-sm">
                    <span class="badge badge-sm bg-gradient-<?php echo ($status == 'SUCCESSFUL') ? 'success' : 'secondary'; ?>">
                      <?php echo $status; ?>
                    </span>
                  </td>
                  <td class="align-middle text-center">
                    <span class="text-secondary text-xs font-weight-bold"><?php echo $row['datetime']; ?></span>
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

<?php require "footer.php"; ?>
