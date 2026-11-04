<?php
$phead = "STAFF MEMBERS";
require "nav-head.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$limit  = 10;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$search = trim($_GET['q'] ?? '');

$where = "";
$params = [];
$types = "";

if ($search !== "") {
    $where = "
        WHERE 
            user.fname LIKE ? OR
            user.lname LIKE ? OR
            user.email LIKE ? OR
            members.phone LIKE ? OR
            members.home_address LIKE ? OR
            members.employer LIKE ? OR
            members.employment_status LIKE ? OR
            members.bank_name LIKE ? OR
            members.bank_account LIKE ?
    ";

    $like = "%{$search}%";
    $params = [$like, $like, $like, $like, $like, $like, $like, $like, $like];
    $types = "sssssssss";
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM members
    INNER JOIN user ON members.user_id = user.id
    $where
";

$countStmt = $conn->prepare($countSql);

if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}

$countStmt->execute();
$totalRows = (int)$countStmt->get_result()->fetch_assoc()['total'];
$totalPages = max(1, (int)ceil($totalRows / $limit));

$sql = "
    SELECT 
        user.id AS user_table_id,
        user.fname,
        user.lname,
        user.email,
        user.status,
        members.*
    FROM members
    INNER JOIN user ON members.user_id = user.id
    $where
    ORDER BY members.id DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $bindTypes = $types . "ii";
    $bindValues = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($bindTypes, ...$bindValues);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$staff = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header">
    <div>
        <div class="title"><?= e($phead) ?></div>
        <div class="sub"><?= number_format($totalRows) ?> registered staff members</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-ghost btn-sm" onclick="download_table_as_csv('report','staff_members.csv')">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
    </div>
</div>

<div class="card clients-card">
    <div class="card-header clients-toolbar">
        <form method="GET" class="clients-search-form">
            <div class="input-group">
                <span class="input-group-text">
                    <i class="fas fa-search"></i>
                </span>

                <input
                    type="text"
                    name="q"
                    class="form-control"
                    value="<?= e($search) ?>"
                    placeholder="Search staff name, phone, email, employer, bank..."
                >

                <button class="btn btn-primary" type="submit">
                    Search
                </button>

                <?php if ($search !== ""): ?>
                    <a href="<?= e(basename($_SERVER['PHP_SELF'])) ?>" class="btn btn-light">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card-body no-pad">
        <div class="table-responsive client-table-scroll">
            <table class="table data-table clients-table" id="report">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Staff Name</th>
                        <th>Home Address</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Employer</th>
                        <th>Employment</th>
                        <th>Bank Name</th>
                        <th>Bank Acc</th>
                        <th>Loan Limit</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!empty($staff)): ?>
                        <?php $counter = $offset + 1; foreach ($staff as $row): ?>
                            <?php
                            $userId = (int)$row['user_id'];
                            $fullName = trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
                            $initials = strtoupper(substr($row['fname'] ?? '', 0, 1) . substr($row['lname'] ?? '', 0, 1));
                            ?>
                            <tr id="staff-row-<?= $userId ?>">
                                <td><?= $counter++ ?></td>

                                <td>
                                    <div class="cell-user">
                                        <div class="cell-av">
                                            <?= e($initials ?: 'ST') ?>
                                        </div>

                                        <div>
                                            <div class="cell-name"><?= e($fullName ?: 'Unnamed Staff') ?></div>
                                            <div class="cell-sub">ID: <?= e($userId) ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="text-muted fs-sm text-truncate-cell">
                                    <?= e($row['home_address'] ?? '—') ?>
                                </td>

                                <td class="text-muted fs-sm">
                                    <?= e($row['phone'] ?? '—') ?>
                                </td>

                                <td class="text-muted fs-sm">
                                    <?= e($row['email'] ?? '—') ?>
                                </td>

                                <td class="td-dark text-truncate-cell">
                                    <?= e($row['employer'] ?? '—') ?>
                                </td>

                                <td>
                                    <span class="badge badge-muted">
                                        <?= e($row['employment_status'] ?? '—') ?>
                                    </span>
                                </td>

                                <td class="td-dark text-truncate-cell">
                                    <?= e($row['bank_name'] ?? '—') ?>
                                </td>

                                <td class="text-muted fs-sm">
                                    <?= e($row['bank_account'] ?? '—') ?>
                                </td>

                                <td class="td-dark">
                                    UGX <?= number_format((float)($row['loan_limit'] ?? 0)) ?>
                                </td>

                                <td>
                                    <?php if ((int)$row['status'] === 1): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Blocked</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-info btn-icon-sm" onclick="viewStaffModal(<?= $userId ?>)" title="View Staff">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <button type="button" class="btn btn-primary btn-icon-sm" onclick="staffLoanHistoryModal(<?= $userId ?>)" title="Loan History">
                                            <i class="fas fa-file-invoice-dollar"></i>
                                        </button>

                                        <button type="button" class="btn btn-warning btn-icon-sm" onclick="editStaffModal(<?= $userId ?>)" title="Edit Staff">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <button type="button" class="btn btn-danger btn-icon-sm" onclick="deleteStaffModal(<?= $userId ?>)" title="Delete Staff">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12">
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <h3>No staff members found</h3>
                                    <p>No matching staff records were found.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-footer clients-pagination">
        <div class="pagination-info">
            Page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination mb-0">
                    <?php $query = $search !== "" ? '&q=' . urlencode($search) : ''; ?>

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= max(1, $page - 1) . $query ?>">Previous</a>
                    </li>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    ?>

                    <?php for ($p = $start; $p <= $end; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p . $query ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= min($totalPages, $page + 1) . $query ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<script>
function ensureStaffPopup() {
    if (document.getElementById('staff-custom-popup')) {
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.id = 'staff-custom-popup';
    wrapper.innerHTML = `
        <div class="staff-popup-backdrop" data-popup-close="1"></div>
        <div class="staff-popup-dialog" role="dialog" aria-modal="true" aria-labelledby="staff-popup-title">
            <div class="staff-popup-icon" id="staff-popup-icon">
                <i class="fas fa-check"></i>
            </div>

            <div class="staff-popup-content">
                <h3 id="staff-popup-title">Message</h3>
                <p id="staff-popup-message"></p>
            </div>

            <div class="staff-popup-actions" id="staff-popup-actions">
                <button type="button" class="btn btn-primary" id="staff-popup-ok">
                    OK
                </button>
            </div>
        </div>
    `;

    document.body.appendChild(wrapper);

    if (!document.getElementById('staff-popup-styles')) {
        const style = document.createElement('style');
        style.id = 'staff-popup-styles';
        style.textContent = `
            #staff-custom-popup {
                position: fixed;
                inset: 0;
                z-index: 99999;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            #staff-custom-popup.is-open {
                display: flex;
            }

            .staff-popup-backdrop {
                position: absolute;
                inset: 0;
                background: rgba(15, 23, 42, .58);
                backdrop-filter: blur(3px);
            }

            .staff-popup-dialog {
                position: relative;
                width: min(430px, 100%);
                background: #fff;
                border-radius: 18px;
                box-shadow: 0 24px 70px rgba(15, 23, 42, .25);
                padding: 28px;
                text-align: center;
                animation: staffPopupIn .18s ease-out;
            }

            .staff-popup-icon {
                width: 62px;
                height: 62px;
                margin: 0 auto 16px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 26px;
                background: #dcfce7;
                color: #15803d;
            }

            .staff-popup-icon.error {
                background: #fee2e2;
                color: #b91c1c;
            }

            .staff-popup-icon.warning {
                background: #fef3c7;
                color: #b45309;
            }

            .staff-popup-content h3 {
                margin: 0 0 8px;
                font-size: 21px;
                font-weight: 700;
                color: #0f172a;
            }

            .staff-popup-content p {
                margin: 0;
                color: #64748b;
                line-height: 1.6;
            }

            .staff-popup-actions {
                margin-top: 22px;
                display: flex;
                justify-content: center;
                gap: 10px;
                flex-wrap: wrap;
            }

            .staff-popup-actions .btn {
                min-width: 110px;
            }

            @keyframes staffPopupIn {
                from {
                    opacity: 0;
                    transform: translateY(8px) scale(.98);
                }
                to {
                    opacity: 1;
                    transform: translateY(0) scale(1);
                }
            }
        `;

        document.head.appendChild(style);
    }
}

function closeStaffPopup() {
    const popup = document.getElementById('staff-custom-popup');
    if (popup) {
        popup.classList.remove('is-open');
    }
}

function staffPopup(type, title, message, options = {}) {
    ensureStaffPopup();

    return new Promise(resolve => {
        const popup = document.getElementById('staff-custom-popup');
        const iconBox = document.getElementById('staff-popup-icon');
        const icon = iconBox.querySelector('i');
        const titleEl = document.getElementById('staff-popup-title');
        const messageEl = document.getElementById('staff-popup-message');
        const actions = document.getElementById('staff-popup-actions');

        iconBox.className = 'staff-popup-icon';
        icon.className = 'fas fa-check';

        if (type === 'error') {
            iconBox.classList.add('error');
            icon.className = 'fas fa-times';
        } else if (type === 'warning' || type === 'confirm') {
            iconBox.classList.add('warning');
            icon.className = 'fas fa-exclamation';
        }

        titleEl.textContent = title || 'Message';
        messageEl.textContent = message || '';

        const finish = value => {
            closeStaffPopup();
            resolve(value);
        };

        if (type === 'confirm') {
            actions.innerHTML = `
                <button type="button" class="btn btn-light" id="staff-popup-cancel">
                    Cancel
                </button>
                <button type="button" class="btn btn-danger" id="staff-popup-confirm">
                    ${options.confirmText || 'Continue'}
                </button>
            `;

            document.getElementById('staff-popup-cancel').onclick = () => finish(false);
            document.getElementById('staff-popup-confirm').onclick = () => finish(true);
        } else {
            actions.innerHTML = `
                <button type="button" class="btn btn-primary" id="staff-popup-ok">
                    ${options.okText || 'OK'}
                </button>
            `;

            document.getElementById('staff-popup-ok').onclick = () => finish(true);
        }

        popup.classList.add('is-open');
    });
}


async function staffPost(url, payload) {
    let options = {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    };

    if (payload instanceof FormData) {
        options.body = payload;
    } else {
        const formData = new FormData();

        Object.entries(payload || {}).forEach(([key, value]) => {
            formData.append(key, value ?? '');
        });

        options.body = formData;
    }

    const response = await fetch(url, options);
    const responseText = (await response.text()).trim();

    let data = null;

    try {
        data = JSON.parse(responseText);
    } catch (parseError) {
        const firstBrace = responseText.indexOf('{');
        const lastBrace = responseText.lastIndexOf('}');

        if (firstBrace !== -1 && lastBrace > firstBrace) {
            const possibleJson = responseText.slice(firstBrace, lastBrace + 1);

            try {
                data = JSON.parse(possibleJson);
                console.warn(
                    'The server returned extra output around its JSON response.',
                    responseText
                );
            } catch (ignored) {
                data = null;
            }
        }

        if (!data) {
            console.error('Non-JSON response from ' + url, responseText);

            if (response.status === 401 || response.status === 403) {
                throw new Error(
                    'Your session may have expired. Refresh the page and sign in again.'
                );
            }

            if (response.status >= 500) {
                throw new Error(
                    'The server encountered an error while saving. Please check the PHP error log.'
                );
            }

            if (
                /<!doctype/i.test(responseText) ||
                /<html/i.test(responseText) ||
                /<body/i.test(responseText)
            ) {
                throw new Error(
                    'The save request was redirected to an HTML page instead of the AJAX endpoint.'
                );
            }

            throw new Error(
                'The server did not return a valid save response. Please check the PHP error log.'
            );
        }
    }

    if (!data || typeof data !== 'object') {
        throw new Error('The server returned an invalid response.');
    }

    if (!response.ok || data.success === false) {
        throw new Error(data.message || 'The server could not complete the request.');
    }

    return data;
}

function staffFriendlyError(error, fallback = 'The request could not be completed.') {
    const message = String(error && error.message ? error.message : '').trim();

    if (!message) {
        return fallback;
    }

    // Never place a returned HTML document/source code in a popup.
    if (
        /<!doctype/i.test(message) ||
        /<html/i.test(message) ||
        /<body/i.test(message) ||
        /<div/i.test(message) ||
        /<a\s/i.test(message) ||
        /class\s*=/i.test(message) ||
        message.length > 350
    ) {
        return fallback;
    }

    return message;
}

async function staffAjaxGet(url, modalId, title, icon = 'user', size = 'lg') {
    Modal.open(modalId, { title, icon, size });
    Modal.loading(modalId);

    try {
        const html = await Ajax.get(url);
        Modal.setBody(modalId, html);
        bindStaffForms(modalId);
    } catch (error) {
        console.error(error);

        Modal.setBody(modalId, '');

        await staffPopup(
            'error',
            'Unable to Load',
            staffFriendlyError(
                error,
                'The requested staff information could not be loaded. Please refresh the page and try again.'
            )
        );
    }
}

function viewStaffModal(userId) {
    staffAjaxGet(
        'ajax/process-staff.php?action=view&user_id=' + encodeURIComponent(userId),
        'm-view-staff',
        'Staff Profile',
        'user'
    );
}

function editStaffModal(userId) {
    staffAjaxGet(
        'ajax/process-staff.php?action=edit&user_id=' + encodeURIComponent(userId),
        'm-edit-staff',
        'Edit Staff',
        'user-edit'
    );
}

function staffLoanHistoryModal(userId) {
    staffAjaxGet(
        'ajax/process-staff.php?action=loans&user_id=' + encodeURIComponent(userId),
        'm-staff-loans',
        'Staff Loan History',
        'file-invoice-dollar'
    );
}

async function deleteStaffModal(userId) {
    const confirmed = await staffPopup(
        'confirm',
        'Delete Staff Member?',
        'This action will permanently remove this staff member. Staff with active loans cannot be deleted.',
        { confirmText: 'Delete Staff' }
    );

    if (!confirmed) {
        return;
    }

    try {
        const response = await staffPost('ajax/process-staff.php', {
            action: 'delete',
            user_id: userId
        });

        if (!response.success) {
            throw new Error(response.message || 'Delete failed.');
        }

        const row = document.getElementById('staff-row-' + userId);

        if (row) {
            row.remove();
        }

        await staffPopup(
            'success',
            'Staff Deleted',
            response.message || 'Staff member deleted successfully.'
        );
    } catch (error) {
        console.error(error);

        await staffPopup(
            'error',
            'Unable to Delete Staff',
            staffFriendlyError(
                error,
                'The staff member could not be deleted. Please try again.'
            )
        );
    }
}

function bindStaffForms(modalId) {
    const body = document.getElementById(modalId + '-body');
    if (!body) return;

    const passwordButtons = body.querySelectorAll('[data-password-toggle]');

    passwordButtons.forEach(button => {
        if (button.dataset.toggleBound === '1') return;

        button.dataset.toggleBound = '1';

        button.addEventListener('click', function() {
            const inputId = button.getAttribute('data-password-toggle');
            const input = document.getElementById(inputId);

            if (!input) return;

            const icon = button.querySelector('i');
            const showing = input.type === 'text';

            input.type = showing ? 'password' : 'text';

            if (icon) {
                icon.classList.toggle('fa-eye', showing);
                icon.classList.toggle('fa-eye-slash', !showing);
            }

            button.setAttribute(
                'title',
                showing ? 'Show password' : 'Hide password'
            );

            button.setAttribute(
                'aria-label',
                showing ? 'Show password' : 'Hide password'
            );
        });
    });

    const forms = body.querySelectorAll('form[data-ajax="true"], form.ajax-form');

    forms.forEach(form => {
        if (form.dataset.bound === '1') return;

        form.dataset.bound = '1';

        form.addEventListener('submit', async function(event) {
            event.preventDefault();

            const btn = form.querySelector('[type="submit"]');
            const submitUrl = form.getAttribute('action') || 'ajax/process-staff.php';
            const oldText = btn ? btn.innerHTML : '';

            try {
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
                }

                const data = await staffPost(submitUrl, new FormData(form));

                if (!data.success) {
                    throw new Error(data.message || 'Save failed.');
                }

                await staffPopup(
                    'success',
                    'Changes Saved',
                    data.message || 'Staff details saved successfully.'
                );

                window.location.reload();

            } catch (error) {
                console.error(error);

                await staffPopup(
                    'error',
                    'Unable to Save Changes',
                    staffFriendlyError(
                        error,
                        'The changes could not be saved. Please refresh the page and try again.'
                    )
                );
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = oldText;
                }
            }
        });
    });
}
</script>

<?php require "footer.php"; ?>