<?php
declare(strict_types=1);
namespace AllStore\Core;

final class Config
{
    private static array $c = [];

    public static function init(): void
    {
        $env = strtolower(Env::get('APP_ENV', 'local') ?: 'local');
        if (!in_array($env, ['local','staging','production'], true)) { $env = 'local'; }
        $driver = strtolower(Env::get('DB_DRIVER', 'local') ?: 'local');
        if (!in_array($driver, ['local','pgsql'], true)) {
            throw new AppException('Invalid DB_DRIVER. Use local or pgsql.', 500);
        }
        if ($env === 'production' && $driver === 'local') {
            throw new AppException('Production requires a PostgreSQL database. Configure DATABASE_URL.', 500);
        }
        $root = dirname(__DIR__, 2);
        self::$c = [
            'app' => [
                'name' => Env::get('APP_NAME', 'AllStore') ?: 'AllStore',
                'env' => $env,
                'debug' => $env !== 'production' && Env::bool('APP_DEBUG', true),
                'url' => rtrim(Env::get('APP_URL', 'http://localhost:8080') ?: 'http://localhost:8080', '/'),
                'timezone' => Env::get('APP_TIMEZONE', 'UTC') ?: 'UTC',
                'currency' => strtoupper(Env::get('APP_CURRENCY', 'USD') ?: 'USD'),
                'locale' => Env::get('APP_LOCALE', 'en') ?: 'en',
                'root' => $root,
            ],
            'security' => [
                'session_idle' => max(300, Env::int('SESSION_IDLE_TIMEOUT', 7200)),
                'csrf_header' => Env::get('CSRF_HEADER_NAME', 'X-CSRF-TOKEN') ?: 'X-CSRF-TOKEN',
                'login_max' => max(1, Env::int('RATE_LIMIT_LOGIN_MAX', 5)),
                'login_window' => max(60, Env::int('RATE_LIMIT_LOGIN_WINDOW', 900)),
            ],
            'db' => [
                'driver' => $driver,
                'dsn' => static::buildDsn($driver),
                'user' => static::dbUser(),
                'password' => static::dbPassword(),
                'sqlite_path' => $root . '/storage/allstore.sqlite',
                'pool_mode' => in_array(Env::get('DB_POOL_MODE', 'transaction'), ['transaction','session'], true)
                    ? Env::get('DB_POOL_MODE', 'transaction') : 'transaction',
            ],
            'supabase' => [
                'url' => rtrim(Env::get('SUPABASE_URL', '') ?: '', '/'),
                'secret' => Env::get('SUPABASE_SECRET_KEY', '') ?: Env::get('SUPABASE_SERVICE_ROLE_KEY', '') ?: '',
                'public_bucket' => Env::get('SUPABASE_STORAGE_BUCKET', 'product-images') ?: 'product-images',
                'private_bucket' => Env::get('SUPABASE_PRIVATE_BUCKET', 'private-documents') ?: 'private-documents',
            ],
            'mail' => [
                'url' => Env::get('MAIL_PROVIDER_URL', '') ?: '',
                'key' => Env::get('MAIL_API_KEY', '') ?: '',
                'from' => Env::get('MAIL_FROM_ADDRESS', '') ?: '',
                'name' => Env::get('MAIL_FROM_NAME', 'AllStore') ?: 'AllStore',
            ],
            'payments' => [
                'provider' => strtolower(Env::get('PAYMENT_PROVIDER', '') ?: ''),
                'secret' => Env::get('PAYMENT_GATEWAY_SECRET', '') ?: '',
                'webhook_secret' => Env::get('PAYMENT_WEBHOOK_SECRET', '') ?: '',
            ],
            'log' => [
                'level' => strtolower(Env::get('LOG_LEVEL', 'info') ?: 'info'),
                'path' => $root . '/storage/logs',
            ],
        ];
        if ($env === 'production') {
            if (self::$c['db']['dsn'] === '' || self::$c['db']['password'] === '') {
                throw new AppException('Database configuration is incomplete. Check DATABASE_URL / SUPABASE_DB_*.', 500);
            }
            if (self::secretKey() === '') {
                throw new AppException('APP_KEY is required in production.', 500);
            }
        }
    }

    private static function buildDsn(string $driver): string
    {
        if ($driver === 'local') { return 'sqlite:' . dirname(__DIR__, 2) . '/storage/allstore.sqlite'; }
        $url = Env::get('DATABASE_URL', '') ?: '';
        if ($url !== '') {
            $p = parse_url($url);
            if ($p === false || empty($p['host'])) {
                throw new AppException('DATABASE_URL is malformed.', 500);
            }
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], $p['port'] ?? 5432, ltrim($p['path'] ?? '/postgres', '/'));
            $ssl = 'require';
            parse_str($p['query'] ?? '', $q);
            $ssl = $q['sslmode'] ?? Env::get('SUPABASE_DB_SSLMODE', 'require') ?: 'require';
            if (!in_array($ssl, ['require','verify-ca','verify-full'], true)) { $ssl = 'require'; }
            return $dsn . ';sslmode=' . $ssl;
        }
        $host = Env::get('SUPABASE_DB_HOST', '') ?: '';
        if ($host === '') { return ''; }
        $ssl = Env::get('SUPABASE_DB_SSLMODE', 'require') ?: 'require';
        if (!in_array($ssl, ['require','verify-ca','verify-full'], true)) { $ssl = 'require'; }
        return sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host,
            Env::int('SUPABASE_DB_PORT', 6543), Env::get('SUPABASE_DB_NAME', 'postgres'), $ssl);
    }

    private static function dbUser(): string
    {
        $url = Env::get('DATABASE_URL', '') ?: '';
        if ($url !== '') { return rawurldecode((string)(parse_url($url, PHP_URL_USER) ?? '')); }
        return Env::get('SUPABASE_DB_USER', '') ?: '';
    }

    private static function dbPassword(): string
    {
        $url = Env::get('DATABASE_URL', '') ?: '';
        if ($url !== '') { return rawurldecode((string)(parse_url($url, PHP_URL_PASS) ?? '')); }
        return Env::get('SUPABASE_DB_PASSWORD', '') ?: '';
    }

    /** HMAC pepper for reset tokens. Never sent to the browser. */
    public static function secretKey(): string
    {
        $k = Env::get('APP_KEY', '') ?: '';
        if ($k !== '') { return $k; }
        if (self::get('app.env') !== 'production') {
            $f = dirname(__DIR__, 2) . '/storage/app.key';
            if (is_file($f)) { return (string)file_get_contents($f); }
            $generated = bin2hex(random_bytes(32));
            @file_put_contents($f, $generated);
            @chmod($f, 0600);
            return $generated;
        }
        return '';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cur = self::$c;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) { return $default; }
            $cur = $cur[$part];
        }
        return $cur;
    }

    public static function all(): array { return self::$c; }
}
