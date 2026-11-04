<?php

declare(strict_types=1);

function formatPhone(string $phone): string
{
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (str_starts_with($phone, '0'))   return '+256' . substr($phone, 1);
    if (str_starts_with($phone, '256')) return '+' . $phone;
    if (!str_starts_with($phone, '+'))  return '+256' . $phone;
    return $phone;
}

function creditScore(string $type): int
{
    $scores = [
        'Beauty salon'             => 6,
        'Education'                => 8,
        'Electronics'              => 4,
        'Farming'                  => 5,
        'Healthcare'               => 9,
        'Market stall'             => 8,
        'Poultry keeping'          => 4,
        'Restaurant'               => 7,
        'Retail shop'              => 6,
        'Sell second hand clothes' => 4,
        'Tailoring'                => 7,
        'Transportation'           => 5,
    ];

    foreach ($scores as $key => $val) {
        if (stripos($type, $key) !== false || stripos($key, $type) !== false) {
            return $val;
        }
    }

    return 5;
}

function parseAmountRange(string $str): float
{
    $patterns = [
        '/10,?000?\s*[-–]\s*50,?000?/i'         => 30_000,
        '/50,?000?\s*[-–]\s*100,?000?/i'        => 75_000,
        '/100,?000?\s*[-–]\s*500,?000?/i'       => 300_000,
        '/500,?000?\s*[-–]\s*(1m|1,000,000)/i'  => 750_000,
        '/1\s*[-–]\s*2\s*m/i'                   => 1_500_000,
        '/(above|over|more than)\s*2\s*m/i'     => 2_500_000,
    ];

    foreach ($patterns as $pattern => $value) {
        if (preg_match($pattern, $str)) return (float)$value;
    }

    if (preg_match('/([\d,.]+)\s*([kKmM]?)\s*[-–]\s*([\d,.]+)\s*([kKmM]?)/', $str, $m)) {
        $v1 = (float)str_replace(',', '', $m[1]) * match (strtolower($m[2])) { 'k' => 1_000, 'm' => 1_000_000, default => 1 };
        $v2 = (float)str_replace(',', '', $m[3]) * match (strtolower($m[4])) { 'k' => 1_000, 'm' => 1_000_000, default => 1 };
        return ($v1 + $v2) / 2;
    }

    return 0.0;
}

function calcLoanLimit(string $amountLast, string $amountThis, string $businessType): float
{
    $last  = parseAmountRange($amountLast) * 0.9;
    $this_ = parseAmountRange($amountThis) * 1.1;
    $avg   = ($last + $this_) / 2;
    return $avg + $avg * creditScore($businessType) / 10;
}


const VALID_AMOUNT_RANGES = [
    '10,000 - 50,000',
    '50,000 - 100,000',
    '100,000 - 500,000',
    '500,000 - 1M',
    '1 - 2M',
    'Above 2M',
];

/** Exact <option value="..."> strings from add_client.php's business_period select. */
const VALID_BUSINESS_PERIODS = [
    'Less than 6 months',
    '6 months - 1 year',
    '1 - 2 years',
    '2 - 5 years',
    'More than 5 years',
];

/** Fixed business types from add_client.php's dropdown; "Other" + free text is also accepted. */
const VALID_BUSINESS_TYPES = [
    'Beauty salon', 'Education', 'Electronics', 'Farming', 'Healthcare',
    'Market stall', 'Poultry keeping', 'Restaurant', 'Retail shop',
    'Sell second hand clothes', 'Tailoring', 'Transportation',
];

/** Uganda districts from add_client.php's district select. */
const VALID_DISTRICTS = [
    'Abim','Adjumani','Agago','Alebtong','Amolatar','Amudat','Amuria','Amuru','Apac','Arua',
    'Budaka','Bududa','Bugiri','Bugweri','Buhweju','Buikwe','Bukedea','Bukomansimbi','Bukwo',
    'Bulambuli','Buliisa','Bundibugyo','Bunyangabu','Bushenyi','Busia','Butaleja','Butambala',
    'Butebo','Buvuma','Buyende','Dokolo','Gomba','Gulu','Hoima','Ibanda','Iganga','Isingiro',
    'Jinja','Kaabong','Kabale','Kabarole','Kaberamaido','Kagadi','Kakumiro','Kalangala','Kaliro',
    'Kalungu','Kampala','Kamuli','Kamwenge','Kanungu','Kapchorwa','Kapelebyong','Karenga','Kasese',
    'Kassanda','Katakwi','Kayunga','Kazo','Kibaale','Kiboga','Kibuku','Kikuube','Kiruhura',
    'Kiryandongo','Kisoro','Kitagwenda','Kitgum','Koboko','Kole','Kotido','Kumi','Kwania','Kween',
    'Kyankwanzi','Kyegegwa','Kyenjojo','Kyotera','Lamwo','Lira','Luuka','Luweero','Lwengo',
    'Lyantonde','Manafwa','Maracha','Masaka','Masindi','Mayuge','Mbale','Mbarara','Mitooma',
    'Mityana','Moroto','Moyo','Mpigi','Mubende','Mukono','Nabilatuk','Nakapiripirit','Nakaseke',
    'Nakasongola','Namayingo','Namisindwa','Namutumba','Napak','Nebbi','Ngora','Ntoroko',
    'Ntungamo','Nwoya','Obongi','Omoro','Otuke','Oyam','Pader','Pakwach','Pallisa','Rakai',
    'Rubanda','Rubirizi','Rukiga','Rukungiri','Rwampara','Sembabule','Serere','Sheema','Sironko',
    'Soroti','Terego','Tororo','Wakiso','Yumbe','Zombo',
];


function matchAllowedValue(string $value, array $allowed): ?string
{
    foreach ($allowed as $option) {
        if (strcasecmp(trim($value), $option) === 0) return $option;
    }
    return null;
}