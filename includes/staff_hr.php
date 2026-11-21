<?php
declare(strict_types=1);

/**
 * Staff HR: profiles for portal staff (admins and loan officers), their
 * contracts and ID files, KPIs, performance appraisals, the monthly report
 * every staff member must submit, and the invoice consultants submit.
 *
 * Pay rule: a month can only be marked paid once the monthly report for that
 * month is approved, and, for consultants, the invoice is approved too.
 *
 * Files live in /storage/staff_documents/{profile_id}/ (web access denied)
 * and are only served through admin/hr_file.php.
 *
 * Tables are created on first use; see admin/staff-hr.sql.
 */

const HR_FILE_MAX_BYTES = 5 * 1024 * 1024;
const HR_FILE_MIME = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];
const HR_STAFF_TYPES = ['employee' => 'Employee', 'consultant' => 'Consultant'];

/** File kinds kept on a profile. Report and invoice attachments are added with their submission. */
function hr_file_types(): array
{
    return [
        'contract'    => ['label' => 'Contract', 'icon' => 'fa-file-signature'],
        'national_id' => ['label' => 'National ID', 'icon' => 'fa-id-card'],
        'cv'          => ['label' => 'CV', 'icon' => 'fa-file-alt'],
        'certificate' => ['label' => 'Certificate', 'icon' => 'fa-certificate'],
        'appraisal'   => ['label' => 'Appraisal document', 'icon' => 'fa-star-half-alt'],
        'report'      => ['label' => 'Report attachment', 'icon' => 'fa-paperclip'],
        'invoice'     => ['label' => 'Invoice', 'icon' => 'fa-file-invoice'],
        'other'       => ['label' => 'Other', 'icon' => 'fa-folder-open'],
    ];
}

function hr_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS staff_profiles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            staff_type VARCHAR(20) NOT NULL DEFAULT 'employee',
            job_title VARCHAR(120) NOT NULL DEFAULT '',
            department VARCHAR(120) NOT NULL DEFAULT '',
            phone VARCHAR(40) NOT NULL DEFAULT '',
            national_id_number VARCHAR(40) NOT NULL DEFAULT '',
            date_of_birth DATE NULL,
            gender VARCHAR(20) NOT NULL DEFAULT '',
            address VARCHAR(255) NOT NULL DEFAULT '',
            contract_start DATE NULL,
            contract_end DATE NULL,
            monthly_pay DECIMAL(15,2) NOT NULL DEFAULT 0,
            bank_name VARCHAR(120) NOT NULL DEFAULT '',
            bank_account VARCHAR(60) NOT NULL DEFAULT '',
            mobile_money VARCHAR(40) NOT NULL DEFAULT '',
            tin VARCHAR(40) NOT NULL DEFAULT '',
            nok_name VARCHAR(150) NOT NULL DEFAULT '',
            nok_relationship VARCHAR(60) NOT NULL DEFAULT '',
            nok_phone VARCHAR(40) NOT NULL DEFAULT '',
            nok_address VARCHAR(255) NOT NULL DEFAULT '',
            notes TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_profiles_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS staff_files (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            profile_id INT UNSIGNED NOT NULL,
            file_type VARCHAR(30) NOT NULL,
            title VARCHAR(150) NOT NULL DEFAULT '',
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL DEFAULT '',
            mime_type VARCHAR(100) NOT NULL DEFAULT '',
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_staff_files_profile (profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS staff_kpis (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            profile_id INT UNSIGNED NOT NULL,
            title VARCHAR(150) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            target VARCHAR(100) NOT NULL DEFAULT '',
            frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
            weight TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_staff_kpis_profile (profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS staff_appraisals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            profile_id INT UNSIGNED NOT NULL,
            period_label VARCHAR(60) NOT NULL,
            review_date DATE NOT NULL,
            overall_rating TINYINT UNSIGNED NOT NULL DEFAULT 3,
            kpi_ratings TEXT NULL,
            strengths TEXT NULL,
            improvements TEXT NULL,
            goals TEXT NULL,
            reviewer_comments TEXT NULL,
            staff_comments TEXT NULL,
            acknowledged_at DATETIME NULL,
            file_id INT UNSIGNED NULL,
            reviewed_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_staff_appraisals_profile (profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS staff_monthly_reports (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            profile_id INT UNSIGNED NOT NULL,
            period CHAR(7) NOT NULL,
            work_summary TEXT NOT NULL,
            achievements TEXT NULL,
            challenges TEXT NULL,
            support_needed TEXT NULL,
            next_month_plan TEXT NULL,
            kpi_progress TEXT NULL,
            file_id INT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'submitted',
            review_note VARCHAR(1000) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_reports_period (profile_id, period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS staff_invoices (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            profile_id INT UNSIGNED NOT NULL,
            period CHAR(7) NOT NULL,
            invoice_number VARCHAR(60) NOT NULL,
            invoice_date DATE NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            description TEXT NULL,
            file_id INT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'submitted',
            review_note VARCHAR(1000) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_invoices_period (profile_id, period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS staff_payments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            profile_id INT UNSIGNED NOT NULL,
            period CHAR(7) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            reference VARCHAR(120) NOT NULL DEFAULT '',
            paid_on DATE NOT NULL,
            paid_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_payments_period (profile_id, period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $done = true;
}

/* ---------------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------------- */

function hr_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function hr_text($value, int $max = 4000): string
{
    if (!is_scalar($value)) {
        return '';
    }
    return mb_substr(trim((string)$value), 0, $max, 'UTF-8');
}

/** 'YYYY-MM-DD' or null. */
function hr_date($value): ?string
{
    $value = hr_text($value, 10);
    if ($value === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value ? $value : null;
}

function hr_money($value): float
{
    $clean = preg_replace('/[^0-9.]/', '', (string)$value) ?? '';
    return $clean === '' ? 0.0 : round(max(0, (float)$clean), 2);
}

function hr_valid_period(string $period): bool
{
    return (bool)preg_match('/^(19|20)\d\d-(0[1-9]|1[0-2])$/', $period);
}

function hr_period_label(string $period): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m', $period);
    return $d ? $d->format('F Y') : $period;
}

function hr_current_period(): string
{
    return date('Y-m');
}

/**
 * Months a staff member may report on: from the later of their contract
 * start and 12 months ago, up to the current month, newest first.
 */
function hr_reportable_periods(array $profile): array
{
    $now = new DateTimeImmutable('first day of this month');
    $earliest = $now->modify('-11 months');
    $start = $profile['contract_start'] ? new DateTimeImmutable(substr((string)$profile['contract_start'], 0, 7) . '-01') : $earliest;
    if ($start < $earliest) {
        $start = $earliest;
    }
    $periods = [];
    for ($d = $now; $d >= $start; $d = $d->modify('-1 month')) {
        $periods[] = $d->format('Y-m');
    }
    return $periods ?: [$now->format('Y-m')];
}

function hr_status_badge(?string $status): string
{
    $map = [
        null        => ['Not submitted', 'hr-b-none'],
        ''          => ['Not submitted', 'hr-b-none'],
        'submitted' => ['Awaiting review', 'hr-b-wait'],
        'approved'  => ['Approved', 'hr-b-ok'],
        'returned'  => ['Returned for changes', 'hr-b-bad'],
        'paid'      => ['Paid', 'hr-b-paid'],
        'not_needed'=> ['Not needed', 'hr-b-none'],
    ];
    [$label, $cls] = $map[$status] ?? [ucfirst((string)$status), 'hr-b-none'];
    return '<span class="hr-badge ' . $cls . '">' . hr_h($label) . '</span>';
}

/* ---------------------------------------------------------------------
 * Page plumbing: session, CSRF, flash messages
 * ------------------------------------------------------------------- */

/** Require an admin (role 1) or, with $allowOfficers, a loan officer too. Returns [userId, role]. */
function hr_require_login(bool $allowOfficers = false): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $role = (int)($_SESSION['role'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['logged_in'] ?? 0);
    $allowed = $allowOfficers ? [1, 2] : [1];
    if ($userId <= 0 || !in_array($role, $allowed, true)) {
        header('Location: ' . ($role === 2 ? 'my_staff_portal.php' : '../login.php'));
        exit;
    }
    return [$userId, $role];
}

function hr_csrf(): string
{
    if (empty($_SESSION['hr_csrf'])) {
        $_SESSION['hr_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['hr_csrf'];
}

function hr_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . hr_h(hr_csrf()) . '">';
}

function hr_csrf_ok(): bool
{
    return hash_equals(hr_csrf(), (string)($_POST['csrf_token'] ?? ''));
}

function hr_flash(string $message, string $type = 'success'): void
{
    $_SESSION['hr_flash'] = [$message, $type];
}

function hr_flash_html(): string
{
    [$msg, $type] = $_SESSION['hr_flash'] ?? ['', ''];
    unset($_SESSION['hr_flash']);
    return $msg === '' ? '' : '<div class="hr-flash ' . hr_h($type) . '" role="status">' . hr_h($msg) . '</div>';
}

function hr_redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function hr_styles(): string
{
    $rel = 'assets/css/staff-hr.css';
    $abs = dirname(__DIR__) . '/' . $rel;
    return '<link rel="stylesheet" href="../' . $rel . (is_file($abs) ? '?v=' . filemtime($abs) : '') . '">';
}

function hr_type_badge(string $type): string
{
    return '<span class="hr-badge hr-b-type">' . hr_h(HR_STAFF_TYPES[$type] ?? $type) . '</span>';
}

function hr_ugx($amount): string
{
    return 'UGX ' . number_format((float)$amount);
}

/* ---------------------------------------------------------------------
 * Profiles
 * ------------------------------------------------------------------- */

const HR_PROFILE_FIELDS = [
    'staff_type', 'job_title', 'department', 'phone', 'national_id_number', 'date_of_birth', 'gender',
    'address', 'contract_start', 'contract_end', 'monthly_pay', 'bank_name', 'bank_account', 'mobile_money',
    'tin', 'nok_name', 'nok_relationship', 'nok_phone', 'nok_address', 'notes', 'status',
];

function hr_profile_select(): string
{
    return "SELECT p.*, u.fname, u.lname, u.email, u.role, u.status AS user_status
            FROM staff_profiles p JOIN user u ON u.id = p.user_id";
}

function hr_profile_find(mysqli $conn, int $profileId): ?array
{
    hr_ensure_schema($conn);
    $s = $conn->prepare(hr_profile_select() . ' WHERE p.id = ? LIMIT 1');
    $s->bind_param('i', $profileId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

function hr_profile_for_user(mysqli $conn, int $userId): ?array
{
    hr_ensure_schema($conn);
    $s = $conn->prepare(hr_profile_select() . ' WHERE p.user_id = ? LIMIT 1');
    $s->bind_param('i', $userId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

function hr_profiles_all(mysqli $conn, bool $activeOnly = false): array
{
    hr_ensure_schema($conn);
    $sql = hr_profile_select() . ($activeOnly ? " WHERE p.status = 'active'" : '') . ' ORDER BY u.fname, u.lname';
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/** Portal users (admins and loan officers) who do not have a profile yet. */
function hr_users_without_profile(mysqli $conn): array
{
    hr_ensure_schema($conn);
    return $conn->query("
        SELECT u.id, u.fname, u.lname, u.email, u.role
        FROM user u LEFT JOIN staff_profiles p ON p.user_id = u.id
        WHERE u.role IN (1, 2) AND p.id IS NULL
        ORDER BY u.fname, u.lname
    ")->fetch_all(MYSQLI_ASSOC);
}

function hr_display_name(array $profile): string
{
    return trim((string)($profile['fname'] ?? '') . ' ' . (string)($profile['lname'] ?? '')) ?: 'Staff member';
}

/** Clean profile input. Throws DomainException with a message safe to show. */
function hr_profile_input(array $post): array
{
    $type = hr_text($post['staff_type'] ?? '', 20);
    if (!isset(HR_STAFF_TYPES[$type])) {
        throw new DomainException('Choose whether this person is an employee or a consultant.');
    }
    $data = [
        'staff_type'         => $type,
        'job_title'          => hr_text($post['job_title'] ?? '', 120),
        'department'         => hr_text($post['department'] ?? '', 120),
        'phone'              => hr_text($post['phone'] ?? '', 40),
        'national_id_number' => strtoupper(hr_text($post['national_id_number'] ?? '', 40)),
        'date_of_birth'      => hr_date($post['date_of_birth'] ?? ''),
        'gender'             => in_array($post['gender'] ?? '', ['Male', 'Female'], true) ? $post['gender'] : '',
        'address'            => hr_text($post['address'] ?? '', 255),
        'contract_start'     => hr_date($post['contract_start'] ?? ''),
        'contract_end'       => hr_date($post['contract_end'] ?? ''),
        'monthly_pay'        => hr_money($post['monthly_pay'] ?? ''),
        'bank_name'          => hr_text($post['bank_name'] ?? '', 120),
        'bank_account'       => hr_text($post['bank_account'] ?? '', 60),
        'mobile_money'       => hr_text($post['mobile_money'] ?? '', 40),
        'tin'                => hr_text($post['tin'] ?? '', 40),
        'nok_name'           => hr_text($post['nok_name'] ?? '', 150),
        'nok_relationship'   => hr_text($post['nok_relationship'] ?? '', 60),
        'nok_phone'          => hr_text($post['nok_phone'] ?? '', 40),
        'nok_address'        => hr_text($post['nok_address'] ?? '', 255),
        'notes'              => hr_text($post['notes'] ?? '', 4000),
        'status'             => ($post['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
    ];
    if ($data['contract_start'] && $data['contract_end'] && $data['contract_end'] < $data['contract_start']) {
        throw new DomainException('The contract end date is before the start date.');
    }
    return $data;
}

function hr_profile_create(mysqli $conn, int $userId, array $data, int $actorId): int
{
    hr_ensure_schema($conn);
    $s = $conn->prepare('SELECT id FROM user WHERE id = ? AND role IN (1, 2) LIMIT 1');
    $s->bind_param('i', $userId);
    $s->execute();
    $ok = (bool)$s->get_result()->fetch_assoc();
    $s->close();
    if (!$ok) {
        throw new DomainException('Choose an admin or loan officer account for this profile.');
    }
    if (hr_profile_for_user($conn, $userId)) {
        throw new DomainException('This person already has a staff profile.');
    }

    $cols = HR_PROFILE_FIELDS;
    $sql = 'INSERT INTO staff_profiles (user_id, created_by, ' . implode(', ', $cols) . ') VALUES (?, ?'
        . str_repeat(', ?', count($cols)) . ')';
    $values = array_merge([$userId, $actorId], array_map(static fn($c) => $data[$c], $cols));
    $s = $conn->prepare($sql);
    $s->bind_param('ii' . hr_bind_types($cols), ...$values);
    $s->execute();
    $id = (int)$s->insert_id;
    $s->close();
    return $id;
}

function hr_profile_update(mysqli $conn, int $profileId, array $data): void
{
    $cols = HR_PROFILE_FIELDS;
    $sql = 'UPDATE staff_profiles SET ' . implode(', ', array_map(static fn($c) => "$c = ?", $cols)) . ' WHERE id = ?';
    $values = array_merge(array_map(static fn($c) => $data[$c], $cols), [$profileId]);
    $s = $conn->prepare($sql);
    $s->bind_param(hr_bind_types($cols) . 'i', ...$values);
    $s->execute();
    $s->close();
}

function hr_bind_types(array $cols): string
{
    return implode('', array_map(static fn($c) => $c === 'monthly_pay' ? 'd' : 's', $cols));
}

/* ---------------------------------------------------------------------
 * Files
 * ------------------------------------------------------------------- */

function hr_storage_root(): string
{
    return dirname(__DIR__) . '/storage/staff_documents';
}

function hr_ensure_storage(int $profileId): string
{
    $root = hr_storage_root();
    $dir = $root . '/' . $profileId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create staff document folder.');
    }
    $guards = [
        dirname($root) . '/.htaccess' => "Require all denied\nDeny from all\n",
        dirname($root) . '/index.html' => '',
        $root . '/index.html' => '',
        $dir . '/index.html' => '',
    ];
    foreach ($guards as $path => $content) {
        if (!is_file($path)) {
            @file_put_contents($path, $content);
        }
    }
    return $dir;
}

/** True when the form field holds a file the user chose. */
function hr_has_upload(?array $file): bool
{
    return is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && !empty($file['tmp_name']);
}

/** Validate and store an upload. Throws DomainException with a message safe to show. */
function hr_store_file(mysqli $conn, int $profileId, string $type, string $title, array $file, int $actorId): int
{
    hr_ensure_schema($conn);
    $types = hr_file_types();
    if (!isset($types[$type])) {
        throw new DomainException('Choose what kind of document this is.');
    }
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
        throw new DomainException('Choose a file to upload.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int)$file['size'] > HR_FILE_MAX_BYTES) {
        throw new DomainException('The file is too large. The maximum size is 5 MB.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new DomainException('The upload did not complete. Please try again.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $ext = HR_FILE_MIME[$mime] ?? '';
    if ($ext === '') {
        throw new DomainException('Only PDF files or JPG, PNG and WebP images are accepted.');
    }

    $title = hr_text(preg_replace('/\s+/', ' ', $title) ?? '', 150) ?: $types[$type]['label'];
    $dir = hr_ensure_storage($profileId);
    $filename = $type . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        throw new RuntimeException('move_uploaded_file failed for ' . $filename);
    }
    $relative = $profileId . '/' . $filename;
    $original = mb_substr(basename((string)($file['name'] ?? $filename)), 0, 255, 'UTF-8');
    $size = (int)$file['size'];

    try {
        $s = $conn->prepare('INSERT INTO staff_files (profile_id, file_type, title, file_path, original_name, mime_type, file_size, uploaded_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $s->bind_param('isssssii', $profileId, $type, $title, $relative, $original, $mime, $size, $actorId);
        $s->execute();
        $id = (int)$s->insert_id;
        $s->close();
    } catch (Throwable $e) {
        @unlink($dir . '/' . $filename);
        throw $e;
    }
    return $id;
}

function hr_file_find(mysqli $conn, int $fileId): ?array
{
    hr_ensure_schema($conn);
    $s = $conn->prepare('SELECT * FROM staff_files WHERE id = ? LIMIT 1');
    $s->bind_param('i', $fileId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

/** Files kept on the profile itself (not report or invoice attachments). */
function hr_profile_files(mysqli $conn, int $profileId): array
{
    hr_ensure_schema($conn);
    $s = $conn->prepare("SELECT * FROM staff_files WHERE profile_id = ? AND file_type NOT IN ('report','invoice')
                         ORDER BY created_at DESC, id DESC");
    $s->bind_param('i', $profileId);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}

function hr_file_delete(mysqli $conn, array $file): void
{
    $s = $conn->prepare('DELETE FROM staff_files WHERE id = ?');
    $id = (int)$file['id'];
    $s->bind_param('i', $id);
    $s->execute();
    $s->close();
    $path = hr_file_path($file);
    if ($path !== null) {
        @unlink($path);
    }
}

function hr_file_path(array $file): ?string
{
    $relative = (string)$file['file_path'];
    if (!preg_match('#^\d+/[a-z_]+_[a-f0-9]{24}\.(jpg|png|webp|pdf)$#', $relative)) {
        return null;
    }
    $path = hr_storage_root() . '/' . $relative;
    return is_file($path) ? $path : null;
}

function hr_file_link(?array $file, string $label = ''): string
{
    if (!$file) {
        return '';
    }
    $label = $label !== '' ? $label : ((string)$file['title'] ?: 'View file');
    return '<a href="hr_file.php?id=' . (int)$file['id'] . '" target="_blank" rel="noopener"><i class="fas fa-paperclip me-1"></i>'
        . hr_h($label) . '</a>';
}

function hr_file_stream(array $file, bool $download = false): void
{
    $path = hr_file_path($file);
    if ($path === null) {
        http_response_code(404);
        exit('File not found.');
    }
    $mime = array_key_exists($file['mime_type'], HR_FILE_MIME) ? $file['mime_type'] : 'application/octet-stream';
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($file['original_name'] ?: basename($path)));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; plugin-types application/pdf");
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

/* ---------------------------------------------------------------------
 * KPIs and appraisals
 * ------------------------------------------------------------------- */

function hr_kpis(mysqli $conn, int $profileId, bool $activeOnly = false): array
{
    hr_ensure_schema($conn);
    $sql = 'SELECT * FROM staff_kpis WHERE profile_id = ?' . ($activeOnly ? " AND status = 'active'" : '')
        . " ORDER BY status = 'active' DESC, id";
    $s = $conn->prepare($sql);
    $s->bind_param('i', $profileId);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}

function hr_appraisals(mysqli $conn, int $profileId): array
{
    hr_ensure_schema($conn);
    $s = $conn->prepare('SELECT a.*, CONCAT(u.fname, " ", u.lname) AS reviewer_name
                         FROM staff_appraisals a LEFT JOIN user u ON u.id = a.reviewed_by
                         WHERE a.profile_id = ? ORDER BY a.review_date DESC, a.id DESC');
    $s->bind_param('i', $profileId);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}

function hr_rating_label(int $rating): string
{
    return [1 => 'Poor', 2 => 'Needs improvement', 3 => 'Meets expectations', 4 => 'Exceeds expectations', 5 => 'Outstanding'][$rating] ?? '-';
}

/** @return array<int,array{actual:string,comment:string}> keyed by KPI id */
function hr_json_map(?string $json): array
{
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : [];
}

/* ---------------------------------------------------------------------
 * Monthly reports, invoices, payments
 * ------------------------------------------------------------------- */

function hr_report_for(mysqli $conn, int $profileId, string $period): ?array
{
    hr_ensure_schema($conn);
    $s = $conn->prepare('SELECT * FROM staff_monthly_reports WHERE profile_id = ? AND period = ? LIMIT 1');
    $s->bind_param('is', $profileId, $period);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

function hr_invoice_for(mysqli $conn, int $profileId, string $period): ?array
{
    hr_ensure_schema($conn);
    $s = $conn->prepare('SELECT * FROM staff_invoices WHERE profile_id = ? AND period = ? LIMIT 1');
    $s->bind_param('is', $profileId, $period);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

function hr_payment_for(mysqli $conn, int $profileId, string $period): ?array
{
    hr_ensure_schema($conn);
    $s = $conn->prepare('SELECT * FROM staff_payments WHERE profile_id = ? AND period = ? LIMIT 1');
    $s->bind_param('is', $profileId, $period);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

function hr_reports(mysqli $conn, int $profileId): array
{
    hr_ensure_schema($conn);
    $s = $conn->prepare('SELECT * FROM staff_monthly_reports WHERE profile_id = ? ORDER BY period DESC');
    $s->bind_param('i', $profileId);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}

function hr_invoices(mysqli $conn, int $profileId): array
{
    hr_ensure_schema($conn);
    $s = $conn->prepare('SELECT * FROM staff_invoices WHERE profile_id = ? ORDER BY period DESC');
    $s->bind_param('i', $profileId);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}

/** period => payment row */
function hr_payments(mysqli $conn, int $profileId): array
{
    hr_ensure_schema($conn);
    $s = $conn->prepare('SELECT * FROM staff_payments WHERE profile_id = ? ORDER BY period DESC');
    $s->bind_param('i', $profileId);
    $s->execute();
    $out = [];
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $out[$row['period']] = $row;
    }
    $s->close();
    return $out;
}

/** Staff may change a submission until it is approved. */
function hr_is_editable(?array $row): bool
{
    return !$row || in_array($row['status'], ['submitted', 'returned'], true);
}

/**
 * Can this month be paid, and if not, why. A report must be approved; a
 * consultant's invoice must be approved too.
 * @return array{ok:bool, reasons:string[]}
 */
function hr_pay_check(array $profile, ?array $report, ?array $invoice): array
{
    $reasons = [];
    if (!$report) {
        $reasons[] = 'Monthly report not submitted';
    } elseif ($report['status'] !== 'approved') {
        $reasons[] = 'Monthly report not approved';
    }
    if ($profile['staff_type'] === 'consultant') {
        if (!$invoice) {
            $reasons[] = 'Invoice not submitted';
        } elseif ($invoice['status'] !== 'approved') {
            $reasons[] = 'Invoice not approved';
        }
    }
    return ['ok' => !$reasons, 'reasons' => $reasons];
}

/** Amount to pay for a month: the approved invoice for consultants, otherwise the monthly pay. */
function hr_pay_amount(array $profile, ?array $invoice): float
{
    if ($profile['staff_type'] === 'consultant' && $invoice) {
        return (float)$invoice['amount'];
    }
    return (float)$profile['monthly_pay'];
}

/** Reports and invoices waiting for a reviewer. */
function hr_pending_review_count(mysqli $conn): int
{
    try {
        hr_ensure_schema($conn);
        $r = $conn->query("SELECT
            (SELECT COUNT(*) FROM staff_monthly_reports WHERE status = 'submitted') +
            (SELECT COUNT(*) FROM staff_invoices WHERE status = 'submitted') AS c")->fetch_assoc();
        return (int)($r['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Number of things the staff member still owes for the current month (report, invoice). */
function hr_my_due_count(mysqli $conn, array $profile): int
{
    if ($profile['status'] !== 'active') {
        return 0;
    }
    $period = hr_current_period();
    $due = 0;
    $report = hr_report_for($conn, (int)$profile['id'], $period);
    if (!$report || $report['status'] === 'returned') {
        $due++;
    }
    if ($profile['staff_type'] === 'consultant') {
        $invoice = hr_invoice_for($conn, (int)$profile['id'], $period);
        if (!$invoice || $invoice['status'] === 'returned') {
            $due++;
        }
    }
    return $due;
}

/* ---------------------------------------------------------------------
 * Email
 * ------------------------------------------------------------------- */

/** Best-effort email; failures are logged and never block the action. */
function hr_mail(string $to, string $subject, string $html): void
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    try {
        require_once dirname(__DIR__) . '/php/mail-function.php';
        sendEmail($to, $subject, $html, strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));
    } catch (Throwable $e) {
        error_log('Staff HR email failed: ' . $e->getMessage());
    }
}

/** Tell active admins that a report or invoice was submitted. */
function hr_mail_admins(mysqli $conn, string $subject, string $html): void
{
    try {
        $rows = $conn->query("SELECT email FROM user WHERE role = 1 AND status = 1 AND email <> ''")->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $e) {
        return;
    }
    foreach ($rows as $row) {
        hr_mail((string)$row['email'], $subject, $html);
    }
}
