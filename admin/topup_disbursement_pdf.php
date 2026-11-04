<?php
declare(strict_types=1);

session_start();

require "db.php";
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
if (!in_array($role, [1, 5], true)) {
    die("Not authorized to view disbursement vouchers.");
}

$topupId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$topupId) {
    die("Invalid top-up id.");
}

$stmt = $conn->prepare("
    SELECT t.*, l.amount AS loan_amount, l.interest_rate AS loan_interest_rate,
           l.loan_period AS loan_total_period, l.due_date AS loan_due_date,
           l.loan_balance AS loan_balance,
           u.fname, u.lname, u.email, u.phone
    FROM loan_topup_requests t
    INNER JOIN loan l ON l.id = t.loan_id
    LEFT JOIN user u ON u.id = t.client_id
    WHERE t.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $topupId);
$stmt->execute();
$topup = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$topup) {
    die("Top-up request not found.");
}

if (empty($topup['disbursed_at'])) {
    die("This top-up has not been disbursed yet - no voucher to generate.");
}

$disbursedByName = 'N/A';
if (!empty($topup['disbursed_by'])) {
    $stmtU = $conn->prepare("SELECT fname, lname FROM user WHERE id = ? LIMIT 1");
    $stmtU->bind_param("i", $topup['disbursed_by']);
    $stmtU->execute();
    $u = $stmtU->get_result()->fetch_assoc();
    $stmtU->close();
    if ($u) {
        $disbursedByName = trim($u['fname'] . ' ' . $u['lname']);
    }
}

$approvedByName = 'N/A';
$approvedAt = null;
$stmtA = $conn->prepare("
    SELECT at.created_at, u.fname, u.lname
    FROM loan_audit_trail at
    LEFT JOIN user u ON u.id = at.actor_id
    WHERE at.topup_id = ? AND at.action = 'topup_approved'
    ORDER BY at.created_at DESC
    LIMIT 1
");
if ($stmtA) {
    $stmtA->bind_param("i", $topupId);
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

function rowHtml(string $label, string $value): string
{
    return '<tr><td class="label">' . h($label) . '</td><td class="value">' . h($value) . '</td></tr>';
}

$clientName = trim((string)($topup['fname'] ?? '') . ' ' . (string)($topup['lname'] ?? ''));
$clientName = $clientName !== '' ? $clientName : ('Client #' . (int)$topup['client_id']);

$loanId = (int)$topup['loan_id'];
$newDueDateLabel = !empty($topup['loan_due_date']) ? date('M j, Y', strtotime((string)$topup['loan_due_date'])) : 'N/A';
$disbursedOnLabel = date('M j, Y g:i A', strtotime((string)$topup['disbursed_at']));
$approvedOnLabel = $approvedAt ? date('M j, Y g:i A', strtotime((string)$approvedAt)) : 'N/A';
$generatedOnLabel = date('M j, Y g:i A');

$html = '
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 32px; }
    body { font-family: "Helvetica", "DejaVu Sans", sans-serif; color: #1f2937; font-size: 11px; }

    .brand { color: #7e22ce; font-size: 20px; font-weight: 700; margin: 0; }
    .subtitle { color: #6b7280; font-size: 11px; margin: 2px 0 10px 0; }
    .divider { border-top: 2px solid #7e22ce; margin-bottom: 10px; }

    .meta { text-align: right; color: #6b7280; font-size: 10px; margin-bottom: 12px; }

    .section-title {
        background: #f3e8ff;
        color: #111827;
        font-weight: 700;
        font-size: 11px;
        padding: 6px 8px;
        margin-top: 10px;
        margin-bottom: 4px;
    }

    table.info { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    table.info td { padding: 3px 8px; font-size: 10.5px; }
    table.info td.label { color: #6b7280; width: 190px; }
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
    <p class="subtitle">Loan Top-Up Disbursement Voucher</p>
    <div class="divider"></div>

    <div class="meta">
        Voucher Reference: ' . h((string)($topup['disbursement_reference'] ?? 'N/A')) . '<br>
        Generated: ' . h($generatedOnLabel) . '
    </div>

    <div class="section-title">Borrower Details</div>
    <table class="info">
        ' . rowHtml('Name:', $clientName) . '
        ' . rowHtml('Phone:', (string)($topup['phone'] ?? 'N/A')) . '
        ' . rowHtml('Email:', (string)($topup['email'] ?? 'N/A')) . '
    </table>

    <div class="section-title">Original Loan</div>
    <table class="info">
        ' . rowHtml('Loan ID:', '#' . $loanId) . '
        ' . rowHtml('New Total Principal:', moneyPdf($topup['loan_amount'] ?? 0)) . '
        ' . rowHtml('New Outstanding Balance:', moneyPdf($topup['loan_balance'] ?? 0)) . '
        ' . rowHtml('New Combined Period:', (int)($topup['loan_total_period'] ?? 0) . ' month(s)') . '
        ' . rowHtml('New Due Date:', $newDueDateLabel) . '
    </table>

    <div class="section-title">Top-Up Details</div>
    <table class="info">
        ' . rowHtml('Top-Up Amount:', moneyPdf($topup['amount'] ?? 0)) . '
        ' . rowHtml('Additional Period:', (int)($topup['loan_period'] ?? 0) . ' month(s)') . '
        ' . rowHtml('Interest Rate Applied:', (string)($topup['loan_interest_rate'] ?? 'N/A') . '%') . '
    </table>

    <div class="section-title">Disbursement Details</div>
    <table class="info">
        ' . rowHtml('Disbursed On:', $disbursedOnLabel) . '
        ' . rowHtml('Method:', methodLabelPdf($topup['disbursement_method'] ?? null)) . '
        ' . rowHtml('Reference #:', (string)($topup['disbursement_reference'] ?? 'N/A')) . '
        ' . rowHtml('Disbursed By:', $disbursedByName) . '
        ' . (!empty($topup['disbursement_notes']) ? rowHtml('Notes:', (string)$topup['disbursement_notes']) : '') . '
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
        This voucher is a system-generated record of a loan top-up disbursement and does not require a physical signature to be valid.
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

$filename = 'topup-voucher-loan-' . $loanId . '-' . (int)$topup['id'] . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);