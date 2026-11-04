<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ============================================================
   PDF EXPORT (must run before nav-head outputs HTML)
============================================================ */
if (($_GET['tab'] ?? '') === 'books' && ($_GET['export'] ?? '') === 'pdf') {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/includes/books-tab.php';

    if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['1', '2', 1, 2], true)) {
        header('Location: ../login.php');
        exit;
    }

    if (!function_exists('h')) {
        function h($v): string
        {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('money')) {
        function money($amount): string
        {
            return 'UGX ' . number_format((float)$amount);
        }
    }

    $user_id = (int)($_GET['user_id'] ?? $_GET['client_id'] ?? $_GET['id'] ?? 0);
    if ($user_id <= 0) {
        http_response_code(400);
        exit('Invalid client selected.');
    }

    $clientStmt = $conn->prepare("
        SELECT
            u.id AS user_id,
            u.fname,
            u.lname,
            u.email,
            c.phone
        FROM customers c
        INNER JOIN user u ON c.user_id = u.id
        WHERE c.user_id = ?
        LIMIT 1
    ");
    if (!$clientStmt) {
        error_log('books pdf: client lookup prepare failed: ' . $conn->error);
        http_response_code(500);
        exit('Something went wrong. Please try again.');
    }

    $clientStmt->bind_param('i', $user_id);
    $clientStmt->execute();
    $client = $clientStmt->get_result()->fetch_assoc();
    $clientStmt->close();

    if (!$client) {
        http_response_code(404);
        exit('Client not found.');
    }

    $fullName = trim(($client['fname'] ?? '') . ' ' . ($client['lname'] ?? ''));
    $books = books_tab_view_model($conn, $user_id, true);

    if (!empty($books['failed'])) {
        http_response_code(500);
        exit('Books history is temporarily unavailable. Please try again shortly.');
    }

    $generatedAt = date('F d, Y H:i:s');

    ob_start();
    require __DIR__ . '/includes/books-tab-pdf.php';
    $html = ob_get_clean();

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $fullName !== '' ? $fullName : 'client');
    $filename = 'Books_History_' . $slug . '_' . $books['month'] . '_' . date('Ymd_His') . '.pdf';

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream($filename, ['Attachment' => false]);
    exit;
}

require "nav-head.php";
require_once __DIR__ . '/includes/books-tab.php';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// book_entries.currency is UGX-only today and the ledger totals sum across rows
// without regard to it, so amounts are formatted as UGX throughout.
function money($amount): string {
    return 'UGX ' . number_format((float)$amount);
}

function client_file_url(?string $path): string {
    if (!$path) return '';

    $path = trim($path);

    if ($path === '') return '';

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return "/loans/clients/" . ltrim($path, '/');
}

function client_doc_card(string $label, ?string $path): string {
    $url = client_file_url($path);

    if ($url === '') {
        return '
            <div class="client-doc-card">
                <div class="doc-icon muted">
                    <i class="fas fa-file"></i>
                </div>
                <div>
                    <strong>' . h($label) . '</strong>
                    <p>No file uploaded</p>
                </div>
            </div>
        ';
    }

    return '
        <div class="client-doc-card">
            <div class="doc-icon">
                <i class="fas fa-file-alt"></i>
            </div>
            <div>
                <strong>' . h($label) . '</strong>
                <p>Uploaded document</p>
                <a href="' . h($url) . '" target="_blank" class="btn btn-sm btn-outline">
                    <i class="fas fa-eye"></i> View File
                </a>
            </div>
        </div>
    ';
}

$user_id = (int)($_GET['user_id'] ?? $_GET['client_id'] ?? $_GET['id'] ?? 0);

if ($user_id <= 0) {
    echo '<div class="alert alert-danger">Invalid client selected.</div>';
    require "footer.php";
    exit;
}

$clientSql = "
    SELECT 
        u.id AS user_id,
        u.fname,
        u.lname,
        u.username,
        u.email,
        u.image_url,
        u.status AS user_status,
        u.role,
        u.datetime AS user_created_at,

        c.id AS customer_id,
        c.phone,
        c.nin,
        c.business_name,
        c.business_type,
        c.location,
        c.district,
        c.loan_limit,
        c.nok_name,
        c.nok_tel,
        c.active,
        c.id_front,
        c.id_back,
        c.license,
        c.stock,
        c.applicant_signature
    FROM customers c
    INNER JOIN user u ON c.user_id = u.id
    WHERE c.user_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($clientSql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    echo '<div class="alert alert-danger">Client not found.</div>';
    require "footer.php";
    exit;
}

$loanStmt = $conn->prepare("
    SELECT 
        l.id,
        l.client_id,
        l.amount,
        l.interest,
        COALESCE((
            SELECT SUM(p.amount)
            FROM payments p
            WHERE p.loan_id = l.id
            AND p.user_id = l.client_id
            AND (
                p.status = 'PAID'
                OR p.status = 'SUCCESS'
                OR p.status = 'COMPLETED'
                OR p.status = 'APPROVED'
                OR p.status IS NULL
                OR p.status = ''
            )
        ), 0) AS amount_paid,
        l.loan_balance,
        l.outstanding_balance,
        l.status,
        l.datetime,
        l.due_date
    FROM loan l
    WHERE l.client_id = ?
    ORDER BY l.id DESC
");

$loans = [];
$totalLoans = 0;
$totalAmount = 0;
$totalPaid = 0;
$totalBalance = 0;

$loanStmt->bind_param("i", $user_id);
$loanStmt->execute();
$loanResult = $loanStmt->get_result();

while ($loan = $loanResult->fetch_assoc()) {
    $amount = (float)($loan['amount'] ?? 0);
    $interest = (float)($loan['interest'] ?? 0);
    $paid = (float)($loan['amount_paid'] ?? 0);
    $balance = isset($loan['loan_balance'])
        ? (float)$loan['loan_balance']
        : (float)($loan['outstanding_balance'] ?? 0);

    $loans[] = $loan;
    $totalLoans++;
    $totalAmount += ($amount + $interest);
    $totalPaid += $paid;
    $totalBalance += $balance;
}

$loanStmt->close();

$fullName = trim(($client['fname'] ?? '') . ' ' . ($client['lname'] ?? ''));
$initials = strtoupper(substr($client['fname'] ?? 'C', 0, 1) . substr($client['lname'] ?? '', 0, 1));

$isActive = ((int)($client['active'] ?? 0) === 1 && (int)($client['user_status'] ?? 0) === 1);
$statusText = $isActive ? 'Active' : 'Blocked';
$statusBadge = $isActive ? 'badge-success' : 'badge-danger';

$activeTab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview', 'documents', 'loans', 'books', 'edit', 'password'];

if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'overview';
}

// Only the books tab depends on the query string, so it is the one pane that is
// fetched on demand rather than rendered into every page load.
$books = $activeTab === 'books' ? books_tab_view_model($conn, $user_id) : null;
?>

<div class="page-header">
    <div>
        <div class="title">Client Profile</div>
        <div class="sub">View client details, documents, loan history, and update profile</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="clients.php" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Back to Clients
        </a>
    </div>
</div>

<?php if (!empty($_GET['success'])): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?= h($_GET['success']) ?>
    </div>
<?php endif; ?>

<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <?= h($_GET['error']) ?>
    </div>
<?php endif; ?>

<div class="client-profile-page">

    <div class="client-profile-sidebar">
        <div class="client-main-card">

            <div class="client-avatar-wrap">
                <?php if (!empty($client['image_url'])): ?>
                    <img src="<?= h(client_file_url($client['image_url'])) ?>" class="client-avatar-img" alt="Client">
                <?php else: ?>
                    <div class="client-avatar-fallback">
                        <?= h($initials) ?>
                    </div>
                <?php endif; ?>
            </div>

            <h3><?= h($fullName ?: 'Unknown Client') ?></h3>
            <p><?= h($client['email'] ?: 'No email') ?></p>

            <span class="badge <?= h($statusBadge) ?>">
                <?= h($statusText) ?>
            </span>

            <div class="profile-mini-grid">
                <div>
                    <strong><?= number_format($totalLoans) ?></strong>
                    <span>Loans</span>
                </div>

                <div>
                    <strong><?= money($totalPaid) ?></strong>
                    <span>Paid</span>
                </div>

                <div>
                    <strong><?= money($totalBalance) ?></strong>
                    <span>Balance</span>
                </div>
            </div>

            <a href="status_client.php?client=<?= (int)$client['user_id'] ?>&admin=<?= (int)($_SESSION['user_id'] ?? $user_id) ?>"
               class="btn <?= $isActive ? 'btn-danger' : 'btn-success' ?> w-100"
               onclick="return confirm('<?= $isActive ? 'Block this client?' : 'Activate this client?' ?>');">
                <i class="fas fa-user-shield"></i>
                <?= $isActive ? 'Block Client' : 'Activate Client' ?>
            </a>
        </div>
    </div>

    <div class="client-profile-content">

        <div class="profile-tab-card">

            <div class="profile-tabs-horizontal">
                <button type="button" class="profile-tab-btn <?= $activeTab === 'overview' ? 'active' : '' ?>" data-tab="overview">
                    <i class="fas fa-user"></i> Overview
                </button>

                <button type="button" class="profile-tab-btn <?= $activeTab === 'documents' ? 'active' : '' ?>" data-tab="documents">
                    <i class="fas fa-folder-open"></i> Documents
                </button>

                <button type="button" class="profile-tab-btn <?= $activeTab === 'loans' ? 'active' : '' ?>" data-tab="loans">
                    <i class="fas fa-file-invoice-dollar"></i> Loans
                </button>

                <a
                    href="view_client.php?user_id=<?= (int)$user_id ?>&tab=books"
                    class="profile-tab-btn <?= $activeTab === 'books' ? 'active' : '' ?>"
                >
                    <i class="fas fa-book"></i> Books History
                </a>

                <button type="button" class="profile-tab-btn <?= $activeTab === 'edit' ? 'active' : '' ?>" data-tab="edit">
                    <i class="fas fa-edit"></i> Edit Client
                </button>

                <button type="button" class="profile-tab-btn <?= $activeTab === 'password' ? 'active' : '' ?>" data-tab="password">
                    <i class="fas fa-key"></i> Password
                </button>
            </div>

            <div class="profile-tab-content">

                <section class="profile-tab-pane <?= $activeTab === 'overview' ? 'active' : '' ?>" id="tab-overview">
                    <div class="profile-section-title">
                        <h4>Client Overview</h4>
                        <p>Personal, business, and next of kin details.</p>
                    </div>

                    <div class="detail-grid">
                        <?php
                        $overview = [
                            'Full Name' => $fullName,
                            'Username' => $client['username'] ?? '',
                            'Email' => $client['email'] ?? '',
                            'Phone' => $client['phone'] ?? '',
                            'National ID NIN' => $client['nin'] ?? '',
                            'Business Name' => $client['business_name'] ?? '',
                            'Business Type' => $client['business_type'] ?? '',
                            'Location' => $client['location'] ?? '',
                            'District' => $client['district'] ?? '',
                            'Loan Limit' => money($client['loan_limit'] ?? 0),
                            'Next of Kin' => $client['nok_name'] ?? '',
                            'Next of Kin Phone' => $client['nok_tel'] ?? '',
                            'Created' => $client['user_created_at'] ?? '',
                            'Status' => $statusText,
                        ];
                        ?>

                        <?php foreach ($overview as $label => $value): ?>
                            <div class="detail-item">
                                <div class="lbl"><?= h($label) ?></div>
                                <div class="val"><?= $value ? h($value) : '&mdash;' ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="profile-tab-pane <?= $activeTab === 'documents' ? 'active' : '' ?>" id="tab-documents">
                    <div class="profile-section-title">
                        <h4>Client Documents</h4>
                        <p>Uploaded verification and business documents.</p>
                    </div>

                    <div class="profile-doc-grid">
                        <?= client_doc_card('Profile Image', $client['image_url'] ?? '') ?>
                        <?= client_doc_card('National ID Front', $client['id_front'] ?? '') ?>
                        <?= client_doc_card('National ID Back', $client['id_back'] ?? '') ?>
                        <?= client_doc_card('License / Certificate', $client['license'] ?? '') ?>
                        <?= client_doc_card('Business Photo / Stock', $client['stock'] ?? '') ?>
                        <?= client_doc_card('Applicant Signature', $client['applicant_signature'] ?? '') ?>
                    </div>
                </section>

                <section class="profile-tab-pane <?= $activeTab === 'loans' ? 'active' : '' ?>" id="tab-loans">
                    <div class="profile-section-title">
                        <h4>Loan History</h4>
                        <p>Loan records and repayment totals based on payments table.</p>
                    </div>

                    <div class="stats-grid clients-stats-grid">
                        <div class="stat-card c-blue">
                            <div class="stat-value sm"><?= number_format($totalLoans) ?></div>
                            <div class="stat-label">Total Loans</div>
                        </div>

                        <div class="stat-card c-indigo">
                            <div class="stat-value"><?= money($totalAmount) ?></div>
                            <div class="stat-label">Total Amount</div>
                        </div>

                        <div class="stat-card c-green">
                            <div class="stat-value"><?= money($totalPaid) ?></div>
                            <div class="stat-label">Total Paid</div>
                        </div>

                        <div class="stat-card c-orange">
                            <div class="stat-value"><?= money($totalBalance) ?></div>
                            <div class="stat-label">Outstanding</div>
                        </div>
                    </div>

                    <div class="table-responsive client-table-scroll">
                        <table class="table clients-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Loan ID</th>
                                    <th>Amount</th>
                                    <th>Interest</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Issued</th>
                                    <th>Due Date</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!empty($loans)): ?>
                                    <?php foreach ($loans as $i => $loan): ?>
                                        <?php
                                        $loanBalance = isset($loan['loan_balance'])
                                            ? $loan['loan_balance']
                                            : ($loan['outstanding_balance'] ?? 0);
                                        ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td>#<?= h($loan['id']) ?></td>
                                            <td><?= money($loan['amount'] ?? 0) ?></td>
                                            <td><?= money($loan['interest'] ?? 0) ?></td>
                                            <td class="text-success fw-700"><?= money($loan['amount_paid'] ?? 0) ?></td>
                                            <td class="text-danger fw-700"><?= money($loanBalance) ?></td>
                                            <td><span class="badge badge-primary"><?= h($loan['status'] ?? 'N/A') ?></span></td>
                                            <td><?= h($loan['datetime'] ?? '') ?></td>
                                            <td><?= h($loan['due_date'] ?? '') ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="client_loan_details.php?loan_id=<?= (int)$loan['id'] ?>" class="btn btn-info btn-icon-sm">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10">
                                            <div class="empty-state">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                                <h3>No loan history found</h3>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php if ($books !== null): ?>
                    <section class="profile-tab-pane active" id="tab-books">
                        <?php require __DIR__ . '/partials/client-books-tab.php'; ?>
                    </section>
                <?php endif; ?>

                <section class="profile-tab-pane <?= $activeTab === 'edit' ? 'active' : '' ?>" id="tab-edit">
                    <div class="profile-section-title">
                        <h4>Edit Client</h4>
                        <p>Update personal and business profile information.</p>
                    </div>

                    <form method="post" action="includes/view_client_process.php">
                        <input type="hidden" name="action" value="update_client">
                        <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="fname" value="<?= h($client['fname'] ?? '') ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="lname" value="<?= h($client['lname'] ?? '') ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" name="username" value="<?= h($client['username'] ?? '') ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" value="<?= h($client['email'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone" value="<?= h($client['phone'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">NIN</label>
                                <input type="text" class="form-control" name="nin" value="<?= h($client['nin'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Business Name</label>
                                <input type="text" class="form-control" name="business_name" value="<?= h($client['business_name'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Business Type</label>
                                <input type="text" class="form-control" name="business_type" value="<?= h($client['business_type'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Location</label>
                                <input type="text" class="form-control" name="location" value="<?= h($client['location'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">District</label>
                                <input type="text" class="form-control" name="district" value="<?= h($client['district'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Loan Limit</label>
                                <input type="number" step="0.01" class="form-control" name="loan_limit" value="<?= h($client['loan_limit'] ?? 0) ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select class="form-control" name="active">
                                    <option value="1" <?= ((int)($client['active'] ?? 0) === 1) ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= ((int)($client['active'] ?? 0) === 0) ? 'selected' : '' ?>>Blocked</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Next of Kin</label>
                                <input type="text" class="form-control" name="nok_name" value="<?= h($client['nok_name'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Next of Kin Phone</label>
                                <input type="text" class="form-control" name="nok_tel" value="<?= h($client['nok_tel'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Client
                            </button>
                        </div>
                    </form>
                </section>

                <section class="profile-tab-pane <?= $activeTab === 'password' ? 'active' : '' ?>" id="tab-password">
                    <div class="profile-section-title">
                        <h4>Change Client Password</h4>
                        <p>Set a new login password for this client.</p>
                    </div>

                    <form method="post" action="includes/view_client_process.php" onsubmit="return confirm('Change password for this client?');">
                        <input type="hidden" name="action" value="update_password">
                        <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">New Password</label>
                                <input type="password" class="form-control" name="new_password" required minlength="6">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" name="confirm_password" required minlength="6">
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </div>
                    </form>
                </section>

            </div>
        </div>
    </div>
</div>

<script>
// Tabs backed by a link (the books tab) navigate instead of switching in place,
// because their contents are driven by the query string.
document.querySelectorAll('.profile-tab-btn[data-tab]').forEach(function(button) {
    button.addEventListener('click', function() {
        const tab = this.dataset.tab;

        document.querySelectorAll('.profile-tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.profile-tab-pane').forEach(pane => pane.classList.remove('active'));

        this.classList.add('active');

        const target = document.getElementById('tab-' + tab);

        if (target) {
            target.classList.add('active');
        }

        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    });
});
</script>
<script src="<?= h(asset_url('assets/js/books-tab.js')) ?>"></script>

<?php require "footer.php"; ?>
