<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/site_public.php';

$page = null;
try {
    $query = $conn->prepare("SELECT id, title, meta_description, hero_title, hero_subtitle, body_content, cta_label, cta_url, hero_image FROM site_pages WHERE slug = 'home' AND status = 'published' LIMIT 1");
    $query->execute();
    $page = $query->get_result()->fetch_assoc() ?: null;
    $query->close();
} catch (Throwable $ignored) {
    $page = null;
}
$settings = site_settings($conn);
$page ??= [
    'id' => 0,
    'title' => $settings['site_name'] . ' | Capital for women-led businesses',
    'meta_description' => 'Dignity for women wrapped in capital. We invest in women-led businesses across Uganda and Sub-Saharan Africa.',
    'hero_title' => 'Capital that sees the potential in every woman.',
    'hero_subtitle' => 'Backing women-led businesses with the capital, confidence and community to build what comes next.',
    'body_content' => '',
    'cta_label' => 'Explore financing', 'cta_url' => '/apply', 'hero_image' => '',
];

[$sections, $slides] = (int)$page['id'] > 0 ? site_page_blocks($conn, (int)$page['id']) : [null, []];
// Until an admin opens the page builder (which seeds them), show the original homepage sections.
if ($sections === null || (!$sections && $settings['home_sections_seeded'] !== '1')) {
    $sections = site_default_home_sections((string)$page['body_content']);
}

$heroImage = site_clean_media((string)$page['hero_image']);
$ctaUrl = site_normalize_cta((string)$page['cta_url']);
$donorButton = '<a class="button button-outline" href="/join-us">Join as Donor / ESO</a>';
$pageTitle = strcasecmp(trim((string)$page['title']), 'Home') === 0 ? $settings['site_name'] . ' | Capital for women-led businesses' : (string)$page['title'];

echo site_public_head($pageTitle, (string)$page['meta_description']);
echo site_public_header(site_section_menu($sections), site_nav_pages($conn));
?>
<main>
<?php if ($slides): ?>
  <?= site_render_slider($slides, ['title' => $page['hero_title'], 'subtitle' => $page['hero_subtitle'], 'cta_label' => $page['cta_label'], 'cta_url' => $ctaUrl, 'secondary' => $donorButton]) ?>
<?php else: ?>
  <section class="hero wp-hero<?= $heroImage !== '' ? ' has-image' : '' ?>"<?= $heroImage !== '' ? ' style="--hero-image:url(\'' . site_e($heroImage) . '\')"' : '' ?>>
    <div class="hero-content"><span class="eyebrow"><span></span> DIGNITY FOR WOMEN WRAPPED IN</span><h1><?= site_e($page['hero_title']) ?></h1><p><?= site_e($page['hero_subtitle']) ?></p><div class="hero-actions"><a class="button button-lime" href="<?= site_e($ctaUrl) ?>"><?= site_e($page['cta_label']) ?> <i class="fa-solid fa-arrow-right"></i></a><?= $donorButton ?></div><div class="hero-note"><i class="fa-solid fa-location-dot"></i> Changing Nations, One Woman at a Time</div></div>
    <div class="hero-art" aria-hidden="true"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><div class="art-sun"></div><div class="art-figure"><span></span></div><div class="art-card"><i class="fa-solid fa-seedling"></i><b>Investing in women</b><small>Capital for opportunity</small></div><div class="art-caption">DIGNITY<br>IN ACTION <span><i class="fa-solid fa-star" aria-hidden="true"></i></span></div></div>
  </section>
<?php endif; ?>
  <?= site_render_sections($sections) ?>
</main>
<?= site_public_footer() ?>
