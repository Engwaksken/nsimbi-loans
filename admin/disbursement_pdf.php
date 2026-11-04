<?php
declare(strict_types=1);

session_start();

require "db.php";

// Requires Composer:  composer require dompdf/dompdf
// (If Packagist is blocked on your network, dompdf and its dependencies —
// dompdf/php-font-lib, dompdf/php-svg-lib, sabberworm/php-css-parser,
// masterminds/html5, thecodingmachine/safe — can be added as local "path"
// repositories in composer.json instead of pulling from packagist.org.)
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Only the accountant who disburses funds, or a reviewer double-checking the
// paper trail, should be able to pull a voucher.
$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
if (!in_array($role, [1, 5], true)) {
    die("Not authorized to view disbursement vouchers.");
}

$loan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$loan_id) {
    die("Invalid loan id.");
}

$stmt = $conn->prepare("
    SELECT l.*, u.fname, u.lname, u.email, u.phone
    FROM loan l
    LEFT JOIN user u ON u.id = l.client_id
    WHERE l.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    die("Loan not found.");
}

if (empty($loan['disbursed_at'])) {
    die("This loan has not been disbursed yet — no voucher to generate.");
}

// Who disbursed it.
$disbursedByName = 'N/A';
if (!empty($loan['disbursed_by'])) {
    $stmtU = $conn->prepare("SELECT fname, lname FROM user WHERE id = ? LIMIT 1");
    $stmtU->bind_param("i", $loan['disbursed_by']);
    $stmtU->execute();
    $u = $stmtU->get_result()->fetch_assoc();
    $stmtU->close();
    if ($u) {
        $disbursedByName = trim($u['fname'] . ' ' . $u['lname']);
    }
}

// Who approved it (from the audit trail).
$approvedByName = 'N/A';
$approvedAt = null;
$stmtA = $conn->prepare("
    SELECT at.created_at, u.fname, u.lname
    FROM loan_audit_trail at
    LEFT JOIN user u ON u.id = at.actor_id
    WHERE at.loan_id = ? AND at.action = 'approved'
    ORDER BY at.created_at DESC
    LIMIT 1
");
if ($stmtA) {
    $stmtA->bind_param("i", $loan_id);
    $stmtA->execute();
    $a = $stmtA->get_result()->fetch_assoc();
    $stmtA->close();
    if ($a) {
        $approvedByName = trim(($a['fname'] ?? '') . ' ' . ($a['lname'] ?? '')) ?: 'N/A';
        $approvedAt = $a['created_at'];
    }
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function moneyPdf($value): string
{
    return 'UGX ' . number_format((float)($value ?? 0), 2);
}

function methodLabelPdf(?string $method): string
{
    return match ($method) {
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'mobile_money'  => 'Mobile Money',
        default         => $method !== null && $method !== '' ? ucfirst($method) : 'N/A',
    };
}

$clientName = trim((string)($loan['fname'] ?? '') . ' ' . (string)($loan['lname'] ?? ''));
$clientName = $clientName !== '' ? $clientName : ('Client #' . (int)$loan['client_id']);

$dueDateLabel = !empty($loan['due_date']) ? date('M j, Y', strtotime((string)$loan['due_date'])) : 'N/A';
$disbursedOnLabel = date('M j, Y g:i A', strtotime((string)$loan['disbursed_at']));
$approvedOnLabel = $approvedAt ? date('M j, Y g:i A', strtotime((string)$approvedAt)) : 'N/A';
$generatedOnLabel = date('M j, Y g:i A');

function rowHtml(string $label, string $value): string
{
    return '<tr><td class="label">' . h($label) . '</td><td class="value">' . h($value) . '</td></tr>';
}

$html = '
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 32px; }
    body { font-family: "Helvetica", "DejaVu Sans", sans-serif; color: #1f2937; font-size: 11px; }

    .brand { color: #0d9488; font-size: 20px; font-weight: 700; margin: 0; }
    .subtitle { color: #6b7280; font-size: 11px; margin: 2px 0 10px 0; }
    .divider { border-top: 2px solid #0d9488; margin-bottom: 10px; }

    .meta { text-align: right; color: #6b7280; font-size: 10px; margin-bottom: 12px; }

    .section-title {
        background: #f0fdfa;
        color: #111827;
        font-weight: 700;
        font-size: 11px;
        padding: 6px 8px;
        margin-top: 10px;
        margin-bottom: 4px;
    }

    table.info { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    table.info td { padding: 3px 8px; font-size: 10.5px; }
    table.info td.label { color: #6b7280; width: 170px; }
    table.info td.value { color: #111827; font-weight: 700; }

    .signatures { margin-top: 40px; width: 100%; }
    .signatures td { padding-top: 24px; font-size: 10.5px; color: #374151; width: 50%; }
    .sig-line { border-top: 1px solid #374151; width: 85%; margin-bottom: 4px; }

    .footer-note {
        margin-top: 30px;
        font-size: 8.5px;
        color: #9ca3af;
        text-align: center;
        font-style: italic;
    }
</style>
</head>
<body>

    <p class="brand">NSIMBI IMPACT</p>
    <p class="subtitle">Loan Disbursement Voucher</p>
    <div class="divider"></div>

    <div class="meta">
        Voucher Reference: ' . h((string)($loan['disbursement_reference'] ?? 'N/A')) . '<br>
        Generated: ' . h($generatedOnLabel) . '
    </div>

    <div class="section-title">Borrower Details</div>
    <table class="info">
        ' . rowHtml('Name:', $clientName) . '
        ' . rowHtml('Phone:', (string)($loan['phone'] ?? 'N/A')) . '
        ' . rowHtml('Email:', (string)($loan['email'] ?? 'N/A')) . '
    </table>

    <div class="section-title">Loan Details</div>
    <table class="info">
        ' . rowHtml('Loan ID:', '#' . (int)$loan['id']) . '
        ' . rowHtml('Principal Amount:', moneyPdf($loan['amount'] ?? 0)) . '
        ' . rowHtml('Interest Rate:', (string)($loan['interest_rate'] ?? 'N/A') . '%') . '
        ' . rowHtml('Loan Period:', (int)($loan['loan_period'] ?? 0) . ' month(s)') . '
        ' . rowHtml('Due Date:', $dueDateLabel) . '
    </table>

    <div class="section-title">Disbursement Details</div>
    <table class="info">
        ' . rowHtml('Disbursed On:', $disbursedOnLabel) . '
        ' . rowHtml('Method:', methodLabelPdf($loan['disbursement_method'] ?? null)) . '
        ' . rowHtml('Reference #:', (string)($loan['disbursement_reference'] ?? 'N/A')) . '
        ' . rowHtml('Disbursed By:', $disbursedByName) . '
        ' . (!empty($loan['disbursement_notes']) ? rowHtml('Notes:', (string)$loan['disbursement_notes']) : '') . '
    </table>

    <div class="section-title">Approval Details</div>
    <table class="info">
        ' . rowHtml('Approved By:', $approvedByName) . '
        ' . rowHtml('Approved On:', $approvedOnLabel) . '
    </table>

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-line"></div>
                Accountant Signature
            </td>
            <td>
                <div class="sig-line"></div>
                Borrower Signature
            </td>
        </tr>
    </table>

    <p class="footer-note">
        This voucher is a system-generated record of funds disbursed and does not require a physical signature to be valid.
    </p>

</body>
</html>
';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'disbursement-voucher-loan-' . (int)$loan['id'] . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);