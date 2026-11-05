<?php
declare(strict_types=1);

$phead = 'Branding & Fonts';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/site_cms.php';
site_admin_guard();
site_cms_ensure_schema($conn);

$defaults = site_setting_defaults();
$fonts = site_font_catalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    site_csrf_check();
    $current = site_settings($conn, true);
    $values = [];
    $errors = [];

    $name = trim((string)($_POST['site_name'] ?? ''));
    $values['site_name'] = $name !== '' ? mb_substr($name, 0, 80) : $defaults['site_name'];

    foreach (['heading_font', 'body_font', 'panel_font'] as $key) {
        $font = (string)($_POST[$key] ?? '');
        $values[$key] = isset($fonts[$font]) ? $font : $defaults[$key];
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
    site_flash($errors ? 'error' : 'success', $errors ? implode(' ', $errors) : 'Branding and fonts saved. They apply across the website and dashboards.');
    header('Location: site_settings.php'); exit;
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
$pageScripts = ['../assets/js/site-admin.js'];
require __DIR__ . '/nav-head.php';
?>
<link rel="stylesheet" href="<?= $e(site_google_fonts_url(array_keys($fonts))) ?>">
<div class="page-header">
  <div><div class="title">Branding &amp; Fonts</div><div class="sub">Name, logo, favicon and typefaces used on the public website, sign-in page and dashboards.</div></div>
  <a class="btn btn-outline" href="pages.php"><i class="fas fa-globe"></i> Frontend pages</a>
</div>

<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= $e(site_csrf_token()) ?>">
  <div class="grid-2">
    <div class="card">
      <div class="card-header"><div class="card-title"><span class="card-dot"></span> Brand</div></div>
      <div class="card-body">
        <div class="form-group"><label class="form-label" for="st-name">Platform name</label><input class="form-control" id="st-name" name="site_name" maxlength="80" value="<?= $e($s['site_name']) ?>"><div class="form-help">Used in the header wordmark, page titles and footer. The last word is highlighted.</div></div>

        <div class="form-group">
          <label class="form-label">Logo</label>
          <div class="cms-brand-preview"><img src="<?= $e($s['logo']) ?>" alt="Current logo" data-file-preview="logo_file"></div>
          <input class="form-control" type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/gif">
          <div class="form-help">PNG with a transparent background works best, at least 200 px tall. Max 8 MB.</div>
          <?php if ($s['logo'] !== $defaults['logo']): ?><label class="cms-check"><input type="checkbox" name="logo_reset" value="1"> Restore the original logo</label><?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">Favicon</label>
          <div class="cms-brand-preview small"><img src="<?= $e($s['favicon']) ?>" alt="Current favicon" data-file-preview="favicon_file"></div>
          <input class="form-control" type="file" name="favicon_file" accept="image/png,image/x-icon,.ico">
          <div class="form-help">Square PNG (512×512 recommended) or .ico. Max 1 MB. Browsers may cache the old icon for a while.</div>
          <?php if ($s['favicon'] !== $defaults['favicon']): ?><label class="cms-check"><input type="checkbox" name="favicon_reset" value="1"> Restore the original favicon</label><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title"><span class="card-dot green"></span> Typography</div></div>
      <div class="card-body">
        <div class="form-group"><label class="form-label" for="st-heading">Website headings</label><select class="form-select" id="st-heading" name="heading_font" data-font-preview="heading"><?= $fontOptions($s['heading_font']) ?></select></div>
        <div class="form-group"><label class="form-label" for="st-body">Website body text</label><select class="form-select" id="st-body" name="body_font" data-font-preview="body"><?= $fontOptions($s['body_font']) ?></select></div>
        <div class="cms-font-sample mb-4">
          <div data-font-sample="heading" style="font-family:<?= $e(site_font_stack($s['heading_font'])) ?>">Capital that sees the potential in every woman.</div>
          <p data-font-sample="body" style="font-family:<?= $e(site_font_stack($s['body_font'])) ?>">Backing women-led businesses with the capital, confidence and community to build what comes next.</p>
        </div>
        <div class="form-group"><label class="form-label" for="st-panel">Dashboards (admin, staff, client)</label><select class="form-select" id="st-panel" name="panel_font" data-font-preview="panel"><?= $fontOptions($s['panel_font']) ?></select></div>
        <div class="cms-font-sample"><p data-font-sample="panel" style="font-family:<?= $e(site_font_stack($s['panel_font'])) ?>">Pending loans · 12 &nbsp; Outstanding · UGX 4,250,000</p></div>
      </div>
    </div>
  </div>
  <div class="d-flex justify-end mt-4"><button class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Save branding</button></div>
</form>
<?php require __DIR__ . '/footer.php'; ?>
