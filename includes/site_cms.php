<?php
declare(strict_types=1);

/**
 * Shared website CMS helpers: global brand settings (name, logo, favicon,
 * fonts), page sections, hero slides, media uploads and public rendering.
 *
 * Include from a page's top-level scope so $conn from database.php is global.
 */

require_once __DIR__ . '/database.php';

const SITE_UPLOAD_DIR = 'uploads/site';

/* ------------------------------------------------------------------ */
/* Schema                                                              */
/* ------------------------------------------------------------------ */

function site_cms_ensure_schema(mysqli $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $db->query("CREATE TABLE IF NOT EXISTS site_settings (
        setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS site_page_sections (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS site_page_slides (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    site_cms_seed_home_sections($db);
}

/** Copy the original homepage layout into editable sections, once. */
function site_cms_seed_home_sections(mysqli $db): void
{
    if (site_settings($db)['home_sections_seeded'] === '1') return;
    $home = $db->query("SELECT id, body_content FROM site_pages WHERE slug='home' LIMIT 1")->fetch_assoc();
    if (!$home) return;

    $count = (int)$db->query('SELECT COUNT(*) AS c FROM site_page_sections WHERE page_id=' . (int)$home['id'])->fetch_assoc()['c'];
    if ($count === 0) {
        $order = 0;
        foreach (site_default_home_sections((string)$home['body_content']) as $section) {
            $section['sort_order'] = ($order += 10);
            $section['is_active'] = 1;
            site_section_save($db, (int)$home['id'], 0, $section);
        }
    }
    site_settings_save($db, ['home_sections_seeded' => '1']);
}

/* ------------------------------------------------------------------ */
/* Global settings                                                     */
/* ------------------------------------------------------------------ */

function site_font_catalog(): array
{
    // name => [CSS fallback, Google Fonts weight axis]
    return [
        'DM Sans' => ['sans-serif', '400;500;600;700'],
        'Inter' => ['sans-serif', '400;500;600;700;800'],
        'Lato' => ['sans-serif', '400;700;900'],
        'Manrope' => ['sans-serif', '400;500;600;700;800'],
        'Montserrat' => ['sans-serif', '400;500;600;700;800'],
        'Nunito' => ['sans-serif', '400;500;600;700;800'],
        'Open Sans' => ['sans-serif', '400;500;600;700;800'],
        'Outfit' => ['sans-serif', '400;500;600;700;800'],
        'Plus Jakarta Sans' => ['sans-serif', '400;500;600;700;800'],
        'Poppins' => ['sans-serif', '400;500;600;700;800'],
        'Raleway' => ['sans-serif', '400;500;600;700;800'],
        'Roboto' => ['sans-serif', '400;500;700;900'],
        'Source Sans 3' => ['sans-serif', '400;500;600;700;800'],
        'Work Sans' => ['sans-serif', '400;500;600;700;800'],
        'Cormorant Garamond' => ['serif', '400;500;600;700'],
        'Libre Baskerville' => ['serif', '400;700'],
        'Lora' => ['serif', '400;500;600;700'],
        'Merriweather' => ['serif', '400;700;900'],
        'Playfair Display' => ['serif', '400;500;600;700;800'],
    ];
}

function site_setting_defaults(): array
{
    return [
        'site_name' => 'Nsimbi Impact',
        'logo' => '/assets/img/logo.png',
        'favicon' => '/assets/img/favicon.png',
        'heading_font' => 'Playfair Display',
        'body_font' => 'DM Sans',
        'panel_font' => 'Plus Jakarta Sans',
        'home_sections_seeded' => '0',
    ];
}

function site_settings(?mysqli $db = null, bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;
    $settings = site_setting_defaults();
    $db ??= ($GLOBALS['conn'] ?? null);
    if ($db instanceof mysqli) {
        try {
            $result = $db->query('SELECT setting_key, setting_value FROM site_settings');
            while ($row = $result->fetch_assoc()) {
                if (array_key_exists($row['setting_key'], $settings) && trim((string)$row['setting_value']) !== '') {
                    $settings[$row['setting_key']] = (string)$row['setting_value'];
                }
            }
        } catch (Throwable $ignored) {
            // Table not created yet: defaults apply until an admin opens the CMS.
        }
    }
    $fonts = site_font_catalog();
    foreach (['heading_font', 'body_font', 'panel_font'] as $key) {
        if (!isset($fonts[$settings[$key]])) $settings[$key] = site_setting_defaults()[$key];
    }
    return $cache = $settings;
}

function site_settings_save(mysqli $db, array $values): void
{
    $stmt = $db->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($values as $key => $value) {
        $key = (string)$key; $value = (string)$value;
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
    $stmt->close();
    site_settings($db, true);
}

function site_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** CSS font-family value. Only catalog names reach here, so it is safe inside <style>. */
function site_font_stack(string $font): string
{
    if (!isset(site_font_catalog()[$font])) $font = 'DM Sans';
    $fallback = site_font_catalog()[$font][0] ?? 'sans-serif';
    return "'" . str_replace("'", '', $font) . "', " . ($fallback === 'serif' ? 'Georgia, serif' : 'Arial, sans-serif');
}

function site_google_fonts_url(array $fonts): string
{
    $catalog = site_font_catalog();
    $families = [];
    foreach (array_unique($fonts) as $font) {
        if (!isset($catalog[$font])) continue;
        $families[] = 'family=' . str_replace(' ', '+', $font) . ':wght@' . $catalog[$font][1];
    }
    return 'https://fonts.googleapis.com/css2?' . implode('&', $families) . '&display=swap';
}

/** Favicon tags for any page on the platform. */
function site_favicon_tags(): string
{
    $icon = site_e(site_settings()['favicon']);
    return '<link rel="icon" href="' . $icon . '"><link rel="apple-touch-icon" href="' . $icon . '">';
}

/** Fonts for the public website: overrides --display / --sans used by its stylesheets. */
function site_public_font_tags(): string
{
    $s = site_settings();
    return '<link rel="stylesheet" href="' . site_e(site_google_fonts_url([$s['heading_font'], $s['body_font']])) . '">'
        . '<style>:root{--display:' . site_font_stack($s['heading_font']) . ';--sans:' . site_font_stack($s['body_font']) . '}'
        . 'body{font-family:var(--sans)}h1,h2,h3,.hero h1,.editorial-page h1{font-family:var(--display)}</style>';
}

/** Font for the admin, client and staff dashboards. Icons keep their own font. */
function site_panel_font_tags(): string
{
    $font = site_settings()['panel_font'];
    if ($font === site_setting_defaults()['panel_font']) return '';
    return '<link rel="stylesheet" href="' . site_e(site_google_fonts_url([$font])) . '">'
        . '<style>body,body *:not(i):not([class*="fa"]):not([class^="ni"]):not([class*=" ni-"]):not(.material-icons){font-family:'
        . site_font_stack($font) . '!important}</style>';
}

/** Wordmark HTML: last word of the site name is highlighted. */
function site_wordmark_html(): string
{
    $name = trim(site_settings()['site_name']);
    $parts = preg_split('/\s+/', $name) ?: [$name];
    if (count($parts) < 2) return site_e($name);
    $last = array_pop($parts);
    return site_e(implode(' ', $parts)) . ' <b>' . site_e($last) . '</b>';
}

/* ------------------------------------------------------------------ */
/* URLs and uploads                                                    */
/* ------------------------------------------------------------------ */

/** Link targets for buttons: anchors, site paths, https, mailto and tel. */
function site_clean_link(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('/^#[A-Za-z0-9_-]*$/', $url)) return $url;
    if (preg_match('#^/(?!/)[A-Za-z0-9._~/?=&%\#+-]*$#', $url)) return $url;
    if (preg_match('#^https?://[^\s"\'<>]+$#i', $url)) return $url;
    if (preg_match('/^(mailto|tel):[^\s"\'<>]+$/i', $url)) return $url;
    if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/?=&%\#-]*$#', $url)) return $url;
    return '#';
}

/** Media sources: https URLs or files on this site. */
function site_clean_media(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https://[^\s"\'<>]+$#i', $url)) return $url;
    if (preg_match('#^/(?!/)[A-Za-z0-9._~/-]+$#', $url)) return $url;
    return '';
}

function site_upload_rules(string $kind): array
{
    return match ($kind) {
        'favicon' => [1 * 1024 * 1024, ['image/png' => 'png', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico']],
        'video' => [100 * 1024 * 1024, ['video/mp4' => 'mp4', 'video/webm' => 'webm']],
        default => [8 * 1024 * 1024, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif']],
    };
}

/**
 * Store an uploaded file under /uploads/site.
 * Returns ['url' => string|null, 'error' => string|null]; url is null when nothing was uploaded.
 */
function site_store_upload(?array $file, string $kind): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['url' => null, 'error' => null];
    [$maxBytes, $types] = site_upload_rules($kind);
    $error = (int)$file['error'];
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['url' => null, 'error' => 'The file is larger than the server allows (' . ini_get('upload_max_filesize') . '). Use a smaller file or paste a link instead.'];
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        return ['url' => null, 'error' => 'The upload did not complete. Please try again.'];
    }
    if ((int)$file['size'] > $maxBytes) {
        return ['url' => null, 'error' => 'The file is too large. Maximum size is ' . round($maxBytes / 1048576) . ' MB.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']) ?: '';
    if (!isset($types[$mime])) {
        return ['url' => null, 'error' => 'Unsupported file type. Allowed: ' . implode(', ', array_unique(array_values($types))) . '.'];
    }

    $dir = dirname(__DIR__) . '/' . SITE_UPLOAD_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['url' => null, 'error' => 'Could not create the upload folder.'];
    }
    $guard = $dir . '/.htaccess';
    if (!is_file($guard)) {
        file_put_contents($guard, "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(?i:php[0-9]?|phtml|phar|pl|py|cgi|sh|html?|svg)$\">\n    Require all denied\n</FilesMatch>\n");
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $types[$mime];
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        return ['url' => null, 'error' => 'Could not save the uploaded file.'];
    }
    return ['url' => '/' . SITE_UPLOAD_DIR . '/' . $name, 'error' => null];
}

/** Delete an uploaded file when nothing references it any more. */
function site_discard_media(mysqli $db, string $url): void
{
    $prefix = '/' . SITE_UPLOAD_DIR . '/';
    if (!str_starts_with($url, $prefix) || !preg_match('#^[A-Za-z0-9.-]+$#', substr($url, strlen($prefix)))) return;
    $checks = [
        'SELECT COUNT(*) AS c FROM site_settings WHERE setting_value = ?',
        'SELECT COUNT(*) AS c FROM site_page_sections WHERE media_url = ?',
        'SELECT COUNT(*) AS c FROM site_page_slides WHERE media_url = ?',
        'SELECT COUNT(*) AS c FROM site_pages WHERE hero_image = ?',
    ];
    foreach ($checks as $sql) {
        $stmt = $db->prepare($sql); $stmt->bind_param('s', $url); $stmt->execute();
        $inUse = (int)$stmt->get_result()->fetch_assoc()['c'] > 0; $stmt->close();
        if ($inUse) return;
    }
    $path = dirname(__DIR__) . $url;
    if (is_file($path)) @unlink($path);
}

/** YouTube / Vimeo link -> embeddable player URL, or null for direct files. */
function site_video_embed(string $url, bool $background): ?string
{
    if (preg_match('#^https://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m)) {
        $id = $m[1];
        $query = $background
            ? "autoplay=1&mute=1&loop=1&playlist={$id}&controls=0&rel=0&playsinline=1&modestbranding=1"
            : 'rel=0&modestbranding=1';
        return "https://www.youtube-nocookie.com/embed/{$id}?{$query}";
    }
    if (preg_match('#^https://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d+)#', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1] . ($background ? '?background=1' : '');
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* Sections                                                            */
/* ------------------------------------------------------------------ */

/**
 * Section types. "fields" drives which inputs the admin modal shows.
 */
function site_section_types(): array
{
    return [
        'text' => ['name' => 'Text block', 'icon' => 'align-left', 'help' => 'Heading on the left, text on the right. Add an image or video to show it beside the text.', 'fields' => ['label', 'heading', 'heading_accent', 'body', 'media', 'button']],
        'statement' => ['name' => 'Highlight statement', 'icon' => 'quote-left', 'help' => 'Short statement on a soft background. Two in a row sit side by side (e.g. Vision and Mission).', 'fields' => ['label', 'heading', 'heading_accent', 'body']],
        'features' => ['name' => 'Checklist cards', 'icon' => 'list-check', 'help' => 'Cards with ticked points.', 'fields' => ['label', 'heading', 'heading_accent', 'body', 'items', 'media']],
        'cards' => ['name' => 'Numbered icon cards', 'icon' => 'table-cells-large', 'help' => 'A row of numbered cards, each with an icon and a short line.', 'fields' => ['label', 'heading', 'heading_accent', 'items']],
        'media' => ['name' => 'Image / video', 'icon' => 'photo-film', 'help' => 'A wide image or video with an optional heading and caption.', 'fields' => ['label', 'heading', 'heading_accent', 'body', 'media']],
        'cta' => ['name' => 'Call to action', 'icon' => 'bullhorn', 'help' => 'A bold band with a heading, a sentence and a button.', 'fields' => ['heading', 'heading_accent', 'body', 'button']],
        'newsletter' => ['name' => 'Newsletter signup', 'icon' => 'envelope-open-text', 'help' => 'Email signup form. The text field becomes the form label.', 'fields' => ['label', 'heading', 'heading_accent', 'body']],
    ];
}

function site_default_home_sections(string $overviewBody = ''): array
{
    $overviewBody = $overviewBody !== '' ? $overviewBody : "Nsimbi Impact is a data-driven, female-led investment platform transforming financial access for women-led businesses across Sub-Saharan Africa, starting in Uganda.\n\nWe combine flexible capital with trusted community partnerships and practical business support—because talent is everywhere, but access to finance is not.";
    $base = ['label' => '', 'heading' => '', 'heading_accent' => '', 'body' => '', 'items' => '', 'media_type' => 'none', 'media_url' => '', 'button_label' => '', 'button_url' => '', 'anchor' => '', 'menu_label' => '', 'is_active' => 1];
    return array_map(static fn(array $s): array => $s + $base, [
        ['section_type' => 'text', 'anchor' => 'story', 'menu_label' => 'Overview', 'label' => 'AN OVERVIEW', 'heading' => 'Investing in the', 'heading_accent' => 'power of women.', 'body' => $overviewBody, 'button_label' => 'Already a client? Sign in', 'button_url' => '/login'],
        ['section_type' => 'statement', 'anchor' => 'vision', 'menu_label' => 'Our Vision', 'label' => 'OUR VISION', 'heading' => 'Empowered women.', 'heading_accent' => 'Stronger communities.', 'body' => 'Our vision is to cultivate a robust community of empowered, financially independent women who make a positive difference in their communities and the world.'],
        ['section_type' => 'statement', 'anchor' => 'mission', 'menu_label' => 'Our Mission', 'label' => 'OUR MISSION', 'heading' => 'Data-informed investment.', 'heading_accent' => 'Lasting change.', 'body' => 'We catalyze change by using data insights to drive smart investments and fuel the success of the next generation of women business leaders in Africa.'],
        ['section_type' => 'features', 'anchor' => 'approach', 'menu_label' => 'Approach & Insight', 'label' => 'APPROACH & INSIGHT', 'heading' => 'Why this moment.', 'heading_accent' => 'Why this model.', 'body' => 'We bring community trust, technology and financial access together to help women-led businesses move forward.', 'items' => "## Why Now?\nGrowing digital penetration\nShifting financial regulations\nRising demand for financial inclusion\n## What is New?\nVoice-powered multilingual interfaces\nAI-driven credit scoring\nCapital designed around women's realities\n> Access creates possibility. | Community, Capital and Confidence"],
        ['section_type' => 'cards', 'anchor' => 'impact', 'menu_label' => 'Impact', 'label' => 'OUR IMPACT VISION', 'heading' => 'A ripple effect that', 'heading_accent' => 'reaches everyone.', 'items' => "fa-chart-line | Economic empowerment and independence\nfa-house-chimney | Enhanced quality of life and family wellbeing\nfa-people-group | Social empowerment and community development"],
        ['section_type' => 'newsletter', 'label' => 'STAY CONNECTED', 'heading' => 'Stories, insights and', 'heading_accent' => 'new possibilities.', 'body' => 'Subscribe to our newsletter'],
    ]);
}

function site_section_save(mysqli $db, int $pageId, int $id, array $s): int
{
    $type = array_key_exists($s['section_type'] ?? '', site_section_types()) ? $s['section_type'] : 'text';
    $mediaType = in_array($s['media_type'] ?? 'none', ['image', 'video'], true) ? $s['media_type'] : 'none';
    $mediaUrl = site_clean_media((string)($s['media_url'] ?? ''));
    if ($mediaUrl === '') $mediaType = 'none';
    $anchor = trim((string)preg_replace('/[^a-z0-9-]+/', '-', strtolower((string)($s['anchor'] ?? ''))), '-');
    $vals = [
        $type,
        mb_substr(trim((string)($s['label'] ?? '')), 0, 120),
        mb_substr(trim((string)($s['heading'] ?? '')), 0, 220),
        mb_substr(trim((string)($s['heading_accent'] ?? '')), 0, 220),
        trim((string)($s['body'] ?? '')),
        trim((string)($s['items'] ?? '')),
        $mediaType,
        $mediaUrl,
        mb_substr(trim((string)($s['button_label'] ?? '')), 0, 80),
        site_clean_link((string)($s['button_url'] ?? '')),
        mb_substr($anchor, 0, 60),
        mb_substr(trim((string)($s['menu_label'] ?? '')), 0, 60),
        !empty($s['is_active']) ? 1 : 0,
    ];
    if ($id > 0) {
        $stmt = $db->prepare('UPDATE site_page_sections SET section_type=?, label=?, heading=?, heading_accent=?, body=?, items=?, media_type=?, media_url=?, button_label=?, button_url=?, anchor=?, menu_label=?, is_active=? WHERE id=? AND page_id=?');
        $params = [...$vals, $id, $pageId];
        $stmt->bind_param('ssssssssssssiii', ...$params);
    } else {
        $sort = (int)($s['sort_order'] ?? 0);
        if ($sort === 0) {
            $q = $db->prepare('SELECT COALESCE(MAX(sort_order),0)+10 AS n FROM site_page_sections WHERE page_id=?');
            $q->bind_param('i', $pageId); $q->execute(); $sort = (int)$q->get_result()->fetch_assoc()['n']; $q->close();
        }
        $stmt = $db->prepare('INSERT INTO site_page_sections (section_type,label,heading,heading_accent,body,items,media_type,media_url,button_label,button_url,anchor,menu_label,is_active,page_id,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $params = [...$vals, $pageId, $sort];
        $stmt->bind_param('ssssssssssssiii', ...$params);
    }
    $stmt->execute();
    $newId = $id > 0 ? $id : (int)$stmt->insert_id;
    $stmt->close();
    return $newId;
}

/** Swap an item with its neighbour. $table is one of the two CMS tables. */
function site_move_item(mysqli $db, string $table, int $pageId, int $id, string $direction): void
{
    if (!in_array($table, ['site_page_sections', 'site_page_slides'], true)) return;
    $stmt = $db->prepare("SELECT id FROM {$table} WHERE page_id=? ORDER BY sort_order, id");
    $stmt->bind_param('i', $pageId); $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id')); $stmt->close();
    $pos = array_search($id, $ids, true);
    if ($pos === false) return;
    $swap = $direction === 'up' ? $pos - 1 : $pos + 1;
    if (!isset($ids[$swap])) return;
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    $update = $db->prepare("UPDATE {$table} SET sort_order=? WHERE id=?");
    foreach ($ids as $i => $itemId) {
        $order = ($i + 1) * 10;
        $update->bind_param('ii', $order, $itemId); $update->execute();
    }
    $update->close();
}

function site_page_sections(mysqli $db, int $pageId, bool $activeOnly = true): array
{
    $stmt = $db->prepare('SELECT * FROM site_page_sections WHERE page_id=?' . ($activeOnly ? ' AND is_active=1' : '') . ' ORDER BY sort_order, id');
    $stmt->bind_param('i', $pageId); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    return $rows;
}

function site_page_slides(mysqli $db, int $pageId, bool $activeOnly = true): array
{
    $stmt = $db->prepare('SELECT * FROM site_page_slides WHERE page_id=?' . ($activeOnly ? ' AND is_active=1' : '') . ' ORDER BY sort_order, id');
    $stmt->bind_param('i', $pageId); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    return $rows;
}

/** Header menu entries from sections that have both an anchor and a menu label. */
function site_section_menu(array $sections): array
{
    $menu = [];
    foreach ($sections as $s) {
        if ($s['anchor'] !== '' && $s['menu_label'] !== '') $menu[] = ['href' => '#' . $s['anchor'], 'label' => $s['menu_label']];
    }
    return $menu;
}

/* ------------------------------------------------------------------ */
/* Public rendering                                                    */
/* ------------------------------------------------------------------ */

function site_heading_html(array $s): string
{
    $html = site_e($s['heading']);
    if ($s['heading_accent'] !== '') $html .= ($html !== '' ? '<br>' : '') . '<em>' . site_e($s['heading_accent']) . '</em>';
    return $html;
}

function site_media_html(string $type, string $url, string $alt = '', bool $background = false): string
{
    $url = site_clean_media($url);
    if ($url === '') return '';
    if ($type === 'video') {
        $embed = site_video_embed($url, $background);
        if ($embed !== null) {
            return '<iframe src="' . site_e($embed) . '" title="' . site_e($alt ?: 'Video') . '" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen loading="lazy"' . ($background ? ' tabindex="-1" aria-hidden="true"' : '') . '></iframe>';
        }
        return $background
            ? '<video src="' . site_e($url) . '" autoplay muted loop playsinline preload="metadata" aria-hidden="true"></video>'
            : '<video src="' . site_e($url) . '" controls playsinline preload="metadata"></video>';
    }
    return '<img src="' . site_e($url) . '" alt="' . site_e($alt) . '" loading="lazy">';
}

/**
 * Hero slider. $fallback holds the page hero title/subtitle/CTA used when a
 * slide leaves its own text empty.
 */
function site_render_slider(array $slides, array $fallback): string
{
    if (!$slides) return '';
    $out = '<section class="hero-slider" data-slider aria-roledescription="carousel" aria-label="Featured">';
    foreach (array_values($slides) as $i => $slide) {
        $title = $slide['title'] !== '' ? $slide['title'] : ($i === 0 ? $fallback['title'] : '');
        $caption = $slide['caption'] !== '' ? $slide['caption'] : ($i === 0 ? $fallback['subtitle'] : '');
        $btnLabel = $slide['button_label'] !== '' ? $slide['button_label'] : $fallback['cta_label'];
        $btnUrl = $slide['button_label'] !== '' ? $slide['button_url'] : $fallback['cta_url'];
        $out .= '<div class="hs-slide' . ($i === 0 ? ' is-active' : '') . '" role="group" aria-roledescription="slide" aria-label="' . ($i + 1) . ' of ' . count($slides) . '"' . ($i === 0 ? '' : ' aria-hidden="true"') . '>'
            . '<div class="hs-media">' . site_media_html($slide['media_type'], $slide['media_url'], $title, true) . '</div>'
            . '<div class="hs-content">'
            . ($title !== '' ? ($i === 0 ? '<h1>' : '<h2 class="hs-title">') . site_e($title) . ($i === 0 ? '</h1>' : '</h2>') : '')
            . ($caption !== '' ? '<p>' . site_e($caption) . '</p>' : '')
            . '<div class="hero-actions">'
            . ($btnLabel !== '' ? '<a class="button button-lime" href="' . site_e(site_clean_link($btnUrl) ?: '#') . '">' . site_e($btnLabel) . ' <i class="fa-solid fa-arrow-right"></i></a>' : '')
            . ($fallback['secondary'] ?? '')
            . '</div></div></div>';
    }
    if (count($slides) > 1) {
        $out .= '<button type="button" class="hs-nav hs-prev" aria-label="Previous slide"><i class="fa-solid fa-chevron-left"></i></button>'
            . '<button type="button" class="hs-nav hs-next" aria-label="Next slide"><i class="fa-solid fa-chevron-right"></i></button><div class="hs-dots">';
        foreach (array_keys($slides) as $i) {
            $out .= '<button type="button" aria-label="Go to slide ' . ($i + 1) . '"' . ($i === 0 ? ' aria-current="true"' : '') . '></button>';
        }
        $out .= '</div>';
    }
    return $out . '</section>';
}

function site_render_sections(array $sections): string
{
    $out = '';
    $number = 0;
    $count = count($sections);
    for ($i = 0; $i < $count; $i++) {
        $s = $sections[$i];
        if ($s['section_type'] === 'statement') {
            // Group consecutive statements into one band.
            $group = [$s];
            while ($i + 1 < $count && $sections[$i + 1]['section_type'] === 'statement') $group[] = $sections[++$i];
            $out .= '<section class="vision-band"' . ($group[0]['anchor'] !== '' ? ' id="' . site_e($group[0]['anchor']) . '"' : '') . '><div class="section-wrap vision-grid' . (count($group) === 1 ? ' single' : '') . '">';
            foreach ($group as $j => $g) {
                $label = $g['label'] !== '' ? site_section_label($g['label'], ++$number) : '';
                $id = $j > 0 && $g['anchor'] !== '' ? ' id="' . site_e($g['anchor']) . '"' : '';
                $out .= '<article' . $id . '>' . $label . '<h2>' . site_heading_html($g) . '</h2>' . site_paragraphs($g['body']) . '</article>';
            }
            $out .= '</div></section>';
            continue;
        }
        $numbered = !in_array($s['section_type'], ['newsletter', 'cta'], true) && $s['label'] !== '';
        $label = $s['label'] !== '' ? ($numbered ? site_section_label($s['label'], ++$number) : '<div class="section-label">' . site_e($s['label']) . '</div>') : '';
        $out .= site_render_section($s, $label);
    }
    return $out;
}

function site_section_label(string $label, int $number): string
{
    return '<div class="section-label"><span>' . sprintf('%02d', $number) . '</span> ' . site_e($label) . '</div>';
}

function site_paragraphs(?string $text, string $class = ''): string
{
    $text = trim((string)$text);
    if ($text === '') return '';
    $html = '';
    foreach (preg_split("/\R{2,}/", $text) as $para) {
        $html .= '<p' . ($class ? ' class="' . $class . '"' : '') . '>' . nl2br(site_e(trim($para))) . '</p>';
    }
    return $html;
}

function site_render_section(array $s, string $label): string
{
    $id = $s['anchor'] !== '' ? ' id="' . site_e($s['anchor']) . '"' : '';
    $heading = site_heading_html($s);
    $media = $s['media_type'] !== 'none' ? site_media_html($s['media_type'], $s['media_url'], $s['heading']) : '';
    $button = $s['button_label'] !== '' ? '<a class="under-link" href="' . site_e($s['button_url'] ?: '#') . '">' . site_e($s['button_label']) . ' <i class="fa-solid fa-arrow-right"></i></a>' : '';

    switch ($s['section_type']) {
        case 'features':
            [$cards, $highlight] = site_parse_feature_items((string)$s['items']);
            $html = '<section class="approach-section"' . $id . '><div class="section-wrap">' . $label
                . '<div class="approach-heading"><h2>' . $heading . '</h2>' . ($s['body'] !== '' ? '<p>' . nl2br(site_e($s['body'])) . '</p>' : '') . '</div><div class="approach-grid">';
            foreach ($cards as $n => $card) {
                $html .= '<article><span class="approach-number">' . sprintf('%02d', $n + 1) . '</span><h3>' . site_e($card['title']) . '</h3><ul>';
                foreach ($card['points'] as $point) $html .= '<li><i class="fa-solid fa-check" aria-hidden="true"></i> ' . site_e($point) . '</li>';
                $html .= '</ul></article>';
            }
            if ($media !== '') {
                $html .= '<article class="approach-image has-media">' . $media . ($highlight ? '<div><strong>' . site_e($highlight[0]) . '</strong><span>' . site_e($highlight[1]) . '</span></div>' : '') . '</article>';
            } elseif ($highlight) {
                $html .= '<article class="approach-image"><i class="fa-solid fa-seedling"></i><strong>' . site_e($highlight[0]) . '</strong><span>' . site_e($highlight[1]) . '</span></article>';
            }
            return $html . '</div></div></section>';

        case 'cards':
            $html = '<section class="impact-section"' . $id . '><div class="section-wrap">' . $label . '<h2>' . $heading . '</h2><div class="impact-cards">';
            $n = 0;
            foreach (preg_split('/\R/', (string)$s['items']) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                [$icon, $text] = str_contains($line, '|') ? array_map('trim', explode('|', $line, 2)) : ['fa-star', $line];
                $icon = preg_match('/^fa-[a-z0-9-]+$/', $icon) ? $icon : 'fa-star';
                $html .= '<article><span>' . sprintf('%02d', ++$n) . '</span><i class="fa-solid ' . $icon . '"></i><h3>' . site_e($text) . '</h3></article>';
            }
            return $html . '</div></div></section>';

        case 'media':
            return '<section class="media-section section-wrap"' . $id . '>' . $label . ($heading !== '' ? '<h2>' . $heading . '</h2>' : '')
                . '<figure class="media-frame' . ($s['media_type'] === 'video' ? ' is-video' : '') . '">' . $media . '</figure>'
                . ($s['body'] !== '' ? site_paragraphs($s['body'], 'media-caption') : '') . '</section>';

        case 'cta':
            return '<section class="cta-band"' . $id . '><div class="section-wrap"><div><h2>' . $heading . '</h2>' . site_paragraphs($s['body']) . '</div>'
                . ($s['button_label'] !== '' ? '<a class="button button-lime" href="' . site_e($s['button_url'] ?: '#') . '">' . site_e($s['button_label']) . ' <i class="fa-solid fa-arrow-right"></i></a>' : '')
                . '</div></section>';

        case 'newsletter':
            return '<section class="newsletter-section"' . $id . '><div>' . ($s['label'] !== '' ? '<span class="section-label">' . site_e($s['label']) . '</span>' : '') . '<h2>' . $heading . '</h2></div>'
                . '<form class="site-newsletter"><label>' . site_e($s['body'] !== '' ? $s['body'] : 'Subscribe to our newsletter')
                . '</label><div><input type="email" name="email" placeholder="Your email address" required aria-label="Email address"><button type="submit">Subscribe <i class="fa-solid fa-arrow-right"></i></button></div><p aria-live="polite"></p></form></section>';

        default: // text
            $copy = site_paragraphs($s['body']) . $button;
            if ($media !== '') {
                return '<section class="story section-wrap wordpress-overview text-media"' . $id . '>' . $label
                    . '<div class="text-media-grid"><div class="story-copy">' . ($heading !== '' ? '<h2>' . $heading . '</h2>' : '') . $copy . '</div><figure class="media-frame' . ($s['media_type'] === 'video' ? ' is-video' : '') . '">' . $media . '</figure></div></section>';
            }
            return '<section class="story section-wrap wordpress-overview"' . $id . '>' . $label
                . '<div class="story-grid"><h2>' . $heading . '</h2><div class="story-copy">' . $copy . '</div></div></section>';
    }
}

/**
 * Checklist syntax: "## Title" starts a card, plain lines are its points,
 * "> Big line | small line" is the highlight card.
 */
function site_parse_feature_items(string $items): array
{
    $cards = []; $highlight = null;
    foreach (preg_split('/\R/', $items) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (str_starts_with($line, '##')) { $cards[] = ['title' => trim(substr($line, 2)), 'points' => []]; continue; }
        if (str_starts_with($line, '>')) { $parts = array_map('trim', explode('|', substr($line, 1), 2)); $highlight = [$parts[0], $parts[1] ?? '']; continue; }
        if (!$cards) $cards[] = ['title' => '', 'points' => []];
        $cards[count($cards) - 1]['points'][] = ltrim($line, "-* \t");
    }
    return [$cards, $highlight];
}

/* ------------------------------------------------------------------ */
/* Admin helpers                                                       */
/* ------------------------------------------------------------------ */

/** Allow admins (role 1) and managers (role 2) only. */
function site_admin_guard(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['logged_in']) || !in_array((string)($_SESSION['role'] ?? ''), ['1', '2'], true)) {
        header('Location: /login'); exit;
    }
}

function site_csrf_token(): string
{
    if (empty($_SESSION['site_cms_csrf'])) $_SESSION['site_cms_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['site_cms_csrf'];
}

function site_csrf_check(): void
{
    if (!hash_equals(site_csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('Your session expired. Go back, refresh the page and try again.');
    }
}

function site_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** Uploaded file wins over a pasted link. Returns [url, error]. */
function site_media_from_request(string $fileField, string $urlField, string $kind): array
{
    $upload = site_store_upload($_FILES[$fileField] ?? null, $kind);
    if ($upload['error'] !== null) return [null, $upload['error']];
    if ($upload['url'] !== null) return [$upload['url'], null];
    $url = trim((string)($_POST[$urlField] ?? ''));
    if ($url !== '' && site_clean_media($url) === '') return [null, 'Links must start with https:// or point to a file on this site.'];
    return [site_clean_media($url), null];
}
