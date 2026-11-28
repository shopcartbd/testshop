<?php
declare(strict_types=1);
namespace AllStore\Core;

final class Config
{
    private static array $c = [];
    private static string $databaseUrlSource = 'none';

    public static function init(): void
    {
        // Vercel injects VERCEL_ENV even when the operator only supplied APP_*/-style
        // names in the dashboard. Accept it as a fallback, never as an override.
        $env = strtolower(Env::get('APP_ENV', '') ?: '');
        if ($env === '') {
            $vercelEnv = strtolower(Env::get('VERCEL_ENV', '') ?: '');
            $env = $vercelEnv === 'production' ? 'production'
                : ($vercelEnv === 'preview' ? 'staging' : 'local');
        }
        if (!in_array($env, ['local','staging','production'], true)) { $env = 'local'; }

        $dbUrl = static::databaseUrl();
        $driver = strtolower(Env::get('DB_DRIVER', '') ?: '');
        if ($driver === '') {
            // Supabase/Vercel integrations commonly name the DSN SUPABASE_DB_URL
            // or POSTGRES_URL rather than DATABASE_URL. Infer pgsql when present.
            $discreteHost = Env::get('SUPABASE_DB_HOST', '') ?: '';
            $driver = ($dbUrl !== '' || $discreteHost !== '') ? 'pgsql' : 'local';
        }
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
                'url' => static::appUrl(),
                'timezone' => Env::get('APP_TIMEZONE', 'UTC') ?: 'UTC',
                'currency' => strtoupper(Env::get('APP_CURRENCY', 'BDT') ?: 'BDT'),
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
                'url' => static::supabaseUrl($dbUrl),
                'secret' => Env::get('SUPABASE_SECRET_KEY', '') ?: Env::get('SUPABASE_SERVICE_ROLE_KEY', '') ?: '',
                'public_bucket' => Env::get('SUPABASE_STORAGE_BUCKET', 'product-images') ?: 'product-images',
                'private_bucket' => Env::get('SUPABASE_PRIVATE_BUCKET', 'private-documents') ?: 'private-documents',
            ],
            'cloudinary' => static::cloudinary(),
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

    /**
     * Cloudinary credentials (server-side only). Discrete variables win; a
     * single CLOUDINARY_URL=cloudinary://key:secret@cloud is also accepted.
     * Values are never rendered or logged.
     */
    private static function cloudinary(): array
    {
        $cloud = trim((string)(Env::get('CLOUDINARY_CLOUD_NAME', '') ?: ''));
        $key = trim((string)(Env::get('CLOUDINARY_API_KEY', '') ?: ''));
        $secret = trim((string)(Env::get('CLOUDINARY_API_SECRET', '') ?: ''));
        $url = trim((string)(Env::get('CLOUDINARY_URL', '') ?: ''));
        if (($cloud === '' || $key === '' || $secret === '') && $url !== ''
            && preg_match('#^cloudinary://([^:]+):([^@]+)@([A-Za-z0-9_\-]+)$#', $url, $m) === 1) {
            $key = $key !== '' ? $key : rawurldecode($m[1]);
            $secret = $secret !== '' ? $secret : rawurldecode($m[2]);
            $cloud = $cloud !== '' ? $cloud : $m[3];
        }
        $folder = preg_replace('#[^A-Za-z0-9_\-/]#', '', (string)(Env::get('CLOUDINARY_UPLOAD_FOLDER', 'allstore/products') ?: 'allstore/products')) ?? '';
        return [
            'cloud' => $cloud,
            'key' => $key,
            'secret' => $secret,
            'folder' => trim($folder, '/') !== '' ? trim($folder, '/') : 'allstore/products',
            'base' => rtrim((string)(Env::get('CLOUDINARY_API_BASE', 'https://api.cloudinary.com/v1_1') ?: 'https://api.cloudinary.com/v1_1'), '/'),
            'configured' => $cloud !== '' && $key !== '' && $secret !== '',
            'source' => $url !== '' && ($cloud === '' || $key === '' || $secret === '') ? 'CLOUDINARY_URL' : 'discrete',
        ];
    }

    /** Name of the env var that supplied the DSN; diagnostics only. */
    public static function databaseUrlSource(): string { return self::$databaseUrlSource; }

    /** Accepted DSN aliases, in priority order. Only PostgreSQL URIs qualify. */
    private static function databaseUrl(): string
    {
        $candidates = ['DATABASE_URL', 'SUPABASE_DB_URL', 'POSTGRES_URL', 'POSTGRES_URL_NON_POOLING'];
        foreach ($candidates as $key) {
            $v = Env::get($key, '') ?: '';
            if ($v !== '' && static::isPostgresUrl($v)) {
                self::$databaseUrlSource = $key;
                return $v;
            }
        }
        self::$databaseUrlSource = (Env::get('SUPABASE_DB_HOST', '') ?: '') !== '' ? 'SUPABASE_DB_*' : 'none';
        return '';
    }

    private static function isPostgresUrl(string $v): bool
    {
        $scheme = strtolower((string)parse_url($v, PHP_URL_SCHEME));
        if ($scheme !== '') { return in_array($scheme, ['postgres','postgresql'], true); }
        return preg_match('#^postgres(?:ql)?://#i', $v) === 1;
    }

    /**
     * Robust DSN parser. parse_url() alone can fail when a database password
     * contains unencoded reserved characters; split on the LAST @ instead.
     */
    private static function parseDbUrl(string $url): ?array
    {
        $url = trim($url);
        if (preg_match('#^postgres(?:ql)?://#i', $url) !== 1) { return null; }
        $rest = preg_replace('#^postgres(?:ql)?://#i', '', $url, 1) ?? '';
        $at = strrpos($rest, '@');
        if ($at === false) { return null; }
        $auth = substr($rest, 0, $at);
        $tail = substr($rest, $at + 1);
        $colon = strpos($auth, ':');
        if ($colon === false) { return null; }
        $user = rawurldecode(substr($auth, 0, $colon));
        $pass = rawurldecode(substr($auth, $colon + 1));
        $p = parse_url('http://' . $tail);
        if (!is_array($p) || empty($p['host'])) { return null; }
        parse_str((string)($p['query'] ?? ''), $q);
        $ssl = (string)($q['sslmode'] ?? Env::get('SUPABASE_DB_SSLMODE', 'require') ?: 'require');
        if (!in_array($ssl, ['require','verify-ca','verify-full'], true)) { $ssl = 'require'; }
        return [
            'user' => $user,
            'pass' => $pass,
            'host' => (string)$p['host'],
            'port' => (int)($p['port'] ?? 5432),
            'dbname' => ltrim((string)($p['path'] ?? '/postgres'), '/'),
            'ssl' => $ssl,
        ];
    }

    private static function appUrl(): string
    {
        $url = rtrim(Env::get('APP_URL', '') ?: '', '/');
        $host = $url !== '' ? strtolower((string)parse_url($url, PHP_URL_HOST)) : '';
        if ($url === '' || in_array($host, ['', 'localhost', '127.0.0.1', '[::1]'], true)) {
            $vercelUrl = Env::get('VERCEL_URL', '') ?: '';
            $httpHost = Env::get('HTTP_HOST', '') ?: '';
            if ($vercelUrl !== '') { return 'https://' . $vercelUrl; }
            if ($httpHost !== '' && preg_match('/(localhost|127\.0\.0\.1)/i', $httpHost) !== 1) {
                return 'https://' . $httpHost;
            }
            return 'http://localhost:8080';
        }
        return $url;
    }

    /** Derive the Supabase project URL when only a DB URI was supplied. */
    private static function supabaseUrl(string $dbUrl): string
    {
        foreach (['SUPABASE_URL', 'SUPABASE_PROJECT_URL', 'NEXT_PUBLIC_SUPABASE_URL'] as $key) {
            $v = rtrim(Env::get($key, '') ?: '', '/');
            if ($v !== '') { return $v; }
        }
        // Some integrations store the REST URL in SUPABASE_DB_URL by mistake.
        $candidate = Env::get('SUPABASE_DB_URL', '') ?: '';
        if ($candidate !== '' && preg_match('#^https?://#i', $candidate) === 1) {
            $p = parse_url($candidate);
            if (is_array($p) && !empty($p['host']) && str_ends_with(strtolower((string)$p['host']), '.supabase.co')) {
                return rtrim($candidate, '/');
            }
        }
        $p = $dbUrl !== '' ? static::parseDbUrl($dbUrl) : null;
        if (is_array($p)) {
            if (preg_match('/^postgres\.([a-z0-9]+)$/i', $p['user'], $m) === 1) {
                return 'https://' . $m[1] . '.supabase.co';
            }
            if (preg_match('/^db\.([a-z0-9]+)\.supabase\.co$/i', $p['host'], $m) === 1) {
                return 'https://' . $m[1] . '.supabase.co';
            }
        }
        return '';
    }

    private static function buildDsn(string $driver): string
    {
        if ($driver === 'local') { return 'sqlite:' . dirname(__DIR__, 2) . '/storage/allstore.sqlite'; }
        $url = static::databaseUrl();
        if ($url !== '') {
            $p = static::parseDbUrl($url);
            if (!is_array($p)) { throw new AppException('DATABASE_URL is malformed.', 500); }
            return sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                $p['host'], $p['port'], $p['dbname'], $p['ssl']);
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
        $p = static::parseDbUrl(static::databaseUrl());
        if (is_array($p)) { return $p['user']; }
        return Env::get('SUPABASE_DB_USER', '') ?: '';
    }

    private static function dbPassword(): string
    {
        $p = static::parseDbUrl(static::databaseUrl());
        if (is_array($p)) { return $p['pass']; }
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
