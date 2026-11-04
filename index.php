<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$page = null;
$navPages = [];
try {
    $query = $conn->prepare("SELECT title, meta_description, hero_title, hero_subtitle, body_content, cta_label, cta_url, hero_image FROM site_pages WHERE slug = 'home' AND status = 'published' LIMIT 1");
    $query->execute();
    $page = $query->get_result()->fetch_assoc() ?: null;
    $query->close();
    $navPages = $conn->query("SELECT title, slug FROM site_pages WHERE status='published' AND slug <> 'home' ORDER BY title LIMIT 5")->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $ignored) {
    $page = null;
}
$esc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$page ??= [
    'title' => 'Nsimbi Impact | Capital for women-led businesses',
    'meta_description' => 'Dignity for women wrapped in capital. We invest in women-led businesses across Uganda and Sub-Saharan Africa.',
    'hero_title' => 'Capital that sees the potential in every woman.',
    'hero_subtitle' => 'Backing women-led businesses with the capital, confidence and community to build what comes next.',
    'body_content' => "Nsimbi Impact is a data-driven, female-led investment platform transforming financial access for women-led businesses across Sub-Saharan Africa, starting in Uganda.\n\nWe combine flexible capital with trusted community partnerships and practical business support—because talent is everywhere, but access to finance is not.",
    'cta_label' => 'Explore financing', 'cta_url' => 'clients/index.php', 'hero_image' => '',
];
$heroImage = trim((string)$page['hero_image']);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $esc($page['title']) ?></title>
  <meta name="description" content="<?= $esc($page['meta_description']) ?>">
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="assets/css/public-site.css">
</head>
<body>
<header class="public-header"><a class="wordmark" href="index.php"><img src="assets/img/logo.png" alt="Nsimbi Impact"><span>Nsimbi <b>Impact</b></span></a><nav><a href="#story">Our story</a><a href="#funds">Our approach</a><a href="#impact">Our impact</a><?php foreach ($navPages as $navPage): ?><a href="page.php?slug=<?= $esc($navPage['slug']) ?>"><?= $esc($navPage['title']) ?></a><?php endforeach; ?></nav><a class="header-cta" href="clients/index.php">Apply for a loan <i class="fa-solid fa-arrow-up-right-from-square"></i></a><button class="menu-toggle" aria-label="Toggle menu"><i class="fa-solid fa-bars"></i></button></header>
<main>
  <section class="hero<?= $heroImage !== '' ? ' has-image' : '' ?>"<?= $heroImage !== '' ? ' style="--hero-image:url(\'' . $esc($heroImage) . '\')"' : '' ?>>
    <div class="hero-content"><span class="eyebrow"><span></span> WOMEN-LED. COMMUNITY-ROOTED. FUTURE-FOCUSED.</span><h1><?= $esc($page['hero_title']) ?></h1><p><?= $esc($page['hero_subtitle']) ?></p><div class="hero-actions"><a class="button button-lime" href="<?= $esc($page['cta_url']) ?>"><?= $esc($page['cta_label']) ?> <i class="fa-solid fa-arrow-right"></i></a><a class="text-link" href="#story">Discover Nsimbi <i class="fa-solid fa-arrow-down"></i></a></div><div class="hero-note"><i class="fa-solid fa-location-dot"></i> Starting in Uganda. Growing across Africa.</div></div>
    <div class="hero-art" aria-hidden="true"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><div class="art-sun"></div><div class="art-figure"><span></span></div><div class="art-card"><i class="fa-solid fa-seedling"></i><b>Investing in possibility</b><small>One business at a time</small></div><div class="art-caption">DIGNITY<br>IN ACTION <span>✳</span></div></div>
    <div class="hero-bottom"><span>01 — 03</span><span class="hero-line"></span><span>THE NSIMBI DIFFERENCE</span></div>
  </section>
  <section class="ticker"><div><span>ACCESS TO CAPITAL</span><i>✳</i><span>WOMEN'S ECONOMIC POWER</span><i>✳</i><span>COMMUNITY-LED GROWTH</span><i>✳</i><span>ACCESS TO CAPITAL</span><i>✳</i><span>WOMEN'S ECONOMIC POWER</span></div></section>
  <section class="story section-wrap" id="story"><div class="section-label"><span>01</span> WHO WE ARE</div><div class="story-grid"><h2>When women thrive,<br><em>communities move forward.</em></h2><div class="story-copy"><p><?= nl2br($esc($page['body_content'])) ?></p><a class="under-link" href="login.php">Already a client? Sign in <i class="fa-solid fa-arrow-right"></i></a></div></div><div class="story-stats"><div><b>100%</b><span>Focus on women-led enterprise</span></div><div><b>Uganda</b><span>Our home and starting point</span></div><div><b>Community</b><span>At the heart of every investment</span></div></div></section>
  <section class="funds" id="funds"><div class="section-wrap"><div class="section-label light"><span>02</span> HOW WE WORK</div><div class="funds-heading"><h2>More than money.<br><em>Momentum.</em></h2><p>We meet entrepreneurs where they are, with finance and support shaped around the realities of their businesses.</p></div><div class="fund-cards"><article class="fund-card"><span class="card-index">01 / MICRO BUSINESS FUND</span><div class="fund-icon"><i class="fa-solid fa-shop"></i></div><h3>For the builders<br>of everyday economies.</h3><p>Flexible capital for micro entrepreneurs ready to strengthen their businesses, grow their income and support their families.</p><a href="clients/index.php">Learn about financing <i class="fa-solid fa-arrow-right"></i></a></article><article class="fund-card featured"><span class="card-index">02 / SME GROWTH FUND</span><div class="fund-icon"><i class="fa-solid fa-chart-line"></i></div><h3>For the businesses<br>ready to scale.</h3><p>Patient capital and strategic support for women-led small and growing businesses with the drive to reach the next level.</p><a href="clients/index.php">Explore opportunities <i class="fa-solid fa-arrow-right"></i></a></article><article class="fund-card"><span class="card-index">03 / OUR NETWORK</span><div class="fund-icon"><i class="fa-solid fa-people-group"></i></div><h3>Rooted in trusted<br>partnerships.</h3><p>We work with local ESOs and community organisations to find, prepare and walk alongside entrepreneurs.</p><a href="mailto:info@nsimbiimpact.com">Partner with us <i class="fa-solid fa-arrow-right"></i></a></article></div></div></section>
  <section class="impact section-wrap" id="impact"><div class="impact-art"><div class="impact-circle"><span>GROW<br>TOGETHER</span><i class="fa-solid fa-seedling"></i></div><span class="impact-small">CAPITAL WITH<br>COMMUNITY AT ITS CORE</span></div><div class="impact-copy"><div class="section-label"><span>03</span> THE CHANGE WE BACK</div><h2>Investing in a future<br>where <em>everyone rises.</em></h2><p>Our work is about more than the businesses we finance. It is about creating lasting economic independence, stronger families and more resilient communities.</p><ul><li><i class="fa-solid fa-check"></i> Economic empowerment and independence</li><li><i class="fa-solid fa-check"></i> Better quality of life for families</li><li><i class="fa-solid fa-check"></i> Stronger, more connected communities</li></ul><a class="button button-dark" href="mailto:info@nsimbiimpact.com">Start a conversation <i class="fa-solid fa-arrow-right"></i></a></div></section>
  <section class="closing"><div class="closing-inner"><span class="eyebrow">THE NEXT CHAPTER STARTS HERE</span><h2>Big ideas deserve<br><em>room to grow.</em></h2><a class="button button-lime" href="clients/index.php">Take your next step <i class="fa-solid fa-arrow-right"></i></a></div><div class="closing-mark">N</div></section>
</main>
<footer class="public-footer"><div class="footer-top"><a class="wordmark" href="index.php"><img src="assets/img/logo.png" alt=""><span>Nsimbi <b>Impact</b></span></a><p>Dignity for women, wrapped in capital.<br>Building opportunity, one business at a time.</p><div class="socials"><a href="https://www.facebook.com/witug/" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a><a href="https://x.com/wituganda" aria-label="X"><i class="fa-brands fa-x-twitter"></i></a><a href="https://www.linkedin.com/company/witu/" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a></div></div><div class="footer-bottom"><span>© <?= date('Y') ?> Nsimbi Impact</span><span>Plot 12, Corporation Rise, Bukoto · Kampala, Uganda</span><a href="login.php">Client & staff login <i class="fa-solid fa-arrow-right"></i></a></div></footer>
<script>document.querySelector('.menu-toggle').addEventListener('click',()=>document.querySelector('.public-header nav').classList.toggle('open'));</script>
</body></html>
