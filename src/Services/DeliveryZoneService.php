<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Helpers;

/**
 * Admin-configurable Bangladesh delivery charges, stored in the existing
 * `settings` table. No new DB tables, grants or user-run SQL are required.
 *
 * Three merchant-facing zones:
 *  - Dhaka city / ঢাকা সিটি
 *  - Dhaka sub-area / ঢাকা সাব-এরিয়া (Savar, Keraniganj, nearby districts…)
 *  - Outside Dhaka / ঢাকার বাহিরে
 *
 * The selected division/district/upazila-thana at checkout is classified on
 * the server; the browser never chooses the price.
 */
final class DeliveryZoneService
{
    public const KEY = 'delivery_zone_config_json';

    public const ZONES = [
        'dhaka_city' => 'ঢাকা সিটি / Dhaka city',
        'dhaka_sub_area' => 'ঢাকা সাব-এরিয়া / Dhaka sub-area',
        'outside_dhaka' => 'ঢাকার বাহিরে / Outside Dhaka',
    ];

    public static function defaultConfig(): array
    {
        return [
            'enabled' => 1,
            'version' => 1,
            'rates' => [
                'dhaka_city' => 6000,      // ৳60.00
                'dhaka_sub_area' => 9000,  // ৳90.00
                'outside_dhaka' => 12000,  // ৳120.00
            ],
            'free_over' => 0, // cents; 0 disables the free-delivery threshold
            'city_matches' => [
                // Dhaka city thanas only. Generic “Dhaka” terms were removed so
                // same-named upazilas in other divisions (e.g. Kushtia Mirpur)
                // are never charged the city rate.
                'ঢাকা সিটি', 'Dhaka City',
                // Common Dhaka city thanas; admins can add more in Bangladesh areas.
                'ধানমন্ডি', 'Dhanmondi', 'গুলশান', 'Gulshan', 'মিরপুর', 'Mirpur',
                'উত্তরা', 'Uttara', 'বাড্ডা', 'Badda', 'তেজগাঁও', 'Tejgaon',
                'মতিঝিল', 'Motijheel', 'পল্টন', 'Paltan', 'রমনা', 'Ramna',
                'সূত্রাপুর', 'Sutrapur', 'লালবাগ', 'Lalbagh', 'কোতোয়ালী', 'Kotwali',
                'ওয়ারী', 'Wari', 'মোহাম্মদপুর', 'Mohammadpur', 'বনানী', 'Banani',
                'মোহাখালী', 'Mohakhali', 'খিলক্ষেত', 'Khilkhet', 'কাফরুল', 'Kafrul',
                'পল্লবী', 'Pallabi', 'ক্যান্টনমেন্ট', 'Cantonment', 'শাহবাগ', 'Shahbag',
                'নিউ মার্কেট', 'New Market', 'কলাবাগান', 'Kalabagan', 'কামরাঙ্গীরচর', 'Kamrangirchar',
                'শ্যামপুর', 'Shyampur', 'ডেমরা', 'Demra', 'খিলগাঁও', 'Khilgaon',
                'সবুজবাগ', 'Sabujbagh', 'বসুন্ধরা', 'Bashundhara', 'বারিধারা', 'Baridhara',
            ],
            'sub_area_matches' => [
                // Dhaka division outside the city is sub-area by default; these
                // are explicit district/thana hints for the admin preview.
                'সাভার', 'Savar', 'কেরানীগঞ্জ', 'Keraniganj',
                'ধামরাই', 'Dhamrai', 'দোহার', 'Dohar', 'নবাবগঞ্জ', 'Nawabganj',
                'গাজীপুর', 'Gazipur', 'নারায়ণগঞ্জ', 'Narayanganj',
                'মানিকগঞ্জ', 'Manikganj', 'মুন্সীগঞ্জ', 'Munshiganj',
                'নরসিংদী', 'Narsingdi',
            ],
            'outside_matches' => [], // optional admin overrides, checked first
        ];
    }

    public static function ensureDefaults(): void
    {
        try {
            if (Helpers::setting(self::KEY, '') === '') {
                Helpers::setSetting(self::KEY, Helpers::json(self::defaultConfig()), 'delivery');
            }
        } catch (\Throwable) {
            // Checkout still works from defaultConfig() if a replica is read-only.
        }
    }

    public static function all(): array
    {
        $raw = Helpers::setting(self::KEY, '');
        $data = $raw !== '' ? Helpers::decode($raw, []) : [];
        return self::normalize(is_array($data) ? $data : []);
    }

    public static function save(array $in): array
    {
        $config = self::fromAdmin($in);
        Helpers::setSetting(self::KEY, Helpers::json($config), 'delivery');
        return $config;
    }

    public static function reset(): array
    {
        $default = self::defaultConfig();
        return self::save($default);
    }

    public static function enabled(): bool
    {
        return (int)(self::all()['enabled'] ?? 1) === 1;
    }

    public static function normalize(array $in): array
    {
        $default = self::defaultConfig();
        $rates = isset($in['rates']) && is_array($in['rates']) ? $in['rates'] : [];
        $out = $default;
        $out['enabled'] = array_key_exists('enabled', $in) ? ((int)$in['enabled'] ? 1 : 0) : 1;
        $out['version'] = max(1, (int)($in['version'] ?? 1));
        foreach (array_keys(self::ZONES) as $zone) {
            $out['rates'][$zone] = max(0, min(10000000, (int)($rates[$zone] ?? $default['rates'][$zone])));
        }
        $out['free_over'] = max(0, min(10000000, (int)($in['free_over'] ?? 0)));
        $out['city_matches'] = self::lines($in['city_matches'] ?? $default['city_matches']);
        $out['sub_area_matches'] = self::lines($in['sub_area_matches'] ?? $default['sub_area_matches']);
        $out['outside_matches'] = self::lines($in['outside_matches'] ?? []);
        return $out;
    }

    public static function fromAdmin(array $in): array
    {
        $money = static function (string $key, int $fallback) use ($in): int {
            $raw = trim((string)($in[$key] ?? ''));
            if ($raw === '') { return $fallback; }
            return max(0, min(10000000, (int)round(((float)str_replace(',', '', $raw)) * 100)));
        };
        $default = self::defaultConfig();
        return self::normalize([
            'enabled' => !empty($in['enabled']) ? 1 : 0,
            'version' => (int)($in['version'] ?? 1),
            'rates' => [
                'dhaka_city' => $money('charge_dhaka_city', $default['rates']['dhaka_city']),
                'dhaka_sub_area' => $money('charge_dhaka_sub_area', $default['rates']['dhaka_sub_area']),
                'outside_dhaka' => $money('charge_outside_dhaka', $default['rates']['outside_dhaka']),
            ],
            'free_over' => $money('free_over', $default['free_over']),
            'city_matches' => $in['city_matches'] ?? $default['city_matches'],
            'sub_area_matches' => $in['sub_area_matches'] ?? $default['sub_area_matches'],
            'outside_matches' => $in['outside_matches'] ?? [],
        ]);
    }

    /** A short storefront promise derived from the current admin configuration. */
    public static function publicHeadline(): string
    {
        $summary = self::publicSummary();
        if (!$summary['enabled']) { return 'Delivery across Bangladesh'; }
        if ($summary['free_over_cents'] > 0) {
            return 'Free Bangladesh delivery over ' . Helpers::money($summary['free_over_cents']);
        }
        return 'Bangladesh delivery from ' . Helpers::money($summary['minimum_charge_cents']);
    }

    public static function publicSummary(): array
    {
        $config = self::all();
        $zones = [];
        foreach (self::ZONES as $key => $label) {
            $zones[] = [
                'key' => $key,
                'label' => $label,
                'charge_cents' => (int)$config['rates'][$key],
                'charge_label' => Helpers::money((int)$config['rates'][$key]),
            ];
        }
        return [
            'enabled' => (int)$config['enabled'] === 1,
            'free_over_cents' => (int)$config['free_over'],
            'free_over_label' => Helpers::money((int)$config['free_over']),
            'minimum_charge_cents' => min(array_map('intval', $config['rates'])),
            'zones' => $zones,
        ];
    }

    /** Build a location array from a saved order/address JSON document. */
    public static function locationFromAddress(array $address): array
    {
        $get = static fn(string $k): string => mb_substr(trim((string)($address[$k] ?? '')), 0, 160);
        return [
            'country' => strtoupper($get('country')),
            'division' => $get('division'), 'division_en' => $get('division_en'),
            'district' => $get('district'), 'district_en' => $get('district_en'),
            'area' => $get('area'), 'area_en' => $get('area_en'),
            'area_type' => $get('area_type'), 'city' => $get('city'), 'region' => $get('region'),
            'division_id' => $get('division_id'), 'district_id' => $get('district_id'),
            'area_id' => $get('area_id') ?: ($get('area') !== '' ? 'legacy-area' : ''),
        ];
    }

    /** Classify a resolved checkout location into one of the three zones. */
    public static function classify(array $location): array
    {
        $config = self::all();
        $areaFields = [
            $location['area'] ?? '', $location['area_en'] ?? '', $location['city'] ?? '',
            $location['area_id'] ?? '',
        ];
        $districtFields = [
            $location['district'] ?? '', $location['district_en'] ?? '', $location['district_id'] ?? '',
        ];
        $divisionFields = [
            $location['division'] ?? '', $location['division_en'] ?? '', $location['region'] ?? '',
            $location['division_id'] ?? '',
        ];
        $allFields = array_merge($areaFields, $districtFields, $divisionFields);
        // Admin overrides always win, even for an area inside Dhaka division.
        if (self::matchesAny($allFields, (array)$config['outside_matches'])) {
            return self::zone('outside_dhaka', $config);
        }
        // City/sub-area names are scoped to Dhaka division. This prevents a
        // same-named upazila elsewhere (e.g. Kushtia Mirpur) from being charged
        // as Dhaka city. Everything outside Dhaka division is outside Dhaka.
        $isDhakaDivision = self::matchesAny($divisionFields, ['ঢাকা', 'Dhaka', 'BD30']);
        if (!$isDhakaDivision) { return self::zone('outside_dhaka', $config); }
        // An exact city thana wins before the broader sub-area hints.
        if (self::matchesAny($areaFields, (array)$config['city_matches'])) {
            return self::zone('dhaka_city', $config);
        }
        if (self::matchesAny($areaFields, (array)$config['sub_area_matches'])) {
            return self::zone('dhaka_sub_area', $config);
        }
        if (self::matchesAny($districtFields, (array)$config['city_matches'])) {
            return self::zone('dhaka_city', $config);
        }
        if (self::matchesAny(array_merge($districtFields, $divisionFields), (array)$config['sub_area_matches'])) {
            return self::zone('dhaka_sub_area', $config);
        }
        // Remaining Dhaka-division districts default to the sub-area rate.
        return self::zone('dhaka_sub_area', $config);
    }

    /** One shipping-rate row consumed by ShippingService and checkout.js. */
    public static function rateForLocation(array $location, int $subtotalCents = 0): array
    {
        $config = self::all();
        $country = strtoupper((string)($location['country'] ?? ''));
        $digitalOnly = !empty($location['digital_only']);
        $areaSelected = trim((string)($location['area_id'] ?? '')) !== '' || trim((string)($location['area'] ?? '')) !== '';
        if ((int)$config['enabled'] !== 1 || $country !== 'BD' || $digitalOnly || !$areaSelected) {
            return [
                'id' => 'select_area', 'method' => 'select_area',
                'name' => 'Select delivery area', 'zone_name' => 'Bangladesh',
                'cost_cents' => 0, 'cost_label' => Helpers::money(0), 'free' => false,
                'eta_min' => 0, 'eta_max' => 0,
                'estimate' => 'Choose division, district and upazila/thana to see the exact delivery charge.',
                'zone_key' => 'select_area', 'zone_label' => 'Select delivery area',
                'zone_note' => 'Delivery charge is calculated automatically from your address.',
            ];
        }
        $zone = self::classify($location);
        $freeOver = (int)$config['free_over'];
        $free = $freeOver > 0 && $subtotalCents >= $freeOver;
        $cost = $free ? 0 : (int)$zone['charge_cents'];
        $eta = $zone['key'] === 'outside_dhaka' ? '3–6 business days' : '1–3 business days';
        return [
            'id' => 'zone_' . $zone['key'], 'method' => 'zone_' . $zone['key'],
            'name' => $zone['label'] . ' delivery', 'zone_name' => 'Bangladesh',
            'cost_cents' => $cost, 'cost_label' => Helpers::money($cost), 'free' => $free,
            'eta_min' => $zone['key'] === 'outside_dhaka' ? 3 : 1,
            'eta_max' => $zone['key'] === 'outside_dhaka' ? 6 : 3,
            'estimate' => $free ? 'Free delivery applied · ' . $eta : $eta,
            'zone_key' => $zone['key'], 'zone_label' => $zone['label'],
            'zone_note' => $free
                ? 'Free delivery over ' . Helpers::money($freeOver) . ' applied automatically.'
                : 'Auto-calculated from your division, district and upazila/thana.',
        ];
    }

    /** Counts for the admin preview, based on the live editable hierarchy. */
    public static function previewCounts(): array
    {
        $counts = ['dhaka_city'=>0,'dhaka_sub_area'=>0,'outside_dhaka'=>0,'total'=>0];
        foreach (LocationService::publicData()['divisions'] as $d) {
            foreach ($d['districts'] as $x) {
                foreach ($x['areas'] as $a) {
                    $location = [
                        'country'=>'BD','division'=>$d['name'],'division_en'=>$d['en'],'division_id'=>$d['id'],
                        'district'=>$x['name'],'district_en'=>$x['en'],'district_id'=>$x['id'],
                        'area'=>$a['name'],'area_en'=>$a['en'],'area_type'=>$a['type'],'area_id'=>$a['id'],
                        'city'=>$a['name'],'region'=>$d['name'].' / '.$x['name'],
                    ];
                    $zone = self::classify($location);
                    $counts[$zone['key']]++;
                    $counts['total']++;
                }
            }
        }
        return $counts;
    }

    private static function zone(string $key, array $config): array
    {
        return [
            'key' => $key,
            'label' => self::ZONES[$key],
            'charge_cents' => (int)($config['rates'][$key] ?? 0),
        ];
    }

    private static function lines(mixed $raw): array
    {
        if (is_string($raw)) { $raw = preg_split('/\r\n|\r|\n/', $raw) ?: []; }
        if (!is_array($raw)) { return []; }
        $out = [];
        foreach (array_slice($raw, 0, 200) as $line) {
            $line = mb_substr(trim((string)(is_scalar($line) ? $line : '')), 0, 120);
            if ($line !== '') { $out[] = $line; }
        }
        return array_values(array_unique($out));
    }

    private static function matchesAny(array $haystack, array $needles): bool
    {
        $hay = [];
        foreach ($haystack as $value) {
            $value = self::norm($value);
            if ($value !== '') { $hay[] = $value; }
        }
        if ($hay === []) { return false; }
        foreach ($needles as $needle) {
            $needle = self::norm($needle);
            if ($needle === '') { continue; }
            foreach ($hay as $value) {
                if ($value === $needle) { return true; }
                if (mb_strlen($needle) >= 2 && mb_strlen($value) >= 2
                    && (str_contains($value, $needle) || str_contains($needle, $value))) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function norm(mixed $value): string
    {
        $value = mb_strtolower(trim((string)(is_scalar($value) ? $value : '')));
        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }
}
