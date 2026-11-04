<?php 
require "nav-head.php";
$current_user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 0;

$lget = mysqli_query($conn, 'SELECT * FROM loan WHERE status="PENDING" ORDER BY id DESC');

$phead = "STAFF PENDING LOANS";              
?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
    
        <div class="card-header d-flex justify-content-between align-items-center pb-0">
          <h6 class="mb-0"><?php echo htmlspecialchars($phead); ?></h6>
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
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Employer</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Department</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Amount</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Loan Period (Months)</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Request Date</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $counter =1;
                while ($lfect = mysqli_fetch_assoc($lget)) {
                    $client_id = mysqli_real_escape_string($conn, $lfect['client_id']);

                    $client_get = mysqli_query($conn, "
                        SELECT members.*, user.fname AS fname, user.lname AS lname FROM members 
                        JOIN user ON members.user_id = user.id WHERE members.user_id = '$client_id'");

                    $cfect = mysqli_fetch_assoc($client_get);
                    $full_name = isset($cfect['fname'], $cfect['lname']) 
                        ? $cfect['fname'] . ' ' . $cfect['lname'] 
                        : 'N/A';
                ?>
                <tr>
                  <td>
                    <div class="d-flex px-2 py-1">
                      <div class="d-flex flex-column justify-content-center">
                        <h6 class="mb-0 text-sm"><?php echo $counter++; ?></h6>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div class="d-flex px-2 py-1">
                      <div class="d-flex flex-column justify-content-center">
                        <h6 class="mb-0 text-sm"><?php echo htmlspecialchars($full_name); ?></h6>
                      </div>
                    </div>
                  </td>
<td>
                    <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($cfect['employer']); ?></p>
                  </td>
                  <td>
                    <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($cfect['department']); ?></p>
                  </td>
                  <td>
                    <p class="text-xs font-weight-bold mb-0"><?php echo number_format($lfect['amount']); ?></p>
                  </td>

                  <td>
                    <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($lfect['loan_period']); ?></p>
                  </td>

                  <td class="align-middle text-center">
                    <span class="text-secondary text-xs font-weight-bold"><?php echo htmlspecialchars($lfect['datetime']); ?></span>
                  </td>
 <?php if ($current_user_role == 1): ?>
                 <td class="align-middle text-center">
  <button class="badge badge-sm bg-info border-0" title="Approve" onclick="openApproveModal('<?php echo $lfect['id']; ?>')"><i class="fa fa-edit"></i></button>
  <button class="badge badge-sm bg-danger border-0" title="Reject" onclick="openRejectModal('<?php echo $lfect['id']; ?>')"> <i class="fa fa-times"></i>
  </button></td>
 <?php endif; ?>
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
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" action="loan_approved.php">
      <input type="hidden" name="loan_id" id="approveLoanId">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="approveModalLabel">Confirm Approval</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body text-success">
          Are you sure you want to approve this loan request?
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-info">Approve</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="GET" action="loan_rejected.php">
      <input type="hidden" name="id" id="rejectLoanId">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="rejectModalLabel">Confirm Rejection</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body text-danger">
          Are you sure you want to reject this loan request?
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Reject</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
  function openApproveModal(loanId) {
    document.getElementById('approveLoanId').value = loanId;
    var approveModal = new bootstrap.Modal(document.getElementById('approveModal'));
    approveModal.show();
  }

  function openRejectModal(loanId) {
    document.getElementById('rejectLoanId').value = loanId;
    var rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
    rejectModal.show();
  }
</script>

<?php require "footer.php"; ?>
