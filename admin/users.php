<?php
require "nav-head.php";

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function role_name($role) {
    return ((int)$role === 1) ? 'Admin' : (((int)$role === 2) ? 'Loan Officer' : 'Unknown Role');
}

$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$where = "WHERE role IN (1,2)";
$params = [];
$types = "";

if ($search !== "") {
    $where .= " AND (fname LIKE ? OR lname LIKE ? OR email LIKE ? OR username LIKE ?)";
    $like = "%{$search}%";
    $params = [$like, $like, $like, $like];
    $types = "ssss";
}

$countSql = "SELECT COUNT(*) AS total FROM user $where";
$countStmt = $conn->prepare($countSql);

if ($params) {
    $countStmt->bind_param($types, ...$params);
}

$countStmt->execute();
$totalRows = (int)$countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));

$sql = "
    SELECT id, fname, lname, username, email, role, status, datetime
    FROM user
    $where
    ORDER BY id DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if ($params) {
    $bindTypes = $types . "ii";
    $bindValues = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($bindTypes, ...$bindValues);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stats = $conn->query("
    SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS active_users,
        SUM(CASE WHEN status != 1 THEN 1 ELSE 0 END) AS blocked_users,
        SUM(CASE WHEN role = 1 THEN 1 ELSE 0 END) AS admins
    FROM user
    WHERE role IN (1,2)
")->fetch_assoc();
?>

<div class="page-header">
    <div>
        <div class="title">System Users</div>
        <div class="sub"><?= number_format($totalRows) ?> system users found</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-primary" onclick="openAddUserModal()">
            <i class="fas fa-plus-circle"></i> Add New User
        </button>

        <button type="button" class="btn btn-ghost btn-sm" onclick="download_table_as_csv('report','system_users.csv')">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
    </div>
</div>

<div class="stats-grid clients-stats-grid">
    <div class="stat-card c-blue">
        <div class="stat-head">
            <div class="stat-ico c-blue"><i class="fas fa-users-cog"></i></div>
        </div>
        <div class="stat-value sm"><?= number_format((int)($stats['total_users'] ?? 0)) ?></div>
        <div class="stat-label">Total Users</div>
    </div>

    <div class="stat-card c-green">
        <div class="stat-head">
            <div class="stat-ico c-green"><i class="fas fa-user-check"></i></div>
        </div>
        <div class="stat-value sm"><?= number_format((int)($stats['active_users'] ?? 0)) ?></div>
        <div class="stat-label">Active Users</div>
    </div>

    <div class="stat-card c-red">
        <div class="stat-head">
            <div class="stat-ico c-red"><i class="fas fa-user-slash"></i></div>
        </div>
        <div class="stat-value sm"><?= number_format((int)($stats['blocked_users'] ?? 0)) ?></div>
        <div class="stat-label">Blocked Users</div>
    </div>

    <div class="stat-card c-indigo">
        <div class="stat-head">
            <div class="stat-ico c-indigo"><i class="fas fa-user-shield"></i></div>
        </div>
        <div class="stat-value sm"><?= number_format((int)($stats['admins'] ?? 0)) ?></div>
        <div class="stat-label">Administrators</div>
    </div>
</div>

<div class="card clients-card">
    <div class="card-header clients-toolbar">
        <form method="GET" class="clients-search-form">
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-search"></i></span>

                <input type="text"
                       name="q"
                       class="form-control"
                       value="<?= e($search) ?>"
                       placeholder="Search name, username, or email...">

                <button type="submit" class="btn btn-primary">Search</button>

                <?php if ($search !== ""): ?>
                    <a href="users.php" class="btn btn-light">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card-body no-pad">
        <div class="table-responsive client-table-scroll">
            <table class="table clients-table" id="report">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User Name</th>
                        <th>Username</th>
                        <th>User Role</th>
                        <th>Email</th>
                        <th>Added On</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($users): ?>
                        <?php $i = $offset + 1; foreach ($users as $u): ?>
                            <?php
                            $userId = (int)$u['id'];
                            $fullName = trim(($u['fname'] ?? '') . ' ' . ($u['lname'] ?? ''));
                            $initials = strtoupper(substr($u['fname'] ?? '', 0, 1) . substr($u['lname'] ?? '', 0, 1));
                            $isActive = (int)$u['status'] === 1;
                            ?>
                            <tr id="user-row-<?= $userId ?>">
                                <td><?= $i++ ?></td>

                                <td>
                                    <div class="cell-user">
                                        <div class="cell-av"><?= e($initials ?: 'US') ?></div>
                                        <div>
                                            <div class="cell-name"><?= e($fullName ?: 'Unnamed User') ?></div>
                                            <div class="cell-sub">ID: <?= $userId ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="td-dark"><?= e($u['username'] ?? '-') ?></td>

                                <td><span class="badge badge-primary"><?= e(role_name($u['role'])) ?></span></td>

                                <td><?= e($u['email'] ?? '-') ?></td>

                                <td><?= e($u['datetime'] ?? '-') ?></td>

                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Blocked</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-info btn-icon-sm" onclick="openViewUserModal(<?= $userId ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <button type="button" class="btn btn-warning btn-icon-sm" onclick="openEditUserModal(<?= $userId ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <button type="button" class="btn <?= $isActive ? 'btn-danger' : 'btn-success' ?> btn-icon-sm" onclick="toggleUserStatus(<?= $userId ?>)">
                                            <i class="fas <?= $isActive ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                                        </button>

                                        <button type="button" class="btn btn-danger btn-icon-sm" onclick="deleteUserRecord(<?= $userId ?>)">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <i class="fas fa-users-cog"></i>
                                    <h3>No users found</h3>
                                    <p>No matching system users were found.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const USER_PROCESS_URL = 'includes/process-users.php';

async function getHtml(url) {
    const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    });

    return await response.text();
}

async function postJson(url, body) {
    let options = {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    };

    if (body instanceof FormData) {
        options.body = body;
    } else {
        options.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        options.body = new URLSearchParams(body).toString();
    }

    const response = await fetch(url, options);
    const text = await response.text();

    try {
        return JSON.parse(text);
    } catch (e) {
        console.error('Invalid JSON response:', text);
        throw new Error('Server returned HTML instead of JSON. Check process-users.php path or PHP error.');
    }
}

async function openUserModal(url, modalId, title, icon) {
    Modal.open(modalId, {title: title, icon: icon, size: 'md'});
    Modal.loading(modalId);

    try {
        const html = await getHtml(url);
        Modal.setBody(modalId, html);
        bindUserAjaxForms(modalId);
    } catch (error) {
        Modal.setBody(modalId, `<div class="alert alert-danger">${error.message}</div>`);
    }
}

function openAddUserModal() {
    openUserModal(USER_PROCESS_URL + '?action=add_form', 'm-add-user', 'Add New User', 'user-plus');
}

function openViewUserModal(userId) {
    openUserModal(USER_PROCESS_URL + '?action=view&user_id=' + encodeURIComponent(userId), 'm-view-user', 'User Profile', 'user');
}

function openEditUserModal(userId) {
    openUserModal(USER_PROCESS_URL + '?action=edit_form&user_id=' + encodeURIComponent(userId), 'm-edit-user', 'Edit User', 'user-edit');
}

async function toggleUserStatus(userId) {
    if (!confirm('Change this user status?')) return;

    try {
        const data = await postJson(USER_PROCESS_URL, {
            action: 'toggle_status',
            user_id: userId
        });

        if (!data.success) throw new Error(data.message || 'Status update failed.');

        Toast.success(data.message);
        setTimeout(() => window.location.reload(), 700);
    } catch (error) {
        Toast.error(error.message);
    }
}

async function deleteUserRecord(userId) {
    if (!confirm('Delete this user?')) return;

    try {
        const data = await postJson(USER_PROCESS_URL, {
            action: 'delete',
            user_id: userId
        });

        if (!data.success) throw new Error(data.message || 'Delete failed.');

        Toast.success(data.message);
        setTimeout(() => window.location.reload(), 700);
    } catch (error) {
        Toast.error(error.message);
    }
}

function bindUserAjaxForms(modalId) {
    const body = document.getElementById(modalId + '-body');
    if (!body) return;

    const forms = body.querySelectorAll('form[data-ajax="true"]');

    forms.forEach(form => {
        if (form.dataset.bound === '1') return;
        form.dataset.bound = '1';

        form.addEventListener('submit', async function(event) {
            event.preventDefault();

            const btn = form.querySelector('[type="submit"]');
            const oldHtml = btn ? btn.innerHTML : '';

            try {
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
                }

                const formData = new FormData(form);
                const data = await postJson(USER_PROCESS_URL, formData);

                if (!data.success) throw new Error(data.message || 'Save failed.');

                Toast.success(data.message);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                Toast.error(error.message);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                }
            }
        });
    });
}
</script>

<?php require "footer.php"; ?>