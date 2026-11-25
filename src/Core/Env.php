<?php
declare(strict_types=1);
namespace AllStore\Core;

/** Server-side .env loader. Values are never rendered, logged or exposed. */
final class Env
{
    private static array $file = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;
        if (!is_file($path) || !is_readable($path)) { return; }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) { continue; }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            if ($k === '' || !preg_match('/^[A-Z0-9_]+$/', $k)) { continue; }
            if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
                $v = substr($v, 1, -1);
            } else {
                $v = preg_replace('/\s+#.*$/', '', $v) ?? '';
                $v = trim($v);
            }
            self::$file[$k] = $v;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') { return $v; }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') { return (string)$_SERVER[$key]; }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') { return (string)$_ENV[$key]; }
        return self::$file[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) { return $default; }
        return in_array(strtolower($v), ['1','true','yes','on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return $v !== null && preg_match('/^-?\d+$/', $v) ? (int)$v : $default;
    }

    /** Names only - for diagnostics. Never returns values. */
    public static function names(): array { return array_keys(self::$file); }
    public static function wasLoaded(): bool { return self::$loaded; }
}
