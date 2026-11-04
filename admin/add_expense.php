<?php
require "nav-head.php";

if (!isset($conn)) {
    die("Database connection failed.");
}

$cat_result = $conn->query("SELECT id, name FROM expense_categories ORDER BY name ASC");
?>

<div class="container py-5">
    <div class="card mx-auto" style="max-width: 600px;">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0">Add Expense</h4>
        </div>
        <div class="card-body">
            <form method="POST" action="added_expense.php">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Select Expense Category *</label>
<select name="category_name" class="form-control" required>
    <option value="">Select Expense Category</option>
    <?php while ($cat = $cat_result->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($cat['name']) ?>">
            <?= htmlspecialchars($cat['name']) ?>
        </option>
    <?php endwhile; ?>
</select>

                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Amount *</label>
                        <input type="number" step="0.01" name="amount" class="form-control" placeholder="e.g. 10000" required>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="payment_method" class="form-label">Payment Method</label>
                        <select name="payment_method" id="payment_method" class="form-control">
                            <option value="">Select Payment Method</option>
                            <option value="Cash">Cash</option>
                            <option value="Mobile Money">Mobile Money</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Paid To</label>
                        <input type="text" name="paid_to" class="form-control" placeholder="e.g Paid to John">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Date of Expense *</label>
                    <input type="date" name="expense_date" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Description Expense e.g Transport to Kampala Town"></textarea>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-success">Add Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require "footer.php"; ?>