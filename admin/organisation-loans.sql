-- Run once against the application database to enable organisation loan clients.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
