<?php
declare(strict_types=1);

/*
 * Branding for every PDF the admin area produces (dompdf).
 *
 *   $dompdf->loadHtml(pdf_brand_html($conn, $html));
 *   $dompdf->render();
 *   pdf_brand_footer($dompdf, $conn);
 *
 * pdf_brand_html() puts a brand bar (logo, company name, contact) at the top
 * of the first page; pdf_brand_footer() writes the company name, the print
 * date and "Page X of Y" along the bottom of every page. Name, logo, colour
 * and email come from the website settings (System > Website / CMS), so a
 * change there shows up in every PDF.
 */

require_once __DIR__ . '/site_cms.php';

/** @return array{name: string, logo: string, color: string, email: string} logo is a data URI or '' */
function pdf_brand(?mysqli $conn = null): array
{
    static $brand = null;
    if ($brand !== null) {
        return $brand;
    }
    $settings = site_settings($conn);
    $name = trim((string)($settings['site_name'] ?? '')) ?: 'Nsimbi Impact';
    $color = (string)($settings['color_primary'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#0f3399';
    }
    return $brand = [
        'name'  => $name,
        'logo'  => pdf_brand_logo_uri((string)($settings['logo'] ?? '')),
        'color' => $color,
        'email' => trim((string)($settings['enquiry_email'] ?? '')),
    ];
}

/** The site logo as a data URI (dompdf can't fetch site-relative paths), or '' when none is readable. */
function pdf_brand_logo_uri(string $logo): string
{
    $root = dirname(__DIR__);
    $candidates = [];
    $logo = trim($logo);
    if ($logo !== '' && !preg_match('~^(https?:)?//~i', $logo) && !str_starts_with($logo, 'data:')) {
        $path = ltrim(preg_replace('~^(\.\./)+~', '', parse_url($logo, PHP_URL_PATH) ?: ''), '/');
        if ($path !== '' && !str_contains($path, '..')) {
            $candidates[] = $root . '/' . $path;
        }
    }
    $candidates[] = $root . '/assets/img/logo.png'; // the built-in logo
    foreach ($candidates as $file) {
        if (!is_file($file) || filesize($file) > 2 * 1024 * 1024) {
            continue;
        }
        $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            'svg'         => 'image/svg+xml',
            default       => '',
        };
        $bytes = $mime !== '' ? @file_get_contents($file) : false;
        if ($bytes !== false && $bytes !== '') {
            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }
    }
    return '';
}

/** The brand bar HTML (with its own styles). */
function pdf_brand_header(?mysqli $conn = null): string
{
    $b = pdf_brand($conn);
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $logo = $b['logo'] !== ''
        ? '<img src="' . $b['logo'] . '" alt="" style="height:38px;max-width:150px">'
        : '';
    return '
<table class="pdf-brand-bar" style="width:100%;border-collapse:collapse;margin:0 0 12px;border-bottom:2px solid ' . $b['color'] . '">
    <tr>
        <td style="padding:0 0 8px;vertical-align:middle;border:none;background:none;width:1%;white-space:nowrap">' . $logo . '</td>
        <td style="padding:0 0 8px ' . ($logo !== '' ? '10px' : '0') . ';vertical-align:middle;border:none;background:none;font-family:DejaVu Sans,Arial,sans-serif">
            <div style="font-size:15px;font-weight:bold;color:' . $b['color'] . ';letter-spacing:.3px">' . $e($b['name']) . '</div>
        </td>
        <td style="padding:0 0 8px;vertical-align:middle;text-align:right;border:none;background:none;font-family:DejaVu Sans,Arial,sans-serif;font-size:8px;color:#64748b">'
            . ($b['email'] !== '' ? $e($b['email']) : '') . '
        </td>
    </tr>
</table>';
}

/**
 * Add the brand bar right after <body> (or at the start when there is no
 * <body>), and keep enough room at the bottom of each page for the footer.
 */
function pdf_brand_html(?mysqli $conn, string $html): string
{
    $quote = static fn(string $s): string => str_replace(['\\', '$'], ['\\\\', '\\$'], $s);
    $bar = pdf_brand_header($conn);

    // Comes after the page's own styles, so only the bottom margin is overridden.
    $room = '<style>@page { margin-bottom: 15mm; }</style>';
    $count = 0;
    $withRoom = preg_replace('~</head>~i', $quote($room) . '$0', $html, 1, $count);
    $html = $count > 0 && is_string($withRoom) ? $withRoom : $room . $html;

    $branded = preg_replace('/<body\b[^>]*>/i', '$0' . $quote($bar), $html, 1, $count);
    return $count > 0 && is_string($branded) ? $branded : $bar . $html;
}

/** Company name, print date and page numbers along the bottom of every page (call after render()). */
function pdf_brand_footer(\Dompdf\Dompdf $dompdf, ?mysqli $conn = null): void
{
    $b = pdf_brand($conn);
    $canvas = $dompdf->getCanvas();
    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $w = $canvas->get_width();
    $y = $canvas->get_height() - 24;
    $grey = [0.39, 0.45, 0.55];
    $tz = new DateTimeZone('Africa/Kampala');
    $printed = (new DateTimeImmutable('now', $tz))->format('d M Y, H:i');

    $canvas->page_line(28, $y - 6, $w - 28, $y - 6, $grey, 0.4);
    $canvas->page_text(28, $y, $b['name'] . '  ·  Printed ' . $printed, $font, 7, $grey);
    $canvas->page_text($w - 95, $y, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, $grey);
}
