<?php
declare(strict_types=1);

$phead = 'Branding & Fonts';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/site_cms.php';
site_admin_guard();
site_cms_ensure_schema($conn);

$defaults = site_setting_defaults();
$fonts = site_font_catalog();
$colors = site_color_keys();
$sizes = site_size_limits();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    site_csrf_check();
    $current = site_settings($conn, true);
    $values = [];
    $errors = [];
    $tab = preg_replace('/[^a-z]/', '', (string)($_POST['tab'] ?? '')) ?: 'brand';

    $name = trim((string)($_POST['site_name'] ?? ''));
    $values['site_name'] = $name !== '' ? mb_substr($name, 0, 80) : $defaults['site_name'];

    foreach (['heading_font', 'body_font', 'panel_font'] as $key) {
        $font = (string)($_POST[$key] ?? '');
        $values[$key] = isset($fonts[$font]) ? $font : $defaults[$key];
    }

    foreach ($sizes as $key => [$min, $max]) {
        $values[$key] = (string)max($min, min($max, (int)($_POST[$key] ?? $defaults[$key])));
    }

    foreach (site_choice_settings() as $key => $options) {
        $choice = (string)($_POST[$key] ?? '');
        $values[$key] = isset($options[$choice]) ? $choice : $defaults[$key];
    }

    $resetColors = !empty($_POST['colors_reset']);
    foreach (array_keys($colors) as $key) {
        $color = $resetColors ? null : site_clean_color((string)($_POST[$key] ?? ''));
        if (!$resetColors && $color === null) $errors[] = $colors[$key][0] . ' colour must be a hex value like #0f3399.';
        $values[$key] = $color ?? ($resetColors ? $defaults[$key] : $current[$key]);
    }

    $replaced = [];
    foreach (['logo' => 'image', 'favicon' => 'favicon'] as $key => $kind) {
        if (!empty($_POST[$key . '_reset'])) {
            $values[$key] = $defaults[$key];
            $replaced[] = $current[$key];
            continue;
        }
        $upload = site_store_upload($_FILES[$key . '_file'] ?? null, $kind);
        if ($upload['error'] !== null) { $errors[] = ucfirst($key) . ': ' . $upload['error']; continue; }
        if ($upload['url'] !== null) { $values[$key] = $upload['url']; $replaced[] = $current[$key]; }
    }

    site_settings_save($conn, $values);
    foreach ($replaced as $old) site_discard_media($conn, (string)$old);
    site_flash($errors ? 'error' : 'success', $errors ? implode(' ', $errors) : 'Branding saved. Changes apply across the website and dashboards.');
    header('Location: site_settings#' . $tab); exit;
}

$s = site_settings($conn, true);
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fontOptions = static function (string $selected) use ($fonts, $e): string {
    $html = '';
    foreach ($fonts as $font => [$fallback]) {
        $html .= '<option value="' . $e($font) . '" data-stack="' . $e(site_font_stack($font)) . '"' . ($font === $selected ? ' selected' : '') . '>' . $e($font) . ' (' . $fallback . ')</option>';
    }
    return $html;
};
$range = static function (string $key, string $label, string $unit, string $help) use ($s, $sizes, $defaults, $e): string {
    [$min, $max] = $sizes[$key];
    return '<div class="form-group"><label class="form-label" for="st-' . $key . '">' . $e($label) . '</label>'
        . '<div class="cms-range"><input type="range" id="st-' . $key . '" name="' . $key . '" min="' . $min . '" max="' . $max . '" step="1" value="' . $e($s[$key]) . '" data-unit="' . $unit . '">'
        . '<output for="st-' . $key . '">' . $e($s[$key]) . $unit . '</output></div>'
        . '<div class="form-help">' . $e($help) . ' Default ' . $e($defaults[$key]) . $unit . '.</div></div>';
};
$choices = site_choice_settings();
$select = static function (string $key, string $label) use ($s, $choices, $e): string {
    $html = '<div class="form-group"><label class="form-label" for="st-' . $key . '">' . $e($label) . '</label><select class="form-select" id="st-' . $key . '" name="' . $key . '" data-style-preview="' . $key . '">';
    foreach ($choices[$key] as $value => $text) {
        $html .= '<option value="' . $e($value) . '"' . ((string)$s[$key] === (string)$value ? ' selected' : '') . '>' . $e($text) . '</option>';
    }
    return $html . '</select></div>';
};
$previewStyle = static function (string $prefix) use ($s): string {
    return 'font-size:' . (int)$s[$prefix . '_size'] . 'px;font-weight:' . (int)$s[$prefix . '_weight'] . ';text-transform:' . $s[$prefix . '_case']
        . ';font-family:' . site_font_stack($s[$s[$prefix . '_font'] === 'heading' ? 'heading_font' : 'body_font']);
};
$pageScripts = ['../assets/js/site-admin.js'];
require __DIR__ . '/nav-head.php';
?>
<link rel="stylesheet" href="<?= $e(site_google_fonts_url(array_keys($fonts))) ?>">
<div class="page-header">
  <div><div class="title">Branding &amp; Fonts</div><div class="sub">Name, logo, colours and typography used on the public website, sign-in page and dashboards.</div></div>
  <a class="btn btn-outline" href="pages"><i class="fas fa-globe"></i> Frontend pages</a>
</div>

<form method="post" enctype="multipart/form-data" data-tabs-root>
  <input type="hidden" name="csrf" value="<?= $e(site_csrf_token()) ?>">
  <input type="hidden" name="tab" value="brand" data-current-tab>
  <div class="card">
    <div class="card-body">
      <div class="cms-tabs" role="tablist" aria-label="Branding settings">
        <button type="button" role="tab" data-tab="brand" aria-selected="true"><i class="fas fa-id-badge"></i> Brand</button>
        <button type="button" role="tab" data-tab="colors" aria-selected="false"><i class="fas fa-palette"></i> Theme colours</button>
        <button type="button" role="tab" data-tab="typography" aria-selected="false"><i class="fas fa-font"></i> Fonts &amp; sizes</button>
        <button type="button" role="tab" data-tab="headerfooter" aria-selected="false"><i class="fas fa-window-maximize"></i> Header &amp; footer</button>
      </div>

      <!-- Brand -->
      <div class="cms-tab-panel" role="tabpanel" data-tab-panel="brand">
        <div class="form-group"><label class="form-label" for="st-name">Platform name</label><input class="form-control" id="st-name" name="site_name" maxlength="80" value="<?= $e($s['site_name']) ?>"><div class="form-help">Used in the header wordmark, page titles and footer. The last word is highlighted.</div></div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Logo</label>
            <div class="cms-brand-preview"><img src="<?= $e($s['logo']) ?>" alt="Current logo" data-file-preview="logo_file"></div>
            <input class="form-control" type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/gif">
            <div class="form-help">PNG with a transparent background works best, at least 200 px tall. Max 8 MB.</div>
            <?php if ($s['logo'] !== $defaults['logo']): ?><label class="cms-check"><input type="checkbox" name="logo_reset" value="1"> Restore the original logo</label><?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label">Favicon</label>
            <div class="cms-brand-preview"><img src="<?= $e($s['favicon']) ?>" alt="Current favicon" data-file-preview="favicon_file" style="max-height:48px"></div>
            <input class="form-control" type="file" name="favicon_file" accept="image/png,image/x-icon,.ico">
            <div class="form-help">Square PNG (512×512 recommended) or .ico. Max 1 MB. Browsers may cache the old icon for a while.</div>
            <?php if ($s['favicon'] !== $defaults['favicon']): ?><label class="cms-check"><input type="checkbox" name="favicon_reset" value="1"> Restore the original favicon</label><?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Colours -->
      <div class="cms-tab-panel" role="tabpanel" data-tab-panel="colors" hidden>
        <div class="grid-2">
          <div>
            <?php foreach ($colors as $key => [$label, $help]): ?>
              <div class="cms-color-row">
                <input type="color" value="<?= $e($s[$key]) ?>" data-color-picker="<?= $e($key) ?>" aria-label="<?= $e($label) ?> colour picker">
                <input class="form-control cms-color-hex" name="<?= $e($key) ?>" value="<?= $e($s[$key]) ?>" pattern="#[0-9A-Fa-f]{6}" maxlength="7" required data-color-text="<?= $e($key) ?>" aria-label="<?= $e($label) ?> hex value">
                <div><strong><?= $e($label) ?></strong><small><?= $e($help) ?> Default <?= $e($defaults[$key]) ?>.</small></div>
              </div>
            <?php endforeach; ?>
            <label class="cms-check mt-3"><input type="checkbox" name="colors_reset" value="1"> Restore the original Nsimbi colours</label>
          </div>
          <div>
            <label class="form-label">Preview</label>
            <div class="cms-theme-preview" data-theme-preview style="<?php foreach (array_keys($colors) as $key) echo '--tp-' . $e($key) . ':' . $e($s[$key]) . ';'; ?>">
              <div class="tp-strip" style="background:var(--tp-color_primary)"></div>
              <div class="tp-hero" style="background:linear-gradient(110deg,var(--tp-color_primary),var(--tp-color_dark))">
                <b>Capital that sees the potential in every woman.</b>
                <span class="tp-btn" style="background:var(--tp-color_accent);color:var(--tp-color_dark)">Explore financing →</span>
              </div>
              <div class="tp-body">
                <b style="color:var(--tp-color_primary)">Investing in the</b>
                <b style="color:var(--tp-color_accent_text);font-style:italic">power of women.</b>
              </div>
            </div>
            <div class="form-help mt-2">Dashboards use Primary for the sidebar and buttons, Dark for hover states and Accent for highlights.</div>
          </div>
        </div>
      </div>

      <!-- Header & footer -->
      <div class="cms-tab-panel" role="tabpanel" data-tab-panel="headerfooter" hidden>
        <div class="form-help mb-3">Text styling for the public website's top strip, menu and buttons, and its footer. Colours come from <b>Theme colours</b>.</div>
        <div class="grid-2">
          <div>
            <div class="card-title mb-3"><span class="card-dot"></span> Header menu</div>
            <?= $range('header_size', 'Menu text size', 'px', 'Menu links and header buttons; the top strip scales with it.') ?>
            <div class="grid-2">
              <?= $select('header_weight', 'Weight') ?>
              <?= $select('header_case', 'Letter case') ?>
            </div>
            <?= $select('header_font', 'Font') ?>
          </div>
          <div>
            <div class="card-title mb-3"><span class="card-dot green"></span> Footer</div>
            <?= $range('footer_size', 'Footer text size', 'px', 'Footer tagline; the copyright line is 1px smaller.') ?>
            <div class="grid-2">
              <?= $select('footer_weight', 'Weight') ?>
              <?= $select('footer_case', 'Letter case') ?>
            </div>
            <?= $select('footer_font', 'Font') ?>
          </div>
        </div>
        <label class="form-label mt-2">Preview</label>
        <div class="cms-hf-preview" style="--hf-primary:<?= $e($s['color_primary']) ?>;--hf-dark:<?= $e($s['color_dark']) ?>;--hf-accent:<?= $e($s['color_accent']) ?>;--hf-accent-text:<?= $e($s['color_accent_text']) ?>">
          <div class="hf-header" data-hf-preview="header" data-size-sample="header_size" data-body-stack="<?= $e(site_font_stack($s['body_font'])) ?>" data-heading-stack="<?= $e(site_font_stack($s['heading_font'])) ?>" style="<?= $e($previewStyle('header')) ?>">
            <span class="hf-logo"><?= str_replace(['<b>', '</b>'], ['<b style="color:var(--hf-accent-text)">', '</b>'], site_wordmark_html()) ?></span>
            <span class="hf-links"><span>Overview</span><span>Our Vision</span><span>Impact</span></span>
            <span class="hf-cta">Apply for a Loan →</span>
          </div>
          <div class="hf-footer" data-hf-preview="footer" data-size-sample="footer_size" data-body-stack="<?= $e(site_font_stack($s['body_font'])) ?>" data-heading-stack="<?= $e(site_font_stack($s['heading_font'])) ?>" style="<?= $e($previewStyle('footer')) ?>">
            <span>Dignity for women, wrapped in capital.</span>
            <span>© <?= date('Y') ?> <?= $e($s['site_name']) ?> · Client &amp; staff login →</span>
          </div>
        </div>
      </div>

      <!-- Typography -->
      <div class="cms-tab-panel" role="tabpanel" data-tab-panel="typography" hidden>
        <div class="grid-2">
          <div>
            <div class="form-group"><label class="form-label" for="st-heading">Website headings font</label><select class="form-select" id="st-heading" name="heading_font" data-font-preview="heading"><?= $fontOptions($s['heading_font']) ?></select></div>
            <div class="form-group"><label class="form-label" for="st-body">Website body font</label><select class="form-select" id="st-body" name="body_font" data-font-preview="body"><?= $fontOptions($s['body_font']) ?></select></div>
            <?= $range('heading_scale', 'Website heading size', '%', 'Scales every heading on the public website.') ?>
            <?= $range('text_size', 'Website text size', 'px', 'Paragraph text size; hero and intro text scale with it.') ?>
          </div>
          <div class="cms-font-sample">
            <div data-font-sample="heading" data-size-sample="heading_scale" data-base="26" style="font-family:<?= $e(site_font_stack($s['heading_font'])) ?>;font-size:<?= 26 * (int)$s['heading_scale'] / 100 ?>px">Capital that sees the potential in every woman.</div>
            <p data-font-sample="body" data-size-sample="text_size" data-base="14" style="font-family:<?= $e(site_font_stack($s['body_font'])) ?>;font-size:<?= (int)$s['text_size'] ?>px">Backing women-led businesses with the capital, confidence and community to build what comes next.</p>
          </div>
        </div>
        <hr style="border:0;border-top:1px solid var(--border);margin:1.2rem 0">
        <div class="grid-2">
          <div>
            <div class="form-group"><label class="form-label" for="st-panel">Dashboard font (admin, staff, client)</label><select class="form-select" id="st-panel" name="panel_font" data-font-preview="panel"><?= $fontOptions($s['panel_font']) ?></select></div>
            <?= $range('panel_text_size', 'Dashboard text size', 'px', 'Scales the whole dashboard layout, including tables and forms.') ?>
          </div>
          <div class="cms-font-sample"><p data-font-sample="panel" data-size-sample="panel_text_size" data-base="14" style="font-family:<?= $e(site_font_stack($s['panel_font'])) ?>;font-size:<?= (int)$s['panel_text_size'] ?>px">Pending loans · 12 &nbsp; Outstanding · UGX 4,250,000</p></div>
        </div>
        <hr style="border:0;border-top:1px solid var(--border);margin:1.2rem 0">
        <div class="grid-2">
          <div>
            <?= $range('sidebar_group_size', 'Sidebar group titles', 'px', 'Size of the collapsible group headings (Loans, People, Finance…).') ?>
            <?= $range('sidebar_link_size', 'Sidebar links', 'px', 'Size of the menu items inside each group.') ?>
          </div>
          <div class="cms-sidebar-sample">
            <div class="nav-group" data-size-sample="sidebar_group_size" style="font-size:<?= (int)$s['sidebar_group_size'] ?>px">Loans</div>
            <div class="nav-link active" data-size-sample="sidebar_link_size" style="font-size:<?= (int)$s['sidebar_link_size'] ?>px"><span class="ni"><i class="fas fa-file-invoice-dollar"></i></span>All Loans</div>
            <div class="nav-link" data-size-sample="sidebar_link_size" style="font-size:<?= (int)$s['sidebar_link_size'] ?>px"><span class="ni"><i class="fas fa-hourglass-half"></i></span>Pending</div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="d-flex justify-end mt-4"><button class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Save branding</button></div>
</form>
<script>
// Remember which tab was open so the page returns to it after saving.
document.addEventListener('click', function (event) {
  var tab = event.target.closest('.cms-tabs [role="tab"]');
  var field = document.querySelector('[data-current-tab]');
  if (tab && field) field.value = tab.dataset.tab;
});
document.querySelector('[data-current-tab]').value = (location.hash || '#brand').slice(1);
</script>
<?php require __DIR__ . '/footer.php'; ?>
