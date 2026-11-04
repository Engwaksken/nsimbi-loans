<?php
require "nav-head.php";
require "services_check.php";

if (!isset($_SESSION['logged_in'])) {
    exit("User not logged in.");
}

$client_id = $_SESSION['logged_in'];

$query = "SELECT * FROM loan WHERE client_id = ? AND status = 'OUTSTANDING' ORDER BY id DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $client_id);
$stmt->execute();
$result = $stmt->get_result();

$user_query = "SELECT customers.*, user.fname, user.lname 
               FROM customers 
               JOIN user ON customers.user_id = user.id 
               WHERE customers.user_id = ?";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bind_param("s", $client_id);
$user_stmt->execute();
$user_data = $user_stmt->get_result()->fetch_assoc();

$phead = "Outstanding Loans";
?>

<div class="container-fluid my-4">
    <div class="row">
        <div class="col-12">

            <!-- Page Header -->
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><?php echo $phead; ?></h6>
                    <button class="btn bg-gradient-success btn-sm" onclick="download_table_as_csv('report')">
                        <i class="fa fa-download me-1"></i> Export CSV
                    </button>
                </div>
        <div class="card-body px-0 pt-0 pb-2">
          <div class="table-responsive p-0">
            <table class="table align-items-center mb-0" id="report">
              <thead>
                <tr>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">#</th>
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
                $counter = 1;
                while ($loan = $result->fetch_assoc()):
                ?>
                  <tr>
                    <td>
                      <h6 class="mb-0 text-sm"><?php echo $counter++; ?></h6>
                    </td>
                    <td>
                      <p class="text-xs font-weight-bold mb-0"><?php echo number_format($loan['amount']); ?></p>
                    </td>
                    <td>
                      <p class="text-xs font-weight-bold mb-0"><?php echo number_format($loan['interest']); ?></p>
                    </td>
                    <td>
                      <p class="text-xs font-weight-bold mb-0"><?php echo number_format($loan['loan_balance']); ?></p>
                    </td>
                    <td class="align-middle text-center text-sm">
                      <?php
                        $status_class = match($loan['status']) {
                          'CLEARED' => 'success',
                          'OVERDUE' => 'danger',
                          default => 'secondary',
                        };
                      ?>
                      <span class="badge badge-sm bg-gradient-<?php echo $status_class; ?>">
                        <?php echo htmlspecialchars($loan['status']); ?>
                      </span>
                    </td>
                    <td class="align-middle text-center">
                      <span class="text-secondary text-xs font-weight-bold">
                        <?php echo htmlspecialchars($loan['datetime']); ?>
                      </span>
                    </td>
                    <td class="align-middle text-center">
                      <span class="text-secondary text-xs font-weight-bold">
                        <?php echo htmlspecialchars($loan['due_date']); ?>
                      </span>
                    </td>
                    <td class="align-middle text-center text-sm">
                      <a href="view_loan.php?id=<?php echo $loan['id']; ?>&user_id=<?php echo $loan['user_id']; ?>" class="badge badge-sm bg-info">

                        <i class="fa fa-eye"></i>
                      </a>
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
