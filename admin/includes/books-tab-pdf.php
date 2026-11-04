<?php
/**
 * Dompdf HTML for the per-client Books History export.
 *
 * Expects in scope:
 * @var array $books
 * @var array $client
 * @var string $fullName
 * @var string $generatedAt
 */

$statusLabel = BOOKS_TAB_STATUSES[$books['status']] ?? ucfirst((string)$books['status']);
$typeLabel = $books['type'] === 'all' ? 'All types' : ucfirst((string)$books['type']);
$clientEmail = (string)($client['email'] ?? '');
?>
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 18mm 12mm; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 9px;
            color: #1f2937;
        }
        h1 {
            color: #123299;
            margin: 0 0 4px;
            font-size: 18px;
        }
        .meta {
            color: #64748b;
            margin-bottom: 10px;
            line-height: 1.45;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary td {
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            padding: 8px;
            width: 33.33%;
        }
        .label {
            color: #64748b;
            font-size: 7px;
            text-transform: uppercase;
        }
        .value {
            font-size: 11px;
            font-weight: bold;
            color: #111827;
        }
        .rpt th {
            background: #123299;
            color: #fff;
            padding: 5px;
            text-align: left;
            font-size: 7.5px;
        }
        .rpt td {
            border-bottom: 1px solid #e5e7eb;
            padding: 4px;
            vertical-align: top;
        }
        .rpt tr:nth-child(even) td {
            background: #f8fafc;
        }
        .amount-in { color: #16a34a; font-weight: bold; }
        .amount-out { color: #ea580c; font-weight: bold; }
    </style>
</head>
<body>

<h1>Books History Report</h1>
<div class="meta">
    Client: <?= h($fullName !== '' ? $fullName : 'N/A') ?>
    <?php if ($clientEmail !== ''): ?>
        (<?= h($clientEmail) ?>)
    <?php endif; ?><br>
    Month: <?= h($books['month_label']) ?>
    &middot; Status: <?= h($statusLabel) ?>
    &middot; Type: <?= h($typeLabel) ?><br>
    Generated: <?= h($generatedAt) ?>
    &middot; <?= number_format((int)$books['total_rows']) ?> record<?= (int)$books['total_rows'] === 1 ? '' : 's' ?>
</div>

<table class="summary">
    <tr>
        <td>
            <div class="label">Total Money In</div>
            <div class="value"><?= money($books['money_in']) ?></div>
        </td>
        <td>
            <div class="label">Total Money Out</div>
            <div class="value"><?= money($books['money_out']) ?></div>
        </td>
        <td>
            <div class="label">Net Worth</div>
            <div class="value"><?= money($books['net']) ?></div>
        </td>
    </tr>
</table>

<br>

<table class="rpt">
    <thead>
    <tr>
        <th>#</th>
        <th>Type</th>
        <th>Entry Date</th>
        <th>Item Name</th>
        <th>Quantity</th>
        <th>Unit</th>
        <th>Amount</th>
        <th>Status</th>
    </tr>
    </thead>
    <tbody>
    <?php if (!empty($books['entries'])): ?>
        <?php foreach ($books['entries'] as $index => $entry): ?>
            <?php
            $entryType = (string)($entry['type'] ?? '');
            $entryStatus = (string)($entry['status'] ?? '');
            $quantity = $entry['quantity'];
            $quantityDisplay = ($quantity === null || $quantity === '')
                ? '-'
                : rtrim(rtrim(number_format((float)$quantity, 3, '.', ''), '0'), '.');
            $isMoneyIn = books_is_money_in($entryType);
            $signedAmount = ($isMoneyIn ? '+' : '-') . money(abs((float)($entry['amount'] ?? 0)));
            $amountClass = $isMoneyIn ? 'amount-in' : 'amount-out';
            $entryDate = !empty($entry['entry_date'])
                ? date('Y-m-d H:i', strtotime((string)$entry['entry_date']))
                : '-';
            ?>
            <tr>
                <td><?= number_format($index + 1) ?></td>
                <td><?= h(ucfirst($entryType)) ?></td>
                <td><?= h($entryDate) ?></td>
                <td><?= h($entry['item_name'] ?? '') ?></td>
                <td><?= h($quantityDisplay) ?></td>
                <td><?= h($entry['unit'] ?: '-') ?></td>
                <td class="<?= $amountClass ?>"><?= h($signedAmount) ?></td>
                <td><?= h(ucfirst($entryStatus)) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="8" style="text-align:center;padding:15px">No book entries found.</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

</body>
</html>
