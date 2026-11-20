<?php
declare(strict_types=1);

/*
 * Links to files clients and staff uploaded (ID photos, licence, business
 * photo, signature, collateral...).
 *
 * The database only stores a relative path such as "uploads/x.jpg", but the
 * file sits in a different folder depending on where it was uploaded:
 *   app sign-up (api.php)            <root>/uploads/
 *   website sign-up, loan collateral <root>/clients/uploads/
 *   admin "Add client"               <root>/admin/uploads/
 *   staff sign-up                    <root>/staff/uploads/
 * These helpers find the file where it really is and return a link relative
 * to the admin folder, so it works whether the site is at / or /loans/.
 */

const STORED_FILE_DIRS = ['clients', '', 'admin', 'staff'];

function stored_file_root(): string
{
    return dirname(__DIR__);
}

/**
 * Where a stored path really lives.
 * @param string[] $prefer folders to try first (e.g. ['staff'] for staff files)
 * @return array{dir: string, rel: string, abs: string}|null
 */
function stored_file_locate(?string $path, array $prefer = []): ?array
{
    $path = trim((string)$path);
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return null;
    }
    $path = str_replace('\\', '/', $path);
    // Never allow paths that climb out of the app.
    if (str_contains($path, '..')) {
        return null;
    }
    $root = stored_file_root();

    // Absolute server paths saved by older code: keep the part inside the app.
    $normRoot = str_replace('\\', '/', $root);
    if (str_starts_with($path, $normRoot . '/')) {
        $path = substr($path, strlen($normRoot) + 1);
    }
    $path = ltrim($path, '/');
    // Paths like "loans/clients/uploads/x.jpg" or "/loans/uploads/x.jpg".
    $path = preg_replace('#^loans/#', '', $path) ?? $path;

    $dirs = array_values(array_unique(array_merge($prefer, STORED_FILE_DIRS)));
    // "clients/uploads/x.jpg" is already relative to the root.
    if (preg_match('#^(clients|admin|staff)/#', $path)) {
        array_unshift($dirs, '');
    }
    foreach ($dirs as $dir) {
        $rel = $dir === '' ? $path : $dir . '/' . $path;
        $abs = $root . '/' . $rel;
        if (is_file($abs)) {
            return ['dir' => $dir, 'rel' => $rel, 'abs' => $abs];
        }
    }
    return null;
}

/**
 * Link usable from a page in admin/ (or another first-level folder).
 * '' when nothing was uploaded; null when a path is saved but the file is missing.
 */
function stored_file_url(?string $path, array $prefer = []): ?string
{
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $found = stored_file_locate($path, $prefer);
    if ($found === null) {
        return null;
    }
    return '../' . implode('/', array_map('rawurlencode', explode('/', $found['rel'])));
}

/** Absolute server path of a stored file, or null. */
function stored_file_path(?string $path, array $prefer = []): ?string
{
    $found = stored_file_locate($path, $prefer);
    return $found['abs'] ?? null;
}

function stored_file_is_image(string $path): bool
{
    return (bool)preg_match('/\.(jpe?g|png|gif|webp)$/i', $path);
}

/** Card with preview for a stored file; used on client and loan pages. */
function stored_file_card(string $label, ?string $path, array $prefer = []): string
{
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $url = stored_file_url($path, $prefer);

    if ($url === '') {
        return '<div class="sf-card"><div class="sf-thumb muted"><i class="fas fa-file"></i></div>'
            . '<div><strong>' . $e($label) . '</strong><p>Not uploaded</p></div></div>';
    }
    if ($url === null) {
        return '<div class="sf-card"><div class="sf-thumb warn"><i class="fas fa-exclamation-triangle"></i></div>'
            . '<div><strong>' . $e($label) . '</strong><p>File missing on server</p>'
            . '<small class="text-muted">' . $e(basename((string)$path)) . '</small></div></div>';
    }
    $thumb = stored_file_is_image((string)$path)
        ? '<img src="' . $e($url) . '" alt="" loading="lazy">'
        : '<i class="fas fa-file-pdf"></i>';
    return '<div class="sf-card"><a class="sf-thumb" href="' . $e($url) . '" target="_blank" rel="noopener">' . $thumb . '</a>'
        . '<div><strong>' . $e($label) . '</strong><p>Uploaded</p>'
        . '<a href="' . $e($url) . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline"><i class="fas fa-eye"></i> View</a></div></div>';
}

function stored_file_card_css(): string
{
    return '<style>
.sf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.8rem}
.sf-card{display:flex;gap:.8rem;align-items:center;border:1px solid #e5e7eb;border-radius:12px;padding:.7rem;background:#fff}
.sf-card p{margin:0 0 .3rem;font-size:.8rem;color:#64748b}
.sf-thumb{width:64px;height:64px;flex-shrink:0;border-radius:10px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;overflow:hidden;font-size:1.5rem}
.sf-thumb img{width:100%;height:100%;object-fit:cover}
.sf-thumb.muted{background:#f1f5f9;color:#94a3b8}
.sf-thumb.warn{background:#fef3c7;color:#b45309}
</style>';
}
