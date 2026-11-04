<?php
require "nav-head.php";
require "db.php";

$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg   = $_SESSION['error_msg'] ?? '';

unset($_SESSION['success_msg'], $_SESSION['error_msg']);

$expense_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($expense_id <= 0) {
    echo "Invalid expense ID.";
    exit;
}

$stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ?");
$stmt->bind_param("i", $expense_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "Expenditure not found.";
    exit;
}

$expense = $result->fetch_assoc();
$stmt->close();

$cat_result = $conn->query("SELECT name FROM expense_categories ORDER BY name ASC");
?>


<div class="container py-5">
    <div class="card mx-auto" style="max-width: 600px;">
        <div class="card-header bg-warning text-white">
            <h4 class="mb-0">Edit Expense</h4>
        </div>
        <div class="card-body">
<?php if ($success_msg): ?>
    <div id="flash-message" class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($success_msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error_msg): ?>
    <div id="flash-message" class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($error_msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
            <form method="POST" action="update_expense.php">
                <input type="hidden" name="id" value="<?= $expense['id'] ?>">

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Category *</label>
                        <select name="category_name" class="form-control">
                            <option value="">Select Category</option>
                            <?php while ($cat = $cat_result->fetch_assoc()): 
                                $selected = ($expense['category'] === $cat['name']) ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($cat['name']) ?>" <?= $selected ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Amount *</label>
                        <input type="number" step="0.01" name="amount" class="form-control"
                               value="<?= htmlspecialchars($expense['amount']) ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="">Select Payment Method</option>
                            <?php
                            $methods = ['Cash', 'Mobile Money', 'Bank Transfer'];
                            foreach ($methods as $method):
                                $selected = ($expense['payment_method'] === $method) ? 'selected' : '';
                            ?>
                                <option value="<?= $method ?>" <?= $selected ?>><?= $method ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Paid To</label>
                        <input type="text" name="paid_to" class="form-control"
                               value="<?= htmlspecialchars($expense['paid_to']) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Date of Expenditure *</label>
                    <input type="date" name="expense_date" class="form-control" 
                           value="<?= htmlspecialchars($expense['expense_date']) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($expense['description']) ?></textarea>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-success">Update Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const flash = document.getElementById('flash-message');
    if (flash) {
      
        setTimeout(() => {
         
            flash.classList.remove('show');
            flash.classList.add('hide');
         
            setTimeout(() => flash.remove(), 500);
        }, 5000);
    }
});
</script>