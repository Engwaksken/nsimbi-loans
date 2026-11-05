<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/site_public.php';

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_GET['slug'] ?? '')));
if ($slug === 'home') { header('Location: /', true, 301); exit; }
$findPage = static function () use ($conn, $slug): ?array {
    $stmt = $conn->prepare("SELECT * FROM site_pages WHERE slug=? AND status='published' LIMIT 1");
    $stmt->bind_param('s', $slug); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
};
$page = $findPage();
// Contact, Join as Donor / ESO and Privacy Policy are created on first visit after deploy.
if (!$page && in_array($slug, array_column(site_default_pages(), 'slug'), true) && site_settings($conn)['default_pages_seeded'] !== '1') {
    try { site_cms_ensure_schema($conn); $page = $findPage(); } catch (Throwable $e) { error_log('Default page setup failed: ' . $e->getMessage()); }
}
if (!$page) { http_response_code(404); exit('Page not found'); }

site_settings($conn);
[$sections, $slides] = site_page_blocks($conn, (int)$page['id']);
$sections ??= [];
$ctaUrl = site_normalize_cta((string)$page['cta_url']);
$heroTitle = $page['hero_title'] ?: $page['title'];

echo site_public_head($page['title'] . ' | ' . site_settings()['site_name'], (string)$page['meta_description']);
echo site_public_header(site_section_menu($sections), site_nav_pages($conn));
?>
<main>
<?php if ($slides): ?>
  <?= site_render_slider($slides, ['title' => $heroTitle, 'subtitle' => $page['hero_subtitle'], 'cta_label' => $page['cta_label'], 'cta_url' => $ctaUrl]) ?>
  <?php if (trim((string)$page['body_content']) !== ''): ?><div class="editorial-page has-sections"><div class="editorial-copy"><?= site_paragraphs($page['body_content']) ?></div></div><?php endif; ?>
<?php else: ?>
  <div class="editorial-page<?= $sections ? ' has-sections' : '' ?>">
    <div class="section-label"><span><?= site_e(strtoupper(site_settings()['site_name'])) ?></span> / <?= site_e(strtoupper($page['slug'])) ?></div>
    <h1><?= site_e($heroTitle) ?></h1>
    <?php if ($page['hero_subtitle']): ?><p class="editorial-lede"><?= site_e($page['hero_subtitle']) ?></p><?php endif; ?>
    <?php if (site_clean_media((string)$page['hero_image']) !== ''): ?><figure class="media-frame"><img src="<?= site_e(site_clean_media((string)$page['hero_image'])) ?>" alt=""></figure><?php endif; ?>
    <div class="editorial-copy"><?= site_paragraphs($page['body_content']) ?></div>
    <?php if ($page['cta_label']): ?><a class="button button-dark" href="<?= site_e($ctaUrl) ?>"><?= site_e($page['cta_label']) ?> <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
  </div>
<?php endif; ?>
  <?= site_render_sections($sections) ?>
</main>
<?= site_public_footer() ?>
