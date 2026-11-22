<?php

namespace App\Domain\Competitors\Support;

/**
 * Complete ISO 3166-1 alpha-2 country catalog with geo-targeting metadata
 * and strict country-relevance verification for competitor discovery.
 */
class CountryCatalog
{
    /**
     * Full ISO-2 => English country name map (195 sovereign states & territories).
     */
    public const COUNTRIES = [
        'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra', 'AO' => 'Angola',
        'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia', 'AT' => 'Austria',
        'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados',
        'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BT' => 'Bhutan',
        'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brazil', 'BN' => 'Brunei',
        'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'CV' => 'Cabo Verde', 'KH' => 'Cambodia',
        'CM' => 'Cameroon', 'CA' => 'Canada', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile',
        'CN' => 'China', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'DR Congo',
        'CR' => 'Costa Rica', 'CI' => 'Cote d Ivoire', 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus',
        'CZ' => 'Czechia', 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic',
        'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea',
        'EE' => 'Estonia', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia', 'FJ' => 'Fiji', 'FI' => 'Finland',
        'FR' => 'France', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany',
        'GH' => 'Ghana', 'GR' => 'Greece', 'GD' => 'Grenada', 'GT' => 'Guatemala', 'GN' => 'Guinea',
        'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HT' => 'Haiti', 'HN' => 'Honduras', 'HK' => 'Hong Kong',
        'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran',
        'IQ' => 'Iraq', 'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica',
        'JP' => 'Japan', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati',
        'KP' => 'North Korea', 'KR' => 'South Korea', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos',
        'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya',
        'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MO' => 'Macao', 'MG' => 'Madagascar',
        'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta',
        'MH' => 'Marshall Islands', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'MX' => 'Mexico', 'FM' => 'Micronesia',
        'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MA' => 'Morocco',
        'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal',
        'NL' => 'Netherlands', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria',
        'MK' => 'North Macedonia', 'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau',
        'PS' => 'Palestine', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru',
        'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar', 'RO' => 'Romania',
        'RU' => 'Russia', 'RW' => 'Rwanda', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia', 'VC' => 'Saint Vincent',
        'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'Sao Tome and Principe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal',
        'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SK' => 'Slovakia',
        'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'SS' => 'South Sudan',
        'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SE' => 'Sweden',
        'CH' => 'Switzerland', 'SY' => 'Syria', 'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania',
        'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago',
        'TN' => 'Tunisia', 'TR' => 'Turkey', 'TM' => 'Turkmenistan', 'TV' => 'Tuvalu', 'UG' => 'Uganda',
        'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States', 'UY' => 'Uruguay',
        'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VA' => 'Vatican City', 'VE' => 'Venezuela', 'VN' => 'Vietnam',
        'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    ];

    /**
     * DuckDuckGo supported `kl` region codes.
     */
    private const DDG_KL_MAP = [
        'US' => 'us-en', 'GB' => 'uk-en', 'CA' => 'ca-en', 'AU' => 'au-en', 'NZ' => 'nz-en',
        'IN' => 'in-en', 'IE' => 'ie-en', 'SG' => 'sg-en', 'MY' => 'my-en', 'PH' => 'ph-en',
        'ZA' => 'za-en', 'PK' => 'pk-en', 'DE' => 'de-de', 'AT' => 'at-de', 'CH' => 'ch-de',
        'FR' => 'fr-fr', 'BE' => 'be-fr', 'NL' => 'nl-nl', 'ES' => 'es-es', 'IT' => 'it-it',
        'PT' => 'pt-pt', 'BR' => 'br-pt', 'MX' => 'mx-es', 'AR' => 'ar-es', 'CO' => 'co-es',
        'CL' => 'cl-es', 'PE' => 'pe-es', 'JP' => 'jp-jp', 'KR' => 'kr-kr', 'TW' => 'tw-tzh',
        'HK' => 'hk-tzh', 'CN' => 'cn-zh', 'ID' => 'id-en', 'TH' => 'th-en', 'VN' => 'vn-en',
        'TR' => 'tr-tr', 'PL' => 'pl-pl', 'SE' => 'se-sv', 'NO' => 'no-no', 'DK' => 'dk-da',
        'FI' => 'fi-fi', 'RU' => 'ru-ru', 'UA' => 'ua-uk', 'SA' => 'xa-ar', 'AE' => 'xa-en',
        'EG' => 'xa-ar', 'IL' => 'il-he', 'GR' => 'gr-el', 'CZ' => 'cz-cs', 'HU' => 'hu-hu',
        'RO' => 'ro-ro', 'BG' => 'bg-bg', 'HR' => 'hr-hr', 'SK' => 'sk-sk', 'SI' => 'sl-sl',
        'EE' => 'ee-et', 'LV' => 'lv-lv', 'LT' => 'lt-lt', 'IS' => 'is-is', 'NG' => 'ng-en',
    ];

    /**
     * Rich local signals per country: major cities, demonyms, currencies, safe domain suffixes.
     */
    private const MARKET_SIGNALS = [
        'BD' => [
            'terms' => ['bangladesh', 'bangladeshi', 'dhaka', 'chittagong', 'chattogram', 'sylhet', 'khulna', 'rajshahi', 'barisal', 'barishal', 'rangpur', 'mymensingh', 'cumilla', 'comilla', 'gazipur', 'narayanganj', 'gulshan', 'banani', 'uttara', 'dhanmondi', 'mirpur', 'bashundhara', 'mohammadpur', 'motijheel', 'bdt', 'taka', '৳'],
            'domain_suffixes' => ['bd'],
            'tlds' => ['.bd', '.com.bd', '.org.bd', '.net.bd', '.gov.bd', '.edu.bd'],
            'major_city' => 'Dhaka',
        ],
        'US' => [
            'terms' => ['united states', 'usa', 'u.s.a', 'american', 'new york', 'los angeles', 'chicago', 'houston', 'phoenix', 'philadelphia', 'san antonio', 'san diego', 'dallas', 'austin', 'miami', 'florida', 'california', 'texas', 'seattle', 'boston', 'baltimore', 'atlanta', 'denver', 'medicare', 'medicaid', 'aarp'],
            'domain_suffixes' => ['usa'],
            'tlds' => ['.us', '.gov', '.edu', '.mil'],
            'major_city' => 'New York',
        ],
        'GB' => [
            'terms' => ['united kingdom', 'uk', 'britain', 'british', 'england', 'scotland', 'wales', 'london', 'manchester', 'birmingham', 'leeds', 'glasgow', 'liverpool', 'edinburgh', 'bristol', 'nhs', 'gbp', '£'],
            'domain_suffixes' => ['uk'],
            'tlds' => ['.uk', '.co.uk', '.org.uk', '.gov.uk', '.ac.uk'],
            'major_city' => 'London',
        ],
        'IN' => [
            'terms' => ['india', 'indian', 'mumbai', 'delhi', 'new delhi', 'bangalore', 'bengaluru', 'hyderabad', 'chennai', 'kolkata', 'pune', 'ahmedabad', 'jaipur', 'noida', 'gurgaon', 'gurugram', 'inr', '₹', 'rupees'],
            'domain_suffixes' => ['ind'],
            'tlds' => ['.in', '.co.in', '.org.in', '.gov.in'],
            'major_city' => 'Mumbai',
        ],
        'CA' => [
            'terms' => ['canada', 'canadian', 'toronto', 'vancouver', 'montreal', 'calgary', 'ottawa', 'edmonton', 'winnipeg', 'ontario', 'quebec', 'alberta', 'british columbia', 'cad'],
            'domain_suffixes' => ['can'],
            'tlds' => ['.ca'],
            'major_city' => 'Toronto',
        ],
        'AU' => [
            'terms' => ['australia', 'australian', 'sydney', 'melbourne', 'brisbane', 'perth', 'adelaide', 'canberra', 'queensland', 'victoria', 'nsw', 'aud', 'ndis'],
            'domain_suffixes' => ['aus'],
            'tlds' => ['.au', '.com.au', '.org.au', '.gov.au'],
            'major_city' => 'Sydney',
        ],
        'AE' => [
            'terms' => ['united arab emirates', 'uae', 'dubai', 'abu dhabi', 'sharjah', 'ajman', 'emirates', 'aed', 'dirham'],
            'domain_suffixes' => ['uae', 'dubai'],
            'tlds' => ['.ae'],
            'major_city' => 'Dubai',
        ],
        'SA' => [
            'terms' => ['saudi arabia', 'saudi', 'riyadh', 'jeddah', 'dammam', 'makkah', 'mecca', 'medina', 'ksa', 'sar'],
            'domain_suffixes' => ['ksa', 'saudi'],
            'tlds' => ['.sa', '.com.sa'],
            'major_city' => 'Riyadh',
        ],
        'PK' => [
            'terms' => ['pakistan', 'pakistani', 'karachi', 'lahore', 'islamabad', 'rawalpindi', 'faisalabad', 'multan', 'peshawar', 'pkr'],
            'domain_suffixes' => ['pk'],
            'tlds' => ['.pk', '.com.pk', '.org.pk'],
            'major_city' => 'Karachi',
        ],
        'SG' => [
            'terms' => ['singapore', 'singaporean', 'sgd'],
            'domain_suffixes' => ['sg'],
            'tlds' => ['.sg', '.com.sg'],
            'major_city' => 'Singapore',
        ],
        'MY' => [
            'terms' => ['malaysia', 'malaysian', 'kuala lumpur', 'penang', 'johor', 'selangor', 'myr', 'ringgit'],
            'domain_suffixes' => ['my'],
            'tlds' => ['.my', '.com.my'],
            'major_city' => 'Kuala Lumpur',
        ],
        'DE' => [
            'terms' => ['germany', 'deutschland', 'german', 'berlin', 'munich', 'münchen', 'hamburg', 'frankfurt', 'cologne', 'köln', 'stuttgart', 'düsseldorf'],
            'domain_suffixes' => [],
            'tlds' => ['.de'],
            'major_city' => 'Berlin',
        ],
        'FR' => [
            'terms' => ['france', 'french', 'paris', 'lyon', 'marseille', 'toulouse', 'nice', 'bordeaux'],
            'domain_suffixes' => [],
            'tlds' => ['.fr'],
            'major_city' => 'Paris',
        ],
        'NL' => [
            'terms' => ['netherlands', 'holland', 'dutch', 'amsterdam', 'rotterdam', 'utrecht', 'eindhoven', 'hague'],
            'domain_suffixes' => [],
            'tlds' => ['.nl'],
            'major_city' => 'Amsterdam',
        ],
        'NZ' => [
            'terms' => ['new zealand', 'auckland', 'wellington', 'christchurch', 'nzd'],
            'domain_suffixes' => ['nz'],
            'tlds' => ['.nz', '.co.nz'],
            'major_city' => 'Auckland',
        ],
        'ZA' => [
            'terms' => ['south africa', 'johannesburg', 'cape town', 'durban', 'pretoria', 'zar'],
            'domain_suffixes' => ['za'],
            'tlds' => ['.za', '.co.za'],
            'major_city' => 'Johannesburg',
        ],
        'NG' => [
            'terms' => ['nigeria', 'nigerian', 'lagos', 'abuja', 'port harcourt', 'ngn', 'naira'],
            'domain_suffixes' => ['ng'],
            'tlds' => ['.ng', '.com.ng'],
            'major_city' => 'Lagos',
        ],
        'KE' => [
            'terms' => ['kenya', 'kenyan', 'nairobi', 'mombasa', 'kes'],
            'domain_suffixes' => ['ke'],
            'tlds' => ['.ke', '.co.ke'],
            'major_city' => 'Nairobi',
        ],
        'PH' => [
            'terms' => ['philippines', 'filipino', 'manila', 'cebu', 'davao', 'makati', 'quezon', 'php'],
            'domain_suffixes' => ['ph'],
            'tlds' => ['.ph', '.com.ph'],
            'major_city' => 'Manila',
        ],
        'ID' => [
            'terms' => ['indonesia', 'indonesian', 'jakarta', 'surabaya', 'bandung', 'bali', 'idr', 'rupiah'],
            'domain_suffixes' => [],
            'tlds' => ['.id', '.co.id'],
            'major_city' => 'Jakarta',
        ],
        'NP' => [
            'terms' => ['nepal', 'nepali', 'kathmandu', 'pokhara', 'npr'],
            'domain_suffixes' => ['np'],
            'tlds' => ['.np', '.com.np'],
            'major_city' => 'Kathmandu',
        ],
        'LK' => [
            'terms' => ['sri lanka', 'colombo', 'kandy', 'lkr'],
            'domain_suffixes' => ['lk'],
            'tlds' => ['.lk', '.com.lk'],
            'major_city' => 'Colombo',
        ],
        'QA' => [
            'terms' => ['qatar', 'doha', 'qar'],
            'domain_suffixes' => ['qa'],
            'tlds' => ['.qa', '.com.qa'],
            'major_city' => 'Doha',
        ],
        'KW' => [
            'terms' => ['kuwait', 'kwd'],
            'domain_suffixes' => ['kw'],
            'tlds' => ['.kw', '.com.kw'],
            'major_city' => 'Kuwait City',
        ],
        'JP' => [
            'terms' => ['japan', 'japanese', 'tokyo', 'osaka', 'kyoto', 'yokohama', 'jpy'],
            'domain_suffixes' => ['jp'],
            'tlds' => ['.jp', '.co.jp'],
            'major_city' => 'Tokyo',
        ],
        'BR' => [
            'terms' => ['brazil', 'brasil', 'sao paulo', 'são paulo', 'rio de janeiro', 'brl'],
            'domain_suffixes' => ['br'],
            'tlds' => ['.br', '.com.br'],
            'major_city' => 'Sao Paulo',
        ],
        'IE' => [
            'terms' => ['ireland', 'irish', 'dublin', 'cork', 'galway'],
            'domain_suffixes' => [],
            'tlds' => ['.ie'],
            'major_city' => 'Dublin',
        ],
    ];

    /**
     * Well-known US-specific domains that frequently hijack non-US English healthcare/service queries.
     */
    private const US_ONLY_DOMAINS = [
        'care.com', 'hopkinsmedicine.org', 'mayoclinic.org', 'clevelandclinic.org', 'webmd.com',
        'aarp.org', 'aplaceformom.com', 'visitingangels.com', 'homeinstead.com', 'comfortkeepers.com',
        'seniorliving.org', 'caring.com', 'agingcare.com', 'medicare.gov', 'medicaid.gov',
        'nih.gov', 'cdc.gov', 'healthline.com', 'verywellhealth.com', 'forbes.com', 'usnews.com',
        'thumbtack.com', 'angi.com', 'homeadvisor.com', 'yelp.com', 'bbb.org', 'yellowpages.com',
        'indeed.com', 'ziprecruiter.com', 'glassdoor.com', 'monster.com', 'caregiver.org',
        'rightathome.net', 'synergyhomecare.com', 'brightstarcare.com', 'bayada.com',
    ];

    public static function isValid(string $code): bool
    {
        return isset(self::COUNTRIES[strtoupper(trim($code))]);
    }

    /**
     * Normalize single or multi-country input into unique, valid uppercase ISO-2 codes (max 5).
     *
     * @return string[]
     */
    public static function normalizeCodes(array $codes, string $fallback = 'US', int $max = 5): array
    {
        $clean = [];
        foreach ($codes as $code) {
            $upper = strtoupper(trim((string) $code));
            if ($upper === 'UK') {
                $upper = 'GB';
            }
            if (self::isValid($upper) && !in_array($upper, $clean, true)) {
                $clean[] = $upper;
            }
        }

        if (empty($clean)) {
            $fb = strtoupper(trim($fallback));
            if ($fb === 'UK') {
                $fb = 'GB';
            }
            $clean[] = self::isValid($fb) ? $fb : 'US';
        }

        return array_slice($clean, 0, $max);
    }

    public static function name(string $code): string
    {
        $upper = strtoupper(trim($code));
        if ($upper === 'UK') {
            $upper = 'GB';
        }
        return self::COUNTRIES[$upper] ?? $upper;
    }

    public static function gl(string $code): string
    {
        $upper = strtoupper(trim($code));
        if ($upper === 'UK') {
            $upper = 'GB';
        }
        return strtolower($upper);
    }

    public static function serperLocation(string $code): string
    {
        return self::name($code);
    }

    public static function ddgKl(string $code): string
    {
        $upper = strtoupper(trim($code));
        if ($upper === 'UK') {
            $upper = 'GB';
        }
        return self::DDG_KL_MAP[$upper] ?? 'wt-wt';
    }

    public static function majorCity(string $code): string
    {
        $upper = strtoupper(trim($code));
        return self::MARKET_SIGNALS[$upper]['major_city'] ?? '';
    }

    /**
     * All known city tokens across markets (for query generation).
     *
     * @return string[]
     */
    public static function allMajorCities(): array
    {
        $cities = [];
        foreach (self::MARKET_SIGNALS as $sig) {
            if (!empty($sig['major_city'])) {
                $cities[] = strtolower($sig['major_city']);
            }
            foreach ($sig['terms'] ?? [] as $t) {
                if (strlen($t) >= 4) {
                    $cities[] = $t;
                }
            }
        }
        return array_values(array_unique($cities));
    }

    /**
     * Evaluate whether a candidate domain genuinely belongs to one or more of the selected target countries,
     * and detect unmistakable foreign-country conflicts (e.g., US Florida or UK NHS pages when targeting Bangladesh).
     *
     * @param string[] $urls
     * @param string[] $targetCountries Uppercase ISO-2 codes
     * @return array{matched_countries: string[], country_verified: bool, foreign_conflict: bool, foreign_country_hint: ?string, evidence: string[]}
     */
    public static function evaluateCandidateCountry(
        string $domain,
        ?string $title,
        ?string $snippet,
        array $urls,
        array $targetCountries
    ): array {
        $domainLower = strtolower(trim($domain));
        $domainStem = strtolower((string) pathinfo($domainLower, PATHINFO_FILENAME));
        // Strip second-level suffix for domains like foo.com.bd -> foo
        if (str_contains($domainStem, '.')) {
            $domainStem = explode('.', $domainStem)[0];
        }

        $urlText = strtolower(implode(' ', $urls));
        $bodyText = strtolower(trim(($title ?? '') . ' ' . ($snippet ?? '') . ' ' . $urlText));

        $matchedCountries = [];
        $evidence = [];

        foreach ($targetCountries as $cc) {
            $ccUpper = strtoupper($cc);
            $ccLower = strtolower($ccUpper);
            $countryNameLower = strtolower(self::name($ccUpper));
            $signals = self::MARKET_SIGNALS[$ccUpper] ?? [];
            $hits = [];

            // 1. Exact ccTLD check (.bd, .com.bd, .co.uk, .in, .de, .ae, etc.)
            $tlds = $signals['tlds'] ?? [".{$ccLower}", ".com.{$ccLower}", ".org.{$ccLower}", ".co.{$ccLower}"];
            foreach ($tlds as $tld) {
                if (str_ends_with($domainLower, $tld)) {
                    $hits[] = "ccTLD:{$tld}";
                    break;
                }
            }

            // 2. Country name or safe country suffix inside domain name (e.g. caregiveragencybd.com, homecarebd.com)
            if (strlen($countryNameLower) >= 4 && str_contains($domainLower, str_replace(' ', '', $countryNameLower))) {
                $hits[] = "domain:{$countryNameLower}";
            }
            foreach ($signals['domain_suffixes'] ?? [] as $suffix) {
                if (strlen($domainStem) >= strlen($suffix) + 3 && str_ends_with($domainStem, $suffix)) {
                    $hits[] = "domain_suffix:{$suffix}";
                    break;
                }
            }

            // 3. Explicit country name in title, snippet, or URL
            if (preg_match('/\b' . preg_quote($countryNameLower, '/') . '\b/i', $bodyText)) {
                $hits[] = "text:{$countryNameLower}";
            }

            // 4. Explicit country-specific cities, demonyms, or currency markers
            foreach ($signals['terms'] ?? [] as $term) {
                if (strlen($term) <= 2 && !in_array($term, ['uk', '£', '₹', '৳'], true)) {
                    continue;
                }
                if (in_array($term, ['£', '₹', '৳'], true)) {
                    if (str_contains($bodyText, $term)) {
                        $hits[] = "currency:{$term}";
                    }
                } elseif (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $bodyText)) {
                    $hits[] = "local:{$term}";
                }
            }

            // 5. Special case: domain/title contains standalone uppercase "BD" / "UAE" / "UK" / "USA" / "KSA"
            if (in_array($ccUpper, ['BD', 'AE', 'GB', 'US', 'SA', 'SG', 'PK', 'NZ'], true)) {
                $abbrList = match ($ccUpper) {
                    'GB' => ['UK'],
                    'AE' => ['UAE'],
                    'US' => ['USA'],
                    'SA' => ['KSA'],
                    default => [$ccUpper],
                };
                foreach ($abbrList as $abbr) {
                    if (preg_match('/\b' . preg_quote($abbr, '/') . '\b/', ($title ?? '') . ' ' . ($snippet ?? ''))) {
                        $hits[] = "abbr:{$abbr}";
                    }
                }
            }

            if (!empty($hits)) {
                $matchedCountries[] = $ccUpper;
                $evidence = array_merge($evidence, array_slice(array_unique($hits), 0, 3));
            }
        }

        // Detect unmistakable FOREIGN-country conflict (a country NOT in $targetCountries)
        $foreignConflict = false;
        $foreignHint = null;

        // A. Foreign ccTLD check (if domain ends with a known country's ccTLD not in $targetCountries)
        $domainParts = explode('.', $domainLower);
        $lastLabel = end($domainParts);
        if (is_string($lastLabel) && strlen($lastLabel) === 2) {
            $tldCountry = strtoupper($lastLabel) === 'UK' ? 'GB' : strtoupper($lastLabel);
            // Ignore generic/vanity 2-letter TLDs commonly used globally by SaaS/startups
            $globalVanityTlds = ['IO', 'AI', 'CO', 'ME', 'TV', 'CC', 'GG', 'LY', 'TO', 'SH', 'SO', 'VC', 'FM', 'AM', 'IM'];
            if (self::isValid($tldCountry) && !in_array($tldCountry, $globalVanityTlds, true) && !in_array($tldCountry, $targetCountries, true)) {
                $foreignConflict = true;
                $foreignHint = "{$tldCountry} (.{$lastLabel})";
            }
        }
        if (!$foreignConflict && !in_array('US', $targetCountries, true) && (str_ends_with($domainLower, '.gov') || str_ends_with($domainLower, '.edu') || str_ends_with($domainLower, '.mil'))) {
            $foreignConflict = true;
            $foreignHint = 'US (.gov/.edu)';
        }

        // B. Known US-only giant directories/hospitals when US is NOT selected
        if (!$foreignConflict && !in_array('US', $targetCountries, true)) {
            foreach (self::US_ONLY_DOMAINS as $usDom) {
                if ($domainLower === $usDom || str_ends_with($domainLower, '.' . $usDom)) {
                    $foreignConflict = true;
                    $foreignHint = "US ({$usDom})";
                    break;
                }
            }
        }

        // C. Unmistakable US state/city/USD hourly pricing signals when US is NOT selected and target country NOT matched
        if (!$foreignConflict && empty($matchedCountries) && !in_array('US', $targetCountries, true)) {
            $rawTitleSnip = ($title ?? '') . ' ' . ($snippet ?? '');
            if (preg_match('/,\s*(AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY)\b/', $rawTitleSnip, $stMatch)) {
                $foreignConflict = true;
                $foreignHint = "US (State {$stMatch[1]})";
            } elseif (preg_match('/\/[a-z0-9-]+-(fl|ca|tx|ny|oh|pa|il|ga|nc|mi|nj|va|wa|az|ma|md|co|tn|mn|wi|mo|or|ky|sc|al|la|ok|ct|ut|nv)\b/i', $urlText, $uStMatch)) {
                $foreignConflict = true;
                $foreignHint = 'US (URL state -' . strtoupper($uStMatch[1]) . ')';
            } elseif (preg_match('/\$\s*\d+(?:\.\d+)?\s*(?:\/hr|per hour|an hour)/i', $rawTitleSnip)) {
                $foreignConflict = true;
                $foreignHint = 'US (USD hourly rate)';
            } elseif (preg_match('/\b(united states|medicare|medicaid|florida|california|texas|new york|chicago|los angeles|houston|miami|seattle|baltimore|boston|johns hopkins|mayo clinic)\b/i', $bodyText, $usM)) {
                $foreignConflict = true;
                $foreignHint = "US ({$usM[1]})";
            }
        }

        // D. Unmistakable other major foreign-market text markers when NOT in $targetCountries and target country NOT matched
        if (!$foreignConflict && empty($matchedCountries)) {
            $foreignCheckMarkers = [
                'GB' => ['united kingdom', 'nhs', 'london, uk', 'manchester', 'birmingham', 'england', 'scotland', '£'],
                'IN' => ['india', 'mumbai', 'new delhi', 'bengaluru', 'bangalore', 'hyderabad', 'chennai', 'pune', 'noida', 'gurugram', '₹'],
                'CA' => ['canada', 'toronto', 'vancouver', 'montreal', 'ontario', 'calgary'],
                'AU' => ['australia', 'sydney', 'melbourne', 'brisbane', 'perth', 'ndis'],
                'PK' => ['pakistan', 'karachi', 'lahore', 'islamabad', 'rawalpindi'],
                'AE' => ['united arab emirates', 'dubai', 'abu dhabi', 'sharjah'],
                'SG' => ['singapore'],
                'MY' => ['malaysia', 'kuala lumpur'],
                'NG' => ['nigeria', 'lagos', 'abuja'],
                'ZA' => ['south africa', 'johannesburg', 'cape town'],
            ];

            foreach ($foreignCheckMarkers as $fCode => $markers) {
                if (in_array($fCode, $targetCountries, true)) {
                    continue;
                }
                foreach ($markers as $marker) {
                    if (in_array($marker, ['£', '₹'], true)) {
                        if (str_contains($bodyText, $marker)) {
                            $foreignConflict = true;
                            $foreignHint = "{$fCode} ({$marker})";
                            break 2;
                        }
                    } elseif (preg_match('/\b' . preg_quote($marker, '/') . '\b/i', $bodyText)) {
                        $foreignConflict = true;
                        $foreignHint = "{$fCode} ({$marker})";
                        break 2;
                    }
                }
            }
        }

        // If candidate has target country match AND only a soft text overlap, prefer target country match
        // (unless its actual ccTLD or known US-only domain directly conflicts!)
        return [
            'matched_countries' => $matchedCountries,
            'country_verified' => !empty($matchedCountries) && !$foreignConflict,
            'foreign_conflict' => $foreignConflict,
            'foreign_country_hint' => $foreignHint,
            'evidence' => array_values(array_unique($evidence)),
        ];
    }
}
