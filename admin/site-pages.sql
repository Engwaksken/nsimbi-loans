CREATE TABLE IF NOT EXISTS site_pages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    meta_description VARCHAR(320) NOT NULL DEFAULT '',
    hero_title VARCHAR(220) NOT NULL DEFAULT '',
    hero_subtitle VARCHAR(500) NOT NULL DEFAULT '',
    body_content TEXT NOT NULL,
    cta_label VARCHAR(80) NOT NULL DEFAULT 'Learn more',
    cta_url VARCHAR(500) NOT NULL DEFAULT '#',
    hero_image VARCHAR(500) NOT NULL DEFAULT '',
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_site_pages_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_pages (title, slug, meta_description, hero_title, hero_subtitle, body_content, cta_label, cta_url, status)
VALUES ('Home', 'home', 'Capital and support for women-led businesses across Uganda and Africa.', 'Capital that sees the potential in every woman.', 'Backing women-led businesses with the capital, confidence and community to build what comes next.', 'Nsimbi Impact is a data-driven, female-led investment platform transforming financial access for women-led businesses across Sub-Saharan Africa, starting in Uganda.\n\nWe combine flexible capital with trusted community partnerships and practical business support—because talent is everywhere, but access to finance is not.', 'Explore financing', 'clients/index.php', 'published')
ON DUPLICATE KEY UPDATE slug = VALUES(slug);

-- Website CMS: global branding, page sections and hero slides.
-- admin/pages.php creates these automatically (includes/site_cms.php); they are listed here for reference.
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_page_sections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page_id INT UNSIGNED NOT NULL,
    section_type VARCHAR(30) NOT NULL DEFAULT 'text',
    label VARCHAR(120) NOT NULL DEFAULT '',
    heading VARCHAR(220) NOT NULL DEFAULT '',
    heading_accent VARCHAR(220) NOT NULL DEFAULT '',
    body TEXT NULL,
    items TEXT NULL,
    media_type ENUM('none','image','video') NOT NULL DEFAULT 'none',
    media_url VARCHAR(500) NOT NULL DEFAULT '',
    button_label VARCHAR(80) NOT NULL DEFAULT '',
    button_url VARCHAR(500) NOT NULL DEFAULT '',
    anchor VARCHAR(60) NOT NULL DEFAULT '',
    menu_label VARCHAR(60) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sections_page (page_id, sort_order),
    CONSTRAINT fk_sections_page FOREIGN KEY (page_id) REFERENCES site_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_page_slides (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page_id INT UNSIGNED NOT NULL,
    media_type ENUM('image','video') NOT NULL DEFAULT 'image',
    media_url VARCHAR(500) NOT NULL,
    title VARCHAR(220) NOT NULL DEFAULT '',
    caption VARCHAR(500) NOT NULL DEFAULT '',
    button_label VARCHAR(80) NOT NULL DEFAULT '',
    button_url VARCHAR(500) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slides_page (page_id, sort_order),
    CONSTRAINT fk_slides_page FOREIGN KEY (page_id) REFERENCES site_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pages can be hidden from the header menu (added automatically if missing).
-- ALTER TABLE site_pages ADD COLUMN show_in_menu TINYINT(1) NOT NULL DEFAULT 1 AFTER status;

-- Contact and Join as Donor / ESO form submissions (admin: Website -> Enquiries).
CREATE TABLE IF NOT EXISTS site_enquiries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    form_type ENUM('contact','donor') NOT NULL,
    partner_type VARCHAR(40) NOT NULL DEFAULT '',
    name VARCHAR(150) NOT NULL,
    organisation VARCHAR(200) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(40) NOT NULL DEFAULT '',
    country VARCHAR(100) NOT NULL DEFAULT '',
    website VARCHAR(255) NOT NULL DEFAULT '',
    subject VARCHAR(200) NOT NULL DEFAULT '',
    interests VARCHAR(500) NOT NULL DEFAULT '',
    message TEXT NULL,
    status ENUM('new','read','handled') NOT NULL DEFAULT 'new',
    ip_hash CHAR(64) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enquiries_status (status, created_at),
    INDEX idx_enquiries_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
