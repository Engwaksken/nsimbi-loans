<?php
require "nav-head.php";
require "db.php";

$sql = "
    SELECT u.id AS user_id, u.fname, u.lname, c.phone
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    LEFT JOIN loan l ON l.client_id = u.id  AND l.status = 'CLEARED'
    WHERE u.status = 1 AND l.id IS NULL
    ORDER BY u.fname ASC
";

$result = mysqli_query($conn, $sql);
if (!$result) {
    die("Database error: " . mysqli_error($conn));
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<div class="p-4 bg-secondary">
  <form method="post" action="adding_loan.php">
    <div class="row">
      <div class="col-md-6">
        <div class="form-group">
          <label for="user_id">Select Client</label>
          <select class="form-control select2" name="user_id" id="user_id" style="width: 100%;" required>
            <option value=""> Select Client </option>
            <?php while ($row = mysqli_fetch_assoc($result)): ?>
              <option value="<?= $row['user_id']; ?>">
                <?= htmlspecialchars($row['fname'] . ' ' . $row['lname'] . ' - ' . $row['phone']); ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>
      </div>
      <div class="col-md-4 align-self-end">
        <div class="form-group">
          <input type="submit" name="add" class="btn bg-gradient-info" value="Proceed">
        </div>
      </div>
    </div>
  </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize Select2 (enhances dropdown, but no AJAX needed)
    $('#user_id').select2({
        placeholder: 'Select a client...',
        allowClear: true
    });
});
</script>

<?php require "footer.php"; ?>
