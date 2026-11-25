<?php
declare(strict_types=1);

/*
 * Printable receipt for one loan repayment: receipt.php?payment_id=123
 *
 * Opened from Finance > Payments ("View") and after recording a repayment
 * (includes/process-repayment.php returns this URL). Works from the
 * payments and loan tables alone; the receipts table, where it exists, adds
 * the receipt number, method, reference and who recorded it. Payments that
 * are not SUCCESSFUL (e.g. a payment slip still waiting) are shown clearly
 * as "not a valid receipt". Staff only.
 */

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/includes/site_cms.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || !in_array((int)($_SESSION['role'] ?? 0), [1, 2], true)) {
    header('Location: ../login.php');
    exit;
}
require_once dirname(__DIR__) . '/includes/officer_assignment.php';
organisations_ensure_table($conn);

date_default_timezone_set('Africa/Nairobi');

function r_h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function r_money($v): string
{
    return 'UGX ' . number_format((float)$v);
}

function r_table_exists(mysqli $conn, string $table): bool
{
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function r_first(array $row, array $keys): string
{
    foreach ($keys as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
            return trim((string)$row[$k]);
        }
    }
    return '';
}

/** Whole shillings in words: 1250000 -> "One million two hundred fifty thousand". */
function r_words(float $amount): string
{
    $n = (int)round($amount);
    if ($n === 0) {
        return 'Zero';
    }
    $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
        'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    $chunk = static function (int $x) use ($ones, $tens): string {
        $out = [];
        if ($x >= 100) {
            $out[] = $ones[intdiv($x, 100)] . ' hundred';
            $x %= 100;
        }
        if ($x >= 20) {
            $out[] = $tens[intdiv($x, 10)] . ($x % 10 ? '-' . $ones[$x % 10] : '');
        } elseif ($x > 0) {
            $out[] = $ones[$x];
        }
        return implode(' ', $out);
    };
    $parts = [];
    foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand', 1 => ''] as $size => $name) {
        if ($n >= $size) {
            $parts[] = trim($chunk(intdiv($n, $size)) . ' ' . $name);
            $n %= $size;
        }
    }
    return ucfirst(implode(' ', $parts));
}

$paymentId = (int)($_GET['payment_id'] ?? $_GET['id'] ?? 0);
$error = '';
$payment = $loan = $receipt = null;
$client = ['name' => '', 'phone' => '', 'email' => '', 'org' => '', 'type' => ''];
$recordedBy = '';
$paidBefore = 0.0;
$paidAfter = 0.0;

try {
    if ($paymentId <= 0) {
        throw new DomainException('No payment was chosen. Open a receipt from Finance > Payments.');
    }

    $s = $conn->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
    $s->bind_param('i', $paymentId);
    $s->execute();
    $payment = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$payment) {
        throw new DomainException('Payment #' . $paymentId . ' was not found. It may have been deleted.');
    }

    $loanId = (int)$payment['loan_id'];
    $s = $conn->prepare('SELECT * FROM loan WHERE id = ? LIMIT 1');
    $s->bind_param('i', $loanId);
    $s->execute();
    $loan = $s->get_result()->fetch_assoc() ?: null;
    $s->close();
    // Loan officers may only open receipts for their own loans.
    officer_require_loan($conn, $loanId);

    if (r_table_exists($conn, 'receipts')) {
        $s = $conn->prepare('SELECT * FROM receipts WHERE payment_id = ? ORDER BY id DESC LIMIT 1');
        $s->bind_param('i', $paymentId);
        $s->execute();
        $receipt = $s->get_result()->fetch_assoc() ?: null;
        $s->close();
    }

    // Who paid: organisation (loan.organisation_id, checked first: organisation
    // ids overlap user ids), customer, member/staff or startup (startup loans
    // store startups.startup_id).
    $organisation = $loan ? organisation_find($conn, (int)($loan['organisation_id'] ?? 0)) : null;
    if ($loan && $organisation) {
        $client = ['name' => trim((string)$organisation['company_name']) ?: 'Organisation #' . (int)$organisation['id'],
            'phone' => (string)($organisation['phone'] ?? ''), 'email' => (string)($organisation['email'] ?? ''),
            'org' => (string)($organisation['ceo_name'] ?? ''), 'type' => 'organisation'];
    }
    if ($loan) {
        $clientId = (int)$loan['client_id'];
        $type = strtolower(trim((string)($loan['client_type'] ?? '')));
        $lookups = [
            'customer' => "SELECT CONCAT_WS(' ', u.fname, u.lname) AS name, c.phone, COALESCE(NULLIF(c.email, ''), u.email) AS email, c.business_name AS org
                           FROM customers c JOIN user u ON u.id = c.user_id WHERE c.user_id = ? LIMIT 1",
            'member'   => "SELECT CONCAT_WS(' ', u.fname, u.lname) AS name, m.phone, COALESCE(NULLIF(m.work_email, ''), u.email) AS email, m.employer AS org
                           FROM members m JOIN user u ON u.id = m.user_id WHERE m.user_id = ? LIMIT 1",
            'startup'  => "SELECT COALESCE(NULLIF(st.ceo_name, ''), st.company_name) AS name, st.phone, st.email, st.company_name AS org
                           FROM startups st WHERE st.startup_id = ? LIMIT 1",
        ];
        $isOrganisationLoan = (int)($loan['organisation_id'] ?? 0) > 0;
        $order = $isOrganisationLoan ? [] : (isset($lookups[$type]) ? [$type] : array_keys($lookups));
        foreach ($order as $t) {
            try {
                $s = $conn->prepare($lookups[$t]);
                $s->bind_param('i', $clientId);
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                $s->close();
            } catch (Throwable $e) {
                $row = null; // table missing on this install
            }
            if ($row) {
                $client = ['name' => trim((string)$row['name']), 'phone' => (string)$row['phone'], 'email' => (string)$row['email'],
                    'org' => (string)$row['org'], 'type' => $t];
                break;
            }
        }
        if ($client['name'] === '') {
            $client['name'] = $isOrganisationLoan ? 'Organisation #' . (int)$loan['organisation_id'] : 'Client #' . $clientId;
        }

        // Confirmed payments on this loan before and after this one, to show
        // the balance as it was at the time of this payment.
        $s = $conn->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN id < ? THEN amount END), 0) AS before_amt,
                COALESCE(SUM(CASE WHEN id > ? THEN amount END), 0) AS after_amt
            FROM payments
            WHERE loan_id = ? AND UPPER(status) = 'SUCCESSFUL'
        ");
        $s->bind_param('iii', $paymentId, $paymentId, $loanId);
        $s->execute();
        $sums = $s->get_result()->fetch_assoc();
        $s->close();
        $paidBefore = (float)$sums['before_amt'];
        $paidAfter = (float)$sums['after_amt'];
    }

    // Who recorded or approved it.
    $staffId = (int)r_first($receipt ?? [], ['created_by']) ?: (int)r_first($payment, ['reviewed_by', 'approved_by', 'created_by', 'recorded_by']);
    if ($staffId > 0) {
        $s = $conn->prepare("SELECT CONCAT_WS(' ', fname, lname) AS name FROM user WHERE id = ? LIMIT 1");
        $s->bind_param('i', $staffId);
        $s->execute();
        $recordedBy = trim((string)($s->get_result()->fetch_assoc()['name'] ?? ''));
        $s->close();
    }
} catch (DomainException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('Receipt ' . $paymentId . ': ' . $e->getMessage());
    $error = 'The receipt could not be loaded. Check the server log.';
}

$brand = site_settings($conn);
$status = strtoupper(trim((string)($payment['status'] ?? '')));
$isPaid = $status === 'SUCCESSFUL' || $status === 'PAID';
$amount = (float)($payment['amount'] ?? 0);
$when = r_first($payment ?? [], ['datetime', 'created_at', 'reviewed_at']);
$whenTs = $when !== '' ? strtotime($when) : false;
$receiptNo = r_first($receipt ?? [], ['receipt_no', 'receipt_number'])
    ?: 'RCT-' . ($whenTs ? date('Ymd', $whenTs) : date('Ymd')) . '-' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT);
$method = r_first($receipt ?? [], ['payment_method']) ?: r_first($payment ?? [], ['payment_method', 'method']) ?: 'Cash';
$reference = r_first($receipt ?? [], ['reference']) ?: r_first($payment ?? [], ['transaction_reference', 'reference', 'transaction_id']);
$notes = r_first($receipt ?? [], ['notes']) ?: r_first($payment ?? [], ['notes', 'review_note']);

$principal = (float)($loan['amount'] ?? 0);
$interest = (float)($loan['interest'] ?? 0);
$currentBalance = (float)($loan['loan_balance'] ?? 0);
$balanceAfter = max(0.0, $currentBalance + $paidAfter);
$balanceBefore = $isPaid ? $balanceAfter + $amount : $balanceAfter;
$logo = trim((string)($brand['logo'] ?? ''));
$statusLabel = match (true) {
    $isPaid => 'PAID',
    $status === 'PENDING' => 'PENDING',
    $status === 'REJECTED' => 'REJECTED',
    default => $status !== '' ? $status : 'UNKNOWN',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $error === '' ? 'Receipt ' . r_h($receiptNo) : 'Receipt' ?> | <?= r_h($brand['site_name']) ?></title>
<?= site_favicon_tags() ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root { --brand: <?= r_h($brand['color_primary'] ?? '#0f3399') ?>; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; }
* { box-sizing: border-box; }
body { margin: 0; background: #eef2f7; color: var(--ink); font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
.toolbar { max-width: 780px; margin: 20px auto 0; padding: 0 16px; display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
.btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--line); background: #fff; color: var(--ink); padding: 8px 14px; border-radius: 9px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
.btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; }
.sheet { position: relative; max-width: 780px; margin: 12px auto 32px; background: #fff; border-radius: 14px; box-shadow: 0 10px 30px rgba(15,23,42,.08); padding: 36px 40px; overflow: hidden; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 3px solid var(--brand); padding-bottom: 18px; }
.brand { display: flex; align-items: center; gap: 12px; }
.brand img { max-height: 54px; max-width: 170px; object-fit: contain; }
.brand-name { font-size: 18px; font-weight: 800; color: var(--brand); }
.brand-sub { font-size: 12px; color: var(--muted); }
.doc { text-align: right; }
.doc h1 { margin: 0; font-size: 22px; letter-spacing: .04em; text-transform: uppercase; }
.doc .no { font-weight: 700; color: var(--brand); }
.doc .date { color: var(--muted); font-size: 13px; }
.stamp { display: inline-block; margin-top: 6px; padding: 3px 12px; border: 2px solid; border-radius: 6px; font-weight: 800; letter-spacing: .12em; font-size: 12px; }
.stamp.paid { color: #15803d; border-color: #15803d; }
.stamp.wait { color: #b45309; border-color: #b45309; }
.stamp.bad { color: #b91c1c; border-color: #b91c1c; }
.warn { margin: 18px 0 0; padding: 10px 14px; border-radius: 10px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-weight: 600; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px 28px; margin-top: 22px; }
.label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
.value { font-weight: 600; overflow-wrap: anywhere; }
.amount-box { margin-top: 24px; border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
.amount { font-size: 28px; font-weight: 800; color: var(--brand); white-space: nowrap; }
.words { color: var(--muted); font-style: italic; font-size: 13px; }
table { width: 100%; border-collapse: collapse; margin-top: 22px; font-size: 13.5px; }
th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); border-bottom: 1px solid var(--line); padding: 8px 0; }
td { padding: 9px 0; border-bottom: 1px solid #f1f5f9; }
td.num, th.num { text-align: right; white-space: nowrap; }
tr.strong td { font-weight: 800; border-top: 2px solid var(--line); border-bottom: 0; }
.sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 46px; }
.sign div { border-top: 1px solid #94a3b8; padding-top: 6px; font-size: 12px; color: var(--muted); }
.foot { margin-top: 26px; font-size: 11.5px; color: var(--muted); text-align: center; }
.watermark { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; font-size: 96px; font-weight: 900; letter-spacing: .1em; color: rgba(220, 38, 38, .07); transform: rotate(-24deg); }
.error { max-width: 560px; margin: 60px auto; background: #fff; border-radius: 14px; padding: 28px; text-align: center; box-shadow: 0 10px 30px rgba(15,23,42,.08); }
.error i { font-size: 34px; color: #dc2626; }
@media (max-width: 640px) {
  .sheet { padding: 22px 18px; border-radius: 0; margin: 10px 0 20px; }
  .head { flex-direction: column; }
  .doc { text-align: left; }
  .grid, .sign { grid-template-columns: 1fr; }
  .amount { font-size: 24px; }
}
@media print {
  @page { size: A4; margin: 14mm; }
  body { background: #fff; }
  .toolbar { display: none; }
  .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; border-radius: 0; }
  .amount-box { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>

<?php if ($error !== ''): ?>
  <div class="error">
    <i class="fas fa-receipt"></i>
    <h2>Receipt not available</h2>
    <p><?= r_h($error) ?></p>
    <p><a class="btn" href="loan_payments.php"><i class="fas fa-arrow-left"></i> Back to payments</a></p>
  </div>
<?php else: ?>

<div class="toolbar">
  <a class="btn" href="loan_payments.php"><i class="fas fa-arrow-left"></i> Payments</a>
  <?php if ($loan): ?><a class="btn" href="view_loan.php?id=<?= (int)$loan['id'] ?>"><i class="fas fa-file-invoice-dollar"></i> Loan</a><?php endif; ?>
  <button type="button" class="btn primary" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
</div>

<div class="sheet">
  <?php if (!$isPaid): ?><div class="watermark"><?= r_h($statusLabel) ?></div><?php endif; ?>

  <div class="head">
    <div class="brand">
      <?php if ($logo !== ''): ?><img src="<?= r_h($logo) ?>" alt=""><?php endif; ?>
      <div>
        <div class="brand-name"><?= r_h($brand['site_name']) ?></div>
        <?php if (!empty($brand['enquiry_email'])): ?><div class="brand-sub"><?= r_h($brand['enquiry_email']) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="doc">
      <h1>Payment receipt</h1>
      <div class="no"><?= r_h($receiptNo) ?></div>
      <div class="date"><?= $whenTs ? r_h(date('j F Y, g:i A', $whenTs)) : '' ?></div>
      <span class="stamp <?= $isPaid ? 'paid' : ($status === 'PENDING' ? 'wait' : 'bad') ?>"><?= r_h($statusLabel) ?></span>
    </div>
  </div>

  <?php if (!$isPaid): ?>
    <div class="warn"><i class="fas fa-triangle-exclamation"></i>
      This payment is <?= r_h(strtolower($statusLabel)) ?>, so this is not a valid receipt.
      <?= $status === 'PENDING' ? 'Approve the payment slip under Loans > Payment Slips first.' : '' ?></div>
  <?php endif; ?>

  <div class="grid">
    <div>
      <div class="label">Received from</div>
      <div class="value"><?= r_h($client['name']) ?></div>
      <?php if ($client['org'] !== '' && $client['org'] !== $client['name']): ?><div><?= r_h($client['org']) ?></div><?php endif; ?>
      <?php if ($client['phone'] !== ''): ?><div><?= r_h($client['phone']) ?></div><?php endif; ?>
      <?php if ($client['email'] !== ''): ?><div><?= r_h($client['email']) ?></div><?php endif; ?>
    </div>
    <div>
      <div class="label">Payment details</div>
      <div><strong>Method:</strong> <?= r_h($method) ?></div>
      <?php if ($reference !== ''): ?><div><strong>Reference:</strong> <?= r_h($reference) ?></div><?php endif; ?>
      <div><strong>Loan:</strong> #<?= (int)($payment['loan_id'] ?? 0) ?><?= $client['type'] !== '' ? ' (' . r_h(ucfirst($client['type'])) . ')' : '' ?></div>
      <div><strong>Payment ID:</strong> <?= (int)$paymentId ?></div>
    </div>
  </div>

  <div class="amount-box">
    <div>
      <div class="label">Amount <?= $isPaid ? 'received' : 'claimed' ?></div>
      <div class="words"><?= r_h(r_words($amount)) ?> Uganda shillings only</div>
    </div>
    <div class="amount"><?= r_money($amount) ?></div>
  </div>

  <?php if ($loan): ?>
    <table>
      <thead><tr><th>Loan summary</th><th class="num">Amount</th></tr></thead>
      <tbody>
        <tr><td>Loan amount</td><td class="num"><?= r_money($principal) ?></td></tr>
        <tr><td>Interest</td><td class="num"><?= r_money($interest) ?></td></tr>
        <tr><td>Paid before this payment</td><td class="num"><?= r_money($paidBefore) ?></td></tr>
        <tr><td>Balance before this payment</td><td class="num"><?= r_money($balanceBefore) ?></td></tr>
        <tr><td>This payment</td><td class="num"><?= $isPaid ? '- ' . r_money($amount) : r_money(0) . ' (not confirmed)' ?></td></tr>
        <tr class="strong"><td>Balance after this payment</td><td class="num"><?= r_money($balanceAfter) ?></td></tr>
      </tbody>
    </table>
    <?php if ($balanceAfter <= 0 && $isPaid): ?>
      <p style="margin:10px 0 0;color:#15803d;font-weight:700"><i class="fas fa-circle-check"></i> This payment cleared the loan.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($notes !== ''): ?>
    <div style="margin-top:18px"><div class="label">Notes</div><div><?= nl2br(r_h($notes)) ?></div></div>
  <?php endif; ?>

  <div class="sign">
    <div>Received by<?= $recordedBy !== '' ? ': <strong style="color:var(--ink)">' . r_h($recordedBy) . '</strong>' : '' ?></div>
    <div>Client signature</div>
  </div>

  <div class="foot">
    Thank you for your payment. Keep this receipt for your records.<br>
    Printed <?= r_h(date('j M Y, g:i A')) ?> · <?= r_h($brand['site_name']) ?>
  </div>
</div>
<?php endif; ?>

</body>
</html>
