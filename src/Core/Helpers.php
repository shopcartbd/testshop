<?php
declare(strict_types=1);
namespace AllStore\Core;

final class Helpers
{
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
        return trim(preg_replace('/-+/', '-', $s) ?? '', '-') ?: 'item-' . substr(self::token(4), 0, 6);
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
        static $cache = [];
        if (array_key_exists($key, $cache)) { return $cache[$key]; }
        try { $v = Database::value('SELECT value FROM settings WHERE key=:k', ['k' => $key]); }
        catch (\Throwable) { $v = null; }
        return $cache[$key] = ($v === null || $v === '') ? $default : (string)$v;
    }
    public static function setSetting(string $key, string $value, string $group = 'general'): void
    {
        Database::exec("INSERT INTO settings(key,value,group_name,updated_at) VALUES(:k,:v,:g,:t) ON CONFLICT(key) DO UPDATE SET value=:v2, group_name=:g2, updated_at=:t2",
            ['k'=>$key,'v'=>$value,'g'=>$group,'t'=>self::now(),'v2'=>$value,'g2'=>$group,'t2'=>self::now()]);
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
