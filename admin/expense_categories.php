<?php
require "nav-head.php";
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = intval($_GET['delete']);

    $delete = $conn->prepare("DELETE FROM expense_categories WHERE id = ?");
    $delete->bind_param("i", $id);
    $delete->execute();

    echo "<script>alert('Category deleted.'); window.location.href='categories.php';</script>";
    exit;
}

$categories = $conn->query("SELECT * FROM expense_categories ORDER BY created_at DESC");
?>

<div class="container py-5">
    
   <div class="card">
        <div class="card-header">
            <h6>Category List</h6>
            <a href="add_expense_category.php" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Add Category</a>
        </div>
        <div class="card-body px-0 pt-0 pb-2">
            <div class="table-responsive p-3">
                <table class="table align-items-center mb-0" id="report">
        <thead>
            <tr>
                <th>#</th>
                <th>Category</th>
                <th>Description</th>
                <th>Created</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($categories->num_rows > 0): 
            $i = 1;
            while ($cat = $categories->fetch_assoc()): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($cat['name']) ?></td>
                    <td><?= htmlspecialchars($cat['description']) ?></td>
                    <td><?= htmlspecialchars($cat['created_at']) ?></td>
                    <td>
                        <a href="categories.php?delete=<?= $cat['id'] ?>" class="badge bg-danger"
                           onclick="return confirm('Are you sure you want to delete this category?')"><i class="fas fa-trash-alt"></i></a>
                    </td>
                </tr>
        <?php endwhile; else: ?>
            <tr><td colspan="5">No category found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
                </div>
            </div>
        </div>
<?php require "footer.php"; ?>