<?php
if (!isset($conn)) {
    require_once __DIR__ . '/config.php';
}

/* ---------------------------------------------------------
| Helpers
----------------------------------------------------------*/
if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('assetUrl')) {
    function assetUrl($path): string
    {
        $path = trim((string)$path);

        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $path = ltrim($path, '/');

        return rtrim(SITE_URL, '/') . '/' . $path;
    }
}

if (!function_exists('assetExists')) {
    function assetExists($path): bool
    {
        $path = trim((string)$path);

        if ($path === '') {
            return false;
        }

        if (preg_match('#^https?://#i', $path)) {
            return true;
        }

        return is_file(dirname(__DIR__) . '/' . ltrim($path, '/'));
    }
}

/* ---------------------------------------------------------
| Site Settings
----------------------------------------------------------*/

$siteName = getSetting($conn, 'site_name', 'Nsimbi Impact');
$tagline  = getSetting($conn, 'site_tagline', '');

$logo      = getSetting($conn, 'logo_url', '');
$favicon   = getSetting($conn, 'favicon_url', '');

$address   = getSetting(
    $conn,
    'site_address',
    'Plot 12, Corporation Rise, Bukoto, Kampala'
);

$fb = getSetting($conn, 'facebook_url', '');
$tw = getSetting($conn, 'twitter_url', '');
$li = getSetting($conn, 'linkedin_url', '');

$pageTitle = $pageTitle ?? $siteName;
$pageDesc  = $pageDesc ?? $tagline;

$currentPage = basename($_SERVER['PHP_SELF']);

/* ---------------------------------------------------------
| Favicon
----------------------------------------------------------*/

$faviconUrl = '';

if ($favicon && assetExists($favicon)) {
    $faviconUrl = assetUrl($favicon);
} elseif (assetExists('assets/images/favicon.ico')) {
    $faviconUrl = assetUrl('assets/images/favicon.ico');
}

$faviconExt = strtolower(pathinfo($faviconUrl, PATHINFO_EXTENSION));

$faviconType = match ($faviconExt) {
    'png'  => 'image/png',
    'jpg',
    'jpeg' => 'image/jpeg',
    'svg'  => 'image/svg+xml',
    'gif'  => 'image/gif',
    default => 'image/x-icon'
};

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1,maximum-scale=5">

<title><?= e($pageTitle) ?></title>

<meta
    name="description"
    content="<?= e($pageDesc) ?>">

<meta
    name="theme-color"
    content="<?= e(getSetting($conn,'primary_color','#123299')) ?>">

<?php if ($faviconUrl): ?>

<link
    rel="icon"
    href="<?= e($faviconUrl) ?>"
    type="<?= e($faviconType) ?>">

<link
    rel="shortcut icon"
    href="<?= e($faviconUrl) ?>">

<link
    rel="apple-touch-icon"
    href="<?= e($faviconUrl) ?>">

<?php endif; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">

<link rel="preconnect"
      href="https://fonts.gstatic.com"
      crossorigin>

<link
href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap"
rel="stylesheet">

<link
rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<link
rel="stylesheet"
href="<?= assetUrl('assets/css/style.css') ?>">

</head>

<body>

<div class="topbar">

    <div class="topbar-inner">

        <span class="topbar-addr">
            <i class="fas fa-location-dot"></i>
            <?= e($address) ?>
        </span>

        <div class="topbar-social">

            <?php if ($fb): ?>
                <a href="<?= e($fb) ?>" target="_blank">
                    <i class="fab fa-facebook-f"></i>
                </a>
            <?php endif; ?>

            <?php if ($tw): ?>
                <a href="<?= e($tw) ?>" target="_blank">
                    <i class="fab fa-x-twitter"></i>
                </a>
            <?php endif; ?>

            <?php if ($li): ?>
                <a href="<?= e($li) ?>" target="_blank">
                    <i class="fab fa-linkedin-in"></i>
                </a>
            <?php endif; ?>

        </div>

    </div>

</div>

<header class="navbar" id="navbar">

    <div class="nav-container">

        <a class="brand" href="index.php">

            <?php if ($logo && assetExists($logo)): ?>

                <img
                    src="<?= e(assetUrl($logo)) ?>"
                    alt="<?= e($siteName) ?>"
                    height="44">

            <?php else: ?>

                <span class="brand-text">
                    <?= e($siteName) ?>
                </span>

            <?php endif; ?>

        </a>

        <nav class="nav-links" id="navLinks">

            <a href="index.php"
               class="<?= $currentPage=='index.php'?'active':'' ?>">
               Home
            </a>

            <a href="index.php#overview">About</a>

            <a href="index.php#approach">Approach</a>

            <a href="contact.php"
               class="<?= $currentPage=='contact.php'?'active':'' ?>">
               Contact
            </a>

            <a href="donor-eso.php"
               class="nav-cta <?= $currentPage=='donor-eso.php'?'active':'' ?>">
                <i class="fas fa-hand-holding-heart"></i>
                Join as Donor / ESO
            </a>

        </nav>

        <button
            class="hamburger"
            id="hamburger"
            type="button"
            aria-label="Menu">

            <span></span>
            <span></span>
            <span></span>

        </button>

    </div>

</header>