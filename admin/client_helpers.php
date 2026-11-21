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

function creditScore(string $type): float
{
    // One scoring table for website, admin, import and the loan pages.
    // Business types not in the table get the default score of 3.
    require_once dirname(__DIR__) . '/includes/loan_assessment.php';
    return (float)loan_assessment_business_score($type)[0];
}

function parseAmountRange(string $str): float
{
    // Same reader as the AI recommended amount: plain amounts, k/M, currency
    // words, ranges ("50k-100k", "500,000 to 1M") and open ranges ("Above 2M").
    require_once dirname(__DIR__) . '/includes/loan_assessment.php';
    return loan_assessment_parse_turnover($str)[0];
}

function calcLoanLimit(string $amountLast, string $amountThis, string $businessType): float
{
    // Same loan assessment as the app and website (includes/loan_assessment.php).
    require_once dirname(__DIR__) . '/includes/loan_assessment.php';
    return loan_assessment_limit($businessType, $amountLast, $amountThis)['limit'];
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