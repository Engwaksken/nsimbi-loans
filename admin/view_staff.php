<?php
require "nav-head.php";

require_once __DIR__ . "/vendor/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_REQUEST['user_id']) || trim((string)$_REQUEST['user_id']) === '') {
    die("User ID is required.");
}

$user_id = (int)$_REQUEST['user_id'];
$role    = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string {
    return number_format((float)$amount, 2);
}

function staffFileUrl(?string $path): string {
    if (!$path) return '';
    $path = ltrim($path, '/');

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return "/loans/staff/" . $path;
}

function fileButton(string $label, ?string $path): string {
    if (!$path) {
        return '<span class="text-muted">No file</span>';
    }

    $url = staffFileUrl($path);

    return '
        <a href="' . h($url) . '" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-eye me-1"></i> ' . h($label) . '
        </a>
    ';
}

$staffQuery = "
    SELECT 
        user.id AS account_id,
        user.fname,
        user.lname,
        user.email,
        user.username,
        user.image_url,
        user.status AS account_status,
        members.*
    FROM members
    INNER JOIN user ON members.user_id = user.id
    WHERE members.user_id = ?
    LIMIT 1
";

$staffStmt = $conn->prepare($staffQuery);
$staffStmt->bind_param("i", $user_id);
$staffStmt->execute();
$staffResult = $staffStmt->get_result();

if (!$staffResult || $staffResult->num_rows === 0) {
    die("No staff member found for this ID.");
}

$staffData = $staffResult->fetch_assoc();

$loanQuery = "
    SELECT 
        id,
      
        amount,
        interest,
 
        amount_paid,
        outstanding_balance,
        status,
        created_at
    FROM loan
    WHERE client_id = ?
    ORDER BY id DESC
";

$loanStmt = $conn->prepare($loanQuery);
$loanStmt->bind_param("i", $user_id);
$loanStmt->execute();
$loanResult = $loanStmt->get_result();

$loans = [];
$totalLoans = 0;
$totalBorrowed = 0;
$totalPaid = 0;
$totalOutstanding = 0;

while ($row = $loanResult->fetch_assoc()) {
    $loans[] = $row;
    $totalLoans++;
    $totalBorrowed += (float)($row['amount'] ?? 0);
    $totalPaid += (float)($row['amount_paid'] ?? 0);
    $totalOutstanding += (float)($row['outstanding_balance'] ?? 0);
}

$statusText = ((int)$staffData['account_status'] === 1) ? 'Active' : 'Blocked';
$statusClass = ((int)$staffData['account_status'] === 1) ? 'success' : 'danger';

$fullName = trim(($staffData['fname'] ?? '') . ' ' . ($staffData['lname'] ?? ''));

if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
            .header { text-align: center; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 20px; }
            .header h2 { margin: 0; font-size: 20px; }
            .small { color: #666; font-size: 11px; }
            .section-title { background: #f2f2f2; padding: 8px; font-weight: bold; margin-top: 18px; }
            table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            th, td { border: 1px solid #ddd; padding: 7px; text-align: left; }
            th { background: #f7f7f7; }
            .badge { padding: 4px 7px; border-radius: 4px; font-size: 10px; }
            .badge-success { background: #d1fae5; color: #065f46; }
            .badge-danger { background: #fee2e2; color: #991b1b; }
            .summary td { font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="header">
            <h2>Staff Profile Report</h2>
            <div class="small">Generated on <?= date('d M Y, h:i A') ?></div>
        </div>

        <div class="section-title">Staff Information</div>
        <table>
            <tr><th>Name</th><td><?= h($fullName) ?></td></tr>
            <tr><th>Email</th><td><?= h($staffData['email'] ?? '') ?></td></tr>
            <tr><th>Username</th><td><?= h($staffData['username'] ?? '') ?></td></tr>
            <tr><th>NIN</th><td><?= h($staffData['nin'] ?? '') ?></td></tr>
            <tr><th>Phone</th><td><?= h($staffData['phone'] ?? '') ?></td></tr>
            <tr><th>Loan Limit</th><td><?= money($staffData['loan_limit'] ?? 0) ?></td></tr>
            <tr><th>Status</th><td><?= h($statusText) ?></td></tr>
            <tr><th>Created</th><td><?= h($staffData['created_at'] ?? '') ?></td></tr>
        </table>

        <div class="section-title">Loan Summary</div>
        <table class="summary">
            <tr>
                <td>Total Loans: <?= $totalLoans ?></td>
                <td>Total Borrowed: <?= money($totalBorrowed) ?></td>
                <td>Total Paid: <?= money($totalPaid) ?></td>
                <td>Outstanding: <?= money($totalOutstanding) ?></td>
            </tr>
        </table>

        <div class="section-title">Loan History</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                
                    <th>Total Amount</th>
                    <th>Paid</th>
                    <th>Outstanding</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($loans) > 0): ?>
                    <?php foreach ($loans as $index => $loan): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            
                           
                            <td><?= money($loan['amount_paid'] ?? 0) ?></td>
                            <td><?= money($loan['outstanding_balance'] ?? 0) ?></td>
                            <td><?= h($loan['status'] ?? '') ?></td>
                            <td><?= h($loan['created_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7">No loan history found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    $options = new Options();
    $options->set('isRemoteEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream("staff-profile-" . $user_id . ".pdf", ["Attachment" => false]);
    exit;
}
?>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">Staff Profile</h4>
            <p class="text-sm text-muted mb-0">View staff details, documents, profile updates, and loan history.</p>
        </div>

        <a href="view_staff.php?user_id=<?= $user_id ?>&export=pdf" target="_blank" class="btn bg-gradient-danger">
            <i class="fas fa-file-pdf me-2"></i> Export PDF
        </a>
    </div>

    <div class="row">
        <div class="col-lg-4 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body text-center">

                    <div class="mb-3">
                        <?php if (!empty($staffData['image_url'])): ?>
                            <img src="<?= h(staffFileUrl($staffData['image_url'])) ?>"
                                 alt="Profile"
                                 class="rounded-circle shadow-sm"
                                 style="width:120px;height:120px;object-fit:cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-gradient-primary text-white mx-auto d-flex align-items-center justify-content-center"
                                 style="width:120px;height:120px;font-size:40px;">
                                <i class="fas fa-user"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h5 class="mb-1"><?= h($fullName) ?></h5>
                    <p class="text-muted text-sm mb-2"><?= h($staffData['email'] ?? '') ?></p>

                    <span class="badge bg-gradient-<?= $statusClass ?>"><?= h($statusText) ?></span>

                    <hr>

                    <div class="row text-center">
                        <div class="col-4">
                            <h6 class="mb-0"><?= $totalLoans ?></h6>
                            <small class="text-muted">Loans</small>
                        </div>
                        <div class="col-4">
                            <h6 class="mb-0"><?= money($totalPaid) ?></h6>
                            <small class="text-muted">Paid</small>
                        </div>
                        <div class="col-4">
                            <h6 class="mb-0"><?= money($totalOutstanding) ?></h6>
                            <small class="text-muted">Balance</small>
                        </div>
                    </div>

                    <hr>

                    <a href="status_staff.php?staff=<?= h($staffData['user_id']) ?>&admin=<?= h($user_id) ?>"
                       class="btn bg-gradient-<?= ((int)$staffData['account_status'] === 1) ? 'danger' : 'success' ?> w-100">
                        <i class="fas fa-user-shield me-2"></i>
                        <?= ((int)$staffData['account_status'] === 1) ? 'Block Staff' : 'Activate Staff' ?>
                    </a>

                </div>
            </div>
        </div>

        <div class="col-lg-8 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white pb-0">
                    <ul class="nav nav-tabs card-header-tabs" id="staffTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#overview" type="button">
                                <i class="fas fa-user me-1"></i> Overview
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#documents" type="button">
                                <i class="fas fa-folder-open me-1"></i> Documents
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#loanHistory" type="button">
                                <i class="fas fa-money-bill-wave me-1"></i> Loan History
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#editProfile" type="button">
                                <i class="fas fa-edit me-1"></i> Edit Profile
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="card-body">
                    <div class="tab-content">

                        <div class="tab-pane fade show active" id="overview">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="text-muted text-xs">Full Name</label>
                                    <div class="fw-bold"><?= h($fullName) ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="text-muted text-xs">Email</label>
                                    <div class="fw-bold"><?= h($staffData['email'] ?? '') ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="text-muted text-xs">Username</label>
                                    <div class="fw-bold"><?= h($staffData['username'] ?? '') ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="text-muted text-xs">Phone</label>
                                    <div class="fw-bold"><?= h($staffData['phone'] ?? '') ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="text-muted text-xs">National ID NIN</label>
                                    <div class="fw-bold"><?= h($staffData['nin'] ?? '') ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="text-muted text-xs">Loan Limit</label>
                                    <div class="fw-bold"><?= money($staffData['loan_limit'] ?? 0) ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="text-muted text-xs">Gross Salary</label>
                                    <div class="fw-bold"><?= money($staffData['gross_salary'] ?? 0) ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="text-muted text-xs">Net Salary</label>
                                    <div class="fw-bold"><?= money($staffData['net_salary'] ?? 0) ?></div>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="text-muted text-xs">Home Address</label>
                                    <div class="fw-bold"><?= h($staffData['home_address'] ?? '') ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="text-muted text-xs">Created At</label>
                                    <div class="fw-bold"><?= h($staffData['created_at'] ?? '') ?></div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="text-muted text-xs">Account Status</label>
                                    <div>
                                        <span class="badge bg-gradient-<?= $statusClass ?>"><?= h($statusText) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="documents">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="border rounded p-3 h-100">
                                        <h6>Profile Image</h6>
                                        <?= fileButton("View Profile Image", $staffData['image_url'] ?? '') ?>
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="border rounded p-3 h-100">
                                        <h6>National ID Front</h6>
                                        <?= fileButton("View ID Front", $staffData['id_front'] ?? '') ?>
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="border rounded p-3 h-100">
                                        <h6>National ID Back</h6>
                                        <?= fileButton("View ID Back", $staffData['id_back'] ?? '') ?>
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="border rounded p-3 h-100">
                                        <h6>Signature</h6>
                                        <?= fileButton("View Signature", $staffData['applicant_signature'] ?? '') ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="loanHistory">
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <div class="border rounded p-3 text-center">
                                        <h6 class="mb-0"><?= $totalLoans ?></h6>
                                        <small class="text-muted">Total Loans</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 text-center">
                                        <h6 class="mb-0"><?= money($totalBorrowed) ?></h6>
                                        <small class="text-muted">Borrowed</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 text-center">
                                        <h6 class="mb-0"><?= money($totalPaid) ?></h6>
                                        <small class="text-muted">Paid</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 text-center">
                                        <h6 class="mb-0"><?= money($totalOutstanding) ?></h6>
                                        <small class="text-muted">Outstanding</small>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover align-items-center">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Loan No.</th>
                                            <th>Total Amount</th>
                                            <th>Paid</th>
                                            <th>Outstanding</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($loans) > 0): ?>
                                            <?php foreach ($loans as $index => $loan): ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                  
                                                    <td><?= money($loan['total_amount'] ?? $loan['amount'] ?? 0) ?></td>
                                                    <td><?= money($loan['amount_paid'] ?? 0) ?></td>
                                                    <td><?= money($loan['outstanding_balance'] ?? 0) ?></td>
                                                    <td>
                                                        <span class="badge bg-gradient-info">
                                                            <?= h($loan['status'] ?? '') ?>
                                                        </span>
                                                    </td>
                                                    <td><?= h($loan['created_at'] ?? '') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-4">
                                                    No loan history found.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="editProfile">
                            <form method="post" action="edited_staff.php">
                                <input type="hidden" name="admin" value="<?= h($user_id) ?>">
                                <input type="hidden" name="staff" value="<?= h($staffData['user_id']) ?>">

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label>Gross Salary</label>
                                        <input type="text" class="form-control" name="gross_salary"
                                               value="<?= h($staffData['gross_salary'] ?? '') ?>">
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label>Net Salary</label>
                                        <input type="text" class="form-control" name="net_salary"
                                               value="<?= h($staffData['net_salary'] ?? '') ?>">
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label>Phone</label>
                                        <input type="text" class="form-control" name="phone"
                                               value="<?= h($staffData['phone'] ?? '') ?>">
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label>Home Address</label>
                                        <input type="text" class="form-control" name="home_address"
                                               value="<?= h($staffData['home_address'] ?? '') ?>">
                                    </div>
                                </div>

                                <button type="submit" name="add" class="btn bg-gradient-info">
                                    <i class="fas fa-save me-2"></i> Update Profile
                                </button>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require "footer.php"; ?>