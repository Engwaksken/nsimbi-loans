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
    'cta_label' => 'Explore financing', 'cta_url' => '/apply', 'hero_image' => '',
];
$heroImage = trim((string)$page['hero_image']);
$ctaUrl = trim((string)$page['cta_url']);
if ($ctaUrl === 'clients/index.php') $ctaUrl = '/apply';
if ($ctaUrl === 'login.php') $ctaUrl = '/login';
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
  <link rel="stylesheet" href="assets/css/nsimbi-theme.css?v=2">
</head>
<body>
<div class="top-strip"><span><i class="fa-solid fa-location-dot"></i> Plot 12, Corporation Rise, Bukoto | Kampala | Uganda</span><div><a href="mailto:info@nsimbiimpact.com?subject=Donor%20or%20ESO%20enquiry">Join as Donor / ESO</a><a href="https://www.facebook.com/witug/" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a><a href="https://x.com/wituganda" aria-label="X"><i class="fa-brands fa-x-twitter"></i></a><a href="https://www.linkedin.com/company/witu/" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a></div></div>
<header class="public-header"><a class="wordmark" href="/"><img src="assets/img/logo.png" alt="Nsimbi Impact"><span>Nsimbi <b>Impact</b></span></a><nav><a href="#story">Overview</a><a href="#vision">Our Vision</a><a href="#mission">Our Mission</a><a href="#approach">Approach & Insight</a><a href="#impact">Impact</a><a class="mobile-apply" href="/apply">Apply for a Loan</a><?php foreach ($navPages as $navPage): ?><a href="/<?= $esc($navPage['slug']) ?>"><?= $esc($navPage['title']) ?></a><?php endforeach; ?></nav><div class="header-actions"><a class="header-cta donor-cta" href="mailto:info@nsimbiimpact.com?subject=Donor%20or%20ESO%20enquiry">Join as Donor / ESO</a><a class="header-cta" href="/apply">Apply for a Loan <i class="fa-solid fa-arrow-right"></i></a></div><button class="menu-toggle" aria-label="Toggle menu"><i class="fa-solid fa-bars"></i></button></header>
<main>
  <section class="hero wp-hero<?= $heroImage !== '' ? ' has-image' : '' ?>"<?= $heroImage !== '' ? ' style="--hero-image:url(\'' . $esc($heroImage) . '\')"' : '' ?>>
    <div class="hero-content"><span class="eyebrow"><span></span> DIGNITY FOR WOMEN WRAPPED IN</span><h1><?= $esc($page['hero_title']) ?></h1><p><?= $esc($page['hero_subtitle']) ?></p><div class="hero-actions"><a class="button button-lime" href="<?= $esc($ctaUrl) ?>"><?= $esc($page['cta_label']) ?> <i class="fa-solid fa-arrow-right"></i></a><a class="button button-outline" href="mailto:info@nsimbiimpact.com?subject=Donor%20or%20ESO%20enquiry">Join as Donor / ESO</a></div><div class="hero-note"><i class="fa-solid fa-location-dot"></i> Changing Nations, One Woman at a Time</div></div>
    <div class="hero-art" aria-hidden="true"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><div class="art-sun"></div><div class="art-figure"><span></span></div><div class="art-card"><i class="fa-solid fa-seedling"></i><b>Investing in women</b><small>Capital for opportunity</small></div><div class="art-caption">DIGNITY<br>IN ACTION <span><i class="fa-solid fa-star" aria-hidden="true"></i></span></div></div>
  </section>
  <section class="story section-wrap wordpress-overview" id="story"><div class="section-label"><span>01</span> AN OVERVIEW</div><div class="story-grid"><h2>Investing in the<br><em>power of women.</em></h2><div class="story-copy"><p><?= nl2br($esc($page['body_content'])) ?></p><a class="under-link" href="/login">Already a client? Sign in <i class="fa-solid fa-arrow-right"></i></a></div></div></section>
  <section class="vision-band" id="vision"><div class="section-wrap vision-grid"><article><div class="section-label"><span>02</span> OUR VISION</div><h2>Empowered women.<br><em>Stronger communities.</em></h2><p>Our vision is to cultivate a robust community of empowered, financially independent women who make a positive difference in their communities and the world.</p></article><article id="mission"><div class="section-label"><span>03</span> OUR MISSION</div><h2>Data-informed investment.<br><em>Lasting change.</em></h2><p>We catalyze change by using data insights to drive smart investments and fuel the success of the next generation of women business leaders in Africa.</p></article></div></section>
  <section class="approach-section" id="approach"><div class="section-wrap"><div class="section-label"><span>04</span> APPROACH & INSIGHT</div><div class="approach-heading"><h2>Why this moment.<br><em>Why this model.</em></h2><p>We bring community trust, technology and financial access together to help women-led businesses move forward.</p></div><div class="approach-grid"><article><span class="approach-number">01</span><h3>Why Now?</h3><ul><li><i class="fa-solid fa-check" aria-hidden="true"></i> Growing digital penetration</li><li><i class="fa-solid fa-check" aria-hidden="true"></i> Shifting financial regulations</li><li><i class="fa-solid fa-check" aria-hidden="true"></i> Rising demand for financial inclusion</li></ul></article><article><span class="approach-number">02</span><h3>What is New?</h3><ul><li><i class="fa-solid fa-check" aria-hidden="true"></i> Voice-powered multilingual interfaces</li><li><i class="fa-solid fa-check" aria-hidden="true"></i> AI-driven credit scoring</li><li><i class="fa-solid fa-check" aria-hidden="true"></i> Capital designed around women's realities</li></ul></article><article class="approach-image"><i class="fa-solid fa-seedling"></i><strong>Access creates possibility.</strong><span>Community, Capital and Confidence</span></article></div></div></section>
  <section class="impact-section" id="impact"><div class="section-wrap"><div class="section-label"><span>05</span> OUR IMPACT VISION</div><h2>A ripple effect that<br><em>reaches everyone.</em></h2><div class="impact-cards"><article><span>01</span><i class="fa-solid fa-chart-line"></i><h3>Economic empowerment<br>and independence</h3></article><article><span>02</span><i class="fa-solid fa-house-chimney"></i><h3>Enhanced quality of life<br>and family wellbeing</h3></article><article><span>03</span><i class="fa-solid fa-people-group"></i><h3>Social empowerment<br>and community development</h3></article></div></div></section>
  <section class="newsletter-section"><div><span class="section-label">STAY CONNECTED</span><h2>Stories, insights and<br><em>new possibilities.</em></h2></div><form id="publicNewsletter"><label for="newsletterEmail">Subscribe to our newsletter</label><div><input type="email" name="email" id="newsletterEmail" placeholder="Your email address" required><button type="submit">Subscribe <i class="fa-solid fa-arrow-right"></i></button></div><p id="newsletterMessage" aria-live="polite"></p></form></section>
</main>
<footer class="public-footer"><div class="footer-top"><a class="wordmark" href="/"><img src="assets/img/logo.png" alt=""><span>Nsimbi <b>Impact</b></span></a><p>Dignity for women, wrapped in capital.<br>Building opportunity, one business at a time.</p><div class="socials"><a href="https://www.facebook.com/witug/" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a><a href="https://x.com/wituganda" aria-label="X"><i class="fa-brands fa-x-twitter"></i></a><a href="https://www.linkedin.com/company/witu/" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a></div></div><div class="footer-bottom"><span>© <?= date('Y') ?> Nsimbi Impact</span><span>Plot 12, Corporation Rise, Bukoto · Kampala, Uganda</span><a href="/login">Client & staff login <i class="fa-solid fa-arrow-right"></i></a></div></footer>
<script>
document.querySelector('.menu-toggle').addEventListener('click',()=>document.querySelector('.public-header nav').classList.toggle('open'));
document.getElementById('publicNewsletter').addEventListener('submit', async event => {
  event.preventDefault(); const form=event.currentTarget; const message=document.getElementById('newsletterMessage');
  try { const response=await fetch('includes/newsletter.php',{method:'POST',body:new FormData(form)}); const result=await response.json(); message.textContent=result.message||'Thank you.'; if(result.success) form.reset(); }
  catch (_) { message.textContent='Unable to subscribe right now. Please try again later.'; }
});
</script>
</body></html>
