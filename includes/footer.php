<?php
if (!isset($conn)) {
    require_once __DIR__ . '/config.php';
}

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$siteName = getSetting($conn, 'site_name', 'Nsimbi Impact');
$footerAbout = getSetting($conn, 'footer_about', '');

$fb = getSetting($conn, 'facebook_url', '');
$tw = getSetting($conn, 'twitter_url', '');
$li = getSetting($conn, 'linkedin_url', '');

$footerSecs = [];

$tableCheck = $conn->query("SHOW TABLES LIKE 'footer_sections'");

if ($tableCheck && $tableCheck->num_rows > 0) {
    $fResult = $conn->query("
        SELECT *
        FROM footer_sections
        WHERE status = 'active'
        ORDER BY sort_order ASC, id ASC
    ");

    if ($fResult) {
        while ($r = $fResult->fetch_assoc()) {
            $footerSecs[] = $r;
        }
    }
}
?>

<footer class="site-footer">
    <div class="footer-container">

        <?php if (!empty($footerSecs)): ?>

            <?php foreach ($footerSecs as $fsec): ?>
                <div class="footer-col">
                    <h4><?= e($fsec['title'] ?? '') ?></h4>

                    <?php if (!empty($fsec['content'])): ?>
                        <p><?= nl2br(e($fsec['content'])) ?></p>
                    <?php endif; ?>

                    <?php
                    $links = [];
                    if (!empty($fsec['links_json'])) {
                        $links = json_decode($fsec['links_json'], true) ?: [];
                    }

                    foreach ($links as $link):
                        if (!empty($link['text']) && isset($link['url'])):
                    ?>
                            <a href="<?= e($link['url']) ?>" class="footer-link">
                                <?= e($link['text']) ?>
                            </a>
                        <?php elseif (!empty($link['value'])): ?>
                            <p class="footer-info"><?= e($link['value']) ?></p>
                    <?php
                        endif;
                    endforeach;
                    ?>
                </div>
            <?php endforeach; ?>

        <?php else: ?>

            <div class="footer-col">
                <h4><?= e($siteName) ?></h4>
                <p>
                    <?= nl2br(e($footerAbout ?: 'Dignity for women wrapped in unsecured capital.')) ?>
                </p>
            </div>

            <div class="footer-col">
                <h4>Quick Links</h4>
                <a href="index.php#overview" class="footer-link">About</a>
                <a href="index.php#approach" class="footer-link">Approach</a>
                <a href="index.php#impact" class="footer-link">Impact</a>
                <a href="privacy-policy.php" class="footer-link">Privacy Policy</a>
            </div>

            <div class="footer-col">
                <h4>Contact</h4>
                <p class="footer-info"><?= e(getSetting($conn, 'site_address', 'Kampala, Uganda')) ?></p>
                <p class="footer-info"><?= e(getSetting($conn, 'site_phone', '')) ?></p>
                <p class="footer-info"><?= e(getSetting($conn, 'site_email', '')) ?></p>
            </div>

        <?php endif; ?>

        <div class="footer-col">
            <h4>Follow Us</h4>

            <div class="footer-social">
                <?php if ($fb): ?>
                    <a href="<?= e($fb) ?>" target="_blank" class="social-icon fb">f</a>
                <?php endif; ?>

                <?php if ($tw): ?>
                    <a href="<?= e($tw) ?>" target="_blank" class="social-icon tw">x</a>
                <?php endif; ?>

                <?php if ($li): ?>
                    <a href="<?= e($li) ?>" target="_blank" class="social-icon li">in</a>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <div class="footer-bottom">
        <p>Copyright &copy; <?= date('Y') ?> | <?= e($siteName) ?></p>
    </div>
</footer>

<script src="<?= defined('SITE_URL') ? rtrim(SITE_URL, '/') . '/' : '' ?>assets/js/main.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("nlForm");
    const msg  = document.getElementById("nlMsg");

    if (!form || !msg) return;

    form.addEventListener("submit", function(e){
        e.preventDefault();

        fetch(form.action, {
            method: "POST",
            body: new FormData(form)
        })
        .then(res => res.json())
        .then(data => {
            msg.textContent = data.message || "";

            msg.classList.remove("success", "error", "show");
            msg.classList.add(data.success ? "success" : "error");
            msg.style.display = "block";

            requestAnimationFrame(() => msg.classList.add("show"));

            if (data.success) form.reset();

            setTimeout(function(){
                msg.classList.remove("show");

                setTimeout(function(){
                    msg.style.display = "none";
                    msg.textContent = "";
                }, 600);
            }, 5000);
        })
        .catch(function(){
            msg.textContent = "Something went wrong. Please try again.";
            msg.classList.remove("success", "error", "show");
            msg.classList.add("error");
            msg.style.display = "block";

            requestAnimationFrame(() => msg.classList.add("show"));

            setTimeout(function(){
                msg.classList.remove("show");

                setTimeout(function(){
                    msg.style.display = "none";
                    msg.textContent = "";
                }, 600);
            }, 5000);
        });
    });
});
</script>

</body>
</html>