
<?php
require "nav-head.php";?>
<!-- HTML form -->
<div class="container py-5">
  
    <div class="card mx-auto" style="max-width: 600px;">
        <div class="card-header bg-primary text-white">
            <h4>Add Expense Category</h4>
        </div>
        <div class="card-body">
            <form method="POST" action="added_category.php">
                <div class="mb-3">
                    <label class="form-label">Expense Category Name *</label>
                    <input type="text" name="name" class="form-control" placeholder="Expense Category e.g. Transport" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control"  placeholder="Category Description e.g. Local Transport"></textarea>
                </div>

                <button type="submit" class="btn btn-primary">Add Expense Category</button>
            </form>
        </div>
    </div>
</div>
<?php require "footer.php"; ?>