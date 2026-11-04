<?php
require_once 'db.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Africa/Nairobi');

/* ---------- CONFIG ---------- */
$companyName  = "Nsimbi Impact Limited"; 
$generatedAt  = date('F d, Y \a\t H:i:s');

/* ---------- VALIDATE INPUT ---------- */
if (!isset($_REQUEST['user_id']) || trim($_REQUEST['user_id']) === '') {
    die("User ID is required.");
}
$user_id = trim($_REQUEST['user_id']);

function imageToBase64($relativePath, bool $forceLandscape = false, int $maxWidthPx = 1400) {
    if (!$relativePath) return null;

    $filePath = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . "/loans/staff/" . ltrim($relativePath, '/');
    if (!file_exists($filePath)) return null;

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) return null;

    // Load image
    switch ($ext) {
        case 'jpg':
        case 'jpeg': $src = imagecreatefromjpeg($filePath); break;
        case 'png':  $src = imagecreatefrompng($filePath);  break;
        case 'gif':  $src = imagecreatefromgif($filePath);  break;
        default: return null;
    }

    if (!$src) return null;

    $w = imagesx($src);
    $h = imagesy($src);

    /* ---------- ROTATE ONLY IF REQUESTED ---------- */
    if ($forceLandscape && $h > $w) {
        $rotated = imagerotate($src, -90, 0); // rotate to landscape
        imagedestroy($src);
        if (!$rotated) return null;
        $src = $rotated;
        $w = imagesx($src);
        $h = imagesy($src);
    }

    /* ---------- RESIZE TO FIT PAGE ---------- */
    if ($w > $maxWidthPx) {
        $newW = $maxWidthPx;
        $newH = (int)(($h / $w) * $newW);

        $dst = imagecreatetruecolor($newW, $newH);

        // Preserve transparency
        if (in_array($ext, ['png', 'gif'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $t = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $t);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    /* ---------- OUTPUT BASE64 ---------- */
    ob_start();
    imagepng($src); // PNG is safest for PDFs
    $bytes = ob_get_clean();
    imagedestroy($src);

    return $bytes ? "data:image/png;base64," . base64_encode($bytes) : null;
}


function signatureBase64WhiteBg(?string $relativePath, int $maxWidthPx = 600): ?string {
    if (!$relativePath) return null;

    $filePath = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . "/loans/staff/" . ltrim($relativePath, '/');
    if (!file_exists($filePath)) return null;

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif'], true)) return null;

    // Load image
    switch ($ext) {
        case 'jpg':
        case 'jpeg': $src = @imagecreatefromjpeg($filePath); break;
        case 'png':  $src = @imagecreatefrompng($filePath);  break;
        case 'gif':  $src = @imagecreatefromgif($filePath);  break;
        default: return null;
    }
    
    if (!$src) return null;

    $w = imagesx($src);
    $h = imagesy($src);

    // Calculate new dimensions if resizing needed
    $newW = $w;
    $newH = $h;
    
    if ($w > $maxWidthPx) {
        $newW = $maxWidthPx;
        $newH = (int)round(($h / $w) * $newW);
    }

    // Create new image with WHITE background (not transparent)
    $dst = imagecreatetruecolor($newW, $newH);
    
    // Fill with white background
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
    
    // Enable alpha blending to properly composite the signature
    imagealphablending($dst, true);
    
    // Copy and resample the signature onto white background
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
    
    imagedestroy($src);

    // Output as PNG with white background
    ob_start();
    imagepng($dst);
    $bytes = ob_get_clean();
    imagedestroy($dst);

    return $bytes ? "data:image/png;base64," . base64_encode($bytes) : null;
}


/*  FETCH STAFF DATA  */
$staffQuery = "
    SELECT u.fname, u.lname, u.email, u.image_url, u.status, m.*
    FROM members m
    INNER JOIN user u ON m.user_id = u.id
    WHERE m.user_id = ?
    ORDER BY m.user_id DESC
    LIMIT 1
";
$staffStmt = $conn->prepare($staffQuery);
$staffStmt->bind_param("s", $user_id);
$staffStmt->execute();
$staffResult = $staffStmt->get_result();

if (!$staffResult || $staffResult->num_rows === 0) {
    die("No staff member found for this ID.");
}
$staffData = $staffResult->fetch_assoc();
$staffStmt->close();

/* FETCH LOAN COUNT  */
$loanQuery = "SELECT COUNT(*) as loan_count FROM loan WHERE client_id = ?";
$loanStmt  = $conn->prepare($loanQuery);
$loanStmt->bind_param("s", $user_id);
$loanStmt->execute();
$loanResult = $loanStmt->get_result();
$loanData   = ($loanResult && $loanResult->num_rows > 0) ? $loanResult->fetch_assoc() : ['loan_count' => 0];
$loanStmt->close();

$loanCount = (int)($loanData['loan_count'] ?? 0);

/*  IMAGES (BASE64) */
$profileImage   = imageToBase64($staffData['image_url'], false);
$idFrontImage   = imageToBase64($staffData['id_front'], true);   // auto-rotate
$idBackImage    = imageToBase64($staffData['id_back'], true);    // auto-rotate
$signatureImage = signatureBase64WhiteBg($staffData['applicant_signature'] ?? null, 600);


/*  STATUS  */
$statusText  = ((int)$staffData['status'] === 1) ? 'Active' : 'Blocked';

$html = '
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    @page { margin: 20mm 15mm; }

    body {
      font-family: Arial, sans-serif;
      font-size: 11px;
      color: #333;
      line-height: 1.6;
    }

    .header {
      text-align: center;
      margin-bottom: 30px;
      padding-bottom: 15px;
      border-bottom: 3px solid #29d462;
    }
    .header h1 {
      margin: 0;
      color: #29d462;
      font-size: 24px;
    }
    .header-info {
      margin-top: 10px;
      font-size: 9px;
      color: #666;
    }

    .section-title {
      background-color: #29d462;
      color: #fff;
      padding: 10px 15px;
      margin-top: 25px;
      margin-bottom: 15px;
      font-size: 14px;
      font-weight: bold;
      page-break-after: avoid;
    }

    .profile-image {
      text-align: center;       
      margin: 20px 0;
    }

    .profile-image img {
      display: inline-block;     
      max-width: 150px;
      max-height: 150px;
      border: 2px solid #dee2e6;
      border-radius: 50%;        
    }

    table { width: 100%; border-collapse: collapse; }
    tr { page-break-inside: avoid; }
    td {
      padding: 10px;
      border-bottom: 1px solid #dee2e6;
    }
    td:first-child {
      font-weight: bold;
      width: 35%;
      background-color: #f8f9fa;
    }

    .status-active { color: #28a745; font-weight: bold; }
    .status-blocked { color: #dc3545; font-weight: bold; }

    .page-break { page-break-before: always; }

    .doc-block {
      page-break-inside: avoid;
      margin-bottom: 20px;
    }
    .document-title {
      font-weight: bold;
      font-size: 12px;
      margin: 14px 0 8px 0;
      color: #29d462;
      page-break-after: avoid;
    }
    .document-image {
      text-align: center;
      margin-bottom: 10px;
      page-break-inside: avoid;
    }
    .document-image img {
      max-width: 100%;
      max-height: 650px;
      height: auto;
      border: 1px solid #dee2e6;
    }
    
    .signature-image {
      text-align: left;
      padding: 10px;
      background: #ffffff;
      border: 0px solid #dee2e6;
      margin: 0 auto;
      display: inline-block;
    }

    .signature-image img {
      display: inline-block;
      max-width: 300px;
      max-height: 27px;
      height: auto;
      background: #ffffff;
    }
    
    .signature-container {
      text-align: left;
    }

  </style>
</head>

<body>
  <div class="header">
    <h1>Staff Member Profile Report</h1>
    <div class="header-info">Generated on ' . htmlspecialchars($generatedAt) . '</div>
  </div>
';

/* Profile Image */
if ($profileImage) {
    $html .= '
    <div class="profile-image">
      <img src="' . $profileImage . '" alt="Profile Photo">
      <p style="margin-top: 5px; font-size: 10px; color: #666;">Profile Photo</p>
    </div>';
}

/* Personal Information */
$html .= '
  <div class="section-title">Personal Information</div>
  <table>
    <tr><td>Full Name</td><td>' . htmlspecialchars(($staffData['fname'] ?? '') . ' ' . ($staffData['lname'] ?? '')) . '</td></tr>
    <tr><td>Personal Email</td><td>' . htmlspecialchars($staffData['email'] ?? 'N/A') . '</td></tr>
    <tr><td>Work Email</td><td>' . htmlspecialchars($staffData['work_email'] ?? 'N/A') . '</td></tr>
    <tr><td>National ID (NIN)</td><td>' . htmlspecialchars($staffData['nin'] ?? 'N/A') . '</td></tr>
    <tr><td>Phone Number</td><td>' . htmlspecialchars($staffData['phone'] ?? 'N/A') . '</td></tr>
    <tr><td>Employer</td><td>' . htmlspecialchars($staffData['employer'] ?? 'N/A') . '</td></tr>
    <tr><td>Department</td><td>' . htmlspecialchars($staffData['department'] ?? 'N/A') . '</td></tr>
    <tr><td>Position</td><td>' . htmlspecialchars($staffData['job_title'] ?? 'N/A') . '</td></tr>
    <tr><td>Employment Status</td><td>' . htmlspecialchars($staffData['employment_status'] ?? 'N/A') . '</td></tr>
    <tr><td>Home Address</td><td>' . htmlspecialchars($staffData['home_address'] ?? 'N/A') . '</td></tr>
    <tr>
      <td>Status</td>
      <td><span class="status-' . (((int)($staffData['status'] ?? 0) === 1) ? 'active' : 'blocked') . '">' . htmlspecialchars($statusText) . '</span></td>
    </tr>
    <tr><td>Created Date</td><td>' . htmlspecialchars($staffData['created_at'] ?? 'N/A') . '</td></tr>
  </table>
';

/* Financial Information */
$html .= '
  <div class="section-title">Financial Information</div>
  <table>
    <tr><td>Gross Salary</td><td>' . htmlspecialchars($staffData['gross_salary'] ?? 'N/A') . '</td></tr>
    <tr><td>Net Salary</td><td>' . htmlspecialchars($staffData['net_salary'] ?? 'N/A') . '</td></tr>
    <tr><td>Loan Limit</td><td>' . htmlspecialchars($staffData['loan_limit'] ?? 'N/A') . '</td></tr>
    <tr><td>Total Loans</td><td>' . (int)$loanCount . '</td></tr>
    <tr><td>Bank Name</td><td>' . htmlspecialchars($staffData['bank_name'] ?? 'N/A') . '</td></tr>
    <tr><td>Bank Account</td><td>' . htmlspecialchars($staffData['bank_account'] ?? 'N/A') . '</td></tr>
  </table>
';

/* Documents (NEW PAGE) */
$html .= '
  <div class="page-break"></div>
  <div class="section-title">Attached Documents</div>
';

if ($idFrontImage) {
    $html .= '
    <div class="doc-block">
      <div class="document-title">National ID - Front</div>
      <div class="document-image">
        <img src="' . $idFrontImage . '" alt="National ID Front">
      </div>
    </div>';
}

if ($idBackImage) {
    $html .= '
    <div class="doc-block">
      <div class="document-title">National ID - Back</div>
      <div class="document-image">
        <img src="' . $idBackImage . '" alt="National ID Back">
      </div>
    </div>';
}

if ($signatureImage) {
    $html .= '
    <div class="doc-block">
      <div class="document-title">Applicant Signature</div>
      <div class="signature-container">
        <div class="signature-image">
          <img src="' . $signatureImage . '" alt="Signature">
        </div>
      </div>
    </div>';
}

$html .= '
</body>
</html>';



/*  DOMPDF CONFIG  */
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');
$options->set('chroot', realpath(''));

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

/* PAGE NUMBERS + FOOTER (EVERY PAGE)  */
$canvas = $dompdf->getCanvas();
$font   = $dompdf->getFontMetrics()->getFont("Helvetica", "normal");

// A4 portrait (points) ˜ 595 x 842
// Footer line (left)
$canvas->page_text(
    40, 820,
    $companyName . " | Generated: " . $generatedAt,
    $font,
    9,
    [0, 0, 0]
);

// Page numbers (right)
$canvas->page_text(
    460, 820,
    "Page {PAGE_NUM} of {PAGE_COUNT}",
    $font,
    9,
    [0, 0, 0]
);

/* OUTPUT */
$filename = 'Staff_Profile_' . str_replace(' ', '_', $staffData['fname'] . '_' . $staffData['lname']) . '_' . date('Ymd') . '.pdf';
$dompdf->stream($filename, ['Attachment' => 0]);

if (isset($conn)) $conn->close();
exit;
?>