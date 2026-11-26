<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\CartService;
use AllStore\Services\LocationService;
use AllStore\Services\ShippingService;
api_method('GET'); api_rate('checkout.rates', 120, 60);
$country = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', Request::str('country', '', 2)) ?: LocationService::defaultCountry(), 0, 2));
$resolved = LocationService::resolve($country, Request::id('division_id'), Request::id('district_id'), Request::id('area_id'));
if (!empty($resolved['errors'])) { Response::fail('Choose a valid delivery area.', $resolved['errors'], 422); }
$location = $resolved['location'];
$location['digital_only'] = !CartService::needsShipping();
$method = Request::str('shipping_method', '', 40);
$totals = CartService::totals($method !== '' ? $method : null, $country, $location);
$weight = array_sum(array_map(static fn($i) => (int)$i['weight_grams'] * (int)$i['quantity'], $totals['items']));
$subtotal = max(0, (int)$totals['subtotal_cents'] - (int)$totals['discount_cents']);
$rates = array_map(static function (array $rate): array {
    $rate['cost_label'] = Helpers::money((int)($rate['cost_cents'] ?? 0));
    return $rate;
}, ShippingService::rates($country, $weight, $subtotal, $location));
Response::ok('Delivery options updated.', [
    'rates' => $rates,
    'totals' => api_formatted_totals($totals),
    'country' => $country,
    'location' => $location,
    'requires_shipping' => (bool)($totals['requires_shipping'] ?? true),
    'selected_shipping_method' => (string)($totals['shipping']['method'] ?? ''),
]);
