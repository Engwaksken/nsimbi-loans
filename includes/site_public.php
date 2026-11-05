<?php
declare(strict_types=1);

/** Shared public layout (head, header, footer) for the homepage and CMS pages. */

require_once __DIR__ . '/site_cms.php';

function site_public_head(string $title, string $description): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . site_e($title) . '</title><meta name="description" content="' . site_e($description) . '">'
        . site_favicon_tags()
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">'
        . '<link rel="stylesheet" href="' . site_asset('/assets/css/public-site.css') . '">'
        . '<link rel="stylesheet" href="' . site_asset('/assets/css/nsimbi-theme.css') . '">'
        . '<link rel="stylesheet" href="' . site_asset('/assets/css/site-builder.css') . '">'
        . site_public_style_tags()
        . '</head><body>';
}

/** Cache-busted asset URL. */
function site_asset(string $path): string
{
    $file = dirname(__DIR__) . $path;
    return site_e($path . (is_file($file) ? '?v=' . filemtime($file) : ''));
}

/**
 * @param array $menu     [['href' => '#story', 'label' => 'Overview'], ...]
 * @param array $navPages published CMS pages (title, slug)
 */
function site_public_header(array $menu, array $navPages): string
{
    $s = site_settings();
    $donor = '/join-us';
    $html = '<div class="top-strip"><span><i class="fa-solid fa-location-dot"></i> Plot 12, Corporation Rise, Bukoto | Kampala | Uganda</span><div>'
        . '<a href="' . $donor . '">Join as Donor / ESO</a>'
        . '<a href="https://www.facebook.com/witug/" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>'
        . '<a href="https://x.com/wituganda" aria-label="X"><i class="fa-brands fa-x-twitter"></i></a>'
        . '<a href="https://www.linkedin.com/company/witu/" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a></div></div>'
        . '<header class="public-header">' . site_brand_link('header') . '<nav>';
    foreach ($menu as $item) {
        $html .= '<a href="' . site_e($item['href']) . '">' . site_e($item['label']) . '</a>';
    }
    $html .= '<a class="mobile-apply" href="/apply">Apply for a Loan</a>';
    foreach ($navPages as $navPage) {
        $html .= '<a href="/' . site_e($navPage['slug']) . '">' . site_e($navPage['title']) . '</a>';
    }
    return $html . '</nav><div class="header-actions"><a class="header-cta donor-cta" href="' . $donor . '">Join as Donor / ESO</a>'
        . '<a class="header-cta" href="/apply">Apply for a Loan <i class="fa-solid fa-arrow-right"></i></a></div>'
        . '<button class="menu-toggle" aria-label="Toggle menu"><i class="fa-solid fa-bars"></i></button></header>';
}

function site_public_footer(): string
{
    $s = site_settings();
    return '<footer class="public-footer"><div class="footer-top">' . site_brand_link('footer')
        . '<p>Dignity for women, wrapped in capital.<br>Building opportunity, one business at a time.</p><div class="socials">'
        . '<a href="https://www.facebook.com/witug/" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>'
        . '<a href="https://x.com/wituganda" aria-label="X"><i class="fa-brands fa-x-twitter"></i></a>'
        . '<a href="https://www.linkedin.com/company/witu/" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a></div></div>'
        . '<div class="footer-bottom"><span>© ' . date('Y') . ' ' . site_e($s['site_name']) . ' · Plot 12, Corporation Rise, Bukoto · Kampala, Uganda</span>'
        . '<nav class="footer-links" aria-label="Footer"><a href="/contact-us">Contact us</a><a href="/join-us">Join as Donor / ESO</a><a href="/privacy-policy">Privacy policy</a>'
        . '<a href="/login">Client &amp; staff login <i class="fa-solid fa-arrow-right"></i></a></nav></div></footer>'
        . '<script src="' . site_asset('/assets/js/site-builder.js') . '" defer></script></body></html>';
}

/** Published pages for the header menu (excluding home). */
function site_nav_pages(mysqli $db): array
{
    try {
        return $db->query("SELECT title, slug FROM site_pages WHERE status='published' AND slug <> 'home' AND show_in_menu = 1 ORDER BY title LIMIT 5")->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $ignored) {
        // Before the CMS upgrade adds show_in_menu.
        try {
            return $db->query("SELECT title, slug FROM site_pages WHERE status='published' AND slug <> 'home' ORDER BY title LIMIT 5")->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable $alsoIgnored) {
            return [];
        }
    }
}

/** Legacy CTA paths stored before clean URLs existed. */
function site_normalize_cta(string $url): string
{
    $url = trim($url);
    if ($url === 'clients/index.php') return '/apply';
    if ($url === 'login.php') return '/login';
    return site_clean_link($url) ?: '#';
}

/** Sections and slides for a page; failures (tables missing) yield empty lists. */
function site_page_blocks(mysqli $db, int $pageId): array
{
    try {
        return [site_page_sections($db, $pageId), site_page_slides($db, $pageId)];
    } catch (Throwable $ignored) {
        return [null, []];
    }
}
