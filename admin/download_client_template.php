<?php


declare(strict_types=1);

require_once __DIR__ . '/client_helpers.php';

$columns = [
    'fname', 'lname', 'username', 'gender', 'dob', 'nin',
    'phone', 'email', 'bank_name', 'bank_account',
    'district', 'business_name', 'business_type', 'location',
    'business_period', 'amount_last', 'amount_this',
    'nok_name', 'nok_tel', 'nok_rel',
    'password', 'loan_limit',
];


$example = [
    'fname'           => 'Jane',
    'lname'           => 'Nakato',
    'username'        => 'jnakato',
    'gender'          => 'Female',
    'dob'             => '1992-04-15',
    'nin'             => 'CM12345678ABCDE',
    'phone'           => '0772123456',
    'email'           => 'jane.nakato@example.com',
    'bank_name'       => 'Centenary Bank',
    'bank_account'    => '3201234567',
    'district'        => 'Kampala',
    'business_name'   => 'Jane Fresh Foods',
    'business_type'   => 'Market stall',
    'location'        => 'Nakawa Market',
    'business_period' => '2 - 5 years',
    'amount_last'      => '50,000 - 100,000',
    'amount_this'      => '100,000 - 500,000',
    'nok_name'        => 'Peter Nakato',
    'nok_tel'         => '0700111222',
    'nok_rel'         => 'Spouse',
    'password'        => 'ChangeMe123',
    'loan_limit'      => '', 
];

$filename = 'client_import_template_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// Byte-order mark so Excel opens UTF-8 correctly.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, $columns);
fputcsv($out, array_map(fn($c) => $example[$c] ?? '', $columns));


fputcsv($out, []);
fputcsv($out, ['--- DELETE EVERYTHING BELOW THIS LINE BEFORE UPLOADING ---']);
fputcsv($out, []);

fputcsv($out, ['Allowed values for "business_period":']);
foreach (VALID_BUSINESS_PERIODS as $v) fputcsv($out, ['', $v]);
fputcsv($out, []);

fputcsv($out, ['Allowed values for "amount_last" / "amount_this":']);
foreach (VALID_AMOUNT_RANGES as $v) fputcsv($out, ['', $v]);
fputcsv($out, []);

fputcsv($out, ['Allowed values for "business_type" (or write your own free-text type):']);
foreach (VALID_BUSINESS_TYPES as $v) fputcsv($out, ['', $v]);
fputcsv($out, []);

fputcsv($out, ['Allowed values for "district":']);
foreach (VALID_DISTRICTS as $v) fputcsv($out, ['', $v]);

fclose($out);
exit;