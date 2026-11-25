<?php
declare(strict_types=1);
namespace AllStore\Core;

final class Request
{
    private static ?array $body = null;

    public static function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }
    public static function isPost(): bool { return self::method() === 'POST'; }
    public static function path(): string { return (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'); }
    public static function ip(): string
    {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $ip = $fwd !== '' ? trim(explode(',', $fwd)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    public static function ua(): string { return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250); }
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
    private static function body(): array
    {
        if (self::$body !== null) { return self::$body; }
        $merged = $_GET;
        foreach ($_POST as $k => $v) { $merged[$k] = $v; }
        $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        $raw = '';
        if (str_contains($ct, 'application/json') || (str_contains($ct, 'application/x-www-form-urlencoded') && $_POST === [])) {
            $raw = file_get_contents('php://input') ?: '';
        }
        if ($raw !== '') {
            if (str_contains($ct, 'application/json')) {
                $j = json_decode($raw, true);
                if (is_array($j)) { $merged = array_merge($merged, $j); }
            } else {
                parse_str($raw, $parsed);
                if (is_array($parsed)) { $merged = array_merge($merged, $parsed); }
            }
        }
        return self::$body = $merged;
    }
    public static function input(string $key, mixed $default = null): mixed { return self::body()[$key] ?? $default; }
    public static function has(string $key): bool { return array_key_exists($key, self::body()); }
    public static function str(string $key, string $default = '', int $max = 2000): string
    {
        $v = self::input($key, $default);
        if (!is_scalar($v)) { return $default; }
        return mb_substr(trim((string)$v), 0, $max);
    }
    public static function int(string $key, int $default = 0, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $v = self::input($key, $default);
        if (!is_scalar($v) || !preg_match('/^-?\d+$/', (string)$v)) { return $default; }
        return max($min, min($max, (int)$v));
    }
    public static function float(string $key, float $default = 0.0): float
    {
        $v = self::input($key, $default);
        return is_scalar($v) && is_numeric((string)$v) ? (float)$v : $default;
    }
    public static function bool(string $key, bool $default = false): bool
    {
        if (!self::has($key)) { return $default; }
        $v = self::input($key);
        return in_array(strtolower((string)$v), ['1','true','yes','on'], true);
    }
    public static function email(string $key): string
    {
        $v = strtolower(self::str($key, '', 254));
        return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
    }
    public static function slug(string $key): string
    {
        $v = self::str($key, '', 150);
        return preg_match('/^[a-z0-9][a-z0-9\-]*$/', $v) ? $v : '';
    }
    public static function id(string $key): string
    {
        $v = self::str($key, '', 64);
        return preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $v) ? $v : '';
    }
    public static function ids(string $key): array
    {
        $v = self::input($key, []);
        if (!is_array($v)) { return []; }
        $out = [];
        foreach ($v as $x) { if (is_string($x) && preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $x)) { $out[] = $x; } }
        return array_values(array_unique(array_slice($out, 0, 200)));
    }
    public static function csrf(): string
    {
        $h = (string)Config::get('security.csrf_header');
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $h));
        return (string)($_SERVER[$key] ?? self::str('csrf_token', '', 200));
    }
    public static function expectPost(): void
    {
        if (!self::isPost()) { throw new AppException('Method not allowed.', 405); }
    }
    public static function all(): array { return self::body(); }
}
