<?php
require "db.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || !in_array((int)$_SESSION['role'], [1, 2], true)) {
    $_SESSION['message']      = "Not authorized.";
    $_SESSION['message_type'] = "danger";
    header("Location: dashboard.php");
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getImageDataUri(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') return '';

    $candidates = [
        $path,
        __DIR__ . '/' . ltrim($path, '/\\'),
        $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($path, '/\\'),
    ];

    foreach ($candidates as $p) {
        if ($p && file_exists($p) && is_file($p)) {
            $mime = mime_content_type($p);
            $data = base64_encode(file_get_contents($p));
            return 'data:' . $mime . ';base64,' . $data;
        }
    }
    return '';
}

/* -- Fetch startup ----------------------------------------- */
$startupId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$export    = isset($_GET['export']) ? trim($_GET['export']) : '';

if ($startupId <= 0) {
    $_SESSION['message']      = "Invalid startup selected.";
    $_SESSION['message_type'] = "danger";
    header("Location: startups.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT s.*, u.id AS user_id, u.username, u.role, u.status AS user_status
    FROM startups s
    INNER JOIN user u ON s.startup_id = u.id
    WHERE s.id = ?
    LIMIT 1
");
if (!$stmt) die("Failed to prepare statement.");

$stmt->bind_param("i", $startupId);
$stmt->execute();
$result  = $stmt->get_result();
$startup = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$startup) {
    $_SESSION['message']      = "Startup not found.";
    $_SESSION['message_type'] = "danger";
    header("Location: startups.php");
    exit;
}

/* -- Derived values ---------------------------------------- */
$logoPath    = trim((string)($startup['company_logo'] ?? ''));
$logoDataUri = getImageDataUri($logoPath);

$companyStatus = strtolower(trim((string)($startup['startup_status'] ?? $startup['status'] ?? 'inactive')));
$userStatus    = (int)($startup['user_status'] ?? 0) === 1 ? 'Active' : 'Blocked';

$statusBadgeClass = match($companyStatus) {
    'active'  => 'su-badge-success',
    'blocked' => 'su-badge-danger',
    default   => 'su-badge-warn',
};

/* -- PDF export -------------------------------------------- */
if ($export === 'pdf') {
    ob_start();
    include __DIR__ . '/includes/startup_pdf_template.php'; // or inline below
    $html = ob_get_clean();

    // Inline PDF template if separate file doesn't exist
    if (!$html) {
        ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Startup Profile - <?= h($startup['company_name'] ?? '') ?></title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; color: #1f2937; font-size: 13px; margin: 0; padding: 0; }
  .header { background: #0e1631; color: #fff; padding: 24px; }
  .logo-box { width: 80px; height: 80px; background: #fff; border-radius: 10px; padding: 6px; display: inline-block; vertical-align: middle; }
  .logo-box img { max-width: 68px; max-height: 68px; }
  .title-cell { padding-left: 18px; vertical-align: middle; }
  h1 { font-size: 24px; margin: 0 0 4px; font-weight: bold; }
  .sub { font-size: 13px; opacity: .75; margin: 0; }
  .content { padding: 24px; }
  .section-title { font-size: 14px; font-weight: bold; color: #0e1631; border-left: 4px solid #133297; padding-left: 10px; margin: 20px 0 12px; }
  table.t { width: 100%; border-collapse: collapse; }
  table.t td { padding: 9px 12px; border: 1px solid #e3e6ef; font-size: 12px; }
  table.t td.lbl { width: 30%; font-weight: bold; background: #f7f8fc; color: #334155; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: bold; }
  .b-green { background: #dcfce7; color: #15803d; }
  .b-red   { background: #fee2e2; color: #b91c1c; }
  .b-yellow{ background: #fef9c3; color: #a16207; }
  .footer  { text-align: center; font-size: 11px; color: #94a3b8; padding: 16px; }
</style>
</head>
<body>
<div class="header">
  <table width="100%"><tr>
    <td width="100">
      <div class="logo-box">
        <?php if (!empty($logoDataUri)): ?>
          <img src="<?= $logoDataUri ?>" alt="Logo">
        <?php else: ?><span style="font-size:40px;line-height:68px;">&#127970;</span><?php endif; ?>
      </div>
    </td>
    <td class="title-cell">
      <h1><?= h($startup['company_name'] ?? 'N/A') ?></h1>
      <p class="sub">Startup Profile Report &mdash; Generated <?= date('d M Y H:i') ?></p>
    </td>
  </tr></table>
</div>

<div class="content">
  <div class="section-title">Company Information</div>
  <table class="t">
    <tr><td class="lbl">Company Name</td><td><?= h($startup['company_name'] ?? 'N/A') ?></td></tr>
    <tr><td class="lbl">CEO Name</td><td><?= h($startup['ceo_name'] ?? 'N/A') ?></td></tr>
    <tr><td class="lbl">Phone</td><td><?= h($startup['phone'] ?? 'N/A') ?></td></tr>
    <tr><td class="lbl">Email</td><td><?= h($startup['email'] ?? 'N/A') ?></td></tr>
    <tr><td class="lbl">Location</td><td><?= h($startup['company_location'] ?? 'N/A') ?></td></tr>
    <tr><td class="lbl">Status</td><td>
      <?php $c = $companyStatus === 'active' ? 'b-green' : ($companyStatus === 'blocked' ? 'b-red' : 'b-yellow'); ?>
      <span class="badge <?= $c ?>"><?= h(ucfirst($companyStatus)) ?></span>
    </td></tr>
  </table>

  <div class="section-title">Bank Information</div>
  <table class="t">
    <tr><td class="lbl">Bank Name</td><td><?= h($startup['bank_name'] ?? 'N/A') ?></td></tr>
    <tr><td class="lbl">Account Name</td><td><?= h($startup['account_name'] ?? 'N/A') ?></td></tr>
    <tr><td class="lbl">Account Number</td><td><?= h($startup['account_number'] ?? 'N/A') ?></td></tr>
    <tr><td class="lbl">Bank Branch</td><td><?= h($startup['bank_branch'] ?? 'N/A') ?></td></tr>
  </table>

  <div class="section-title">System Account</div>
  <table class="t">
    <tr><td class="lbl">Username</td><td><?= h($startup['username'] ?? 'N/A') ?></td></tr>
    <tr><td class="lbl">User Status</td><td>
      <span class="badge <?= $userStatus === 'Active' ? 'b-green' : 'b-red' ?>"><?= h($userStatus) ?></span>
    </td></tr>
    <tr><td class="lbl">Record ID</td><td><?= (int)($startup['id'] ?? 0) ?></td></tr>
    <tr><td class="lbl">User ID</td><td><?= (int)($startup['user_id'] ?? 0) ?></td></tr>
  </table>
</div>

<div class="footer">Confidential &mdash; <?= h($startup['company_name'] ?? '') ?> Startup Profile</div>
</body></html>
<?php
        $html = ob_get_clean();
    }

    if (!class_exists('\Dompdf\Dompdf')) {
        @require_once __DIR__ . '/vendor/autoload.php';
    }

    if (!class_exists('\Dompdf\Dompdf')) {
        die("DOMPDF not installed. Run: composer require dompdf/dompdf");
    }

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('startup_profile_' . (int)$startup['id'] . '.pdf', ['Attachment' => true]);
    exit;
}

require "nav-head.php";
?>

<link rel="stylesheet" href="assets/css/startups.css">

<div class="container-fluid py-4">

    <!-- Toolbar -->
    <div class="su-page-header no-print">
        <div>
            <h1 class="su-page-title">
                <span class="su-title-icon"><i class="fas fa-id-card"></i></span>
                Startup Profile
            </h1>
            <p class="su-page-subtitle">Full details for <?= h($startup['company_name'] ?? '') ?></p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="startups.php" class="su-btn su-btn-light">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <button onclick="window.print()" class="su-btn su-btn-secondary">
                <i class="fas fa-print"></i> Print
            </button>
            <a href="startup_profile.php?id=<?= (int)$startup['id'] ?>&export=pdf" class="su-btn su-btn-danger">
                <i class="fas fa-file-pdf"></i> Export PDF
            </a>
        </div>
    </div>

    <!-- Hero banner -->
    <div class="sp-hero">
        <div class="sp-hero-inner">
            <div class="sp-logo">
                <?php if (!empty($logoDataUri)): ?>
                    <img src="<?= $logoDataUri ?>" alt="Logo">
                <?php else: ?>
                    &#127970;
                <?php endif; ?>
            </div>
            <div>
                <h2 class="sp-company-name"><?= h($startup['company_name'] ?? 'N/A') ?></h2>
                <div class="sp-company-meta">
                    <?php if (!empty($startup['ceo_name'])): ?>
                        <span><i class="fas fa-user-tie"></i> <?= h($startup['ceo_name']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($startup['company_location'])): ?>
                        <span><i class="fas fa-map-marker-alt"></i> <?= h($startup['company_location']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($startup['email'])): ?>
                        <span><i class="fas fa-envelope"></i> <?= h($startup['email']) ?></span>
                    <?php endif; ?>
                    <span>
                        <span class="su-badge <?= $statusBadgeClass ?>" style="font-size:0.72rem;">
                            <?= h(ucfirst($companyStatus)) ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile body -->
    <div class="sp-body">
        <div class="row g-4">

            <!-- Company info -->
            <div class="col-lg-6">
                <div class="sp-section-head">
                    <div class="sp-section-icon"><i class="fas fa-building"></i></div>
                    <h5 class="sp-section-title">Company Information</h5>
                </div>
                <div class="sp-panel">
                    <?php
                    $companyFields = [
                        ['fas fa-building',       'Company Name',    $startup['company_name']       ?? 'N/A'],
                        ['fas fa-user-tie',        'CEO Name',        $startup['ceo_name']           ?? 'N/A'],
                        ['fas fa-phone',           'Phone',           $startup['phone']              ?? 'N/A'],
                        ['fas fa-envelope',        'Email',           $startup['email']              ?? 'N/A'],
                        ['fas fa-map-marker-alt',  'Location',        $startup['company_location']   ?? 'N/A'],
                    ];
                    foreach ($companyFields as [$icon, $label, $val]): ?>
                        <div class="sp-row">
                            <div class="sp-key"><i class="<?= $icon ?>"></i><?= $label ?></div>
                            <div class="sp-val"><?= h($val) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="sp-row">
                        <div class="sp-key"><i class="fas fa-circle-dot"></i>Status</div>
                        <div class="sp-val">
                            <span class="su-badge <?= $statusBadgeClass ?>"><?= h(ucfirst($companyStatus)) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bank info -->
            <div class="col-lg-6">
                <div class="sp-section-head">
                    <div class="sp-section-icon"><i class="fas fa-university"></i></div>
                    <h5 class="sp-section-title">Bank Information</h5>
                </div>
                <div class="sp-panel">
                    <?php
                    $bankFields = [
                        ['fas fa-landmark',  'Bank Name',      $startup['bank_name']      ?? 'N/A'],
                        ['fas fa-user',      'Account Name',   $startup['account_name']   ?? 'N/A'],
                        ['fas fa-hashtag',   'Account Number', $startup['account_number'] ?? 'N/A'],
                        ['fas fa-code-branch','Bank Branch',   $startup['bank_branch']    ?? 'N/A'],
                    ];
                    foreach ($bankFields as [$icon, $label, $val]):
                        $isMono = in_array($label, ['Account Number']);
                    ?>
                        <div class="sp-row">
                            <div class="sp-key"><i class="<?= $icon ?>"></i><?= $label ?></div>
                            <div class="sp-val <?= $isMono ? 'mono' : '' ?>"><?= h($val) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- System account -->
            <div class="col-12">
                <div class="sp-section-head">
                    <div class="sp-section-icon"><i class="fas fa-user-shield"></i></div>
                    <h5 class="sp-section-title">System Account Information</h5>
                </div>
                <div class="sp-panel">
                    <div class="sp-row">
                        <div class="sp-key"><i class="fas fa-at"></i>Username</div>
                        <div class="sp-val mono"><?= h($startup['username'] ?? 'N/A') ?></div>
                    </div>
                    <div class="sp-row">
                        <div class="sp-key"><i class="fas fa-circle-dot"></i>User Status</div>
                        <div class="sp-val">
                            <span class="su-badge <?= $userStatus === 'Active' ? 'su-badge-success' : 'su-badge-danger' ?>">
                                <?= h($userStatus) ?>
                            </span>
                        </div>
                    </div>
                    <div class="sp-row">
                        <div class="sp-key"><i class="fas fa-fingerprint"></i>Startup Record ID</div>
                        <div class="sp-val">
                            <span class="sp-id-chip"># <?= (int)($startup['id'] ?? 0) ?></span>
                        </div>
                    </div>
                    <div class="sp-row">
                        <div class="sp-key"><i class="fas fa-id-badge"></i>User ID</div>
                        <div class="sp-val">
                            <span class="sp-id-chip"># <?= (int)($startup['user_id'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /row -->

        <div class="sp-divider"></div>

        <!-- Footer meta -->
        <p style="font-size:0.78rem;color:var(--su-text-3);text-align:right;margin:0;">
            <i class="fas fa-clock"></i>
            Profile generated on <?= date('d M Y, h:i A') ?>
        </p>
    </div><!-- /sp-body -->

</div>

<?php require "footer.php"; ?>