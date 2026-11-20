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
require_once dirname(__DIR__) . '/includes/client_documents.php';

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

// Client-uploaded documents and loan-limit review requests.
$submissions = [];
$limitRequests = [];
$submissionsError = false;
try {
    $submissions = client_docs_for_user($conn, $user_id);
    $limitRequests = client_limit_requests($conn, $user_id);
} catch (Throwable $e) {
    error_log('view_client submissions load failed: ' . $e->getMessage());
    $submissionsError = true;
}
$pendingLimit = client_limit_pending($limitRequests);
$pendingDocs = count(array_filter($submissions, static fn($d) => $d['status'] === 'pending'));
if (empty($_SESSION['review_csrf'])) {
    $_SESSION['review_csrf'] = bin2hex(random_bytes(16));
}
$reviewCsrf = $_SESSION['review_csrf'];
$reviewMessage = $_SESSION['review_message'] ?? '';
$reviewType = $_SESSION['review_message_type'] ?? 'success';
unset($_SESSION['review_message'], $_SESSION['review_message_type']);
// Result of edit-client / password-reset submissions (set by includes/view_client_process.php).
$flashMessage = is_string($_SESSION['message'] ?? null) ? $_SESSION['message'] : '';
$flashType = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);
$docTypes = client_doc_types();
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

<?php if ($flashMessage !== ''): ?>
    <div class="alert <?= $flashType === 'error' ? 'alert-danger' : 'alert-success' ?>" style="margin-bottom:1rem">
        <i class="fas <?= $flashType === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?>"></i>
        <span><?= h($flashMessage) ?></span>
    </div>
<?php endif; ?>

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
                    <?php if ($pendingDocs + ($pendingLimit ? 1 : 0) > 0): ?>
                        <span class="badge badge-danger" style="margin-left:.3rem"><?= $pendingDocs + ($pendingLimit ? 1 : 0) ?></span>
                    <?php endif; ?>
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
                                <div class="val"><?= $value ? h($value) : 'N/A' ?></div>
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

                    <?php if ($reviewMessage !== ''): ?>
                        <div class="alert <?= $reviewType === 'error' ? 'alert-danger' : 'alert-success' ?>" style="margin-top:1.2rem">
                            <i class="fas <?= $reviewType === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?>"></i>
                            <span><?= h($reviewMessage) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($pendingLimit): ?>
                        <div class="rv-limit">
                            <div class="rv-limit-head">
                                <div>
                                    <h4><i class="fas fa-chart-line"></i> Loan limit review requested</h4>
                                    <p>
                                        <?= h(date('d M Y, H:i', strtotime((string)$pendingLimit['created_at']))) ?> |
                                        from <strong><?= money($pendingLimit['current_limit']) ?></strong>
                                        to <strong><?= money($pendingLimit['requested_limit']) ?></strong>
                                    </p>
                                </div>
                                <span class="badge badge-warning">Pending</span>
                            </div>
                            <?php if (trim((string)$pendingLimit['reason']) !== ''): ?>
                                <blockquote class="rv-quote"><?= nl2br(h($pendingLimit['reason'])) ?></blockquote>
                            <?php endif; ?>
                            <div class="rv-limit-forms">
                                <form method="post" action="client_review_action.php" class="rv-inline">
                                    <input type="hidden" name="csrf" value="<?= h($reviewCsrf) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
                                    <input type="hidden" name="request_id" value="<?= (int)$pendingLimit['id'] ?>">
                                    <input type="hidden" name="action" value="limit_approve">
                                    <label class="form-label" for="rv_limit">New loan limit (UGX)</label>
                                    <input type="text" class="form-control" id="rv_limit" name="approved_limit" inputmode="numeric"
                                           value="<?= h(number_format((float)$pendingLimit['requested_limit'], 0, '.', '')) ?>" required>
                                    <input type="text" class="form-control" name="note" maxlength="255" placeholder="Note to client (optional)">
                                    <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Approve &amp; set limit</button>
                                </form>
                                <form method="post" action="client_review_action.php" class="rv-inline">
                                    <input type="hidden" name="csrf" value="<?= h($reviewCsrf) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
                                    <input type="hidden" name="request_id" value="<?= (int)$pendingLimit['id'] ?>">
                                    <input type="hidden" name="action" value="limit_decline">
                                    <label class="form-label" for="rv_decline">Decline</label>
                                    <input type="text" class="form-control" id="rv_decline" name="note" maxlength="255" placeholder="Reason (sent to the client)" required>
                                    <button type="submit" class="btn btn-outline"><i class="fas fa-times"></i> Decline</button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="profile-section-title" style="margin-top:1.6rem">
                        <h4>Submitted by the client</h4>
                        <p>Documents the client uploaded from their portal. Approve or reject each one; the client is emailed your decision.</p>
                    </div>

                    <?php if ($submissionsError): ?>
                        <div class="alert alert-warning">Client submissions could not be loaded.</div>
                    <?php elseif (!$submissions): ?>
                        <div class="empty-state" style="padding:1.5rem 1rem"><i class="fas fa-inbox"></i><p>The client has not uploaded any documents yet.</p></div>
                    <?php else: ?>
                        <div class="rv-list">
                            <?php foreach ($submissions as $doc): ?>
                                <?php
                                $statusBadge = ['approved' => 'badge-success', 'pending' => 'badge-warning', 'rejected' => 'badge-danger'][$doc['status']] ?? 'badge-muted';
                                $typeLabel = $docTypes[$doc['doc_type']]['label'] ?? ucfirst(str_replace('_', ' ', (string)$doc['doc_type']));
                                ?>
                                <div class="rv-item">
                                    <i class="fas <?= $doc['mime_type'] === 'application/pdf' ? 'fa-file-pdf' : 'fa-file-image' ?> rv-ico"></i>
                                    <div class="rv-main">
                                        <div class="rv-title"><?= h($doc['title']) ?> <span class="badge <?= $statusBadge ?>"><?= h(ucfirst($doc['status'])) ?></span></div>
                                        <div class="rv-meta">
                                            <?= h($typeLabel) ?> | <?= h(date('d M Y, H:i', strtotime((string)$doc['created_at']))) ?>
                                            | <?= number_format(max(1, (int)$doc['file_size'] / 1024)) ?> KB
                                            <?php if (!empty($doc['review_note'])): ?>| <em><?= h($doc['review_note']) ?></em><?php endif; ?>
                                        </div>
                                    </div>
                                    <a href="client_document.php?id=<?= (int)$doc['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline"><i class="fas fa-eye"></i> View</a>
                                    <?php if ($doc['status'] !== 'approved'): ?>
                                        <form method="post" action="client_review_action.php">
                                            <input type="hidden" name="csrf" value="<?= h($reviewCsrf) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
                                            <input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
                                            <input type="hidden" name="action" value="doc_approve">
                                            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check"></i> Approve</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($doc['status'] !== 'rejected'): ?>
                                        <form method="post" action="client_review_action.php" class="rv-reject">
                                            <input type="hidden" name="csrf" value="<?= h($reviewCsrf) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
                                            <input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
                                            <input type="hidden" name="action" value="doc_reject">
                                            <input type="text" name="note" class="form-control" maxlength="255" placeholder="Reason for rejecting" required>
                                            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-times"></i> Reject</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php $pastLimits = array_values(array_filter($limitRequests, static fn($r) => $r['status'] !== 'pending')); ?>
                    <?php if ($pastLimits): ?>
                        <div class="profile-section-title" style="margin-top:1.6rem">
                            <h4>Past limit reviews</h4>
                        </div>
                        <div class="table-responsive">
                            <table class="table clients-table">
                                <thead><tr><th>Requested on</th><th>From</th><th>Requested</th><th>Decision</th><th>New limit</th><th>Note</th></tr></thead>
                                <tbody>
                                    <?php foreach ($pastLimits as $r): ?>
                                        <tr>
                                            <td><?= h(date('d M Y', strtotime((string)$r['created_at']))) ?></td>
                                            <td><?= money($r['current_limit']) ?></td>
                                            <td><?= money($r['requested_limit']) ?></td>
                                            <td><span class="badge <?= $r['status'] === 'approved' ? 'badge-success' : 'badge-danger' ?>"><?= h(ucfirst($r['status'])) ?></span></td>
                                            <td><?= $r['approved_limit'] !== null ? money($r['approved_limit']) : 'N/A' ?></td>
                                            <td><?= h($r['staff_note'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <style>
                        .rv-limit{margin-top:1.2rem;padding:1.1rem;border:1.5px solid var(--warning,#d97706);border-radius:14px;background:var(--warning-bg,#fffbeb)}
                        .rv-limit-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start}
                        .rv-limit-head h4{margin:0 0 .2rem;font-size:.95rem}
                        .rv-limit-head p{margin:0;font-size:.82rem;color:var(--text-muted)}
                        .rv-quote{margin:.8rem 0;padding:.65rem .85rem;border-left:3px solid var(--warning,#d97706);background:#fff;border-radius:8px;font-size:.84rem}
                        .rv-limit-forms{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin-top:.8rem}
                        .rv-inline{display:grid;gap:.45rem;background:#fff;border:1px solid var(--border);border-radius:12px;padding:.85rem}
                        .rv-inline .form-label{margin:0}
                        .rv-list{border:1px solid var(--border);border-radius:12px;overflow:hidden}
                        .rv-item{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;padding:.75rem .9rem;border-top:1px solid var(--border)}
                        .rv-item:first-child{border-top:0}
                        .rv-item form{margin:0;display:flex;gap:.4rem;align-items:center}
                        .rv-ico{font-size:1.3rem;color:var(--primary);width:24px;text-align:center}
                        .rv-main{flex:1;min-width:220px}
                        .rv-title{font-weight:700;font-size:.88rem;display:flex;gap:.4rem;align-items:center;flex-wrap:wrap}
                        .rv-meta{font-size:.74rem;color:var(--text-muted)}
                        .rv-reject .form-control{width:200px;padding:.35rem .6rem;font-size:.8rem}
                    </style>
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
