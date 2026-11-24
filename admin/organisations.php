<?php
declare(strict_types=1);

/*
 * People > Organisations: companies that can be given loans. Once added, an
 * organisation appears under "Organisations" when adding a loan.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/db.php';
if (empty($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    header('Location: ../login.php');
    exit;
}
$conn->set_charset('utf8mb4');

// Same table as admin/organisation-loans.sql, created on first use.
$conn->query("
    CREATE TABLE IF NOT EXISTS organisations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_name VARCHAR(190) NOT NULL,
        ceo_name VARCHAR(190) NOT NULL,
        phone VARCHAR(40) NOT NULL,
        email VARCHAR(190) NOT NULL,
        bank VARCHAR(190) NOT NULL,
        account_name VARCHAR(190) NOT NULL,
        account_number VARCHAR(100) NOT NULL,
        bank_branch VARCHAR(190) NOT NULL,
        company_location VARCHAR(255) NOT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_organisations_status_name (status, company_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

const ORG_FIELDS = [
    'company_name'     => 'Company name',
    'ceo_name'         => 'Name of CEO',
    'phone'            => 'Phone number',
    'email'            => 'Email',
    'bank'             => 'Bank',
    'account_name'     => 'Account name',
    'account_number'   => 'Account number',
    'bank_branch'      => 'Bank branch',
    'company_location' => 'Company location',
];

if (empty($_SESSION['org_csrf'])) {
    $_SESSION['org_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['org_csrf'];
$error = '';
$values = array_fill_keys(array_keys(ORG_FIELDS), '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE organisations SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        header('Location: organisations.php?updated=1', true, 303);
        exit;
    } else {
        foreach (ORG_FIELDS as $field => $label) {
            $values[$field] = mb_substr(trim((string)($_POST[$field] ?? '')), 0, 190, 'UTF-8');
            if ($values[$field] === '' && $error === '') {
                $error = 'Please complete all organisation details (' . $label . ' is missing).';
            }
        }
        if ($error === '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        }
        if ($error === '') {
            try {
                $stmt = $conn->prepare('INSERT INTO organisations (company_name, ceo_name, phone, email, bank, account_name, account_number, bank_branch, company_location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sssssssss', ...array_values($values));
                $stmt->execute();
                $stmt->close();
                header('Location: organisations.php?created=1', true, 303);
                exit;
            } catch (Throwable $e) {
                error_log('Organisation save failed: ' . $e->getMessage());
                $error = 'Could not save the organisation. Please try again.';
            }
        }
    }
}

$organisations = [];
try {
    $result = $conn->query("
        SELECT o.*,
               (SELECT COUNT(*) FROM loan l WHERE l.client_id = o.id AND LOWER(COALESCE(l.client_type, '')) = 'organisation') AS loans
        FROM organisations o
        ORDER BY o.status = 'active' DESC, o.company_name
    ");
    $organisations = $result->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    // Older databases without loan.client_type: list without the loan count.
    $result = $conn->query("SELECT o.*, NULL AS loans FROM organisations o ORDER BY o.status = 'active' DESC, o.company_name");
    $organisations = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function org_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$phead = 'Organisations';
require 'nav-head.php';
?>

<style>
.org-page { padding: 1rem; }
.org-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.1rem; margin-bottom: 1rem; }
.org-card h5 { font-weight: 800; margin: 0 0 .75rem; }
.org-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: .75rem; }
.org-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.org-table th, .org-table td { padding: .55rem .6rem; border-bottom: 1px solid #f0f1f3; text-align: left; vertical-align: middle; }
.org-table th { font-size: .74rem; text-transform: uppercase; color: #64748b; background: #f8fafc; }
.org-muted { color: #64748b; font-size: .8rem; }
</style>

<div class="org-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="mb-0 fw-bold">Organisations</h4>
            <div class="org-muted">Active organisations appear under "Organisations" in the New Loan form (Loans &gt; All Loans &gt; New Loan).</div>
        </div>
        <a href="loans.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-invoice-dollar me-1"></i> Go to loans</a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= org_e($error) ?></div>
    <?php elseif (isset($_GET['created'])): ?>
        <div class="alert alert-success">Organisation saved. Choose it under "Organisations" in the New Loan form.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Organisation status updated.</div>
    <?php endif; ?>

    <div class="org-card">
        <h5><i class="fas fa-plus-circle text-primary me-2"></i>Add organisation</h5>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= org_e($csrf) ?>">
            <div class="org-grid">
                <?php foreach (ORG_FIELDS as $key => $label): ?>
                    <div>
                        <label class="form-label fs-sm" for="<?= $key ?>"><?= org_e($label) ?></label>
                        <input class="form-control" id="<?= $key ?>" name="<?= $key ?>" required maxlength="190"
                               type="<?= $key === 'email' ? 'email' : 'text' ?>" value="<?= org_e($values[$key]) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-primary mt-3"><i class="fas fa-save me-1"></i> Save organisation</button>
        </form>
    </div>

    <div class="org-card">
        <h5><i class="fas fa-city text-primary me-2"></i>Registered organisations (<?= count($organisations) ?>)</h5>
        <div class="table-responsive">
            <table class="org-table">
                <thead>
                <tr><th>Company</th><th>CEO</th><th>Contact</th><th>Bank</th><th>Location</th><th>Loans</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (!$organisations): ?>
                    <tr><td colspan="8" class="text-center org-muted py-4">No organisations yet. Add one above.</td></tr>
                <?php endif; ?>
                <?php foreach ($organisations as $org): $active = $org['status'] === 'active'; ?>
                    <tr>
                        <td><strong><?= org_e($org['company_name']) ?></strong></td>
                        <td><?= org_e($org['ceo_name']) ?></td>
                        <td><?= org_e($org['phone']) ?><div class="org-muted"><?= org_e($org['email']) ?></div></td>
                        <td><?= org_e($org['bank']) ?><div class="org-muted"><?= org_e($org['account_name']) ?> &middot; <?= org_e($org['account_number']) ?> &middot; <?= org_e($org['bank_branch']) ?></div></td>
                        <td><?= org_e($org['company_location']) ?></td>
                        <td><?= $org['loans'] === null ? '-' : (int)$org['loans'] ?></td>
                        <td><span class="badge <?= $active ? 'bg-success' : 'bg-secondary' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                        <td class="text-end">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= org_e($csrf) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$org['id'] ?>">
                                <button class="btn btn-sm <?= $active ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                                    <?= $active ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
