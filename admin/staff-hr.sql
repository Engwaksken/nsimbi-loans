-- Staff HR: staff profiles, documents, KPIs, appraisals, monthly reports,
-- consultant invoices and monthly payments.
--
-- These tables are also created automatically the first time Staff HR is
-- opened (includes/staff_hr.php). Run this once instead if the database user
-- is not allowed to CREATE TABLE.

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
