<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

use DateTimeZone;

// Docs: docs/forms.md
final class CountryData
{
    /**
     * @return array<string, array{0:string, 1:string}>
     */
    private static function table(): array
    {
        return [
            'AD' => ['376',  __('Andorra', 'lrob-email-toolkit')],
            'AE' => ['971',  __('United Arab Emirates', 'lrob-email-toolkit')],
            'AF' => ['93',   __('Afghanistan', 'lrob-email-toolkit')],
            'AG' => ['1268', __('Antigua and Barbuda', 'lrob-email-toolkit')],
            'AI' => ['1264', __('Anguilla', 'lrob-email-toolkit')],
            'AL' => ['355',  __('Albania', 'lrob-email-toolkit')],
            'AM' => ['374',  __('Armenia', 'lrob-email-toolkit')],
            'AO' => ['244',  __('Angola', 'lrob-email-toolkit')],
            'AR' => ['54',   __('Argentina', 'lrob-email-toolkit')],
            'AS' => ['1684', __('American Samoa', 'lrob-email-toolkit')],
            'AT' => ['43',   __('Austria', 'lrob-email-toolkit')],
            'AU' => ['61',   __('Australia', 'lrob-email-toolkit')],
            'AW' => ['297',  __('Aruba', 'lrob-email-toolkit')],
            'AX' => ['358',  __('Åland Islands', 'lrob-email-toolkit')],
            'AZ' => ['994',  __('Azerbaijan', 'lrob-email-toolkit')],
            'BA' => ['387',  __('Bosnia and Herzegovina', 'lrob-email-toolkit')],
            'BB' => ['1246', __('Barbados', 'lrob-email-toolkit')],
            'BD' => ['880',  __('Bangladesh', 'lrob-email-toolkit')],
            'BE' => ['32',   __('Belgium', 'lrob-email-toolkit')],
            'BF' => ['226',  __('Burkina Faso', 'lrob-email-toolkit')],
            'BG' => ['359',  __('Bulgaria', 'lrob-email-toolkit')],
            'BH' => ['973',  __('Bahrain', 'lrob-email-toolkit')],
            'BI' => ['257',  __('Burundi', 'lrob-email-toolkit')],
            'BJ' => ['229',  __('Benin', 'lrob-email-toolkit')],
            'BL' => ['590',  __('Saint Barthélemy', 'lrob-email-toolkit')],
            'BM' => ['1441', __('Bermuda', 'lrob-email-toolkit')],
            'BN' => ['673',  __('Brunei', 'lrob-email-toolkit')],
            'BO' => ['591',  __('Bolivia', 'lrob-email-toolkit')],
            'BQ' => ['599',  __('Caribbean Netherlands', 'lrob-email-toolkit')],
            'BR' => ['55',   __('Brazil', 'lrob-email-toolkit')],
            'BS' => ['1242', __('Bahamas', 'lrob-email-toolkit')],
            'BT' => ['975',  __('Bhutan', 'lrob-email-toolkit')],
            'BW' => ['267',  __('Botswana', 'lrob-email-toolkit')],
            'BY' => ['375',  __('Belarus', 'lrob-email-toolkit')],
            'BZ' => ['501',  __('Belize', 'lrob-email-toolkit')],
            'CA' => ['1',    __('Canada', 'lrob-email-toolkit')],
            'CC' => ['61',   __('Cocos (Keeling) Islands', 'lrob-email-toolkit')],
            'CD' => ['243',  __('Congo (DRC)', 'lrob-email-toolkit')],
            'CF' => ['236',  __('Central African Republic', 'lrob-email-toolkit')],
            'CG' => ['242',  __('Congo (Republic)', 'lrob-email-toolkit')],
            'CH' => ['41',   __('Switzerland', 'lrob-email-toolkit')],
            'CI' => ['225',  __('Côte d’Ivoire', 'lrob-email-toolkit')],
            'CK' => ['682',  __('Cook Islands', 'lrob-email-toolkit')],
            'CL' => ['56',   __('Chile', 'lrob-email-toolkit')],
            'CM' => ['237',  __('Cameroon', 'lrob-email-toolkit')],
            'CN' => ['86',   __('China', 'lrob-email-toolkit')],
            'CO' => ['57',   __('Colombia', 'lrob-email-toolkit')],
            'CR' => ['506',  __('Costa Rica', 'lrob-email-toolkit')],
            'CU' => ['53',   __('Cuba', 'lrob-email-toolkit')],
            'CV' => ['238',  __('Cape Verde', 'lrob-email-toolkit')],
            'CW' => ['599',  __('Curaçao', 'lrob-email-toolkit')],
            'CX' => ['61',   __('Christmas Island', 'lrob-email-toolkit')],
            'CY' => ['357',  __('Cyprus', 'lrob-email-toolkit')],
            'CZ' => ['420',  __('Czech Republic', 'lrob-email-toolkit')],
            'DE' => ['49',   __('Germany', 'lrob-email-toolkit')],
            'DJ' => ['253',  __('Djibouti', 'lrob-email-toolkit')],
            'DK' => ['45',   __('Denmark', 'lrob-email-toolkit')],
            'DM' => ['1767', __('Dominica', 'lrob-email-toolkit')],
            'DO' => ['1809', __('Dominican Republic', 'lrob-email-toolkit')],
            'DZ' => ['213',  __('Algeria', 'lrob-email-toolkit')],
            'EC' => ['593',  __('Ecuador', 'lrob-email-toolkit')],
            'EE' => ['372',  __('Estonia', 'lrob-email-toolkit')],
            'EG' => ['20',   __('Egypt', 'lrob-email-toolkit')],
            'EH' => ['212',  __('Western Sahara', 'lrob-email-toolkit')],
            'ER' => ['291',  __('Eritrea', 'lrob-email-toolkit')],
            'ES' => ['34',   __('Spain', 'lrob-email-toolkit')],
            'ET' => ['251',  __('Ethiopia', 'lrob-email-toolkit')],
            'FI' => ['358',  __('Finland', 'lrob-email-toolkit')],
            'FJ' => ['679',  __('Fiji', 'lrob-email-toolkit')],
            'FK' => ['500',  __('Falkland Islands', 'lrob-email-toolkit')],
            'FM' => ['691',  __('Micronesia', 'lrob-email-toolkit')],
            'FO' => ['298',  __('Faroe Islands', 'lrob-email-toolkit')],
            'FR' => ['33',   __('France', 'lrob-email-toolkit')],
            'GA' => ['241',  __('Gabon', 'lrob-email-toolkit')],
            'GB' => ['44',   __('United Kingdom', 'lrob-email-toolkit')],
            'GD' => ['1473', __('Grenada', 'lrob-email-toolkit')],
            'GE' => ['995',  __('Georgia', 'lrob-email-toolkit')],
            'GF' => ['594',  __('French Guiana', 'lrob-email-toolkit')],
            'GG' => ['44',   __('Guernsey', 'lrob-email-toolkit')],
            'GH' => ['233',  __('Ghana', 'lrob-email-toolkit')],
            'GI' => ['350',  __('Gibraltar', 'lrob-email-toolkit')],
            'GL' => ['299',  __('Greenland', 'lrob-email-toolkit')],
            'GM' => ['220',  __('Gambia', 'lrob-email-toolkit')],
            'GN' => ['224',  __('Guinea', 'lrob-email-toolkit')],
            'GP' => ['590',  __('Guadeloupe', 'lrob-email-toolkit')],
            'GQ' => ['240',  __('Equatorial Guinea', 'lrob-email-toolkit')],
            'GR' => ['30',   __('Greece', 'lrob-email-toolkit')],
            'GT' => ['502',  __('Guatemala', 'lrob-email-toolkit')],
            'GU' => ['1671', __('Guam', 'lrob-email-toolkit')],
            'GW' => ['245',  __('Guinea-Bissau', 'lrob-email-toolkit')],
            'GY' => ['592',  __('Guyana', 'lrob-email-toolkit')],
            'HK' => ['852',  __('Hong Kong', 'lrob-email-toolkit')],
            'HN' => ['504',  __('Honduras', 'lrob-email-toolkit')],
            'HR' => ['385',  __('Croatia', 'lrob-email-toolkit')],
            'HT' => ['509',  __('Haiti', 'lrob-email-toolkit')],
            'HU' => ['36',   __('Hungary', 'lrob-email-toolkit')],
            'ID' => ['62',   __('Indonesia', 'lrob-email-toolkit')],
            'IE' => ['353',  __('Ireland', 'lrob-email-toolkit')],
            'IL' => ['972',  __('Israel', 'lrob-email-toolkit')],
            'IM' => ['44',   __('Isle of Man', 'lrob-email-toolkit')],
            'IN' => ['91',   __('India', 'lrob-email-toolkit')],
            'IO' => ['246',  __('British Indian Ocean Territory', 'lrob-email-toolkit')],
            'IQ' => ['964',  __('Iraq', 'lrob-email-toolkit')],
            'IR' => ['98',   __('Iran', 'lrob-email-toolkit')],
            'IS' => ['354',  __('Iceland', 'lrob-email-toolkit')],
            'IT' => ['39',   __('Italy', 'lrob-email-toolkit')],
            'JE' => ['44',   __('Jersey', 'lrob-email-toolkit')],
            'JM' => ['1876', __('Jamaica', 'lrob-email-toolkit')],
            'JO' => ['962',  __('Jordan', 'lrob-email-toolkit')],
            'JP' => ['81',   __('Japan', 'lrob-email-toolkit')],
            'KE' => ['254',  __('Kenya', 'lrob-email-toolkit')],
            'KG' => ['996',  __('Kyrgyzstan', 'lrob-email-toolkit')],
            'KH' => ['855',  __('Cambodia', 'lrob-email-toolkit')],
            'KI' => ['686',  __('Kiribati', 'lrob-email-toolkit')],
            'KM' => ['269',  __('Comoros', 'lrob-email-toolkit')],
            'KN' => ['1869', __('Saint Kitts and Nevis', 'lrob-email-toolkit')],
            'KP' => ['850',  __('North Korea', 'lrob-email-toolkit')],
            'KR' => ['82',   __('South Korea', 'lrob-email-toolkit')],
            'KW' => ['965',  __('Kuwait', 'lrob-email-toolkit')],
            'KY' => ['1345', __('Cayman Islands', 'lrob-email-toolkit')],
            'KZ' => ['7',    __('Kazakhstan', 'lrob-email-toolkit')],
            'LA' => ['856',  __('Laos', 'lrob-email-toolkit')],
            'LB' => ['961',  __('Lebanon', 'lrob-email-toolkit')],
            'LC' => ['1758', __('Saint Lucia', 'lrob-email-toolkit')],
            'LI' => ['423',  __('Liechtenstein', 'lrob-email-toolkit')],
            'LK' => ['94',   __('Sri Lanka', 'lrob-email-toolkit')],
            'LR' => ['231',  __('Liberia', 'lrob-email-toolkit')],
            'LS' => ['266',  __('Lesotho', 'lrob-email-toolkit')],
            'LT' => ['370',  __('Lithuania', 'lrob-email-toolkit')],
            'LU' => ['352',  __('Luxembourg', 'lrob-email-toolkit')],
            'LV' => ['371',  __('Latvia', 'lrob-email-toolkit')],
            'LY' => ['218',  __('Libya', 'lrob-email-toolkit')],
            'MA' => ['212',  __('Morocco', 'lrob-email-toolkit')],
            'MC' => ['377',  __('Monaco', 'lrob-email-toolkit')],
            'MD' => ['373',  __('Moldova', 'lrob-email-toolkit')],
            'ME' => ['382',  __('Montenegro', 'lrob-email-toolkit')],
            'MF' => ['590',  __('Saint Martin (French part)', 'lrob-email-toolkit')],
            'MG' => ['261',  __('Madagascar', 'lrob-email-toolkit')],
            'MH' => ['692',  __('Marshall Islands', 'lrob-email-toolkit')],
            'MK' => ['389',  __('North Macedonia', 'lrob-email-toolkit')],
            'ML' => ['223',  __('Mali', 'lrob-email-toolkit')],
            'MM' => ['95',   __('Myanmar', 'lrob-email-toolkit')],
            'MN' => ['976',  __('Mongolia', 'lrob-email-toolkit')],
            'MO' => ['853',  __('Macao', 'lrob-email-toolkit')],
            'MP' => ['1670', __('Northern Mariana Islands', 'lrob-email-toolkit')],
            'MQ' => ['596',  __('Martinique', 'lrob-email-toolkit')],
            'MR' => ['222',  __('Mauritania', 'lrob-email-toolkit')],
            'MS' => ['1664', __('Montserrat', 'lrob-email-toolkit')],
            'MT' => ['356',  __('Malta', 'lrob-email-toolkit')],
            'MU' => ['230',  __('Mauritius', 'lrob-email-toolkit')],
            'MV' => ['960',  __('Maldives', 'lrob-email-toolkit')],
            'MW' => ['265',  __('Malawi', 'lrob-email-toolkit')],
            'MX' => ['52',   __('Mexico', 'lrob-email-toolkit')],
            'MY' => ['60',   __('Malaysia', 'lrob-email-toolkit')],
            'MZ' => ['258',  __('Mozambique', 'lrob-email-toolkit')],
            'NA' => ['264',  __('Namibia', 'lrob-email-toolkit')],
            'NC' => ['687',  __('New Caledonia', 'lrob-email-toolkit')],
            'NE' => ['227',  __('Niger', 'lrob-email-toolkit')],
            'NF' => ['672',  __('Norfolk Island', 'lrob-email-toolkit')],
            'NG' => ['234',  __('Nigeria', 'lrob-email-toolkit')],
            'NI' => ['505',  __('Nicaragua', 'lrob-email-toolkit')],
            'NL' => ['31',   __('Netherlands', 'lrob-email-toolkit')],
            'NO' => ['47',   __('Norway', 'lrob-email-toolkit')],
            'NP' => ['977',  __('Nepal', 'lrob-email-toolkit')],
            'NR' => ['674',  __('Nauru', 'lrob-email-toolkit')],
            'NU' => ['683',  __('Niue', 'lrob-email-toolkit')],
            'NZ' => ['64',   __('New Zealand', 'lrob-email-toolkit')],
            'OM' => ['968',  __('Oman', 'lrob-email-toolkit')],
            'PA' => ['507',  __('Panama', 'lrob-email-toolkit')],
            'PE' => ['51',   __('Peru', 'lrob-email-toolkit')],
            'PF' => ['689',  __('French Polynesia', 'lrob-email-toolkit')],
            'PG' => ['675',  __('Papua New Guinea', 'lrob-email-toolkit')],
            'PH' => ['63',   __('Philippines', 'lrob-email-toolkit')],
            'PK' => ['92',   __('Pakistan', 'lrob-email-toolkit')],
            'PL' => ['48',   __('Poland', 'lrob-email-toolkit')],
            'PM' => ['508',  __('Saint Pierre and Miquelon', 'lrob-email-toolkit')],
            'PR' => ['1787', __('Puerto Rico', 'lrob-email-toolkit')],
            'PS' => ['970',  __('Palestine', 'lrob-email-toolkit')],
            'PT' => ['351',  __('Portugal', 'lrob-email-toolkit')],
            'PW' => ['680',  __('Palau', 'lrob-email-toolkit')],
            'PY' => ['595',  __('Paraguay', 'lrob-email-toolkit')],
            'QA' => ['974',  __('Qatar', 'lrob-email-toolkit')],
            'RE' => ['262',  __('Réunion', 'lrob-email-toolkit')],
            'RO' => ['40',   __('Romania', 'lrob-email-toolkit')],
            'RS' => ['381',  __('Serbia', 'lrob-email-toolkit')],
            'RU' => ['7',    __('Russia', 'lrob-email-toolkit')],
            'RW' => ['250',  __('Rwanda', 'lrob-email-toolkit')],
            'SA' => ['966',  __('Saudi Arabia', 'lrob-email-toolkit')],
            'SB' => ['677',  __('Solomon Islands', 'lrob-email-toolkit')],
            'SC' => ['248',  __('Seychelles', 'lrob-email-toolkit')],
            'SD' => ['249',  __('Sudan', 'lrob-email-toolkit')],
            'SE' => ['46',   __('Sweden', 'lrob-email-toolkit')],
            'SG' => ['65',   __('Singapore', 'lrob-email-toolkit')],
            'SH' => ['290',  __('Saint Helena', 'lrob-email-toolkit')],
            'SI' => ['386',  __('Slovenia', 'lrob-email-toolkit')],
            'SJ' => ['47',   __('Svalbard and Jan Mayen', 'lrob-email-toolkit')],
            'SK' => ['421',  __('Slovakia', 'lrob-email-toolkit')],
            'SL' => ['232',  __('Sierra Leone', 'lrob-email-toolkit')],
            'SM' => ['378',  __('San Marino', 'lrob-email-toolkit')],
            'SN' => ['221',  __('Senegal', 'lrob-email-toolkit')],
            'SO' => ['252',  __('Somalia', 'lrob-email-toolkit')],
            'SR' => ['597',  __('Suriname', 'lrob-email-toolkit')],
            'SS' => ['211',  __('South Sudan', 'lrob-email-toolkit')],
            'ST' => ['239',  __('São Tomé and Príncipe', 'lrob-email-toolkit')],
            'SV' => ['503',  __('El Salvador', 'lrob-email-toolkit')],
            'SX' => ['1721', __('Sint Maarten', 'lrob-email-toolkit')],
            'SY' => ['963',  __('Syria', 'lrob-email-toolkit')],
            'SZ' => ['268',  __('Eswatini', 'lrob-email-toolkit')],
            'TC' => ['1649', __('Turks and Caicos Islands', 'lrob-email-toolkit')],
            'TD' => ['235',  __('Chad', 'lrob-email-toolkit')],
            'TG' => ['228',  __('Togo', 'lrob-email-toolkit')],
            'TH' => ['66',   __('Thailand', 'lrob-email-toolkit')],
            'TJ' => ['992',  __('Tajikistan', 'lrob-email-toolkit')],
            'TK' => ['690',  __('Tokelau', 'lrob-email-toolkit')],
            'TL' => ['670',  __('Timor-Leste', 'lrob-email-toolkit')],
            'TM' => ['993',  __('Turkmenistan', 'lrob-email-toolkit')],
            'TN' => ['216',  __('Tunisia', 'lrob-email-toolkit')],
            'TO' => ['676',  __('Tonga', 'lrob-email-toolkit')],
            'TR' => ['90',   __('Turkey', 'lrob-email-toolkit')],
            'TT' => ['1868', __('Trinidad and Tobago', 'lrob-email-toolkit')],
            'TV' => ['688',  __('Tuvalu', 'lrob-email-toolkit')],
            'TW' => ['886',  __('Taiwan', 'lrob-email-toolkit')],
            'TZ' => ['255',  __('Tanzania', 'lrob-email-toolkit')],
            'UA' => ['380',  __('Ukraine', 'lrob-email-toolkit')],
            'UG' => ['256',  __('Uganda', 'lrob-email-toolkit')],
            'US' => ['1',    __('United States', 'lrob-email-toolkit')],
            'UY' => ['598',  __('Uruguay', 'lrob-email-toolkit')],
            'UZ' => ['998',  __('Uzbekistan', 'lrob-email-toolkit')],
            'VA' => ['379',  __('Vatican City', 'lrob-email-toolkit')],
            'VC' => ['1784', __('Saint Vincent and the Grenadines', 'lrob-email-toolkit')],
            'VE' => ['58',   __('Venezuela', 'lrob-email-toolkit')],
            'VG' => ['1284', __('British Virgin Islands', 'lrob-email-toolkit')],
            'VI' => ['1340', __('U.S. Virgin Islands', 'lrob-email-toolkit')],
            'VN' => ['84',   __('Vietnam', 'lrob-email-toolkit')],
            'VU' => ['678',  __('Vanuatu', 'lrob-email-toolkit')],
            'WF' => ['681',  __('Wallis and Futuna', 'lrob-email-toolkit')],
            'WS' => ['685',  __('Samoa', 'lrob-email-toolkit')],
            'YE' => ['967',  __('Yemen', 'lrob-email-toolkit')],
            'YT' => ['262',  __('Mayotte', 'lrob-email-toolkit')],
            'ZA' => ['27',   __('South Africa', 'lrob-email-toolkit')],
            'ZM' => ['260',  __('Zambia', 'lrob-email-toolkit')],
            'ZW' => ['263',  __('Zimbabwe', 'lrob-email-toolkit')],
        ];
    }

    /**
     * @return array<int, array{iso:string, name:string, dial:string, flag:string}>
     */
    public static function all_translated(string $sort_by = 'name'): array
    {
        $out = [];
        foreach (self::table() as $iso => [$dial, $name]) {
            $out[] = [
                'iso'  => $iso,
                'name' => $name,
                'dial' => $dial,
                'flag' => self::flag_emoji($iso),
            ];
        }
        if ($sort_by === 'dial') {
            usort($out, static fn(array $a, array $b): int => (int) $a['dial'] <=> (int) $b['dial']);
        } else {
            usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        }
        return $out;
    }

    public static function is_known(string $iso2): bool
    {
        $iso2 = strtoupper($iso2);
        return isset(self::table()[$iso2]);
    }

    public static function dial(string $iso2): string
    {
        $iso2 = strtoupper($iso2);
        $row = self::table()[$iso2] ?? null;
        return $row !== null ? $row[0] : '';
    }

    public static function flag_emoji(string $iso2): string
    {
        $iso2 = strtoupper($iso2);
        if (strlen($iso2) !== 2 || !ctype_alpha($iso2)) {
            return '';
        }
        $base = 0x1F1E6 - ord('A');
        return mb_chr($base + ord($iso2[0]), 'UTF-8')
             . mb_chr($base + ord($iso2[1]), 'UTF-8');
    }

    public static function resolve_default(string $admin_choice): string
    {
        $admin_choice = strtoupper(trim($admin_choice));
        if ($admin_choice !== '' && self::is_known($admin_choice)) {
            return $admin_choice;
        }

        $locale = function_exists('get_locale') ? (string) get_locale() : '';
        if (preg_match('/^[a-z]{2,3}_([A-Z]{2})/', $locale, $m)) {
            $iso = $m[1];
            if (self::is_known($iso)) {
                return $iso;
            }
        }

        $tz_string = function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '';
        if ($tz_string !== '' && str_contains($tz_string, '/')) {
            $map = self::tz_to_country_map();
            if (isset($map[$tz_string])) {
                return $map[$tz_string];
            }
        }

        return '';
    }

    /** @return array<string, string> */
    private static function tz_to_country_map(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach (array_keys(self::table()) as $iso) {
            try {
                $tzs = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $iso);
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($tzs as $tz) {
                $map[$tz] = $iso;
            }
        }
        return $map;
    }
}
