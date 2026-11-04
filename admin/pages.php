<?php
declare(strict_types=1);

$phead = 'Frontend Pages';
require_once __DIR__ . '/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? null, ['1','2',1,2], true)) {
    header('Location: ../login.php'); exit;
}
if (empty($_SESSION['site_pages_csrf'])) $_SESSION['site_pages_csrf'] = bin2hex(random_bytes(32));
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['site_pages_csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('Invalid security token.');
    }
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'delete' && $id > 0) {
        $stmt = $conn->prepare("DELETE FROM site_pages WHERE id = ? AND slug <> 'home'");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        header('Location: pages.php?notice=deleted'); exit;
    }
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    if ($slug === '' || $title === '') { $notice = 'Page title and URL slug are required.'; }
    else {
        $meta = trim((string)($_POST['meta_description'] ?? ''));
        $heroTitle = trim((string)($_POST['hero_title'] ?? ''));
        $heroSubtitle = trim((string)($_POST['hero_subtitle'] ?? ''));
        $body = trim((string)($_POST['body_content'] ?? ''));
        $ctaLabel = trim((string)($_POST['cta_label'] ?? '')) ?: 'Learn more';
        $ctaUrl = trim((string)($_POST['cta_url'] ?? '#'));
        $heroImage = trim((string)($_POST['hero_image'] ?? ''));
        $status = ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft';
        $safeCta = $ctaUrl === '#' || str_starts_with($ctaUrl, 'https://') || (str_starts_with($ctaUrl, '/') && !str_starts_with($ctaUrl, '//')) || (bool)preg_match('#^[a-zA-Z0-9][a-zA-Z0-9._/?=&%-]*$#', $ctaUrl);
        if (!$safeCta) $ctaUrl = '#';
        $safeImage = str_starts_with($heroImage, 'https://') || (str_starts_with($heroImage, '/') && !str_starts_with($heroImage, '//'));
        if ($heroImage !== '' && !$safeImage) $heroImage = '';
        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE site_pages SET title=?, slug=?, meta_description=?, hero_title=?, hero_subtitle=?, body_content=?, cta_label=?, cta_url=?, hero_image=?, status=? WHERE id=?');
            $stmt->bind_param('ssssssssssi', $title, $slug, $meta, $heroTitle, $heroSubtitle, $body, $ctaLabel, $ctaUrl, $heroImage, $status, $id);
        } else {
            $stmt = $conn->prepare('INSERT INTO site_pages (title,slug,meta_description,hero_title,hero_subtitle,body_content,cta_label,cta_url,hero_image,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('ssssssssss', $title, $slug, $meta, $heroTitle, $heroSubtitle, $body, $ctaLabel, $ctaUrl, $heroImage, $status);
        }
        try { $stmt->execute(); header('Location: pages.php?notice=saved'); exit; }
        catch (mysqli_sql_exception $e) { $notice = 'Could not save the page. Check that its URL slug is unique.'; }
        finally { $stmt->close(); }
    }
}
$pageId = (int)($_GET['edit'] ?? 0);
$editing = null;
if ($pageId) { $stmt = $conn->prepare('SELECT * FROM site_pages WHERE id=?'); $stmt->bind_param('i', $pageId); $stmt->execute(); $editing = $stmt->get_result()->fetch_assoc(); $stmt->close(); }
$pages = $conn->query('SELECT id,title,slug,status,updated_at FROM site_pages ORDER BY slug="home" DESC, title ASC')->fetch_all(MYSQLI_ASSOC);
if (isset($_GET['notice'])) $notice = $_GET['notice'] === 'deleted' ? 'Page deleted.' : 'Changes saved.';
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/nav-head.php';
?>
<div class="container-fluid py-4">
<div class="d-flex justify-content-between align-items-center mb-4"><div><h3>Frontend Pages</h3><p>Manage the public pages shown on the Nsimbi Impact website.</p></div><a class="btn btn-primary" href="pages.php">Create page</a></div>
<?php if ($notice): ?><div class="alert alert-info"><?= $e($notice) ?></div><?php endif; ?>
<div class="row g-4"><div class="col-lg-5"><div class="card"><div class="card-header"><h5 class="mb-0">Pages</h5></div><div class="card-body"><?php foreach ($pages as $item): ?><div class="d-flex justify-content-between align-items-center border-bottom py-3"><div><strong><?= $e($item['title']) ?></strong><div class="text-sm">/<?= $e($item['slug'] === 'home' ? '' : $item['slug']) ?> · <?= $e($item['status']) ?></div></div><a class="btn btn-sm btn-outline-primary" href="pages.php?edit=<?= (int)$item['id'] ?>">Edit</a></div><?php endforeach; ?></div></div></div>
<div class="col-lg-7"><div class="card"><div class="card-header"><h5 class="mb-0"><?= $editing ? 'Edit page' : 'New page' ?></h5></div><div class="card-body"><form method="post"><input type="hidden" name="csrf" value="<?= $e($_SESSION['site_pages_csrf']) ?>"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>"><div class="form-group"><label>Page name</label><input class="form-control" name="title" required value="<?= $e($editing['title'] ?? '') ?>"></div><div class="form-group"><label>URL slug</label><input class="form-control" name="slug" placeholder="about-us" required value="<?= $e($editing['slug'] ?? '') ?>"><small>Use “home” for the homepage.</small></div><div class="form-group"><label>Hero heading</label><input class="form-control" name="hero_title" value="<?= $e($editing['hero_title'] ?? '') ?>"></div><div class="form-group"><label>Hero introduction</label><textarea class="form-control" name="hero_subtitle" rows="2"><?= $e($editing['hero_subtitle'] ?? '') ?></textarea></div><div class="form-group"><label>Page content</label><textarea class="form-control" name="body_content" rows="6"><?= $e($editing['body_content'] ?? '') ?></textarea><small>Plain text; paragraphs are preserved.</small></div><div class="form-group"><label>SEO description</label><textarea class="form-control" name="meta_description" rows="2"><?= $e($editing['meta_description'] ?? '') ?></textarea></div><div class="row"><div class="col-md-6 form-group"><label>Button label</label><input class="form-control" name="cta_label" value="<?= $e($editing['cta_label'] ?? 'Learn more') ?>"></div><div class="col-md-6 form-group"><label>Button URL</label><input class="form-control" name="cta_url" value="<?= $e($editing['cta_url'] ?? '#') ?>"></div></div><div class="form-group"><label>Hero image URL (optional)</label><input class="form-control" name="hero_image" value="<?= $e($editing['hero_image'] ?? '') ?>"></div><div class="form-group"><label>Status</label><select class="form-control" name="status"><option value="draft" <?= ($editing['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option><option value="published" <?= ($editing['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>Published</option></select></div><button class="btn btn-primary" name="action" value="save">Save page</button><?php if ($editing && $editing['slug'] !== 'home'): ?><button class="btn btn-danger" name="action" value="delete" formnovalidate onclick="return confirm('Delete this page?')">Delete</button><?php endif; ?></form></div></div></div></div>
</div><?php require __DIR__ . '/footer.php'; ?>
