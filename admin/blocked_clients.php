<?php 
require "nav-head.php";

$table_name = "Clients";
$phead = "BLOCKED CLIENTS";

if (isset($_REQUEST['check']) && $_REQUEST['check'] == 2) {
    $lget = mysqli_query($conn, "SELECT * FROM customers WHERE active = 0 ORDER BY id DESC");
    $phead = "BLOCKED CLIENTS";
}
?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">

       <!-- Card Header -->
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
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">#ID</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">First Name</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Last Name</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Phone</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Loan Limit</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Action</th>
                </tr>
              </thead>
              <tbody>

                <?php if (isset($lget)) : ?>
                  <?php while ($client = mysqli_fetch_assoc($lget)) : ?>
                    <tr>
                      <td>
                        <h6 class="mb-0 text-sm"><?php echo $client['id']; ?></h6>
                      </td>
                      <td>
                        <h6 class="mb-0 text-sm"><?php echo $client['fname']; ?></h6>
                      </td>
                      <td>
                        <p class="text-xs font-weight-bold mb-0"><?php echo $client['lname']; ?></p>
                      </td>
                      <td class="align-middle text-center text-sm">
                        <span class="mb-0 text-sm"><?php echo $client['phone']; ?></span>
                      </td>
                      <td class="align-middle text-center text-sm">
                        <span class="mb-0 text-sm"><?php echo number_format($client['loan_limit']); ?></span>
                      </td>
                      <td class="align-middle text-center">
                        <?php 
                          $isActive = $client['active'] == 1;
                          $badgeClass = $isActive ? 'success' : 'secondary';
                          $statusText = $isActive ? 'Active' : 'Blocked';
                        ?>
                        <span class="badge badge-sm bg-gradient-<?php echo $badgeClass; ?>"><?php echo $statusText; ?></span>
                      </td>
                      <td class="align-middle text-center text-sm">
                        <a href="view_client.php?client=<?php echo $client['id']; ?>" class="badge badge-sm bg-gradient-info">
                          View
                        </a>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else : ?>
                  <tr>
                    <td colspan="7" class="text-center">No clients found.</td>
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
