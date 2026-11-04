<?php


require 'nav-head.php';   


if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit('Forbidden');
}


function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function json_ok(string $msg, array $extra = []): never {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true, 'message' => $msg], $extra));
    exit;
}

function json_err(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

function post(string $key, string $default = ''): string {
    return trim($_POST[$key] ?? $default);
}

function get_param(string $key, string $default = ''): string {
    return trim($_GET[$key] ?? $default);
}

/* -- Upload config --------------------------------------------- */
define('UPLOAD_DIR',      'uploads/clients/');
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);  /* 5 MB */
define('ALLOWED_MIME', [
    'image/jpeg', 'image/png', 'image/webp',
    'application/pdf',
]);

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}


function handle_upload(string $field, string $existing = ''): string {
    if (empty($_FILES[$field]['tmp_name'])) return $existing;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK)  return $existing;

    $tmp  = $_FILES[$field]['tmp_name'];
    $mime = mime_content_type($tmp);

    if (!in_array($mime, ALLOWED_MIME, true))
        json_err("File type not allowed for field '{$field}'.");

    if ($_FILES[$field]['size'] > MAX_UPLOAD_BYTES)
        json_err("File too large for field '{$field}' (max 5 MB).");

    $ext  = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION);
    $name = UPLOAD_DIR . $field . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);

    if (!move_uploaded_file($tmp, $name))
        json_err("Failed to save uploaded file for field '{$field}'.");

    return $name;
}

/** Fetch full client row (customers JOIN user) or null. */
function fetch_client(mysqli $conn, int $user_id): ?array {
    $sql = "
        SELECT
            u.id, u.fname, u.lname, u.email, u.status,
            c.gender, c.dob, c.phone, c.bank_name, c.bank_account,
            c.business_name, c.business_type, c.business_period,
            c.amount_last, c.amount_this,
            c.district, c.location,
            c.nok_name, c.nok_tel, c.nok_rel,
            c.nin,
            c.loan_limit, c.credit_score,
            c.id_front, c.id_back, c.stock, c.license, c.applicant_signature,
            c.active, c.created_at,
            c.id AS customer_id
        FROM customers c
        INNER JOIN user u ON c.user_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ";
    $st = $conn->prepare($sql);
    $st->bind_param('i', $user_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row ?: null;
}

/* ------------------------------------------------------------
   ROUTER
------------------------------------------------------------ */
$action = strtolower(trim($_REQUEST['action'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'];

match (true) {
    $action === 'view'           && $method === 'GET'  => action_view($conn),
    $action === 'edit_form'      && $method === 'GET'  => action_edit_form($conn),
    $action === 'add_form'       && $method === 'GET'  => action_add_form(),
    $action === 'loan_details'   && $method === 'GET'  => action_loan_details($conn),
    $action === 'edit_save'      && $method === 'POST' => action_edit_save($conn),
    $action === 'add_save'       && $method === 'POST' => action_add_save($conn),
    $action === 'delete'         && $method === 'POST' => action_delete($conn),
    $action === 'toggle_status'  && $method === 'POST' => action_toggle_status($conn),
    $action === 'reset_password' && $method === 'POST' => action_reset_password($conn),
    default => json_err('Unknown action or method.', 404),
};

/* ------------------------------------------------------------
   VIEW  - returns HTML
------------------------------------------------------------ */
function action_view(mysqli $conn): never {
    $id = (int)get_param('user_id');
    if (!$id) json_err('Missing user_id.');

    $c = fetch_client($conn, $id);
    if (!$c) json_err('Client not found.', 404);

    /* -- Loan summary -- */
    $ls = $conn->prepare("
        SELECT COUNT(*) AS cnt,
               COALESCE(SUM(CASE WHEN status NOT IN ('CLEARED','PENDING') THEN loan_balance ELSE 0 END), 0) AS bal,
               COALESCE(SUM(principal), 0) AS total_borrowed
        FROM loan WHERE client_id = ?
    ");
    $ls->bind_param('i', $id);
    $ls->execute();
    $loans = $ls->get_result()->fetch_assoc();

    $fullName   = h(trim($c['fname'] . ' ' . $c['lname']));
    $initials   = strtoupper(substr($c['fname'], 0, 1) . substr($c['lname'], 0, 1));
    $statusBadge = ((int)$c['active'] === 1 && (int)$c['status'] === 1)
        ? '<span class="badge badge-success">Active</span>'
        : '<span class="badge badge-danger">Blocked</span>';

    $score      = (int)$c['credit_score'];
    $scoreClass = $score >= 700 ? 'success' : ($score >= 500 ? 'warning' : 'danger');
    $scoreLabel = $score >= 700 ? 'Excellent' : ($score >= 500 ? 'Fair' : 'Poor');

    function doc_thumb(string $path, string $label): string {
        if (!$path) return '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $icon = $ext === 'pdf'
            ? '<div class="doc-pdf-icon"><i class="fas fa-file-pdf"></i><span>PDF</span></div>'
            : '<img src="' . h($path) . '" alt="' . h($label) . '" class="doc-img">';
        return '
            <div class="doc-thumb">
                <a href="' . h($path) . '" target="_blank" title="Open ' . h($label) . '">
                    ' . $icon . '
                </a>
                <div class="doc-label">' . h($label) . '</div>
            </div>';
    }

    ob_start(); ?>
    <style>
    .cv-header{display:flex;align-items:center;gap:1.25rem;margin-bottom:1.5rem;flex-wrap:wrap}
    .cv-av{width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);
           display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem;
           font-weight:700;flex-shrink:0}
    .cv-name{font-size:1.25rem;font-weight:700;color:#0f172a;line-height:1.2}
    .cv-meta{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.4rem;align-items:center}
    .cv-email{color:#64748b;font-size:.85rem}
    .cv-tabs{display:flex;gap:.25rem;border-bottom:2px solid #e5e7eb;margin-bottom:1.25rem;overflow-x:auto}
    .cv-tab{padding:.5rem 1rem;cursor:pointer;border:0;background:none;color:#64748b;
            font-size:.88rem;font-weight:500;white-space:nowrap;border-bottom:2px solid transparent;
            margin-bottom:-2px;transition:color .15s,border-color .15s}
    .cv-tab.active{color:#6366f1;border-bottom-color:#6366f1}
    .cv-pane{display:none}.cv-pane.active{display:block}
    .info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.85rem}
    .info-item label{display:block;font-size:.74rem;text-transform:uppercase;letter-spacing:.05em;
                     color:#94a3b8;margin-bottom:.2rem}
    .info-item .val{font-size:.92rem;color:#1e293b;font-weight:500;word-break:break-word}
    .info-item .val.empty{color:#cbd5e1;font-style:italic}
    .score-bar{height:8px;border-radius:4px;background:#e5e7eb;margin-top:.5rem;overflow:hidden}
    .score-fill{height:100%;border-radius:4px;transition:width .6s ease}
    .score-fill.success{background:#22c55e}.score-fill.warning{background:#f59e0b}.score-fill.danger{background:#ef4444}
    .loan-stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1rem}
    .loan-stat{background:#f8fafc;border-radius:12px;padding:.85rem 1rem;text-align:center}
    .loan-stat .ls-val{font-size:1.1rem;font-weight:700;color:#0f172a}
    .loan-stat .ls-lbl{font-size:.78rem;color:#94a3b8;margin-top:.2rem}
    .docs-grid{display:flex;flex-wrap:wrap;gap:1rem}
    .doc-thumb{text-align:center}
    .doc-thumb a{display:block;width:100px;height:80px;border-radius:10px;overflow:hidden;
                 border:1px solid #e5e7eb;text-decoration:none;background:#f8fafc;
                 display:flex;align-items:center;justify-content:center;transition:box-shadow .15s}
    .doc-thumb a:hover{box-shadow:0 4px 14px rgba(99,102,241,.25)}
    .doc-img{width:100%;height:100%;object-fit:cover}
    .doc-pdf-icon{display:flex;flex-direction:column;align-items:center;gap:.25rem;color:#ef4444}
    .doc-pdf-icon i{font-size:1.8rem}.doc-pdf-icon span{font-size:.72rem;font-weight:600}
    .doc-label{font-size:.76rem;color:#64748b;margin-top:.35rem;max-width:100px;
               white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cv-actions{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1.25rem;padding-top:1rem;border-top:1px solid #eef2f7}
    </style>

    <div class="cv-header">
        <div class="cv-av"><?= $initials ?></div>
        <div>
            <div class="cv-name"><?= $fullName ?></div>
            <div class="cv-meta">
                <?= $statusBadge ?>
                <span class="cv-email"><?= h($c['email']) ?></span>
                <?php if ($c['credit_score']): ?>
                    <span class="badge bg-<?= $scoreClass ?>"><?= $scoreLabel ?> · <?= $score ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="cv-tabs">
        <button class="cv-tab active" onclick="cvTab(this,'cv-personal')">Personal</button>
        <button class="cv-tab"        onclick="cvTab(this,'cv-business')">Business</button>
        <button class="cv-tab"        onclick="cvTab(this,'cv-banking')">Banking</button>
        <button class="cv-tab"        onclick="cvTab(this,'cv-nok')">Next of Kin</button>
        <button class="cv-tab"        onclick="cvTab(this,'cv-loans')">Loans</button>
        <button class="cv-tab"        onclick="cvTab(this,'cv-docs')">Documents</button>
    </div>

    <!-- Personal -->
    <div class="cv-pane active" id="cv-personal">
        <div class="info-grid">
            <?php
            $pFields = [
                'First Name'   => $c['fname'],
                'Last Name'    => $c['lname'],
                'Gender'       => $c['gender'],
                'Date of Birth'=> $c['dob'],
                'Phone'        => $c['phone'],
                'Email'        => $c['email'],
                'NIN'          => $c['nin'],
                'District'     => $c['district'],
                'Location'     => $c['location'],
                'Registered'   => $c['created_at'] ? date('d M Y', strtotime($c['created_at'])) : '',
            ];
            foreach ($pFields as $lbl => $val): ?>
                <div class="info-item">
                    <label><?= h($lbl) ?></label>
                    <div class="val <?= $val ? '' : 'empty' ?>"><?= $val ? h($val) : 'Not set' ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($c['credit_score']): ?>
        <div style="margin-top:1rem">
            <label class="info-item" style="display:block">
                <label>Credit Score</label>
                <div style="display:flex;align-items:center;gap:.75rem;margin-top:.35rem">
                    <strong style="font-size:1.3rem;color:#0f172a"><?= $score ?></strong>
                    <span class="badge bg-<?= $scoreClass ?>"><?= $scoreLabel ?></span>
                </div>
            </label>
            <div class="score-bar"><div class="score-fill <?= $scoreClass ?>" style="width:<?= min(100, round($score/8.5)) ?>%"></div></div>
            <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#94a3b8;margin-top:.3rem">
                <span>0</span><span>850</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Business -->
    <div class="cv-pane" id="cv-business">
        <div class="info-grid">
            <?php
            $bFields = [
                'Business Name'   => $c['business_name'],
                'Business Type'   => $c['business_type'],
                'Business Period' => $c['business_period'] ? $c['business_period'] . ' months' : '',
                'District'        => $c['district'],
                'Location'        => $c['location'],
                'Last Cycle Amt'  => $c['amount_last'] ? 'UGX ' . number_format($c['amount_last']) : '',
                'This Cycle Amt'  => $c['amount_this'] ? 'UGX ' . number_format($c['amount_this']) : '',
                'Loan Limit'      => $c['loan_limit']  ? 'UGX ' . number_format($c['loan_limit'])  : '',
            ];
            foreach ($bFields as $lbl => $val): ?>
                <div class="info-item">
                    <label><?= h($lbl) ?></label>
                    <div class="val <?= $val ? '' : 'empty' ?>"><?= $val ? h($val) : 'Not set' ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Banking -->
    <div class="cv-pane" id="cv-banking">
        <div class="info-grid">
            <?php
            $bnkFields = [
                'Bank Name'    => $c['bank_name'],
                'Bank Account' => $c['bank_account'],
            ];
            foreach ($bnkFields as $lbl => $val): ?>
                <div class="info-item">
                    <label><?= h($lbl) ?></label>
                    <div class="val <?= $val ? '' : 'empty' ?>"><?= $val ? h($val) : 'Not set' ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Next of Kin -->
    <div class="cv-pane" id="cv-nok">
        <div class="info-grid">
            <?php
            $nokFields = [
                'Full Name'    => $c['nok_name'],
                'Phone'        => $c['nok_tel'],
                'Relationship' => $c['nok_rel'],
            ];
            foreach ($nokFields as $lbl => $val): ?>
                <div class="info-item">
                    <label><?= h($lbl) ?></label>
                    <div class="val <?= $val ? '' : 'empty' ?>"><?= $val ? h($val) : 'Not set' ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Loans -->
    <div class="cv-pane" id="cv-loans">
        <div class="loan-stat-row">
            <div class="loan-stat">
                <div class="ls-val"><?= number_format((int)$loans['cnt']) ?></div>
                <div class="ls-lbl">Total Loans</div>
            </div>
            <div class="loan-stat">
                <div class="ls-val">UGX <?= number_format((float)$loans['total_borrowed']) ?></div>
                <div class="ls-lbl">Total Borrowed</div>
            </div>
            <div class="loan-stat">
                <div class="ls-val">UGX <?= number_format((float)$loans['bal']) ?></div>
                <div class="ls-lbl">Outstanding</div>
            </div>
        </div>
        <button class="btn btn-primary btn-sm"
                onclick="clientLoanDetails(<?= (int)$c['id'] ?>)">
            <i class="fas fa-file-invoice-dollar me-1"></i> View Loan History
        </button>
    </div>

    <!-- Documents -->
    <div class="cv-pane" id="cv-docs">
        <?php
        $docs = [
            'id_front'            => 'ID Front',
            'id_back'             => 'ID Back',
            'stock'               => 'Stock Photo',
            'license'             => 'License',
            'applicant_signature' => 'Signature',
        ];
        $hasDocs = false;
        foreach ($docs as $key => $label) {
            if (!empty($c[$key])) { $hasDocs = true; break; }
        }
        ?>
        <?php if ($hasDocs): ?>
            <div class="docs-grid">
                <?php foreach ($docs as $key => $label): ?>
                    <?php if (!empty($c[$key])): ?>
                        <?= doc_thumb($c[$key], $label) ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted">No documents uploaded yet.</p>
        <?php endif; ?>
    </div>

    <!-- Bottom actions -->
    <div class="cv-actions">
        <button class="btn btn-warning btn-sm" onclick="editClientModal(<?= (int)$c['id'] ?>)">
            <i class="fas fa-edit me-1"></i> Edit
        </button>
        <button class="btn btn-info btn-sm" onclick="clientLoanDetails(<?= (int)$c['id'] ?>)">
            <i class="fas fa-file-invoice-dollar me-1"></i> Loans
        </button>
        <button class="btn btn-<?= ((int)$c['active'] && (int)$c['status']) ? 'secondary' : 'success' ?> btn-sm"
                onclick="toggleClientStatus(<?= (int)$c['id'] ?>, this)">
            <i class="fas fa-<?= ((int)$c['active'] && (int)$c['status']) ? 'ban' : 'check' ?> me-1"></i>
            <?= ((int)$c['active'] && (int)$c['status']) ? 'Block' : 'Activate' ?>
        </button>
        <button class="btn btn-danger btn-sm ms-auto"
                onclick="deleteClient(<?= (int)$c['id'] ?>)">
            <i class="fas fa-trash me-1"></i> Delete
        </button>
    </div>

    <script>
    function cvTab(el, paneId){
        document.querySelectorAll('.cv-tab').forEach(t=>t.classList.remove('active'));
        document.querySelectorAll('.cv-pane').forEach(p=>p.classList.remove('active'));
        el.classList.add('active');
        document.getElementById(paneId).classList.add('active');
    }
    </script>
    <?php
    echo ob_get_clean();
    exit;
}

/* ------------------------------------------------------------
   EDIT FORM  - returns HTML
------------------------------------------------------------ */
function action_edit_form(mysqli $conn): never {
    $id = (int)get_param('user_id');
    if (!$id) json_err('Missing user_id.');
    $c = fetch_client($conn, $id);
    if (!$c) json_err('Client not found.', 404);
    render_client_form($c);
    exit;
}

/* ------------------------------------------------------------
   ADD FORM  - returns HTML
------------------------------------------------------------ */
function action_add_form(): never {
    render_client_form(null);
    exit;
}

/* ------------------------------------------------------------
   SHARED FORM RENDERER
------------------------------------------------------------ */
function render_client_form(?array $c): void {
    $isEdit  = $c !== null;
    $action  = $isEdit ? 'edit_save' : 'add_save';
    $uid     = $isEdit ? (int)$c['id'] : 0;

    $v = fn(string $k) => h($c[$k] ?? '');

    $businessTypes = ['Retail','Wholesale','Manufacturing','Agriculture',
                      'Services','Transport','Food & Beverage','Other'];
    $genders       = ['Male','Female','Other'];
    $nokRels       = ['Spouse','Parent','Sibling','Child','Friend','Other'];
    ?>
    <style>
    .cf-section{margin-bottom:1.5rem}
    .cf-section-title{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;
                      color:#94a3b8;font-weight:600;margin-bottom:.75rem;
                      padding-bottom:.4rem;border-bottom:1px solid #eef2f7}
    .cf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.85rem}
    .cf-grid-2{grid-template-columns:repeat(2,1fr)}
    .form-label{font-size:.82rem;font-weight:500;color:#475569;margin-bottom:.3rem}
    .form-label .req{color:#ef4444;margin-left:2px}
    .form-control,.form-select{border-radius:8px;border:1px solid #e2e8f0;font-size:.88rem;
                               padding:.5rem .75rem;transition:border-color .15s,box-shadow .15s}
    .form-control:focus,.form-select:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15);outline:none}
    .file-preview{margin-top:.4rem}
    .file-preview img,.file-preview .pdf-badge{
        max-height:60px;border-radius:6px;border:1px solid #e5e7eb;object-fit:cover}
    .pdf-badge{display:inline-flex;align-items:center;gap:.4rem;background:#fff1f2;
               color:#ef4444;font-size:.78rem;font-weight:600;padding:.25rem .6rem;border-radius:6px}
    .cf-footer{display:flex;gap:.75rem;justify-content:flex-end;padding-top:1rem;
               border-top:1px solid #eef2f7;margin-top:.5rem}
    </style>

    <form id="clientForm"
          class="ajax-form"
          action="process-clients.php?action=<?= $action ?>"
          method="POST"
          enctype="multipart/form-data">

        <?php if ($isEdit): ?>
            <input type="hidden" name="user_id" value="<?= $uid ?>">
        <?php endif; ?>

        <!-- -- Personal -- -->
        <div class="cf-section">
            <div class="cf-section-title">Personal Information</div>
            <div class="cf-grid">
                <div>
                    <label class="form-label">First Name <span class="req">*</span></label>
                    <input type="text" name="fname" class="form-control"
                           value="<?= $v('fname') ?>" required maxlength="80">
                </div>
                <div>
                    <label class="form-label">Last Name <span class="req">*</span></label>
                    <input type="text" name="lname" class="form-control"
                           value="<?= $v('lname') ?>" required maxlength="80">
                </div>
                <div>
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">- Select -</option>
                        <?php foreach ($genders as $g): ?>
                            <option value="<?= h($g) ?>" <?= ($c['gender'] ?? '') === $g ? 'selected' : '' ?>><?= h($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="dob" class="form-control" value="<?= $v('dob') ?>">
                </div>
                <div>
                    <label class="form-label">Phone <span class="req">*</span></label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= $v('phone') ?>" required maxlength="20">
                </div>
                <div>
                    <label class="form-label">Email <span class="req">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= $v('email') ?>" required maxlength="160">
                </div>
                <div>
                    <label class="form-label">NIN</label>
                    <input type="text" name="nin" class="form-control"
                           value="<?= $v('nin') ?>" maxlength="30" placeholder="CM123456789XY">
                </div>
                <div>
                    <label class="form-label">District</label>
                    <input type="text" name="district" class="form-control" value="<?= $v('district') ?>">
                </div>
                <div>
                    <label class="form-label">Location / Village</label>
                    <input type="text" name="location" class="form-control" value="<?= $v('location') ?>">
                </div>
            </div>
        </div>

        <!-- -- Business -- -->
        <div class="cf-section">
            <div class="cf-section-title">Business Information</div>
            <div class="cf-grid">
                <div>
                    <label class="form-label">Business Name <span class="req">*</span></label>
                    <input type="text" name="business_name" class="form-control"
                           value="<?= $v('business_name') ?>" required maxlength="160">
                </div>
                <div>
                    <label class="form-label">Business Type</label>
                    <select name="business_type" class="form-select">
                        <option value="">- Select -</option>
                        <?php foreach ($businessTypes as $bt): ?>
                            <option value="<?= h($bt) ?>" <?= ($c['business_type'] ?? '') === $bt ? 'selected' : '' ?>><?= h($bt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Business Period (months)</label>
                    <input type="number" name="business_period" class="form-control" min="0"
                           value="<?= $v('business_period') ?>">
                </div>
                <div>
                    <label class="form-label">Last Cycle Amount (UGX)</label>
                    <input type="number" name="amount_last" class="form-control" min="0" step="100"
                           value="<?= $v('amount_last') ?>">
                </div>
                <div>
                    <label class="form-label">This Cycle Amount (UGX)</label>
                    <input type="number" name="amount_this" class="form-control" min="0" step="100"
                           value="<?= $v('amount_this') ?>">
                </div>
                <div>
                    <label class="form-label">Loan Limit (UGX)</label>
                    <input type="number" name="loan_limit" class="form-control" min="0" step="1000"
                           value="<?= $v('loan_limit') ?>">
                </div>
                <div>
                    <label class="form-label">Credit Score</label>
                    <input type="number" name="credit_score" class="form-control"
                           min="0" max="850" step="1" value="<?= $v('credit_score') ?>"
                           placeholder="0 – 850">
                </div>
            </div>
        </div>

        <!-- -- Banking -- -->
        <div class="cf-section">
            <div class="cf-section-title">Banking Details</div>
            <div class="cf-grid">
                <div>
                    <label class="form-label">Bank Name</label>
                    <input type="text" name="bank_name" class="form-control"
                           value="<?= $v('bank_name') ?>" maxlength="120">
                </div>
                <div>
                    <label class="form-label">Bank Account Number</label>
                    <input type="text" name="bank_account" class="form-control"
                           value="<?= $v('bank_account') ?>" maxlength="60">
                </div>
            </div>
        </div>

        <!-- -- Next of Kin -- -->
        <div class="cf-section">
            <div class="cf-section-title">Next of Kin</div>
            <div class="cf-grid">
                <div>
                    <label class="form-label">Full Name</label>
                    <input type="text" name="nok_name" class="form-control" value="<?= $v('nok_name') ?>">
                </div>
                <div>
                    <label class="form-label">Phone</label>
                    <input type="text" name="nok_tel" class="form-control" value="<?= $v('nok_tel') ?>">
                </div>
                <div>
                    <label class="form-label">Relationship</label>
                    <select name="nok_rel" class="form-select">
                        <option value="">- Select -</option>
                        <?php foreach ($nokRels as $r): ?>
                            <option value="<?= h($r) ?>" <?= ($c['nok_rel'] ?? '') === $r ? 'selected' : '' ?>><?= h($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- -- Documents -- -->
        <div class="cf-section">
            <div class="cf-section-title">Documents &amp; Photos</div>
            <div class="cf-grid">
                <?php
                $docFields = [
                    'id_front'            => 'National ID - Front',
                    'id_back'             => 'National ID - Back',
                    'stock'               => 'Stock / Inventory Photo',
                    'license'             => 'Business License',
                    'applicant_signature' => 'Applicant Signature',
                ];
                foreach ($docFields as $key => $label):
                    $existing = $c[$key] ?? '';
                    $ext      = $existing ? strtolower(pathinfo($existing, PATHINFO_EXTENSION)) : '';
                ?>
                <div>
                    <label class="form-label"><?= h($label) ?></label>
                    <input type="file" name="<?= $key ?>" class="form-control"
                           accept="image/jpeg,image/png,image/webp,application/pdf">
                    <?php if ($existing): ?>
                        <div class="file-preview">
                            <?php if ($ext === 'pdf'): ?>
                                <a href="<?= h($existing) ?>" target="_blank" class="pdf-badge">
                                    <i class="fas fa-file-pdf"></i> View PDF
                                </a>
                            <?php else: ?>
                                <img src="<?= h($existing) ?>" alt="Current <?= h($label) ?>">
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="<?= $key ?>_existing" value="<?= h($existing) ?>">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- -- Account -- -->
        <div class="cf-section">
            <div class="cf-section-title">Account</div>
            <div class="cf-grid">
                <div>
                    <label class="form-label">Status</label>
                    <select name="active" class="form-select">
                        <option value="1" <?= (($c['active'] ?? 1) == 1) ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= (($c['active'] ?? 1) == 0) ? 'selected' : '' ?>>Blocked</option>
                    </select>
                </div>
                <?php if (!$isEdit): ?>
                <div>
                    <label class="form-label">Password <span class="req">*</span></label>
                    <input type="password" name="password" class="form-control"
                           required minlength="6" placeholder="Min. 6 characters">
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="cf-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-<?= $isEdit ? 'save' : 'user-plus' ?> me-1"></i>
                <?= $isEdit ? 'Save Changes' : 'Add Client' ?>
            </button>
        </div>
    </form>
    <?php
}

/* ------------------------------------------------------------
   EDIT SAVE  - returns JSON
------------------------------------------------------------ */
function action_edit_save(mysqli $conn): never {
    $uid = (int)post('user_id');
    if (!$uid) json_err('Missing user_id.');

    $c = fetch_client($conn, $uid);
    if (!$c) json_err('Client not found.', 404);

    /* -- Validate required fields -- */
    $fname        = post('fname');
    $lname        = post('lname');
    $email        = post('email');
    $phone        = post('phone');
    $business     = post('business_name');

    if (!$fname || !$lname || !$email || !$phone || !$business)
        json_err('Please fill in all required fields.');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        json_err('Invalid email address.');

    /* -- Check email uniqueness (excluding self) -- */
    $chk = $conn->prepare("SELECT id FROM user WHERE email = ? AND id != ? LIMIT 1");
    $chk->bind_param('si', $email, $uid);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0)
        json_err('That email address is already used by another account.');

    /* -- Handle file uploads -- */
    $docFields = ['id_front','id_back','stock','license','applicant_signature'];
    $uploads   = [];
    foreach ($docFields as $f) {
        $uploads[$f] = handle_upload($f, post($f . '_existing'));
    }

    /* -- Update user table -- */
    $active     = (int)post('active');
    $uStmt = $conn->prepare("UPDATE user SET fname=?, lname=?, email=?, status=? WHERE id=?");
    $uStmt->bind_param('sssii', $fname, $lname, $email, $active, $uid);
    if (!$uStmt->execute()) json_err('Failed to update user record: ' . $conn->error);

    /* -- Update customers table -- */
    $gender     = post('gender');
    $dob        = post('dob') ?: null;
    $phone      = post('phone');
    $bankName   = post('bank_name');
    $bankAcc    = post('bank_account');
    $bizType    = post('business_type');
    $bizPeriod  = (int)post('business_period') ?: null;
    $amtLast    = post('amount_last')   !== '' ? (float)post('amount_last')   : null;
    $amtThis    = post('amount_this')   !== '' ? (float)post('amount_this')   : null;
    $district   = post('district');
    $location   = post('location');
    $nokName    = post('nok_name');
    $nokTel     = post('nok_tel');
    $nokRel     = post('nok_rel');
    $nin        = post('nin');
    $loanLimit  = post('loan_limit')    !== '' ? (float)post('loan_limit')    : null;
    $credit     = post('credit_score')  !== '' ? (int)post('credit_score')    : null;

    $cStmt = $conn->prepare("
        UPDATE customers SET
            gender=?, dob=?, phone=?, bank_name=?, bank_account=?,
            business_name=?, business_type=?, business_period=?,
            amount_last=?, amount_this=?,
            district=?, location=?,
            nok_name=?, nok_tel=?, nok_rel=?,
            nin=?, loan_limit=?, credit_score=?,
            id_front=?, id_back=?, stock=?, license=?, applicant_signature=?,
            active=?
        WHERE user_id=?
    ");

    $cStmt->bind_param(
        'sssssssiiddssssssddssssi i',
        $gender, $dob, $phone, $bankName, $bankAcc,
        $business, $bizType, $bizPeriod,
        $amtLast, $amtThis,
        $district, $location,
        $nokName, $nokTel, $nokRel,
        $nin, $loanLimit, $credit,
        $uploads['id_front'], $uploads['id_back'], $uploads['stock'],
        $uploads['license'], $uploads['applicant_signature'],
        $active,
        $uid
    );

    if (!$cStmt->execute()) json_err('Failed to update customer record: ' . $conn->error);

    json_ok('Client updated successfully.');
}

/* ------------------------------------------------------------
   ADD SAVE  - returns JSON
------------------------------------------------------------ */
function action_add_save(mysqli $conn): never {

    $fname    = post('fname');
    $lname    = post('lname');
    $email    = post('email');
    $phone    = post('phone');
    $business = post('business_name');
    $password = post('password');

    if (!$fname || !$lname || !$email || !$phone || !$business || !$password)
        json_err('Please fill in all required fields.');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        json_err('Invalid email address.');

    if (strlen($password) < 6)
        json_err('Password must be at least 6 characters.');

    /* -- Duplicate email check -- */
    $chk = $conn->prepare("SELECT id FROM user WHERE email = ? LIMIT 1");
    $chk->bind_param('s', $email);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0)
        json_err('An account with that email already exists.');

    /* -- File uploads -- */
    $docFields = ['id_front','id_back','stock','license','applicant_signature'];
    $uploads   = [];
    foreach ($docFields as $f) $uploads[$f] = handle_upload($f);

    $conn->begin_transaction();

    try {
        /* Insert user */
        $hash   = password_hash($password, PASSWORD_DEFAULT);
        $status = (int)post('active');
        $ins    = $conn->prepare("INSERT INTO user (fname, lname, email, password, status) VALUES (?,?,?,?,?)");
        $ins->bind_param('ssssi', $fname, $lname, $email, $hash, $status);
        if (!$ins->execute()) throw new RuntimeException($conn->error);
        $newUserId = (int)$conn->insert_id;

        /* Insert customer */
        $gender    = post('gender');
        $dob       = post('dob') ?: null;
        $bankName  = post('bank_name');
        $bankAcc   = post('bank_account');
        $bizType   = post('business_type');
        $bizPeriod = (int)post('business_period') ?: null;
        $amtLast   = post('amount_last')  !== '' ? (float)post('amount_last')  : null;
        $amtThis   = post('amount_this')  !== '' ? (float)post('amount_this')  : null;
        $district  = post('district');
        $location  = post('location');
        $nokName   = post('nok_name');
        $nokTel    = post('nok_tel');
        $nokRel    = post('nok_rel');
        $nin       = post('nin');
        $loanLimit = post('loan_limit')   !== '' ? (float)post('loan_limit')   : null;
        $credit    = post('credit_score') !== '' ? (int)post('credit_score')   : null;

        $cIns = $conn->prepare("
            INSERT INTO customers
                (user_id, gender, dob, phone, bank_name, bank_account,
                 business_name, business_type, business_period,
                 amount_last, amount_this,
                 district, location,
                 nok_name, nok_tel, nok_rel,
                 nin, loan_limit, credit_score,
                 id_front, id_back, stock, license, applicant_signature,
                 active)
            VALUES (?,?,?,?,?,?, ?,?,?, ?,?, ?,?, ?,?,?, ?,?,?, ?,?,?,?,?, ?)
        ");

        $cIns->bind_param(
            'isssssssiiddssssssddssssi',
            $newUserId,
            $gender, $dob, $phone, $bankName, $bankAcc,
            $business, $bizType, $bizPeriod,
            $amtLast, $amtThis,
            $district, $location,
            $nokName, $nokTel, $nokRel,
            $nin, $loanLimit, $credit,
            $uploads['id_front'], $uploads['id_back'], $uploads['stock'],
            $uploads['license'], $uploads['applicant_signature'],
            $status
        );

        if (!$cIns->execute()) throw new RuntimeException($conn->error);

        $conn->commit();
        json_ok('Client added successfully.', ['user_id' => $newUserId]);

    } catch (Throwable $e) {
        $conn->rollback();
        json_err('Failed to add client: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------
   DELETE  - returns JSON
------------------------------------------------------------ */
function action_delete(mysqli $conn): never {
    $uid = (int)post('client_id');
    if (!$uid) $uid = (int)post('user_id');
    if (!$uid) json_err('Missing client_id.');

    /* Verify client exists */
    $chk = $conn->prepare("SELECT id FROM user WHERE id = ? LIMIT 1");
    $chk->bind_param('i', $uid);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) json_err('Client not found.', 404);

    /* Prevent delete if active loans exist */
    $lchk = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM loan
        WHERE client_id = ? AND status NOT IN ('CLEARED','PENDING')
    ");
    $lchk->bind_param('i', $uid);
    $lchk->execute();
    $lrow = $lchk->get_result()->fetch_assoc();
    if ((int)$lrow['cnt'] > 0)
        json_err('Cannot delete: client has ' . $lrow['cnt'] . ' active loan(s). Clear them first.');

    $conn->begin_transaction();
    try {
        /* Delete dependent records first */
        foreach (['loan','customers'] as $tbl) {
            $col  = $tbl === 'customers' ? 'user_id' : 'client_id';
            $dStmt = $conn->prepare("DELETE FROM {$tbl} WHERE {$col} = ?");
            $dStmt->bind_param('i', $uid);
            $dStmt->execute();
        }
        $dUser = $conn->prepare("DELETE FROM user WHERE id = ?");
        $dUser->bind_param('i', $uid);
        $dUser->execute();

        $conn->commit();
        json_ok('Client deleted successfully.');
    } catch (Throwable $e) {
        $conn->rollback();
        json_err('Delete failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------
   TOGGLE STATUS  - returns JSON
------------------------------------------------------------ */
function action_toggle_status(mysqli $conn): never {
    $uid = (int)post('user_id');
    if (!$uid) json_err('Missing user_id.');

    /* Fetch current status */
    $row = $conn->prepare("SELECT u.status, c.active FROM user u INNER JOIN customers c ON c.user_id = u.id WHERE u.id = ? LIMIT 1");
    $row->bind_param('i', $uid);
    $row->execute();
    $cur = $row->get_result()->fetch_assoc();
    if (!$cur) json_err('Client not found.', 404);

    $newStatus = ((int)$cur['status'] === 1 && (int)$cur['active'] === 1) ? 0 : 1;

    $conn->begin_transaction();
    try {
        $u = $conn->prepare("UPDATE user SET status=? WHERE id=?");
        $u->bind_param('ii', $newStatus, $uid);
        $u->execute();

        $c = $conn->prepare("UPDATE customers SET active=? WHERE user_id=?");
        $c->bind_param('ii', $newStatus, $uid);
        $c->execute();

        $conn->commit();

        $label = $newStatus === 1 ? 'activated' : 'blocked';
        json_ok("Client {$label} successfully.", ['new_status' => $newStatus]);
    } catch (Throwable $e) {
        $conn->rollback();
        json_err('Status update failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------
   RESET PASSWORD  - returns JSON
------------------------------------------------------------ */
function action_reset_password(mysqli $conn): never {
    $uid      = (int)post('user_id');
    $password = post('password');
    $confirm  = post('confirm_password');

    if (!$uid)     json_err('Missing user_id.');
    if (!$password) json_err('Password cannot be empty.');
    if (strlen($password) < 6) json_err('Password must be at least 6 characters.');
    if ($password !== $confirm) json_err('Passwords do not match.');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $st   = $conn->prepare("UPDATE user SET password=? WHERE id=?");
    $st->bind_param('si', $hash, $uid);
    if (!$st->execute()) json_err('Failed to update password: ' . $conn->error);

    json_ok('Password reset successfully.');
}

/* ------------------------------------------------------------
   LOAN DETAILS  - returns HTML
------------------------------------------------------------ */
function action_loan_details(mysqli $conn): never {
    $id = (int)get_param('client_id');
    if (!$id) json_err('Missing client_id.');

    /* Client basics */
    $cSt = $conn->prepare("
        SELECT u.fname, u.lname, u.email, c.phone, c.business_name
        FROM customers c INNER JOIN user u ON c.user_id = u.id
        WHERE u.id = ? LIMIT 1
    ");
    $cSt->bind_param('i', $id);
    $cSt->execute();
    $client = $cSt->get_result()->fetch_assoc();
    if (!$client) json_err('Client not found.', 404);

    /* Loan rows */
    $lSt = $conn->prepare("
        SELECT id, principal, loan_balance, interest_rate, purpose,
               status, loan_date, due_date, cleared_date, duration,
               installment_amount, created_at
        FROM loan
        WHERE client_id = ?
        ORDER BY id DESC
    ");
    $lSt->bind_param('i', $id);
    $lSt->execute();
    $loans = $lSt->get_result()->fetch_all(MYSQLI_ASSOC);

    /* Aggregates */
    $totalBorrowed  = array_sum(array_column($loans, 'principal'));
    $totalBalance   = 0;
    $activeCount    = 0;
    foreach ($loans as $l) {
        if (!in_array($l['status'], ['CLEARED','PENDING'])) {
            $totalBalance += (float)$l['loan_balance'];
            $activeCount++;
        }
    }

    $statusColors = [
        'ACTIVE'    => 'success',
        'OVERDUE'   => 'danger',
        'PENDING'   => 'warning',
        'CLEARED'   => 'secondary',
        'WRITTEN OFF' => 'dark',
    ];

    ob_start(); ?>
    <style>
    .ld-header{background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%);
               border-radius:14px;padding:1.25rem 1.5rem;color:#fff;margin-bottom:1.25rem}
    .ld-name{font-size:1.15rem;font-weight:700;margin-bottom:.2rem}
    .ld-sub{font-size:.85rem;opacity:.85}
    .ld-sum{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1.25rem}
    .ld-sum-card{background:#f8fafc;border-radius:12px;padding:.9rem 1rem;text-align:center;
                 border:1px solid #e5e7eb}
    .ld-sum-card .sv{font-size:1.05rem;font-weight:700;color:#0f172a}
    .ld-sum-card .sl{font-size:.76rem;color:#94a3b8;margin-top:.2rem}
    .loan-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;
               margin-bottom:.85rem;overflow:hidden}
    .loan-card-head{display:flex;align-items:center;justify-content:space-between;
                    padding:.75rem 1rem;background:#f8fafc;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;gap:.5rem}
    .loan-card-body{padding:.9rem 1rem}
    .loan-info-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.65rem}
    .li-item label{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8}
    .li-item .lv{font-size:.88rem;font-weight:500;color:#1e293b}
    .progress-thin{height:6px;border-radius:3px;background:#e5e7eb;margin-top:.4rem;overflow:hidden}
    .progress-thin .pf{height:100%;border-radius:3px;background:#6366f1}
    </style>

    <div class="ld-header">
        <div class="ld-name"><?= h(trim($client['fname'] . ' ' . $client['lname'])) ?></div>
        <div class="ld-sub">
            <?= h($client['business_name'] ?: $client['email']) ?>
            <?php if ($client['phone']): ?> &nbsp;·&nbsp; <?= h($client['phone']) ?><?php endif; ?>
        </div>
    </div>

    <div class="ld-sum">
        <div class="ld-sum-card">
            <div class="sv"><?= count($loans) ?></div>
            <div class="sl">Total Loans</div>
        </div>
        <div class="ld-sum-card">
            <div class="sv">UGX <?= number_format($totalBorrowed) ?></div>
            <div class="sl">Total Borrowed</div>
        </div>
        <div class="ld-sum-card">
            <div class="sv">UGX <?= number_format($totalBalance) ?></div>
            <div class="sl">Outstanding Balance</div>
        </div>
    </div>

    <?php if (empty($loans)): ?>
        <div class="text-center py-4 text-muted">
            <i class="fas fa-file-invoice-dollar fa-2x mb-2 d-block"></i>
            No loans on record for this client.
        </div>
    <?php else: ?>
        <?php foreach ($loans as $l):
            $sc       = $statusColors[$l['status']] ?? 'secondary';
            $repaid   = (float)$l['principal'] - (float)$l['loan_balance'];
            $pct      = $l['principal'] > 0 ? min(100, round(($repaid / $l['principal']) * 100)) : 0;
        ?>
        <div class="loan-card">
            <div class="loan-card-head">
                <div>
                    <span style="font-weight:600;color:#0f172a">Loan #<?= (int)$l['id'] ?></span>
                    &nbsp;
                    <span class="badge bg-<?= $sc ?>"><?= h($l['status']) ?></span>
                </div>
                <span style="font-weight:700;color:#6366f1">
                    UGX <?= number_format((float)$l['principal']) ?>
                </span>
            </div>

            <div class="loan-card-body">
                <div class="loan-info-row">
                    <div class="li-item">
                        <label>Balance</label>
                        <div class="lv">UGX <?= number_format((float)$l['loan_balance']) ?></div>
                    </div>
                    <div class="li-item">
                        <label>Interest Rate</label>
                        <div class="lv"><?= h($l['interest_rate']) ?>%</div>
                    </div>
                    <div class="li-item">
                        <label>Installment</label>
                        <div class="lv">UGX <?= number_format((float)$l['installment_amount']) ?></div>
                    </div>
                    <div class="li-item">
                        <label>Duration</label>
                        <div class="lv"><?= h($l['duration']) ?> days</div>
                    </div>
                    <div class="li-item">
                        <label>Loan Date</label>
                        <div class="lv"><?= $l['loan_date'] ? date('d M Y', strtotime($l['loan_date'])) : '-' ?></div>
                    </div>
                    <div class="li-item">
                        <label>Due Date</label>
                        <div class="lv"><?= $l['due_date'] ? date('d M Y', strtotime($l['due_date'])) : '-' ?></div>
                    </div>
                    <?php if ($l['cleared_date']): ?>
                    <div class="li-item">
                        <label>Cleared</label>
                        <div class="lv"><?= date('d M Y', strtotime($l['cleared_date'])) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($l['purpose']): ?>
                    <div class="li-item" style="grid-column:1/-1">
                        <label>Purpose</label>
                        <div class="lv"><?= h($l['purpose']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Repayment progress -->
                <div style="margin-top:.75rem">
                    <div style="display:flex;justify-content:space-between;font-size:.78rem;color:#64748b;margin-bottom:.3rem">
                        <span>Repaid: UGX <?= number_format($repaid) ?></span>
                        <span><?= $pct ?>%</span>
                    </div>
                    <div class="progress-thin">
                        <div class="pf" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php
    echo ob_get_clean();
    exit;
}