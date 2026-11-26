<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Database;
use AllStore\Core\Helpers;

/** Zone + weight + order-value based shipping. All costs come from the DB. */
final class ShippingService
{
    private static array $ratesCache = [];

    public static function rates(string $country, int $weightGrams, int $subtotalCents, array $location = []): array
    {
        $country = strtoupper(substr(preg_replace('/[^A-Za-z\*]/', '', $country), 0, 3));
        $digitalOnly = !empty($location['digital_only']);
        $cacheKey = $country . '|' . $weightGrams . '|' . $subtotalCents . '|' . md5(Helpers::json([
            'division'=>$location['division'] ?? '', 'district'=>$location['district'] ?? '',
            'area'=>$location['area'] ?? '', 'city'=>$location['city'] ?? '', 'region'=>$location['region'] ?? '',
            'digital_only'=>$digitalOnly,
        ]));
        if (isset(self::$ratesCache[$cacheKey])) { return self::$ratesCache[$cacheKey]; }
        if ($digitalOnly) { return self::$ratesCache[$cacheKey] = [self::digitalRate()]; }
        $rows = Database::all("SELECT sr.*, sz.countries, sz.regions, sz.cities, sz.name AS zone_name
            FROM shipping_rates sr JOIN shipping_zones sz ON sz.id=sr.zone_id
            WHERE sr.is_active=1 AND sz.is_active=1 ORDER BY sr.cost_cents ASC, sr.id ASC");
        $out = [];
        foreach ($rows as $r) {
            $countries = (array)Helpers::decode((string)$r['countries'], []);
            $countries = array_map('strtoupper', $countries);
            if ($countries !== [] && !in_array($country, $countries, true) && !in_array('*', $countries, true)) { continue; }
            $regions = self::normList($r['regions'] ?? '[]');
            $cities = self::normList($r['cities'] ?? '[]');
            if ($regions !== [] && !self::matches($regions, [
                $location['division'] ?? '', $location['division_en'] ?? '',
                $location['district'] ?? '', $location['district_en'] ?? '', $location['region'] ?? '',
            ])) { continue; }
            if ($cities !== [] && !self::matches($cities, [
                $location['area'] ?? '', $location['area_en'] ?? '', $location['city'] ?? '',
            ])) { continue; }
            if ((int)$r['min_weight_grams'] > 0 && $weightGrams < (int)$r['min_weight_grams']) { continue; }
            if ((int)$r['max_weight_grams'] > 0 && $weightGrams > (int)$r['max_weight_grams']) { continue; }
            if ((int)$r['min_order_cents'] > 0 && $subtotalCents < (int)$r['min_order_cents']) { continue; }
            $cost = (int)$r['cost_cents'];
            $freeAbove = (int)$r['free_above_cents'];
            if ($freeAbove > 0 && $subtotalCents >= $freeAbove) { $cost = 0; }
            $out[] = [
                'id'=>$r['id'], 'method'=>$r['method'], 'name'=>$r['name'], 'zone_name'=>$r['zone_name'],
                'cost_cents'=>$cost, 'free'=>$cost === 0,
                'eta_min'=>(int)$r['estimated_days_min'], 'eta_max'=>(int)$r['estimated_days_max'],
                'estimate'=>gmdate('M j', time() + (int)$r['estimated_days_min'] * 86400) . ' – ' . gmdate('M j', time() + (int)$r['estimated_days_max'] * 86400),
            ];
        }
        if ($out === []) {
            $fallback = max(0, (int)Helpers::setting('default_shipping_cents', '799'));
            $out[] = ['id'=>'fallback','method'=>'standard','name'=>'Standard Delivery','zone_name'=>'Default',
                'cost_cents'=>$fallback,'free'=>$fallback === 0,'eta_min'=>3,'eta_max'=>7,
                'estimate'=>gmdate('M j', time()+3*86400) . ' – ' . gmdate('M j', time()+7*86400)];
        }
        return self::$ratesCache[$cacheKey] = $out;
    }
    public static function pick(string $method, string $country, int $weightGrams, int $subtotalCents, bool $freeShipping = false, array $location = []): array
    {
        if (!empty($location['digital_only']) || $method === 'digital') { return self::digitalRate(); }
        $rates = self::rates($country, $weightGrams, $subtotalCents, $location);
        $chosen = $rates[0];
        foreach ($rates as $r) { if ($r['method'] === $method || $r['id'] === $method) { $chosen = $r; break; } }
        if ($freeShipping) { $chosen['cost_cents'] = 0; $chosen['free'] = true; }
        return $chosen;
    }

    public static function digitalRate(): array
    {
        return ['id'=>'digital','method'=>'digital','name'=>'Digital delivery','zone_name'=>'Digital',
            'cost_cents'=>0,'free'=>true,'eta_min'=>0,'eta_max'=>0,'estimate'=>'Instant / email delivery'];
    }

    private static function normList(mixed $raw): array
    {
        $items = is_string($raw) ? (array)Helpers::decode($raw, []) : (array)$raw;
        $out = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) { continue; }
            $item = mb_strtolower(trim((string)$item));
            if ($item !== '') { $out[] = $item; }
        }
        return array_values(array_unique(array_slice($out, 0, 100)));
    }

    private static function matches(array $needles, array $haystack): bool
    {
        $hay = [];
        foreach ($haystack as $value) {
            $value = mb_strtolower(trim((string)(is_scalar($value) ? $value : '')));
            if ($value !== '') { $hay[] = $value; }
        }
        if ($hay === []) { return false; }
        foreach ($needles as $needle) {
            if ($needle === '*') { return true; }
            foreach ($hay as $value) {
                if ($value === $needle || str_contains($value, $needle) || str_contains($needle, $value)) { return true; }
            }
        }
        return false;
    }
}
