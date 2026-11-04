<?php
declare(strict_types=1);

require "nav-head.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    exit("Database connection not found.");
}

$conn->set_charset("utf8mb4");

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function money($value): string
{
    return "UGX " . number_format((float)$value, 2);
}

function badgeClass(string $status): string
{
    return match (strtoupper(trim($status))) {
        "CLEARED" => "success",
        "OVERDUE" => "danger",
        "OUTSTANDING" => "warning",
        "APPROVED" => "info",
        "PENDING" => "secondary",
        default => "dark",
    };
}

$loan_id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($loan_id <= 0) {
    echo "<div class='container-fluid py-4'><div class='alert alert-danger'>Missing loan ID.</div></div>";
    require "footer.php";
    exit;
}

$stmt = $conn->prepare("SELECT * FROM loan WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    echo "<div class='container-fluid py-4'><div class='alert alert-danger'>Loan record not found.</div></div>";
    require "footer.php";
    exit;
}

$user_id = 0;

if (isset($_GET["user_id"]) && (int)$_GET["user_id"] > 0) {
    $user_id = (int)$_GET["user_id"];
} elseif (!empty($loan["user_id"])) {
    $user_id = (int)$loan["user_id"];
} elseif (!empty($loan["client_id"])) {
    $user_id = (int)$loan["client_id"];
} elseif (!empty($_SESSION["logged_in"])) {
    $user_id = (int)$_SESSION["logged_in"];
}

$stmt = $conn->prepare("
    SELECT m.*, u.fname, u.lname, u.email
    FROM members m
    LEFT JOIN user u ON u.id = m.user_id
    WHERE m.user_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

$status = strtoupper((string)($loan["status"] ?? ""));
$amount = (float)($loan["amount"] ?? 0);
$principal_balance = (float)($loan["effective_balance"] ?? $loan["loan_balance"] ?? 0);
$interest_balance = (float)($loan["interest_balance"] ?? 0);
$outstanding = (float)($loan["outstanding_balance"] ?? 0);

if ($outstanding <= 0) {
    $outstanding = $principal_balance + $interest_balance;
}

$loan_number = $loan["loan_number"] ?? "LOAN-" . $loan_id;
?>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">Loan Details</h3>
            <p class="text-muted mb-0"><?= e($loan_number) ?></p>
        </div>

        <a href="loans.php" class="btn btn-light">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">
            <div class="card border-start border-primary border-4 shadow-sm">
                <div class="card-body">
                    <small class="text-muted text-uppercase">Loan Amount</small>
                    <h5 class="mb-0"><?= money($amount) ?></h5>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card border-start border-warning border-4 shadow-sm">
                <div class="card-body">
                    <small class="text-muted text-uppercase">Principal Balance</small>
                    <h5 class="mb-0"><?= money($principal_balance) ?></h5>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card border-start border-info border-4 shadow-sm">
                <div class="card-body">
                    <small class="text-muted text-uppercase">Interest Balance</small>
                    <h5 class="mb-0"><?= money($interest_balance) ?></h5>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card border-start border-danger border-4 shadow-sm">
                <div class="card-body">
                    <small class="text-muted text-uppercase">Outstanding</small>
                    <h5 class="mb-0"><?= money($outstanding) ?></h5>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-4">

        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-file-invoice-dollar me-2"></i>
                        Loan Information
                    </h5>
                </div>

                <div class="card-body">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted">Loan Number</small>
                            <div class="fw-bold"><?= e($loan_number) ?></div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted">Status</small>
                            <div>
                                <span class="badge bg-gradient-<?= badgeClass($status) ?>">
                                    <?= e($status) ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted">Client Name</small>
                            <div class="fw-bold">
                                <?= e(trim(($client["fname"] ?? "") . " " . ($client["lname"] ?? "")) ?: "N/A") ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted">Phone Number</small>
                            <div class="fw-bold"><?= e($client["phone"] ?? "N/A") ?></div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted">NIN / ID Number</small>
                            <div class="fw-bold"><?= e($client["nin"] ?? "N/A") ?></div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted">Email</small>
                            <div class="fw-bold"><?= e($client["email"] ?? "N/A") ?></div>
                        </div>
                    </div>

                    <hr>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted">Issue Date</small>
                            <div class="fw-bold"><?= e($loan["datetime"] ?? $loan["created_at"] ?? "N/A") ?></div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted">Due Date</small>
                            <div class="fw-bold"><?= e($loan["due_date"] ?? "N/A") ?></div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-money-bill-wave me-2"></i>
                        Add Payment
                    </h5>
                </div>

                <div class="card-body">

                    <?php if ($status === "CLEARED"): ?>

                        <div class="alert alert-success mb-0">
                            <i class="fas fa-check-circle me-1"></i>
                            This loan is already cleared.
                        </div>

                    <?php else: ?>

                        <form method="post" action="added_payment.php">

                            <input type="hidden" name="loan" value="<?= (int)$loan_id ?>">
                            <input type="hidden" name="client" value="<?= (int)$user_id ?>">

                            <div class="mb-3">
                                <label class="form-label">Payment Amount</label>
                                <input 
                                    type="number" 
                                    step="0.01" 
                                    min="1" 
                                    class="form-control" 
                                    name="amount" 
                                    placeholder="Enter amount" 
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Transaction Number</label>
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    name="rec" 
                                    placeholder="Enter transaction number" 
                                    required
                                >
                            </div>

                            <button type="submit" name="add" class="btn bg-gradient-info w-100">
                                <i class="fas fa-plus-circle me-1"></i>
                                Add Payment
                            </button>

                        </form>

                    <?php endif; ?>

                </div>
            </div>
        </div>

    </div>

</div>

<?php require "footer.php"; ?>