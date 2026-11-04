<?php require "nav-head.php"; ?>

<?php
$table_name = "Clients";
$phead = "ACTIVE CLIENTS";

if (!isset($conn)) {
    die("Database connection not found.");
}

// Corrected query with proper INNER JOIN and WHERE condition
$lget = mysqli_query($conn, "
    SELECT user.id AS user_id, user.fname, user.lname, user.email, user.status, customers.*
    FROM customers
    INNER JOIN user ON customers.user_id = user.id
    WHERE customers.active = 1
    ORDER BY customers.id DESC
");

if (!$lget) {
    die("Database query failed: " . mysqli_error($conn));
}
?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">

        <div class="card-header d-flex justify-content-between align-items-center pb-0">
          <h6 class="mb-0"><?= htmlspecialchars($phead) ?></h6>
          <button class="btn btn-sm bg-gradient-success" onclick="download_table_as_csv('report')">
            <i class="fas fa-file-csv me-1"></i> Export CSV
          </button>
        </div>

        <div class="card-body px-0 pt-0 pb-2">
          <div class="table-responsive p-0">
            <table class="table align-items-center mb-0" id="report">
              <thead>
                <tr>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">#</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Phone</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Business Name</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Business Type</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Business Period</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Bank Name</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Bank Acc</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Location</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">District</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Loan Limit</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">NOK Name</th>
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">NOK Contact</th>
          
                  <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php $counter = 1; ?>
                <?php while ($client = mysqli_fetch_assoc($lget)) : ?>
                  <tr>
                    <td class="align-middle text-center text-sm">
                      <span class="mb-0 text-sm"><?= $counter++; ?></span>
                    </td>
                    <td class="align-middle text-sm">
                      <?= htmlspecialchars($client['fname'] . ' ' . $client['lname']) ?>
                    </td>
                    <td class="align-middle text-center text-sm">
                      <?= htmlspecialchars($client['phone']) ?>
                    </td>
                    <td class="align-middle text-left text-sm">
                      <?= htmlspecialchars($client['business_name']) ?>
                    </td>
                    <td class="align-middle text-left text-sm">
                      <?= htmlspecialchars($client['business_type']) ?>
                    </td>
                    <td class="align-middle text-center text-sm">
                      <?= htmlspecialchars($client['business_period']) ?>
                    </td>
                    <td class="align-middle text-left text-sm">
                      <?= htmlspecialchars($client['bank_name']) ?>
                    </td>
                    <td class="align-middle text-left text-sm">
                      <?= htmlspecialchars($client['bank_account']) ?>
                    </td>
                    <td class="align-middle text-left text-sm">
                      <?= htmlspecialchars($client['location']) ?>
                    </td>
                    <td class="align-middle text-center text-sm">
                      <?= htmlspecialchars($client['district']) ?>
                    </td>
                    <td class="align-middle text-center text-sm">
                      <?= number_format((float)$client['loan_limit']) ?>
                    </td>
                    <td class="align-middle text-left text-sm">
                      <?= htmlspecialchars($client['nok_name']) ?>
                    </td>
                    <td class="align-middle text-center text-sm">
                      <?= htmlspecialchars($client['nok_tel']) ?>
                    </td>
                    
                    <td class="align-middle text-center text-sm">
                      <a href="view_client.php?user_id=<?= urlencode($client['user_id']) ?>" class="badge badge-sm bg-info" title="View Client">
                        <i class="fa fa-eye"></i>
                      </a>
                      <a href="delete_client.php?user_id=<?= urlencode($client['user_id']) ?>" class="badge badge-sm bg-gradient-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this client?');">
                        <i class="fas fa-trash"></i>
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
