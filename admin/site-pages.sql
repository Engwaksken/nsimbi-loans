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
