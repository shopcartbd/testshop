<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Database;
use AllStore\Core\Helpers;

/** Zone + weight + order-value based shipping. All costs come from the DB. */
final class ShippingService
{
    private static array $ratesCache = [];

    public static function rates(string $country, int $weightGrams, int $subtotalCents): array
    {
        $country = strtoupper(substr(preg_replace('/[^A-Za-z\*]/', '', $country), 0, 3));
        $cacheKey = $country . '|' . $weightGrams . '|' . $subtotalCents;
        if (isset(self::$ratesCache[$cacheKey])) { return self::$ratesCache[$cacheKey]; }
        $rows = Database::all("SELECT sr.*, sz.countries, sz.regions, sz.cities, sz.name AS zone_name
            FROM shipping_rates sr JOIN shipping_zones sz ON sz.id=sr.zone_id
            WHERE sr.is_active=1 AND sz.is_active=1 ORDER BY sr.cost_cents ASC, sr.id ASC");
        $out = [];
        foreach ($rows as $r) {
            $countries = (array)Helpers::decode((string)$r['countries'], []);
            $countries = array_map('strtoupper', $countries);
            if ($countries !== [] && !in_array($country, $countries, true) && !in_array('*', $countries, true)) { continue; }
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
    public static function pick(string $method, string $country, int $weightGrams, int $subtotalCents, bool $freeShipping = false): array
    {
        $rates = self::rates($country, $weightGrams, $subtotalCents);
        $chosen = $rates[0];
        foreach ($rates as $r) { if ($r['method'] === $method || $r['id'] === $method) { $chosen = $r; break; } }
        if ($freeShipping) { $chosen['cost_cents'] = 0; $chosen['free'] = true; }
        return $chosen;
    }
}
