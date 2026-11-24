<?php
declare(strict_types=1);

/** Uganda districts, as offered on the client sign-up form (clients/index.php). */
const UGANDA_DISTRICTS = [
    'Abim',
    'Adjumani',
    'Agago',
    'Alebtong',
    'Amolatar',
    'Amudat',
    'Amuria',
    'Amuru',
    'Apac',
    'Arua',
    'Budaka',
    'Bududa',
    'Bugiri',
    'Bugweri',
    'Buhweju',
    'Buikwe',
    'Bukedea',
    'Bukomansimbi',
    'Bukwo',
    'Bulambuli',
    'Buliisa',
    'Bundibugyo',
    'Bunyangabu',
    'Bushenyi',
    'Busia',
    'Butaleja',
    'Butambala',
    'Butebo',
    'Buvuma',
    'Buyende',
    'Dokolo',
    'Gomba',
    'Gulu',
    'Hoima',
    'Ibanda',
    'Iganga',
    'Isingiro',
    'Jinja',
    'Kaabong',
    'Kabale',
    'Kabarole',
    'Kaberamaido',
    'Kagadi',
    'Kakumiro',
    'Kalangala',
    'Kaliro',
    'Kalungu',
    'Kampala',
    'Kamuli',
    'Kamwenge',
    'Kanungu',
    'Kapchorwa',
    'Kapelebyong',
    'Karenga',
    'Kasese',
    'Kassanda',
    'Katakwi',
    'Kayunga',
    'Kazo',
    'Kibaale',
    'Kiboga',
    'Kibuku',
    'Kikuube',
    'Kiruhura',
    'Kiryandongo',
    'Kisoro',
    'Kitagwenda',
    'Kitgum',
    'Koboko',
    'Kole',
    'Kotido',
    'Kumi',
    'Kwania',
    'Kween',
    'Kyankwanzi',
    'Kyegegwa',
    'Kyenjojo',
    'Kyotera',
    'Lamwo',
    'Lira',
    'Luuka',
    'Luweero',
    'Lwengo',
    'Lyantonde',
    'Manafwa',
    'Maracha',
    'Masaka',
    'Masindi',
    'Mayuge',
    'Mbale',
    'Mbarara',
    'Mitooma',
    'Mityana',
    'Moroto',
    'Moyo',
    'Mpigi',
    'Mubende',
    'Mukono',
    'Nabilatuk',
    'Nakapiripirit',
    'Nakaseke',
    'Nakasongola',
    'Namayingo',
    'Namisindwa',
    'Namutumba',
    'Napak',
    'Nebbi',
    'Ngora',
    'Ntoroko',
    'Ntungamo',
    'Nwoya',
    'Obongi',
    'Omoro',
    'Otuke',
    'Oyam',
    'Pader',
    'Pakwach',
    'Pallisa',
    'Rakai',
    'Rubanda',
    'Rubirizi',
    'Rukiga',
    'Rukungiri',
    'Rwampara',
    'Sembabule',
    'Serere',
    'Sheema',
    'Sironko',
    'Soroti',
    'Terego',
    'Tororo',
    'Wakiso',
    'Yumbe',
    'Zombo',
];

/**
 * The district a loan's client is in: the customer's district field, or a
 * known district name found in a free-text address (staff, startups);
 * empty when no known district is recognised.
 */
function location_district(string $district, string $location = ''): string
{
    static $lookup = null;
    if ($lookup === null) {
        $lookup = [];
        foreach (UGANDA_DISTRICTS as $name) {
            $lookup[strtolower($name)] = $name;
        }
    }
    $district = trim($district);
    if ($district !== '' && isset($lookup[strtolower($district)])) {
        return $lookup[strtolower($district)];
    }
    foreach ([$district, $location] as $text) {
        if ($text === '') {
            continue;
        }
        foreach ($lookup as $key => $name) {
            if (preg_match('/\b' . preg_quote($key, '/') . '\b/i', $text)) {
                return $name;
            }
        }
    }
    return '';
}
