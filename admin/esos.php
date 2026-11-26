<?php
declare(strict_types=1);

/*
 * People > ESOs: Enterprise Support Organisations that refer clients.
 * Active ESOs are listed on the /apply sign-up form under "Who referred you?"
 * next to the loan officers. Admins add, edit and delete them here.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/db.php';
if (empty($_SESSION['logged_in']) || (int)($_SESSION['role'] ?? 0) !== 1) {
    header('Location: ../login.php');
    exit;
}
$conn->set_charset('utf8mb4');

require_once dirname(__DIR__) . '/includes/esos.php';
esos_ensure_schema($conn);

const ESO_FIELDS = [
    'name'           => ['ESO name', true],
    'contact_person' => ['Contact person', false],
    'phone'          => ['Phone number', false],
    'email'          => ['Email', false],
    'location'       => ['Location / district', false],
];

if (empty($_SESSION['eso_csrf'])) {
    $_SESSION['eso_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['eso_csrf'];

function eso_done(string $message): never
{
    $_SESSION['eso_flash'] = $message;
    header('Location: esos.php', true, 303);
    exit;
}

function eso_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$error = '';
$formValues = null; // posted values to put back into the form after an error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $counts = eso_client_counts($conn);

    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'delete') {
        $clients = $counts[$id] ?? 0;
        if ($clients > 0) {
            $error = "This ESO referred {$clients} client(s), so it can't be deleted. Edit it and set Status to Inactive to hide it from the sign-up form.";
        } else {
            $stmt = $conn->prepare('DELETE FROM esos WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            eso_done('ESO deleted.');
        }
    } elseif ($action === 'create' || $action === 'update') {
        $values = [];
        foreach (ESO_FIELDS as $field => [$label, $required]) {
            $values[$field] = mb_substr(trim((string)($_POST[$field] ?? '')), 0, 190, 'UTF-8');
            if ($required && $values[$field] === '' && $error === '') {
                $error = $label . ' is required.';
            }
        }
        $services = mb_substr(trim((string)($_POST['services'] ?? '')), 0, 2000, 'UTF-8');
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        if ($error === '' && $values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address, or leave it empty.';
        }
        if ($error === '') {
            try {
                if ($action === 'create') {
                    $stmt = $conn->prepare('INSERT INTO esos (name, contact_person, phone, email, location, services, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('sssssss', $values['name'], $values['contact_person'], $values['phone'], $values['email'], $values['location'], $services, $status);
                } else {
                    $stmt = $conn->prepare('UPDATE esos SET name = ?, contact_person = ?, phone = ?, email = ?, location = ?, services = ?, status = ? WHERE id = ?');
                    $stmt->bind_param('sssssssi', $values['name'], $values['contact_person'], $values['phone'], $values['email'], $values['location'], $services, $status, $id);
                }
                $stmt->execute();
                $stmt->close();
                eso_done($action === 'create'
                    ? 'ESO added. Clients can now choose it under "Who referred you?" when they sign up.'
                    : 'ESO updated.');
            } catch (Throwable $e) {
                error_log('ESO save failed: ' . $e->getMessage());
                $error = 'Could not save the ESO. Please try again.';
            }
        }
        $formValues = $values + ['services' => $services, 'id' => $action === 'update' ? $id : 0, 'status' => $status];
    } else {
        $error = 'Invalid request.';
    }
}

$flash = (string)($_SESSION['eso_flash'] ?? '');
unset($_SESSION['eso_flash']);

$esos = esos_list($conn, true);
$counts = eso_client_counts($conn);
$activeCount = count(array_filter($esos, static fn($e) => $e['status'] === 'active'));
$referredTotal = array_sum($counts);

$phead = 'ESOs';
require 'nav-head.php';
?>

<style>
.eso-page { padding: 1rem; }
.eso-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.1rem; }
.eso-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.eso-table th, .eso-table td { padding: .55rem .6rem; border-bottom: 1px solid #f0f1f3; text-align: left; vertical-align: middle; }
.eso-table th { font-size: .74rem; text-transform: uppercase; color: #64748b; background: #f8fafc; }
.eso-muted { color: #64748b; font-size: .8rem; }
.eso-actions { display: flex; gap: .35rem; justify-content: flex-end; }
.eso-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: .75rem; }
.eso-grid .wide { grid-column: 1 / -1; }
</style>

<div class="eso-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="mb-0 fw-bold">ESOs</h4>
            <div class="eso-muted">Enterprise Support Organisations. Active ESOs appear under "Who referred you?" on the client sign-up form, next to the loan officers.</div>
        </div>
        <button type="button" class="btn btn-primary" data-eso-add>
            <i class="fas fa-plus-circle me-1"></i> Add ESO
        </button>
    </div>

    <?php if ($error !== '' && $formValues === null): ?>
        <div class="alert alert-danger"><?= eso_e($error) ?></div>
    <?php elseif ($flash !== ''): ?>
        <div class="alert alert-success"><?= eso_e($flash) ?></div>
    <?php endif; ?>

    <div class="stats-grid clients-stats-grid">
        <div class="stat-card c-blue">
            <div class="stat-head"><div class="stat-ico c-blue"><i class="fas fa-handshake"></i></div></div>
            <div class="stat-value sm"><?= number_format(count($esos)) ?></div>
            <div class="stat-label">Total ESOs</div>
        </div>
        <div class="stat-card c-green">
            <div class="stat-head"><div class="stat-ico c-green"><i class="fas fa-check-circle"></i></div></div>
            <div class="stat-value sm"><?= number_format($activeCount) ?></div>
            <div class="stat-label">Active (on the sign-up form)</div>
        </div>
        <div class="stat-card c-indigo">
            <div class="stat-head"><div class="stat-ico c-indigo"><i class="fas fa-users"></i></div></div>
            <div class="stat-value sm"><?= number_format($referredTotal) ?></div>
            <div class="stat-label">Clients referred by ESOs</div>
        </div>
    </div>

    <div class="eso-card">
        <div class="table-responsive">
            <table class="eso-table">
                <thead>
                <tr><th>ESO</th><th>Contact</th><th>Location</th><th>Clients referred</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php if (!$esos): ?>
                    <tr><td colspan="6" class="text-center eso-muted py-4">No ESOs yet. Click "Add ESO" to add one.</td></tr>
                <?php endif; ?>
                <?php foreach ($esos as $eso):
                    $active = $eso['status'] === 'active';
                    $data = ['id' => (int)$eso['id'], 'status' => $eso['status'], 'services' => (string)($eso['services'] ?? ''),
                             'clients' => $counts[(int)$eso['id']] ?? 0];
                    foreach (array_keys(ESO_FIELDS) as $field) {
                        $data[$field] = (string)$eso[$field];
                    }
                    $json = eso_e(json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
                ?>
                    <tr>
                        <td>
                            <strong><?= eso_e($eso['name']) ?></strong>
                            <?php if (trim((string)$eso['services']) !== ''): ?>
                                <div class="eso-muted"><?= eso_e(mb_strimwidth((string)$eso['services'], 0, 90, '...', 'UTF-8')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= eso_e($eso['contact_person'] ?: '-') ?>
                            <div class="eso-muted"><?= eso_e(trim($eso['phone'] . ($eso['phone'] !== '' && $eso['email'] !== '' ? ' | ' : '') . $eso['email'])) ?></div>
                        </td>
                        <td><?= eso_e($eso['location'] ?: '-') ?></td>
                        <td><?= number_format($counts[(int)$eso['id']] ?? 0) ?></td>
                        <td><span class="badge <?= $active ? 'bg-success' : 'bg-secondary' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                        <td>
                            <div class="eso-actions">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-eso-edit="<?= $json ?>" title="Edit" aria-label="Edit <?= eso_e($eso['name']) ?>"><i class="fas fa-edit"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-eso-delete="<?= $json ?>" title="Delete" aria-label="Delete <?= eso_e($eso['name']) ?>"><i class="fas fa-trash"></i></button>
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
<div class="modal-overlay eso-modal" id="esoFormModal" aria-hidden="true">
    <div class="modal-box lg" role="dialog" aria-modal="true" aria-labelledby="esoFormTitle">
        <form method="post" id="esoForm">
            <div class="modal-header">
                <div class="modal-title"><div class="modal-ico"><i class="fas fa-handshake"></i></div>
                    <span class="modal-title-text" id="esoFormTitle">Add ESO</span></div>
                <button type="button" class="modal-close" data-eso-close aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger" <?= $error !== '' && $formValues !== null ? '' : 'hidden' ?>><?= eso_e($error) ?></div>
                <input type="hidden" name="csrf_token" value="<?= eso_e($csrf) ?>">
                <input type="hidden" name="action" id="esoAction" value="create">
                <input type="hidden" name="id" id="esoId" value="0">
                <div class="eso-grid">
                    <?php foreach (ESO_FIELDS as $key => [$label, $required]): ?>
                        <div>
                            <label class="form-label fs-sm" for="eso_<?= $key ?>"><?= eso_e($label) ?><?= $required ? ' <span class="text-danger">*</span>' : '' ?></label>
                            <input class="form-control" id="eso_<?= $key ?>" name="<?= $key ?>" maxlength="190" <?= $required ? 'required' : '' ?>
                                   type="<?= $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text') ?>">
                        </div>
                    <?php endforeach; ?>
                    <div>
                        <label class="form-label fs-sm" for="eso_status">Status</label>
                        <select class="form-select" id="eso_status" name="status">
                            <option value="active">Active (shown on the sign-up form)</option>
                            <option value="inactive">Inactive (hidden)</option>
                        </select>
                    </div>
                    <div class="wide">
                        <label class="form-label fs-sm" for="eso_services">Services / notes (optional)</label>
                        <textarea class="form-control" id="eso_services" name="services" rows="3" maxlength="2000"
                                  placeholder="e.g. business training, mentorship, market linkages"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-eso-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save ESO</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete -->
<div class="modal-overlay eso-modal" id="esoDeleteModal" aria-hidden="true">
    <div class="modal-box sm" role="dialog" aria-modal="true" aria-labelledby="esoDeleteTitle">
        <form method="post">
            <div class="modal-header">
                <div class="modal-title"><div class="modal-ico"><i class="fas fa-trash"></i></div>
                    <span class="modal-title-text" id="esoDeleteTitle">Delete ESO</span></div>
                <button type="button" class="modal-close" data-eso-close aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= eso_e($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="esoDeleteId" value="0">
                <p class="mb-0" id="esoDeleteText"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-eso-close>Cancel</button>
                <button type="submit" class="btn btn-danger" id="esoDeleteSubmit"><i class="fas fa-trash me-1"></i> Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var FIELDS = <?= json_encode(array_merge(array_keys(ESO_FIELDS), ['services']), JSON_HEX_TAG) ?>;
    var REOPEN = <?= json_encode($formValues, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
    var $ = function (id) { return document.getElementById(id); };

    function open(id) {
        var m = $(id);
        if (window.Modal) { Modal.open(id); } else { m.classList.add('open'); }
        m.setAttribute('aria-hidden', 'false');
    }
    function close(m) {
        if (window.Modal) { Modal.close(m.id); } else { m.classList.remove('open'); }
        m.setAttribute('aria-hidden', 'true');
    }
    function fill(eso) {
        $('esoFormTitle').textContent = eso && eso.id ? 'Edit ESO' : 'Add ESO';
        $('esoAction').value = eso && eso.id ? 'update' : 'create';
        $('esoId').value = eso && eso.id ? eso.id : 0;
        FIELDS.forEach(function (f) { $('eso_' + f).value = eso && eso[f] ? eso[f] : ''; });
        $('eso_status').value = eso && eso.status === 'inactive' ? 'inactive' : 'active';
    }

    document.addEventListener('click', function (e) {
        var btn;
        if (e.target.closest('[data-eso-add]')) {
            fill(null);
            open('esoFormModal');
        } else if ((btn = e.target.closest('[data-eso-edit]'))) {
            fill(JSON.parse(btn.getAttribute('data-eso-edit')));
            open('esoFormModal');
        } else if ((btn = e.target.closest('[data-eso-delete]'))) {
            var eso = JSON.parse(btn.getAttribute('data-eso-delete'));
            $('esoDeleteId').value = eso.id;
            $('esoDeleteSubmit').disabled = eso.clients > 0;
            $('esoDeleteText').textContent = eso.clients > 0
                ? eso.name + ' referred ' + eso.clients + ' client(s), so it can\'t be deleted. Edit it and set Status to Inactive instead.'
                : 'Delete ' + eso.name + '? This can\'t be undone.';
            open('esoDeleteModal');
        } else {
            var modal = e.target.closest('.eso-modal');
            if (modal && (e.target === modal || e.target.closest('[data-eso-close]'))) {
                close(modal);
            }
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.eso-modal.open').forEach(close);
    });

    if (REOPEN) {
        fill(REOPEN);
        open('esoFormModal');
    }
})();
</script>

<?php require 'footer.php'; ?>
