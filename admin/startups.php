<?php
require "db.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || !in_array((int)$_SESSION['role'], [1, 2], true)) {
    $_SESSION['message'] = "Not authorized.";
    $_SESSION['message_type'] = "danger";
    header("Location: dashboard.php");
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$rows = [];

$sql = "
    SELECT
        s.*,
        u.id AS user_id,
        u.username,
        u.role,
        u.status AS user_status
    FROM startups s
    INNER JOIN user u ON s.startup_id = u.id
    WHERE u.role = 5
    ORDER BY s.id DESC
";

$res = $conn->query($sql);

if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
}

$total = count($rows);

$active = count(array_filter($rows, function ($r) {
    return strtolower((string)($r['startup_status'] ?? $r['status'] ?? '')) === 'active';
}));

$blocked = count(array_filter($rows, function ($r) {
    return (int)($r['user_status'] ?? 0) === 0;
}));

$inactive = max(0, $total - $active);

require "nav-head.php";
?>

<link rel="stylesheet" href="assets/css/startups.css">

<div class="container-fluid py-4">

    <?php
    $msgType = $_SESSION['message_type'] ?? 'info';
    $cssType = match ($msgType) {
        'danger' => 'su-alert-danger',
        'success' => 'su-alert-success',
        default => 'su-alert-info'
    };
    ?>

    <?php if (!empty($_SESSION['message'])): ?>
        <div class="su-alert <?= h($cssType) ?>" role="alert">
            <i class="fas fa-<?= $msgType === 'danger' ? 'exclamation-circle' : 'check-circle' ?>"></i>
            <?= h($_SESSION['message']) ?>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <div class="su-page-header">
        <div>
            <h1 class="su-page-title">
                <span class="su-title-icon">
                    <i class="fas fa-building"></i>
                </span>
                Startups
            </h1>
            <p class="su-page-subtitle">
                Manage registered startup companies and their accounts
            </p>
        </div>

        <button type="button" class="su-btn su-btn-primary" onclick="openCreateModal()">
            <i class="fas fa-plus"></i> Add Startup
        </button>
    </div>

    <div class="su-stats-row">
        <div class="su-stat-card">
            <div class="su-stat-icon blue">
                <i class="fas fa-building"></i>
            </div>
            <div>
                <div class="su-stat-value"><?= number_format($total) ?></div>
                <div class="su-stat-label">Total</div>
            </div>
        </div>

        <div class="su-stat-card">
            <div class="su-stat-icon green">
                <i class="fas fa-check-circle"></i>
            </div>
            <div>
                <div class="su-stat-value"><?= number_format($active) ?></div>
                <div class="su-stat-label">Active</div>
            </div>
        </div>

        <div class="su-stat-card">
            <div class="su-stat-icon red">
                <i class="fas fa-ban"></i>
            </div>
            <div>
                <div class="su-stat-value"><?= number_format($blocked) ?></div>
                <div class="su-stat-label">User Blocked</div>
            </div>
        </div>

        <div class="su-stat-card">
            <div class="su-stat-icon cyan">
                <i class="fas fa-pause-circle"></i>
            </div>
            <div>
                <div class="su-stat-value"><?= number_format($inactive) ?></div>
                <div class="su-stat-label">Inactive</div>
            </div>
        </div>
    </div>

    <div class="su-card">
        <div class="su-card-header">
            <span class="su-card-header-title">
                <i class="fas fa-table"></i>
                All Startups
                <span class="su-count-pill"><?= number_format($total) ?></span>
            </span>

            <div class="su-toolbar">
                <div class="su-search-wrap">
                    <i class="fas fa-search"></i>
                    <input
                        type="text"
                        class="su-search"
                        id="suSearch"
                        placeholder="Search startups..."
                        oninput="filterTable(this.value)">
                </div>
            </div>
        </div>

        <div class="su-table-wrap">
            <table class="su-table" id="suTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Company</th>
                        <th>CEO</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Username</th>
                        <th>User Status</th>
                        <th>Startup Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody id="suTbody">
                    <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $i => $row): ?>
                            <?php
                            $statusVal = strtolower((string)($row['startup_status'] ?? $row['status'] ?? 'inactive'));

                            $statusBadge = match ($statusVal) {
                                'active' => 'su-badge-success',
                                'blocked' => 'su-badge-danger',
                                default => 'su-badge-warn',
                            };

                            $userActive = (int)($row['user_status'] ?? 0) === 1;
                            ?>

                            <tr>
                                <td>
                                    <span class="su-row-num"><?= $i + 1 ?></span>
                                </td>

                                <td>
                                    <div class="su-company-cell">
                                        <div class="su-company-avatar">
                                            <?php if (!empty($row['company_logo'])): ?>
                                                <img src="<?= h($row['company_logo']) ?>" alt="">
                                            <?php else: ?>
                                                <i class="fas fa-building"></i>
                                            <?php endif; ?>
                                        </div>

                                        <div>
                                            <div class="su-company-name">
                                                <?= h($row['company_name'] ?? 'Unnamed Startup') ?>
                                            </div>

                                            <?php if (!empty($row['company_location'])): ?>
                                                <div class="su-company-loc">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <?= h($row['company_location']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span class="su-text-main"><?= h($row['ceo_name'] ?? '—') ?></span>
                                </td>

                                <td>
                                    <span class="su-mono"><?= h($row['phone'] ?? '—') ?></span>
                                </td>

                                <td>
                                    <span class="su-text-muted"><?= h($row['email'] ?? '—') ?></span>
                                </td>

                                <td>
                                    <span class="su-mono"><?= h($row['username'] ?? '—') ?></span>
                                </td>

                                <td>
                                    <span class="su-badge <?= $userActive ? 'su-badge-success' : 'su-badge-danger' ?>">
                                        <?= $userActive ? 'Active' : 'Blocked' ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="su-badge <?= h($statusBadge) ?>">
                                        <?= h(ucfirst($statusVal)) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="su-actions">
                                        <button
                                            type="button"
                                            class="su-btn su-btn-edit su-btn-icon"
                                            title="Edit"
                                            onclick='openEditModal(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                                            <i class="fas fa-pen"></i>
                                        </button>

                                        <a
                                            href="startup_profile.php?id=<?= (int)$row['id'] ?>"
                                            class="su-btn su-btn-view su-btn-icon"
                                            title="View Profile">
                                            <i class="fas fa-eye"></i>
                                        </a>

                                        <form
                                            method="post"
                                            action="includes/process_startup.php"
                                            class="d-inline"
                                            onsubmit="return confirm('Permanently delete this startup?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="startup_user_id" value="<?= (int)$row['startup_id'] ?>">

                                            <button type="submit" class="su-btn su-btn-delete su-btn-icon" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr id="suEmptyRow">
                            <td colspan="9">
                                <div class="su-empty">
                                    <div class="su-empty-icon">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <div class="su-empty-title">No startups found</div>
                                    <p>Add your first startup to get started.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="suNoResults" style="display:none;">
            <div class="su-empty" style="padding:36px 24px;">
                <div class="su-empty-icon">
                    <i class="fas fa-search"></i>
                </div>
                <div class="su-empty-title">No results match your search</div>
            </div>
        </div>
    </div>
</div>

<script>
function filterTable(query) {
    query = String(query || '').toLowerCase().trim();

    const rows = document.querySelectorAll('#suTbody tr');
    const noResults = document.getElementById('suNoResults');

    let visible = 0;

    rows.forEach(row => {
        const match = query === '' || row.innerText.toLowerCase().includes(query);
        row.style.display = match ? '' : 'none';

        if (match) {
            visible++;
        }
    });

    if (noResults) {
        noResults.style.display = visible === 0 ? 'block' : 'none';
    }
}

function startupFormHtml(mode = 'create', data = {}) {
    const isEdit = mode === 'update';

    return `
        <form class="ajax-form" method="post" action="includes/process_startup.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="${isEdit ? 'update' : 'create'}">
            <input type="hidden" name="id" value="${escapeHtml(data.id || '')}">
            <input type="hidden" name="startup_user_id" value="${escapeHtml(data.startup_id || data.user_id || '')}">

            <div class="su-form-section">
                <i class="fas fa-building"></i> Company Information
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">Company Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="company_name" required value="${escapeHtml(data.company_name || '')}">
                </div>

                <div class="form-group">
                    <label class="form-label">CEO Name</label>
                    <input type="text" class="form-control" name="ceo_name" value="${escapeHtml(data.ceo_name || '')}">
                </div>

                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone" value="${escapeHtml(data.phone || '')}">
                </div>

                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" value="${escapeHtml(data.email || '')}">
                </div>

                <div class="form-group">
                    <label class="form-label">Company Location</label>
                    <input type="text" class="form-control" name="company_location" value="${escapeHtml(data.company_location || '')}">
                </div>

                <div class="form-group">
                    <label class="form-label">Startup Status</label>
                    <select class="form-control" name="startup_status">
                        <option value="active" ${selected(data.startup_status || data.status, 'active')}>Active</option>
                        <option value="inactive" ${selected(data.startup_status || data.status, 'inactive')}>Inactive</option>
                        <option value="blocked" ${selected(data.startup_status || data.status, 'blocked')}>Blocked</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Company Logo</label>
                    <input type="file" class="form-control" name="company_logo" accept="image/*">
                </div>
            </div>

            <div class="su-form-section mt-4">
                <i class="fas fa-university"></i> Bank Information
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">Bank Name</label>
                    <input type="text" class="form-control" name="bank_name" value="${escapeHtml(data.bank_name || '')}">
                </div>

                <div class="form-group">
                    <label class="form-label">Account Name</label>
                    <input type="text" class="form-control" name="account_name" value="${escapeHtml(data.account_name || '')}">
                </div>

                <div class="form-group">
                    <label class="form-label">Account Number</label>
                    <input type="text" class="form-control" name="account_number" value="${escapeHtml(data.account_number || '')}">
                </div>

                <div class="form-group">
                    <label class="form-label">Bank Branch</label>
                    <input type="text" class="form-control" name="bank_branch" value="${escapeHtml(data.bank_branch || '')}">
                </div>
            </div>

            <div class="su-form-section mt-4">
                <i class="fas fa-user-shield"></i> Login Account
            </div>

            <div class="grid-3">
                <div class="form-group">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="username" required value="${escapeHtml(data.username || '')}">
                </div>

                <div class="form-group">
                    <label class="form-label">${isEdit ? 'New Password' : 'Password'}</label>
                    <input type="password" class="form-control" name="password" ${isEdit ? '' : 'required'} placeholder="${isEdit ? 'Leave blank to keep current' : 'Enter password'}">
                </div>

                <div class="form-group">
                    <label class="form-label">User Status</label>
                    <select class="form-control" name="user_status">
                        <option value="1" ${selected(String(data.user_status ?? '1'), '1')}>Active</option>
                        <option value="0" ${selected(String(data.user_status ?? '1'), '0')}>Blocked</option>
                    </select>
                </div>
            </div>

            <p class="su-hint mt-3">
                <i class="fas fa-info-circle"></i>
                On edit, leave password blank to keep the existing password.
            </p>

            <div class="d-flex justify-end gap-2 mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Startup
                </button>

                <button type="button" class="btn btn-ghost" onclick="Modal.closeAll()">
                    Cancel
                </button>
            </div>
        </form>
    `;
}

function openCreateModal() {
    ensureModalSystem();

    Modal.open('m-startup-form', {
        title: 'Add Startup',
        icon: 'plus-circle',
        size: 'lg'
    });

    Modal.setBody('m-startup-form', startupFormHtml('create', {}));
    bindStartupForm('m-startup-form');
}

function openEditModal(data) {
    ensureModalSystem();

    if (typeof data === 'string') {
        try {
            data = JSON.parse(data);
        } catch (error) {
            alert('Invalid startup data.');
            return;
        }
    }

    Modal.open('m-startup-form', {
        title: 'Edit Startup',
        icon: 'pen',
        size: 'lg'
    });

    Modal.setBody('m-startup-form', startupFormHtml('update', data || {}));
    bindStartupForm('m-startup-form');
}

function bindStartupForm(modalId) {
    const body = document.getElementById(modalId + '-body');

    if (!body) return;

    const form = body.querySelector('form');

    if (!form || form.dataset.bound === '1') return;

    form.dataset.bound = '1';

    form.addEventListener('submit', async function(event) {
        event.preventDefault();

        const button = form.querySelector('[type="submit"]');
        const oldHtml = button ? button.innerHTML : '';

        try {
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
            }

            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const text = await response.text();
            let data;

            try {
                data = JSON.parse(text);
            } catch (error) {
                if (response.ok) {
                    Toast.success('Startup saved successfully.');
                    setTimeout(() => window.location.reload(), 700);
                    return;
                }

                throw new Error(text || 'Invalid server response.');
            }

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Save failed.');
            }

            Toast.success(data.message || 'Startup saved successfully.');
            setTimeout(() => window.location.reload(), 700);

        } catch (error) {
            console.error(error);
            Toast.error(error.message || 'Request failed.');
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = oldHtml;
            }
        }
    });
}

function ensureModalSystem() {
    if (typeof Modal === 'undefined' || typeof Toast === 'undefined') {
        alert('loanpro.js modal system is not loaded. Check footer script path: ../assets/js/loanpro.js');
        throw new Error('loanpro.js modal system missing.');
    }
}

function selected(current, value) {
    return String(current || '').toLowerCase() === String(value).toLowerCase()
        ? 'selected'
        : '';
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

<?php require "footer.php"; ?>