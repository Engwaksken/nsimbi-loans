<?php
declare(strict_types=1);

$phead = 'Page Builder';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/site_cms.php';
site_admin_guard();
site_cms_ensure_schema($conn);

$pageId = (int)($_GET['page'] ?? $_POST['page_id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM site_pages WHERE id=?');
$stmt->bind_param('i', $pageId); $stmt->execute(); $page = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$page) { site_flash('error', 'Choose a page to build.'); header('Location: pages.php'); exit; }
$back = 'page_builder.php?page=' . $pageId;
$types = site_section_types();

/** Fetch one section/slide that belongs to this page. */
$findItem = static function (string $table, int $id) use ($conn, $pageId): ?array {
    $q = $conn->prepare("SELECT * FROM {$table} WHERE id=? AND page_id=?");
    $q->bind_param('ii', $id, $pageId); $q->execute(); $row = $q->get_result()->fetch_assoc(); $q->close();
    return $row ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    site_csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    [$kind, $verb] = array_pad(explode('_', $action, 2), 2, '');
    $table = $kind === 'slide' ? 'site_page_slides' : 'site_page_sections';
    $anchor = $kind === 'slide' ? '#slides' : '#sections';
    $item = $id > 0 ? $findItem($table, $id) : null;
    if ($id > 0 && !$item) { site_flash('error', 'That item no longer exists.'); header('Location: ' . $back); exit; }

    if (($verb === 'up' || $verb === 'down') && $item) {
        site_move_item($conn, $table, $pageId, $id, $verb);
    } elseif ($verb === 'toggle' && $item) {
        $q = $conn->prepare("UPDATE {$table} SET is_active = 1 - is_active WHERE id=? AND page_id=?");
        $q->bind_param('ii', $id, $pageId); $q->execute(); $q->close();
        site_flash('success', $item['is_active'] ? 'Hidden from the website.' : 'Now visible on the website.');
    } elseif ($verb === 'delete' && $item) {
        $q = $conn->prepare("DELETE FROM {$table} WHERE id=? AND page_id=?");
        $q->bind_param('ii', $id, $pageId); $q->execute(); $q->close();
        site_discard_media($conn, (string)$item['media_url']);
        site_flash('success', ($kind === 'slide' ? 'Slide' : 'Section') . ' deleted.');
    } elseif ($verb === 'save' && $kind === 'section') {
        $sectionType = $types[$_POST['section_type'] ?? ''] ?? $types['text'];
        $mediaType = in_array($_POST['media_type'] ?? '', ['image', 'video'], true) && in_array('media', $sectionType['fields'], true) ? $_POST['media_type'] : 'none';
        [$mediaUrl, $error] = $mediaType === 'none' ? ['', null] : site_media_from_request('media_file', 'media_url', $mediaType);
        if ($error !== null) {
            site_flash('error', $error);
        } else {
            $data = $_POST;
            $data['media_type'] = $mediaType;
            $data['media_url'] = $mediaUrl;
            $data['is_active'] = isset($_POST['is_active']);
            site_section_save($conn, $pageId, $id, $data);
            if ($item && $item['media_url'] !== $mediaUrl) site_discard_media($conn, (string)$item['media_url']);
            site_flash('success', $item ? 'Section saved.' : 'Section added.');
        }
    } elseif ($verb === 'save' && $kind === 'slide') {
        $mediaType = ($_POST['media_type'] ?? '') === 'video' ? 'video' : 'image';
        [$mediaUrl, $error] = site_media_from_request('media_file', 'media_url', $mediaType);
        if ($error === null && $mediaUrl === '') $error = 'Upload an image or video, or paste a link, for the slide.';
        if ($error !== null) {
            site_flash('error', $error);
        } else {
            $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 220);
            $caption = mb_substr(trim((string)($_POST['caption'] ?? '')), 0, 500);
            $btnLabel = mb_substr(trim((string)($_POST['button_label'] ?? '')), 0, 80);
            $btnUrl = site_clean_link((string)($_POST['button_url'] ?? ''));
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($item) {
                $q = $conn->prepare('UPDATE site_page_slides SET media_type=?, media_url=?, title=?, caption=?, button_label=?, button_url=?, is_active=? WHERE id=? AND page_id=?');
                $q->bind_param('ssssssiii', $mediaType, $mediaUrl, $title, $caption, $btnLabel, $btnUrl, $active, $id, $pageId);
            } else {
                $q = $conn->prepare('INSERT INTO site_page_slides (page_id, media_type, media_url, title, caption, button_label, button_url, is_active, sort_order)
                    SELECT ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(MAX(sort_order), 0) + 10 FROM site_page_slides WHERE page_id = ?');
                $q->bind_param('issssssii', $pageId, $mediaType, $mediaUrl, $title, $caption, $btnLabel, $btnUrl, $active, $pageId);
            }
            $q->execute(); $q->close();
            if ($item && $item['media_url'] !== $mediaUrl) site_discard_media($conn, (string)$item['media_url']);
            site_flash('success', $item ? 'Slide saved.' : 'Slide added.');
        }
    }
    header('Location: ' . $back . $anchor); exit;
}

$sections = site_page_sections($conn, $pageId, false);
$slides = site_page_slides($conn, $pageId, false);
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$csrf = $e(site_csrf_token());
$pageUrl = $page['slug'] === 'home' ? '/' : '/' . $page['slug'];
$pageScripts = ['../assets/js/site-admin.js'];

/** Up / down / show-hide / delete buttons for one row. */
$rowActions = static function (string $kind, array $row, int $index, int $total) use ($csrf, $pageId): string {
    $hidden = '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="page_id" value="' . $pageId . '"><input type="hidden" name="id" value="' . (int)$row['id'] . '">';
    $html = '<form method="post" class="d-flex gap-1">' . $hidden
        . '<button class="btn btn-sm btn-ghost btn-icon-sm" name="action" value="' . $kind . '_up"' . ($index === 0 ? ' disabled' : '') . ' title="Move up" aria-label="Move up"><i class="fas fa-arrow-up"></i></button>'
        . '<button class="btn btn-sm btn-ghost btn-icon-sm" name="action" value="' . $kind . '_down"' . ($index === $total - 1 ? ' disabled' : '') . ' title="Move down" aria-label="Move down"><i class="fas fa-arrow-down"></i></button>'
        . '<button class="btn btn-sm btn-ghost btn-icon-sm" name="action" value="' . $kind . '_toggle" title="' . ($row['is_active'] ? 'Hide' : 'Show') . '" aria-label="' . ($row['is_active'] ? 'Hide' : 'Show') . '"><i class="fas fa-eye' . ($row['is_active'] ? '' : '-slash') . '"></i></button>'
        . '</form>';
    return $html;
};
$deleteForm = static function (string $kind, array $row) use ($csrf, $pageId): string {
    return '<form method="post" onsubmit="return confirm(\'Delete this ' . $kind . '?\')"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="page_id" value="' . $pageId . '"><input type="hidden" name="id" value="' . (int)$row['id'] . '">'
        . '<button class="btn btn-sm btn-danger btn-icon-sm" name="action" value="' . $kind . '_delete" title="Delete" aria-label="Delete"><i class="fas fa-trash"></i></button></form>';
};
$thumb = static function (string $type, string $url) use ($e): string {
    if ($url === '') return '<div class="cms-thumb"><i class="fas fa-font"></i></div>';
    if ($type === 'video') return '<div class="cms-thumb"><i class="fas fa-film"></i></div>';
    return '<div class="cms-thumb"><img src="' . $e($url) . '" alt=""></div>';
};
require __DIR__ . '/nav-head.php';
?>
<div class="page-header">
  <div>
    <div class="title"><a href="pages.php" class="text-muted"><i class="fas fa-arrow-left"></i></a> &nbsp;<?= $e($page['title']) ?></div>
    <div class="sub">Slides replace the hero banner at the top. Sections appear below it, top to bottom. Hidden items stay saved but don't show on the site.</div>
  </div>
  <div class="d-flex gap-2"><a class="btn btn-outline" href="<?= $e($pageUrl) ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> View page</a></div>
</div>

<div class="card mb-4" id="slides">
  <div class="card-header">
    <div><div class="card-title"><span class="card-dot green"></span> Hero slides</div><div class="card-sub">Images or videos that rotate at the top of the page. With no slides, the normal hero banner shows.</div></div>
    <button type="button" class="btn btn-primary btn-sm" data-modal-open="slideModal" data-modal-title="Add slide" data-record='{"media_type":"image","is_active":1}'><i class="fas fa-plus"></i> Add slide</button>
  </div>
  <div class="card-body no-pad">
  <?php if (!$slides): ?>
    <div class="empty-state"><i class="fas fa-images"></i><h3>No slides</h3><p>Add an image or video to turn the hero into a slider.</p></div>
  <?php else: foreach ($slides as $i => $slide): ?>
    <div class="cms-row<?= $slide['is_active'] ? '' : ' is-hidden' ?>">
      <?= $thumb($slide['media_type'], $slide['media_url']) ?>
      <div class="cms-row-main">
        <div class="cms-row-title"><?= $e($slide['title'] !== '' ? $slide['title'] : 'Slide ' . ($i + 1)) ?></div>
        <div class="cms-row-sub"><span class="badge badge-soft-primary"><?= $e(ucfirst($slide['media_type'])) ?></span> <?= $slide['is_active'] ? '' : '<span class="badge badge-muted">Hidden</span> ' ?><?= $e(mb_strimwidth($slide['caption'], 0, 90, '…')) ?></div>
      </div>
      <div class="cms-row-actions">
        <?= $rowActions('slide', $slide, $i, count($slides)) ?>
        <button type="button" class="btn btn-sm btn-outline" data-modal-open="slideModal" data-modal-title="Edit slide" data-record="<?= $e(json_encode($slide)) ?>"><i class="fas fa-pen"></i> Edit</button>
        <?= $deleteForm('slide', $slide) ?>
      </div>
    </div>
  <?php endforeach; endif; ?>
  </div>
</div>

<div class="card" id="sections">
  <div class="card-header">
    <div><div class="card-title"><span class="card-dot"></span> Page sections</div><div class="card-sub">Use the arrows to reorder. Sections with a menu label appear in the header menu.</div></div>
    <button type="button" class="btn btn-primary btn-sm" data-modal-open="sectionModal" data-modal-title="Add section" data-record='{"section_type":"text","media_type":"none","is_active":1}'><i class="fas fa-plus"></i> Add section</button>
  </div>
  <div class="card-body no-pad">
  <?php if (!$sections): ?>
    <div class="empty-state"><i class="fas fa-layer-group"></i><h3>No sections</h3><p>Add text, statements, cards, images, videos or a call to action.</p></div>
  <?php else: foreach ($sections as $i => $section): $type = $types[$section['section_type']] ?? $types['text']; ?>
    <div class="cms-row<?= $section['is_active'] ? '' : ' is-hidden' ?>">
      <div class="cms-thumb"><i class="fas fa-<?= $e($type['icon']) ?>"></i></div>
      <div class="cms-row-main">
        <div class="cms-row-title"><?= $e(trim($section['heading'] . ' ' . $section['heading_accent']) ?: $type['name']) ?></div>
        <div class="cms-row-sub"><span class="badge badge-soft-primary"><?= $e($type['name']) ?></span>
          <?= $section['is_active'] ? '' : '<span class="badge badge-muted">Hidden</span>' ?>
          <?= $section['menu_label'] !== '' && $section['anchor'] !== '' ? '<span class="badge badge-soft-success"><i class="fas fa-bars"></i> ' . $e($section['menu_label']) . '</span>' : '' ?>
          <?= $section['media_type'] !== 'none' ? '<span class="badge badge-soft-secondary"><i class="fas fa-' . ($section['media_type'] === 'video' ? 'film' : 'image') . '"></i> ' . $e(ucfirst($section['media_type'])) . '</span>' : '' ?>
        </div>
      </div>
      <div class="cms-row-actions">
        <?= $rowActions('section', $section, $i, count($sections)) ?>
        <button type="button" class="btn btn-sm btn-outline" data-modal-open="sectionModal" data-modal-title="Edit section" data-record="<?= $e(json_encode($section)) ?>"><i class="fas fa-pen"></i> Edit</button>
        <?= $deleteForm('section', $section) ?>
      </div>
    </div>
  <?php endforeach; endif; ?>
  </div>
</div>

<!-- Slide modal -->
<div class="modal-overlay" id="slideModal" aria-hidden="true">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="slideModalTitle">
    <form method="post" enctype="multipart/form-data">
      <div class="modal-header">
        <div class="modal-title"><div class="modal-ico"><i class="fas fa-images"></i></div><span class="modal-title-text" id="slideModalTitle">Add slide</span></div>
        <button type="button" class="modal-close" data-modal-close aria-label="Close"><i class="fas fa-times"></i></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="page_id" value="<?= $pageId ?>"><input type="hidden" name="id" value="0">
        <div class="form-group">
          <label class="form-label">Slide type</label>
          <div class="cms-segment">
            <label><input type="radio" name="media_type" value="image" checked data-media-kind> <i class="fas fa-image"></i> Image</label>
            <label><input type="radio" name="media_type" value="video" data-media-kind> <i class="fas fa-film"></i> Video</label>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Image or video</label>
          <div data-media-preview="media_url"></div>
          <input class="form-control mb-2" type="file" name="media_file" data-accept-image="image/jpeg,image/png,image/webp,image/gif" data-accept-video="video/mp4,video/webm" accept="image/jpeg,image/png,image/webp,image/gif">
          <input class="form-control" name="media_url" placeholder="…or paste a link (https://…, YouTube or Vimeo for video)">
          <div class="form-help">Images: JPG, PNG, WebP or GIF up to 8 MB, ideally 1920×900 or wider. Videos: MP4/WebM up to 100 MB (plays muted on loop), or a YouTube/Vimeo link.</div>
        </div>
        <div class="form-group"><label class="form-label" for="sl-title">Heading</label><input class="form-control" id="sl-title" name="title" maxlength="220"><div class="form-help">Leave empty on the first slide to use the page's hero heading.</div></div>
        <div class="form-group"><label class="form-label" for="sl-caption">Text</label><textarea class="form-control" id="sl-caption" name="caption" rows="2" maxlength="500"></textarea></div>
        <div class="grid-2">
          <div class="form-group"><label class="form-label" for="sl-btn">Button label</label><input class="form-control" id="sl-btn" name="button_label" maxlength="80" placeholder="Uses page button if empty"></div>
          <div class="form-group"><label class="form-label" for="sl-url">Button link</label><input class="form-control" id="sl-url" name="button_url" placeholder="/apply"></div>
        </div>
        <label class="cms-check"><input type="checkbox" name="is_active" value="1" checked> Show this slide on the website</label>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button class="btn btn-primary" name="action" value="slide_save"><i class="fas fa-save"></i> Save slide</button>
      </div>
    </form>
  </div>
</div>

<!-- Section modal -->
<div class="modal-overlay" id="sectionModal" aria-hidden="true">
  <div class="modal-box md" role="dialog" aria-modal="true" aria-labelledby="sectionModalTitle">
    <form method="post" enctype="multipart/form-data">
      <div class="modal-header">
        <div class="modal-title"><div class="modal-ico"><i class="fas fa-layer-group"></i></div><span class="modal-title-text" id="sectionModalTitle">Add section</span></div>
        <button type="button" class="modal-close" data-modal-close aria-label="Close"><i class="fas fa-times"></i></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="page_id" value="<?= $pageId ?>"><input type="hidden" name="id" value="0">
        <div class="form-group">
          <label class="form-label" for="sec-type">Section type</label>
          <select class="form-select" id="sec-type" name="section_type" data-type-select>
            <?php foreach ($types as $key => $type): ?>
              <option value="<?= $e($key) ?>" data-help="<?= $e($type['help']) ?>" data-fields="<?= $e(implode(' ', $type['fields'])) ?>"><?= $e($type['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-help" data-type-help></div>
        </div>
        <div class="form-group" data-field="label"><label class="form-label" for="sec-label">Small label</label><input class="form-control" id="sec-label" name="label" maxlength="120" placeholder="OUR VISION"><div class="form-help">Shown above the heading. Sections are numbered automatically.</div></div>
        <div class="grid-2">
          <div class="form-group" data-field="heading"><label class="form-label" for="sec-heading">Heading</label><input class="form-control" id="sec-heading" name="heading" maxlength="220"></div>
          <div class="form-group" data-field="heading_accent"><label class="form-label" for="sec-accent">Heading — highlighted line</label><input class="form-control" id="sec-accent" name="heading_accent" maxlength="220"><div class="form-help">Optional second line in the accent colour.</div></div>
        </div>
        <div class="form-group" data-field="body"><label class="form-label" for="sec-body">Text</label><textarea class="form-control" id="sec-body" name="body" rows="4"></textarea><div class="form-help">Plain text. Leave a blank line between paragraphs.</div></div>
        <div class="form-group" data-field="items">
          <label class="form-label" for="sec-items">Items</label>
          <textarea class="form-control" id="sec-items" name="items" rows="7" style="font-family:monospace;font-size:.8rem"></textarea>
          <div class="form-help" data-for-type="features">One per line. <code>## Card title</code> starts a card; plain lines are its ticked points; <code>&gt; Big line | small line</code> adds a highlight card (it uses the image below if you add one).</div>
          <div class="form-help" data-for-type="cards">One card per line: <code>fa-chart-line | Card text</code>. The icon is any <a href="https://fontawesome.com/search?o=r&amp;m=free&amp;s=solid" target="_blank" rel="noopener">Font Awesome</a> solid icon name; leave it out for a star.</div>
        </div>
        <div class="form-group" data-field="media">
          <label class="form-label">Image or video</label>
          <div class="cms-segment mb-2">
            <label><input type="radio" name="media_type" value="none" checked data-media-kind> None</label>
            <label><input type="radio" name="media_type" value="image" data-media-kind> <i class="fas fa-image"></i> Image</label>
            <label><input type="radio" name="media_type" value="video" data-media-kind> <i class="fas fa-film"></i> Video</label>
          </div>
          <div data-media-inputs>
            <div data-media-preview="media_url"></div>
            <input class="form-control mb-2" type="file" name="media_file" data-accept-image="image/jpeg,image/png,image/webp,image/gif" data-accept-video="video/mp4,video/webm">
            <input class="form-control" name="media_url" placeholder="…or paste a link (https://…, YouTube or Vimeo for video)">
          </div>
        </div>
        <div class="grid-2" data-field="button">
          <div class="form-group"><label class="form-label" for="sec-btn">Button / link label</label><input class="form-control" id="sec-btn" name="button_label" maxlength="80"></div>
          <div class="form-group"><label class="form-label" for="sec-url">Button link</label><input class="form-control" id="sec-url" name="button_url" placeholder="/apply"></div>
        </div>
        <details class="cms-advanced">
          <summary>Menu &amp; anchor</summary>
          <div class="grid-2 mt-2">
            <div class="form-group"><label class="form-label" for="sec-anchor">Anchor ID</label><input class="form-control" id="sec-anchor" name="anchor" maxlength="60" placeholder="vision" pattern="[A-Za-z0-9-]*"><div class="form-help">Links to this section as <code>#vision</code>.</div></div>
            <div class="form-group"><label class="form-label" for="sec-menu">Header menu label</label><input class="form-control" id="sec-menu" name="menu_label" maxlength="60" placeholder="Our Vision"><div class="form-help">Needs an anchor ID to appear in the menu.</div></div>
          </div>
        </details>
        <label class="cms-check"><input type="checkbox" name="is_active" value="1" checked> Show this section on the website</label>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button class="btn btn-primary" name="action" value="section_save"><i class="fas fa-save"></i> Save section</button>
      </div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
