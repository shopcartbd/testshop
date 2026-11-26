<?php
declare(strict_types=1);
namespace AllStore\Core;

final class Helpers
{
    /** Whole settings table, loaded once per request (was one query per key). */
    private static ?array $settingsCache = null;

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
    public static function token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
    public static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
    public static function fromOffset(int $seconds): string { return gmdate('Y-m-d\TH:i:s\Z', time() + $seconds); }
    public static function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9\s\-_]+/', '', $s) ?? '';
        $s = preg_replace('/[\s_]+/', '-', $s) ?? '';
        return trim(preg_replace('/-+/', '-', $s) ?? '', '-') ?: 'item-' . substr(hash('sha256', $s), 0, 8);
    }
    public static function currencySymbol(): string
    {
        return match (strtoupper((string)Config::get('app.currency'))) {
            'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'BDT' => '৳', 'THB' => '฿', 'INR' => '₹', default => '$',
        };
    }
    public static function money(int $cents, bool $symbol = true): string
    {
        $v = number_format($cents / 100, 2, '.', ',');
        return ($symbol ? self::currencySymbol() : '') . $v;
    }
    public static function url(string $path = '/'): string
    {
        if ($path === '' ) { $path = '/'; }
        if (str_starts_with($path, 'http') || str_starts_with($path, '//')) { return $path; }
        return '/' . ltrim($path, '/');
    }
    public static function asset(string $path): string { return self::url('/assets/' . ltrim($path, '/')); }
    public static function e(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function json(mixed $v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    public static function decode(?string $s, mixed $default = []): mixed
    {
        if ($s === null || $s === '') { return $default; }
        try { $d = json_decode($s, true, 512, JSON_THROW_ON_ERROR); return is_array($d) || is_scalar($d) ? $d : $default; }
        catch (\Throwable) { return $default; }
    }
    public static function truncate(string $s, int $n = 120): string
    {
        $s = trim(strip_tags($s));
        return mb_strlen($s) <= $n ? $s : mb_substr($s, 0, $n - 1) . '…';
    }
    public static function percentOff(int $price, int $compare): int
    {
        return ($compare > $price && $compare > 0) ? (int)round((($compare - $price) / $compare) * 100) : 0;
    }
    public static function orderNumber(): string
    {
        return 'AS-' . gmdate('Ymd') . '-' . strtoupper(substr(self::token(5), 0, 8));
    }
    public static function ticketNumber(): string { return 'TKT-' . gmdate('Ymd') . '-' . strtoupper(substr(self::token(4), 0, 6)); }
    public static function setting(string $key, string $default = ''): string
    {
        if (self::$settingsCache === null) {
            self::$settingsCache = [];
            try {
                foreach (Database::all('SELECT key, value FROM settings') as $row) {
                    self::$settingsCache[(string)$row['key']] = (string)($row['value'] ?? '');
                }
            } catch (\Throwable) {
                self::$settingsCache = [];
            }
        }
        $v = self::$settingsCache[$key] ?? null;
        return ($v === null || $v === '') ? $default : (string)$v;
    }
    public static function setSetting(string $key, string $value, string $group = 'general'): void
    {
        Database::exec("INSERT INTO settings(key,value,group_name,updated_at) VALUES(:k,:v,:g,:t) ON CONFLICT(key) DO UPDATE SET value=:v2, group_name=:g2, updated_at=:t2",
            ['k'=>$key,'v'=>$value,'g'=>$group,'t'=>self::now(),'v2'=>$value,'g2'=>$group,'t2'=>self::now()]);
        if (self::$settingsCache !== null) { self::$settingsCache[$key] = $value; }
    }

    /** Allow only internal media paths or syntactically safe HTTPS URLs. */
    public static function safeMediaUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') { return ''; }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $path = (string)parse_url($url, PHP_URL_PATH);
            return preg_match('#^/(assets|storage/uploads)/[A-Za-z0-9/_\.\-]+$#', $path) ? $url : '';
        }
        if (preg_match('#^supabase-private:[A-Za-z0-9/_\.\-]{1,300}$#', $url)) { return $url; }
        if (!preg_match('#^https://[A-Za-z0-9\.\-]+/[^\s<>]*$#i', $url)) { return ''; }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '' || preg_match('/(^|\.)(localhost|local|internal|test)$/i', $host)) { return ''; }
        return $url;
    }

    /** Snapshot details stored with an order/cart item: options, bundle and delivery file. */
    public static function orderItemDetails(array $options): array
    {
        $display = []; $download = ''; $bundle = []; $kind = '';
        foreach ($options as $key => $value) {
            $key = (string)$key;
            if (str_starts_with($key, '__')) {
                if ($key === '__download_url') { $download = self::safeMediaUrl(self::optionText($value)); }
                elseif ($key === '__bundle_items') { $bundle = self::bundleItems($value); }
                elseif ($key === '__product_kind') { $kind = self::optionText($value); }
                continue;
            }
            if (is_array($value) && (array_key_exists('label', $value) || array_key_exists('value', $value))) {
                $label = mb_substr(trim((string)($value['label'] ?? self::optionLabel($key))), 0, 120);
                $text = self::optionText($value['value'] ?? '');
            } else {
                $label = self::optionLabel($key);
                $text = self::optionText($value);
            }
            if ($text !== '') { $display[] = ['label'=>$label !== '' ? $label : 'Option','value'=>$text]; }
        }
        return [
            'options'=>$display, 'download_url'=>$download, 'bundle_items'=>$bundle,
            'product_kind'=>$kind !== '' ? $kind : null,
        ];
    }

    public static function orderStockVariantId(array $item): string
    {
        $raw = $item['options'] ?? null;
        if (is_string($raw)) { $raw = (array)self::decode($raw, []); }
        $options = is_array($raw) ? $raw : [];
        $base = trim((string)($options['__base_variant_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $base)) { return $base; }
        $variant = (string)($item['variant_id'] ?? '');
        return str_starts_with($variant, 'custom-') ? '' : $variant;
    }

    public static function canShowDigitalDownloads(array $order): bool
    {
        $status = (string)($order['status'] ?? '');
        $payment = (string)($order['payment_status'] ?? '');
        if (in_array($status, ['cancelled','refunded','returned'], true)) { return false; }
        return in_array($payment, ['paid','authorized','cod_pending','partially_refunded'], true)
            || in_array($status, ['confirmed','processing','packed','shipped','delivered'], true);
    }

    private static function optionText(mixed $value): string
    {
        if (is_bool($value)) { return $value ? 'Yes' : 'No'; }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $v) { $t = self::optionText($v); if ($t !== '') { $parts[] = $t; } }
            return mb_substr(implode(', ', $parts), 0, 300);
        }
        if (!is_scalar($value)) { return ''; }
        return mb_substr(trim((string)$value), 0, 300);
    }

    private static function optionLabel(string $key): string
    {
        $key = preg_replace('/[^A-Za-z0-9_\- ]+/', '', $key) ?? '';
        return mb_substr(ucwords(trim(str_replace(['_','-'], ' ', $key))), 0, 120) ?: 'Option';
    }

    private static function bundleItems(mixed $raw): array
    {
        if (is_string($raw)) { $raw = self::decode($raw, []); }
        if (!is_array($raw)) { return []; }
        $out = [];
        foreach (array_slice($raw, 0, 50) as $row) {
            if (!is_array($row)) { $row = ['name'=>(string)$row]; }
            $name = mb_substr(trim((string)($row['name'] ?? '')), 0, 160);
            if ($name === '') { continue; }
            $out[] = [
                'name'=>$name,
                'sku'=>mb_substr(trim((string)($row['sku'] ?? '')), 0, 64),
                'qty'=>max(1, min(999, (int)($row['qty'] ?? 1))),
            ];
        }
        return $out;
    }

    public static function timeAgo(string $iso): string
    {
        $t = strtotime($iso) ?: time();
        $d = time() - $t;
        if ($d < 60) { return 'just now'; }
        if ($d < 3600) { return floor($d/60) . ' min ago'; }
        if ($d < 86400) { return floor($d/3600) . ' hr ago'; }
        if ($d < 2592000) { return floor($d/86400) . ' days ago'; }
        return gmdate('M j, Y', $t);
    }
    public static function dateNice(?string $iso): string
    {
        if (!$iso) { return '—'; }
        $t = strtotime($iso);
        return $t ? gmdate('M j, Y', $t) : '—';
    }
}
