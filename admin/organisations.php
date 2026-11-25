<?php
declare(strict_types=1);

/*
 * People > Organisations: companies that can be given loans. Active
 * organisations appear under "Organisations" in the New Loan form.
 * Add, view, edit and delete all happen in modals on this page.
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

require_once dirname(__DIR__) . '/includes/organisations.php';
require_once dirname(__DIR__) . '/includes/officer_assignment.php';
organisations_ensure_table($conn);

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

function org_done(string $message): never
{
    $_SESSION['org_flash'] = $message;
    header('Location: organisations.php', true, 303);
    exit;
}

/** Loans recorded against an organisation (0 when loan.client_type is missing). */
function org_loan_count(mysqli $conn, int $id): int
{
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM loan WHERE client_id = ? AND LOWER(COALESCE(client_type, '')) = 'organisation'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}

$error = '';
$formValues = null; // posted values to put back into the form after an error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'update' || $action === 'delete') {
        // Loan officers may only change organisations assigned to them.
        officer_require_client($conn, 'organisation', $id);
    }

    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'delete') {
        $loans = org_loan_count($conn, $id);
        if ($loans > 0) {
            $error = "This organisation has {$loans} loan(s), so it can't be deleted. Edit it and set Status to Inactive instead.";
        } else {
            $stmt = $conn->prepare('DELETE FROM organisations WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            org_done('Organisation deleted.');
        }
    } elseif ($action === 'create' || $action === 'update') {
        $values = [];
        foreach (ORG_FIELDS as $field => $label) {
            $values[$field] = mb_substr(trim((string)($_POST[$field] ?? '')), 0, 190, 'UTF-8');
            if ($values[$field] === '' && $error === '') {
                $error = $label . ' is required.';
            }
        }
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        if ($error === '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        }
        if ($error === '') {
            try {
                if ($action === 'create') {
                    $stmt = $conn->prepare('INSERT INTO organisations (company_name, ceo_name, phone, email, bank, account_name, account_number, bank_branch, company_location, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('ssssssssss', ...[...array_values($values), $status]);
                } else {
                    $stmt = $conn->prepare('UPDATE organisations SET company_name = ?, ceo_name = ?, phone = ?, email = ?, bank = ?, account_name = ?, account_number = ?, bank_branch = ?, company_location = ?, status = ? WHERE id = ?');
                    $stmt->bind_param('ssssssssssi', ...[...array_values($values), $status, $id]);
                }
                $stmt->execute();
                $newId = (int)$conn->insert_id;
                $stmt->close();
                if ($action === 'create' && $newId > 0) {
                    // Give the new organisation its officer now, so it shows up straight away.
                    officer_of_client($conn, 'organisation', $newId);
                }
                org_done($action === 'create'
                    ? 'Organisation added. Choose it under "Organisations" in the New Loan form.'
                    : 'Organisation updated.');
            } catch (Throwable $e) {
                error_log('Organisation save failed: ' . $e->getMessage());
                $error = 'Could not save the organisation. Please try again.';
            }
        }
        $formValues = $values + ['id' => $action === 'update' ? $id : 0, 'status' => $status];
    } else {
        $error = 'Invalid request.';
    }
}

$flash = (string)($_SESSION['org_flash'] ?? '');
unset($_SESSION['org_flash']);

$organisations = [];
try {
    $result = $conn->query("
        SELECT o.*,
               (SELECT COUNT(*) FROM loan l WHERE l.client_id = o.id AND LOWER(COALESCE(l.client_type, '')) = 'organisation') AS loans
        FROM organisations o
        WHERE 1 = 1" . officer_client_sql('o') . "
        ORDER BY o.status = 'active' DESC, o.company_name
    ");
    $organisations = $result->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    // Older databases without loan.client_type: list without the loan count.
    $result = $conn->query("SELECT o.*, NULL AS loans FROM organisations o WHERE 1 = 1" . officer_client_sql('o') . " ORDER BY o.status = 'active' DESC, o.company_name");
    $organisations = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function org_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function org_json(array $data): string
{
    return org_e(json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
}

$phead = 'Organisations';
require 'nav-head.php';
?>

<style>
.org-page { padding: 1rem; }
.org-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.1rem; }
.org-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.org-table th, .org-table td { padding: .55rem .6rem; border-bottom: 1px solid #f0f1f3; text-align: left; vertical-align: middle; }
.org-table th { font-size: .74rem; text-transform: uppercase; color: #64748b; background: #f8fafc; }
.org-muted { color: #64748b; font-size: .8rem; }
.org-actions { display: flex; gap: .35rem; justify-content: flex-end; }
.org-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: .75rem; }
.org-view { display: grid; grid-template-columns: 150px 1fr; gap: .45rem .9rem; font-size: .9rem; margin: 0; }
.org-view dt { color: #64748b; font-weight: 600; }
.org-view dd { margin: 0; word-break: break-word; }
@media (max-width: 520px) { .org-view { grid-template-columns: 1fr; } .org-view dd { margin-bottom: .4rem; } }
</style>

<div class="org-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="mb-0 fw-bold">Organisations</h4>
            <div class="org-muted">Active organisations appear under "Organisations" in the New Loan form (Loans &gt; All Loans &gt; New Loan).</div>
        </div>
        <button type="button" class="btn btn-primary" data-org-add>
            <i class="fas fa-plus-circle me-1"></i> Add organisation
        </button>
    </div>

    <?php if ($error !== '' && $formValues === null): ?>
        <div class="alert alert-danger"><?= org_e($error) ?></div>
    <?php elseif ($flash !== ''): ?>
        <div class="alert alert-success"><?= org_e($flash) ?></div>
    <?php endif; ?>

    <div class="org-card">
        <div class="table-responsive">
            <table class="org-table">
                <thead>
                <tr><th>Company</th><th>CEO</th><th>Contact</th><th>Location</th><th>Loans</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php if (!$organisations): ?>
                    <tr><td colspan="7" class="text-center org-muted py-4">No organisations yet. Click "Add organisation" to add one.</td></tr>
                <?php endif; ?>
                <?php foreach ($organisations as $org):
                    $active = $org['status'] === 'active';
                    $data = ['id' => (int)$org['id'], 'status' => $org['status'], 'loans' => $org['loans'] === null ? null : (int)$org['loans'],
                             'created_at' => (string)$org['created_at']];
                    foreach (array_keys(ORG_FIELDS) as $field) {
                        $data[$field] = (string)$org[$field];
                    }
                ?>
                    <tr>
                        <td><strong><?= org_e($org['company_name']) ?></strong></td>
                        <td><?= org_e($org['ceo_name']) ?></td>
                        <td><?= org_e($org['phone']) ?><div class="org-muted"><?= org_e($org['email']) ?></div></td>
                        <td><?= org_e($org['company_location']) ?></td>
                        <td><?= $org['loans'] === null ? '-' : (int)$org['loans'] ?></td>
                        <td><span class="badge <?= $active ? 'bg-success' : 'bg-secondary' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                        <td>
                            <div class="org-actions">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-org-view="<?= org_json($data) ?>" title="View" aria-label="View <?= org_e($org['company_name']) ?>"><i class="fas fa-eye"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-org-edit="<?= org_json($data) ?>" title="Edit" aria-label="Edit <?= org_e($org['company_name']) ?>"><i class="fas fa-edit"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-org-delete="<?= org_json($data) ?>" title="Delete" aria-label="Delete <?= org_e($org['company_name']) ?>"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add / edit -->
<div class="modal-overlay org-modal" id="orgFormModal" aria-hidden="true">
    <div class="modal-box lg" role="dialog" aria-modal="true" aria-labelledby="orgFormTitle">
        <form method="post" id="orgForm" novalidate>
            <div class="modal-header">
                <div class="modal-title"><div class="modal-ico"><i class="fas fa-city"></i></div>
                    <span class="modal-title-text" id="orgFormTitle">Add organisation</span></div>
                <button type="button" class="modal-close" data-org-close aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger" id="orgFormError" <?= $error !== '' && $formValues !== null ? '' : 'hidden' ?>><?= org_e($error) ?></div>
                <input type="hidden" name="csrf_token" value="<?= org_e($csrf) ?>">
                <input type="hidden" name="action" id="orgAction" value="create">
                <input type="hidden" name="id" id="orgId" value="0">
                <div class="org-grid">
                    <?php foreach (ORG_FIELDS as $key => $label): ?>
                        <div>
                            <label class="form-label fs-sm" for="org_<?= $key ?>"><?= org_e($label) ?></label>
                            <input class="form-control" id="org_<?= $key ?>" name="<?= $key ?>" required maxlength="190"
                                   type="<?= $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text') ?>">
                        </div>
                    <?php endforeach; ?>
                    <div>
                        <label class="form-label fs-sm" for="org_status">Status</label>
                        <select class="form-select" id="org_status" name="status">
                            <option value="active">Active (can be given loans)</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-org-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="orgSubmit"><i class="fas fa-save me-1"></i> Save organisation</button>
            </div>
        </form>
    </div>
</div>

<!-- View -->
<div class="modal-overlay org-modal" id="orgViewModal" aria-hidden="true">
    <div class="modal-box md" role="dialog" aria-modal="true" aria-labelledby="orgViewTitle">
        <div class="modal-header">
            <div class="modal-title"><div class="modal-ico"><i class="fas fa-eye"></i></div>
                <span class="modal-title-text" id="orgViewTitle">Organisation</span></div>
            <button type="button" class="modal-close" data-org-close aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body"><dl class="org-view" id="orgViewBody"></dl></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-org-close>Close</button>
            <button type="button" class="btn btn-primary" id="orgViewEdit"><i class="fas fa-edit me-1"></i> Edit</button>
        </div>
    </div>
</div>

<!-- Delete -->
<div class="modal-overlay org-modal" id="orgDeleteModal" aria-hidden="true">
    <div class="modal-box sm" role="dialog" aria-modal="true" aria-labelledby="orgDeleteTitle">
        <form method="post">
            <div class="modal-header">
                <div class="modal-title"><div class="modal-ico"><i class="fas fa-trash"></i></div>
                    <span class="modal-title-text" id="orgDeleteTitle">Delete organisation</span></div>
                <button type="button" class="modal-close" data-org-close aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= org_e($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="orgDeleteId" value="0">
                <p class="mb-0" id="orgDeleteText"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-org-close>Cancel</button>
                <button type="submit" class="btn btn-danger" id="orgDeleteSubmit"><i class="fas fa-trash me-1"></i> Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var FIELDS = <?= json_encode(ORG_FIELDS, JSON_HEX_TAG) ?>;
    var REOPEN = <?= json_encode($formValues, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
    var $ = function (id) { return document.getElementById(id); };
    var viewing = null;

    function open(id) {
        var m = $(id);
        if (window.Modal) { Modal.open(id); } else { m.classList.add('open'); }
        m.setAttribute('aria-hidden', 'false');
    }
    function close(m) {
        if (window.Modal) { Modal.close(m.id); } else { m.classList.remove('open'); }
        m.setAttribute('aria-hidden', 'true');
    }
    function parse(el, attr) { return JSON.parse(el.getAttribute(attr)); }

    function showForm(org, errorText) {
        var editing = org && org.id > 0;
        $('orgFormTitle').textContent = editing ? 'Edit ' + org.company_name : 'Add organisation';
        $('orgSubmit').innerHTML = '<i class="fas fa-save me-1"></i> ' + (editing ? 'Save changes' : 'Save organisation');
        $('orgAction').value = editing ? 'update' : 'create';
        $('orgId').value = editing ? org.id : 0;
        Object.keys(FIELDS).forEach(function (key) { $('org_' + key).value = org ? (org[key] || '') : ''; });
        $('org_status').value = org && org.status === 'inactive' ? 'inactive' : 'active';
        var err = $('orgFormError');
        err.textContent = errorText || '';
        err.hidden = !errorText;
        open('orgFormModal');
        setTimeout(function () { $('org_company_name').focus(); }, 50);
    }

    function showView(org) {
        viewing = org;
        $('orgViewTitle').textContent = org.company_name;
        var rows = Object.keys(FIELDS).map(function (key) { return [FIELDS[key], org[key]]; });
        rows.push(['Status', org.status === 'active' ? 'Active' : 'Inactive']);
        rows.push(['Loans', org.loans === null ? '-' : String(org.loans)]);
        rows.push(['Added', org.created_at ? org.created_at.slice(0, 10) : '-']);
        var body = $('orgViewBody');
        body.innerHTML = '';
        rows.forEach(function (r) {
            var dt = document.createElement('dt'); dt.textContent = r[0];
            var dd = document.createElement('dd'); dd.textContent = r[1] || '-';
            body.appendChild(dt); body.appendChild(dd);
        });
        open('orgViewModal');
    }

    function showDelete(org) {
        $('orgDeleteId').value = org.id;
        var blocked = org.loans > 0;
        $('orgDeleteText').textContent = blocked
            ? org.company_name + ' has ' + org.loans + ' loan(s), so it can\'t be deleted. Edit it and set Status to Inactive instead.'
            : 'Delete ' + org.company_name + '? This cannot be undone.';
        $('orgDeleteSubmit').hidden = blocked;
        open('orgDeleteModal');
    }

    document.addEventListener('click', function (e) {
        var t;
        if (e.target.closest('[data-org-add]')) { showForm(null); return; }
        if ((t = e.target.closest('[data-org-view]'))) { showView(parse(t, 'data-org-view')); return; }
        if ((t = e.target.closest('[data-org-edit]'))) { showForm(parse(t, 'data-org-edit')); return; }
        if ((t = e.target.closest('[data-org-delete]'))) { showDelete(parse(t, 'data-org-delete')); return; }
        if (e.target.closest('#orgViewEdit') && viewing) { close($('orgViewModal')); showForm(viewing); return; }
        var modal = e.target.closest('.org-modal');
        if (modal && (e.target === modal || e.target.closest('[data-org-close]'))) close(modal);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.org-modal.open').forEach(close);
    });

    $('orgForm').addEventListener('submit', function (e) {
        for (var key in FIELDS) {
            var input = $('org_' + key);
            if (!input.value.trim()) { e.preventDefault(); $('orgFormError').textContent = FIELDS[key] + ' is required.'; $('orgFormError').hidden = false; input.focus(); return; }
        }
        if (!$('org_email').checkValidity()) { e.preventDefault(); $('orgFormError').textContent = 'Enter a valid email address.'; $('orgFormError').hidden = false; $('org_email').focus(); }
    });

    // A save that failed on the server reopens the form with what was typed.
    if (REOPEN) showForm(REOPEN, $('orgFormError').textContent);
})();
</script>

<?php require 'footer.php'; ?>
