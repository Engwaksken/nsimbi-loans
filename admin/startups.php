<?php
require "db.php";
require_once dirname(__DIR__) . '/includes/officer_assignment.php';

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
    WHERE u.role = 5" . officer_client_sql('s') . "
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

/* ============================================================
   CSV / PDF EXPORT
   The search box filters the table in the browser; the export links send
   the same text as ?q= so the file has the startups that are on screen.
============================================================ */
$export = (string)($_GET['export'] ?? '');
if ($export === 'csv' || $export === 'pdf') {
    $q = trim((string)($_GET['q'] ?? ''));
    $exportRows = array_values(array_filter($rows, static function (array $r) use ($q): bool {
        if ($q === '') {
            return true;
        }
        $haystack = implode(' ', [
            $r['company_name'] ?? '', $r['ceo_name'] ?? '', $r['phone'] ?? '', $r['email'] ?? '',
            $r['username'] ?? '', $r['company_location'] ?? '', $r['bank_name'] ?? '', $r['account_number'] ?? '',
            // The on-screen search also matches the status badges.
            (int)($r['user_status'] ?? 0) === 1 ? 'Active' : 'Blocked', $r['startup_status'] ?? $r['status'] ?? 'inactive',
        ]);
        return mb_stripos($haystack, $q, 0, 'UTF-8') !== false;
    }));
    $startupStatus = static fn(array $r): string => ucfirst(strtolower((string)($r['startup_status'] ?? $r['status'] ?? 'inactive')));
    $userStatus = static fn(array $r): string => (int)($r['user_status'] ?? 0) === 1 ? 'Active' : 'Blocked';
    $stamp = date('Ymd_His');

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="Startups_' . $stamp . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the file as UTF-8
        fputcsv($out, ['#', 'Company', 'CEO', 'Phone', 'Email', 'Username', 'Location', 'Bank', 'Account Name',
            'Account Number', 'Bank Branch', 'User Status', 'Startup Status']);
        foreach ($exportRows as $i => $r) {
            $line = [$i + 1, $r['company_name'] ?? '', $r['ceo_name'] ?? '', $r['phone'] ?? '', $r['email'] ?? '',
                $r['username'] ?? '', $r['company_location'] ?? '', $r['bank_name'] ?? '', $r['account_name'] ?? '',
                $r['account_number'] ?? '', $r['bank_branch'] ?? '', $userStatus($r), $startupStatus($r)];
            // Stop spreadsheet apps treating a value as a formula (phone numbers like +256... are left alone).
            $line = array_map(static fn($v) => is_string($v) && preg_match('/^(=|@|[+\-](?![\d\s()]+$))/', $v) ? "'" . $v : $v, $line);
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    require_once __DIR__ . '/vendor/autoload.php';
    date_default_timezone_set('Africa/Kampala');

    $eTotal = count($exportRows);
    $eActive = count(array_filter($exportRows, static fn($r) => $startupStatus($r) === 'Active'));
    $eBlocked = count(array_filter($exportRows, static fn($r) => (int)($r['user_status'] ?? 0) === 0));

    ob_start();
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 16mm 10mm; }
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8.5px; color: #1f2937; }
            h1 { color: #123299; margin: 0 0 6px; font-size: 17px; }
            .meta { color: #64748b; margin-bottom: 8px; }
            table { width: 100%; border-collapse: collapse; }
            .summary td { border: 1px solid #e5e7eb; background: #f8fafc; padding: 7px; width: 25%; }
            .label { color: #64748b; font-size: 7px; text-transform: uppercase; }
            .value { font-size: 11px; font-weight: bold; color: #111827; }
            .rpt th { background: #123299; color: #fff; padding: 5px 4px; text-align: left; font-size: 7.5px; }
            .rpt td { border-bottom: 1px solid #e5e7eb; padding: 4px; vertical-align: top; }
            .rpt tr:nth-child(even) td { background: #f8fafc; }
        </style>
    </head>
    <body>
    <h1>Startups</h1>
    <div class="meta">
        Generated: <?= h(date('F d, Y H:i')) ?> &middot; <?= number_format($eTotal) ?> startup(s)
        <?php if ($q !== ''): ?><br>Search: "<?= h($q) ?>"<?php endif; ?>
    </div>

    <table class="summary">
        <tr>
            <td><div class="label"><?= $q !== '' ? 'Matching' : 'Total' ?></div><div class="value"><?= number_format($eTotal) ?></div></td>
            <td><div class="label">Active</div><div class="value"><?= number_format($eActive) ?></div></td>
            <td><div class="label">User Blocked</div><div class="value"><?= number_format($eBlocked) ?></div></td>
            <td><div class="label">Inactive</div><div class="value"><?= number_format(max(0, $eTotal - $eActive)) ?></div></td>
        </tr>
    </table>
    <br>

    <table class="rpt">
        <thead>
        <tr>
            <th>#</th><th>Company</th><th>CEO</th><th>Phone</th><th>Email</th><th>Username</th>
            <th>Location</th><th>Bank</th><th>Account No.</th><th>User Status</th><th>Startup Status</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($exportRows): ?>
            <?php foreach ($exportRows as $i => $r): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= h($r['company_name'] ?? 'Unnamed Startup') ?></td>
                    <td><?= h($r['ceo_name'] ?? '') ?></td>
                    <td><?= h($r['phone'] ?? '') ?></td>
                    <td><?= h($r['email'] ?? '') ?></td>
                    <td><?= h($r['username'] ?? '') ?></td>
                    <td><?= h($r['company_location'] ?? '') ?></td>
                    <td><?= h($r['bank_name'] ?? '') ?></td>
                    <td><?= h($r['account_number'] ?? '') ?></td>
                    <td><?= h($userStatus($r)) ?></td>
                    <td><?= h($startupStatus($r)) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="11" style="text-align:center;padding:15px">No startups found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    require_once dirname(__DIR__) . '/includes/pdf_brand.php';
    $dompdf->loadHtml(pdf_brand_html($conn, $html));
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    pdf_brand_footer($dompdf, $conn);
    $dompdf->stream('Startups_' . $stamp . '.pdf', ['Attachment' => false]);
    exit;
}

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

                <a href="startups.php?export=csv" class="su-btn su-btn-light" id="suExportCsv" title="Download the startups shown as CSV">
                    <i class="fas fa-file-csv"></i> CSV
                </a>

                <a href="startups.php?export=pdf" class="su-btn su-btn-danger" id="suExportPdf" target="_blank" rel="noopener" title="Open the startups shown as PDF">
                    <i class="fas fa-file-pdf"></i> PDF
                </a>
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
                                    <span class="su-text-main"><?= h($row['ceo_name'] ?? 'N/A') ?></span>
                                </td>

                                <td>
                                    <span class="su-mono"><?= h($row['phone'] ?? 'N/A') ?></span>
                                </td>

                                <td>
                                    <span class="su-text-muted"><?= h($row['email'] ?? 'N/A') ?></span>
                                </td>

                                <td>
                                    <span class="su-mono"><?= h($row['username'] ?? 'N/A') ?></span>
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
    const exportQuery = String(query || '').trim();
    ['csv', 'pdf'].forEach(type => {
        const link = document.getElementById(type === 'csv' ? 'suExportCsv' : 'suExportPdf');
        if (link) {
            link.href = 'startups.php?export=' + type + (exportQuery ? '&q=' + encodeURIComponent(exportQuery) : '');
        }
    });

    query = exportQuery.toLowerCase();

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