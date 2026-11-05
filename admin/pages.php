<?php
declare(strict_types=1);

$phead = 'Frontend Pages';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/site_cms.php';
site_admin_guard();
site_cms_ensure_schema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    site_csrf_check();
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);
    $existing = null;
    if ($id > 0) {
        $stmt = $conn->prepare('SELECT * FROM site_pages WHERE id=?');
        $stmt->bind_param('i', $id); $stmt->execute(); $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$existing) { site_flash('error', 'That page no longer exists.'); header('Location: pages.php'); exit; }
    }

    if ($action === 'delete' && $existing) {
        if ($existing['slug'] === 'home') { site_flash('error', 'The homepage cannot be deleted.'); header('Location: pages.php'); exit; }
        $media = [(string)$existing['hero_image']];
        foreach (['site_page_sections', 'site_page_slides'] as $table) {
            $q = $conn->prepare("SELECT media_url FROM {$table} WHERE page_id=?");
            $q->bind_param('i', $id); $q->execute();
            $media = array_merge($media, array_column($q->get_result()->fetch_all(MYSQLI_ASSOC), 'media_url')); $q->close();
        }
        $stmt = $conn->prepare('DELETE FROM site_pages WHERE id=?');
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        foreach (array_unique($media) as $url) site_discard_media($conn, (string)$url);
        site_flash('success', 'Page deleted.');
        header('Location: pages.php'); exit;
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $slugInput = trim((string)($_POST['slug'] ?? '')) ?: $title;
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($slugInput)), '-');
    if ($existing && $existing['slug'] === 'home') $slug = 'home';
    [$heroImage, $mediaError] = $title === '' || $slug === '' ? [null, null] : site_media_from_request('hero_image_file', 'hero_image', 'image');

    if ($title === '' || $slug === '') {
        site_flash('error', 'Page name and URL slug are required.');
    } elseif ($mediaError !== null) {
        site_flash('error', $mediaError);
    } else {
        $meta = trim((string)($_POST['meta_description'] ?? ''));
        $heroTitle = trim((string)($_POST['hero_title'] ?? ''));
        $heroSubtitle = trim((string)($_POST['hero_subtitle'] ?? ''));
        $body = trim((string)($_POST['body_content'] ?? ''));
        $ctaLabel = trim((string)($_POST['cta_label'] ?? ''));
        $ctaUrl = site_clean_link((string)($_POST['cta_url'] ?? '')) ?: '#';
        $status = ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft';
        try {
            if ($existing) {
                $stmt = $conn->prepare('UPDATE site_pages SET title=?, slug=?, meta_description=?, hero_title=?, hero_subtitle=?, body_content=?, cta_label=?, cta_url=?, hero_image=?, status=? WHERE id=?');
                $stmt->bind_param('ssssssssssi', $title, $slug, $meta, $heroTitle, $heroSubtitle, $body, $ctaLabel, $ctaUrl, $heroImage, $status, $id);
            } else {
                $stmt = $conn->prepare('INSERT INTO site_pages (title,slug,meta_description,hero_title,hero_subtitle,body_content,cta_label,cta_url,hero_image,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $stmt->bind_param('ssssssssss', $title, $slug, $meta, $heroTitle, $heroSubtitle, $body, $ctaLabel, $ctaUrl, $heroImage, $status);
            }
            $stmt->execute();
            $newId = $existing ? $id : (int)$stmt->insert_id;
            $stmt->close();
            if ($existing && $existing['hero_image'] !== $heroImage) site_discard_media($conn, (string)$existing['hero_image']);
            site_flash('success', $existing ? 'Page saved.' : 'Page created. Add sections and slides below.');
            header('Location: ' . ($existing ? 'pages.php' : 'page_builder.php?page=' . $newId)); exit;
        } catch (mysqli_sql_exception $e) {
            if ($heroImage && (!$existing || $existing['hero_image'] !== $heroImage)) site_discard_media($conn, $heroImage);
            site_flash('error', $e->getCode() === 1062 ? 'Another page already uses the URL “' . $slug . '”. Choose a different slug.' : 'Could not save the page.');
        }
    }
    header('Location: pages.php'); exit;
}

$pages = $conn->query('SELECT p.*,
        (SELECT COUNT(*) FROM site_page_sections s WHERE s.page_id = p.id) AS section_count,
        (SELECT COUNT(*) FROM site_page_slides sl WHERE sl.page_id = p.id) AS slide_count
    FROM site_pages p ORDER BY p.slug = "home" DESC, p.title ASC')->fetch_all(MYSQLI_ASSOC);
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$recordFields = ['id', 'title', 'slug', 'meta_description', 'hero_title', 'hero_subtitle', 'body_content', 'cta_label', 'cta_url', 'hero_image', 'status'];
$pageScripts = ['../assets/js/site-admin.js'];
require __DIR__ . '/nav-head.php';
?>
<div class="page-header">
  <div><div class="title">Frontend Pages</div><div class="sub">Public pages on the website. Use <b>Build</b> to manage a page's sections and slides.</div></div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline" href="site_settings.php"><i class="fas fa-palette"></i> Branding &amp; fonts</a>
    <button type="button" class="btn btn-primary" data-modal-open="pageModal" data-modal-title="New page" data-record='{"status":"published","cta_label":"Learn more","cta_url":"/apply"}'><i class="fas fa-plus"></i> Add page</button>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Page</th><th>Address</th><th>Status</th><th>Sections</th><th>Slides</th><th>Updated</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($pages as $item): $url = $item['slug'] === 'home' ? '/' : '/' . $item['slug']; ?>
        <tr>
          <td><strong><?= $e($item['title']) ?></strong><?php if ($item['slug'] === 'home'): ?> <span class="badge badge-info">Homepage</span><?php endif; ?></td>
          <td><a href="<?= $e($url) ?>" target="_blank" rel="noopener"><?= $e($url) ?> <i class="fas fa-external-link-alt" style="font-size:.65rem"></i></a></td>
          <td><span class="badge <?= $item['status'] === 'published' ? 'badge-success' : 'badge-muted' ?>"><?= $e(ucfirst($item['status'])) ?></span></td>
          <td><?= (int)$item['section_count'] ?></td>
          <td><?= (int)$item['slide_count'] ?></td>
          <td class="text-muted"><?= $e(date('M j, Y', strtotime((string)$item['updated_at']))) ?></td>
          <td class="text-right">
            <div class="d-flex gap-2 justify-end">
              <a class="btn btn-sm btn-primary" href="page_builder.php?page=<?= (int)$item['id'] ?>"><i class="fas fa-layer-group"></i> Build</a>
              <button type="button" class="btn btn-sm btn-outline" data-modal-open="pageModal" data-modal-title="Edit page" data-record="<?= $e(json_encode(array_intersect_key($item, array_flip($recordFields)))) ?>"><i class="fas fa-pen"></i> Edit</button>
              <?php if ($item['slug'] !== 'home'): ?>
              <form method="post" onsubmit="return confirm('Delete “<?= $e(addslashes($item['title'])) ?>” with all its sections and slides?')">
                <input type="hidden" name="csrf" value="<?= $e(site_csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                <button class="btn btn-sm btn-danger" name="action" value="delete" title="Delete page"><i class="fas fa-trash"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$pages): ?><tr><td colspan="7"><div class="empty-state"><i class="fas fa-file"></i><h3>No pages yet</h3><p>Add your first page.</p></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay" id="pageModal" aria-hidden="true">
  <div class="modal-box md" role="dialog" aria-modal="true" aria-labelledby="pageModalTitle">
    <form method="post" enctype="multipart/form-data">
      <div class="modal-header">
        <div class="modal-title"><div class="modal-ico"><i class="fas fa-file-alt"></i></div><span class="modal-title-text" id="pageModalTitle">New page</span></div>
        <button type="button" class="modal-close" data-modal-close aria-label="Close"><i class="fas fa-times"></i></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= $e(site_csrf_token()) ?>">
        <input type="hidden" name="id" value="0">
        <div class="cms-tabs" role="tablist" aria-label="Page settings">
          <button type="button" role="tab" data-tab="page-basics" aria-selected="true"><i class="fas fa-file-alt"></i> Page</button>
          <button type="button" role="tab" data-tab="page-hero" aria-selected="false"><i class="fas fa-image"></i> Hero &amp; button</button>
          <button type="button" role="tab" data-tab="page-publish" aria-selected="false"><i class="fas fa-globe"></i> SEO &amp; status</button>
        </div>

        <div class="cms-tab-panel" role="tabpanel" data-tab-panel="page-basics">
          <div class="grid-2">
            <div class="form-group"><label class="form-label" for="pg-title">Page name</label><input class="form-control" id="pg-title" name="title" required data-slug-source></div>
            <div class="form-group"><label class="form-label" for="pg-slug">URL slug</label><input class="form-control" id="pg-slug" name="slug" placeholder="about-us" required pattern="[A-Za-z0-9-]+" data-slug-target><div class="form-help">Page address: /<span data-slug-preview>about-us</span></div></div>
          </div>
          <div class="form-group"><label class="form-label" for="pg-body">Intro text</label><textarea class="form-control" id="pg-body" name="body_content" rows="7"></textarea><div class="form-help">Plain text, blank line between paragraphs. On the homepage, content lives in sections instead — use <b>Build</b>.</div></div>
        </div>

        <div class="cms-tab-panel" role="tabpanel" data-tab-panel="page-hero" hidden>
          <div class="form-group"><label class="form-label" for="pg-hero-title">Hero heading</label><input class="form-control" id="pg-hero-title" name="hero_title"></div>
          <div class="form-group"><label class="form-label" for="pg-hero-sub">Hero introduction</label><textarea class="form-control" id="pg-hero-sub" name="hero_subtitle" rows="2"></textarea></div>
          <div class="grid-2">
            <div class="form-group"><label class="form-label" for="pg-cta-label">Button label</label><input class="form-control" id="pg-cta-label" name="cta_label"></div>
            <div class="form-group"><label class="form-label" for="pg-cta-url">Button link</label><input class="form-control" id="pg-cta-url" name="cta_url" placeholder="/apply"></div>
          </div>
          <div class="form-group">
            <label class="form-label">Hero image (optional)</label>
            <div data-media-preview="hero_image"></div>
            <div class="grid-2">
              <input class="form-control" type="file" name="hero_image_file" accept="image/jpeg,image/png,image/webp,image/gif">
              <input class="form-control" name="hero_image" placeholder="…or paste https:// link">
            </div>
            <div class="form-help">Used when the page has no slides. Clear the link to remove it. Max 8 MB.</div>
          </div>
        </div>

        <div class="cms-tab-panel" role="tabpanel" data-tab-panel="page-publish" hidden>
          <div class="form-group"><label class="form-label" for="pg-status">Status</label><select class="form-select" id="pg-status" name="status"><option value="published">Published</option><option value="draft">Draft (hidden)</option></select></div>
          <div class="form-group"><label class="form-label" for="pg-meta">SEO description</label><textarea class="form-control" id="pg-meta" name="meta_description" rows="4" maxlength="320"></textarea><div class="form-help">Shown in search results. Up to 320 characters.</div></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button class="btn btn-primary" name="action" value="save"><i class="fas fa-save"></i> Save page</button>
      </div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
