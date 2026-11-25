<?php
declare(strict_types=1);
/**
 * Vercel / Container preflight — run BEFORE deploying, from a trusted machine:
 *
 *   APP_ENV=production APP_KEY=... DB_DRIVER=pgsql \
 *   DATABASE_URL='postgresql://...:6543/postgres?sslmode=require' \
 *   APP_URL=https://your-domain.example php scripts/vercel_preflight.php
 *
 * This script deliberately does NOT load includes/bootstrap.php: it must be
 * usable even when the production database is not reachable yet. It prints
 * presence/absence only — never secret values.
 */
$root = dirname(__DIR__);
$fail = 0;
$warn = 0;

function env(string $key): string
{
    $v = getenv($key);
    return $v === false ? '' : trim((string)$v);
}
function line(string $status, string $message): void
{
    echo str_pad('[' . $status . ']', 8) . $message . PHP_EOL;
}
function ok(string $m): void { line('OK', $m); }
function warn(string $m): void { global $warn; $warn++; line('WARN', $m); }
function fail(string $m): void { global $fail; $fail++; line('FAIL', $m); }

echo 'AllStore Vercel preflight — ' . gmdate('c') . PHP_EOL;
echo 'PHP ' . PHP_VERSION . ' | SAPI ' . PHP_SAPI . PHP_EOL . str_repeat('-', 64) . PHP_EOL;

// --- Required runtime environment -----------------------------------------
$appEnv = strtolower(env('APP_ENV') ?: 'local');
if ($appEnv === 'production') { ok('APP_ENV=production'); }
else { warn('APP_ENV is not production (' . $appEnv . '); the Docker image sets it at build time.'); }

if ($appEnv === 'production' && strtolower(env('APP_DEBUG') ?: 'false') === 'true') {
    fail('APP_DEBUG=true in production; set APP_DEBUG=false.');
} else { ok('APP_DEBUG is not true in production'); }

if (env('APP_KEY') !== '') { ok('APP_KEY is set'); }
else { fail('APP_KEY is missing; production bootstrap will return HTTP 500.'); }

$driver = strtolower(env('DB_DRIVER') ?: 'local');
if ($driver === 'pgsql') { ok('DB_DRIVER=pgsql'); }
else { fail('DB_DRIVER must be pgsql in production (got "' . $driver . '").'); }

$databaseUrl = env('DATABASE_URL');
$supabaseHost = env('SUPABASE_DB_HOST');
$supabasePass = env('SUPABASE_DB_PASSWORD');
if ($databaseUrl !== '') { ok('DATABASE_URL is set'); }
elseif ($supabaseHost !== '' && $supabasePass !== '') { ok('SUPABASE_DB_* host/password are set'); }
else { fail('No PostgreSQL DSN: set DATABASE_URL or SUPABASE_DB_HOST/_PASSWORD.'); }

if ($databaseUrl !== '' && !str_contains($databaseUrl, 'sslmode=require')) {
    warn('DATABASE_URL has no sslmode=require; the app still enforces require/verify-ca/verify-full.');
}
if ($databaseUrl !== '' && str_contains($databaseUrl, ':6543')) {
    ok('Transaction pooler port 6543 detected');
    if (strtolower(env('DB_POOL_MODE') ?: 'transaction') !== 'transaction') {
        warn('DB_POOL_MODE is not transaction; emulated prepares may be off on 6543.');
    }
}

$appUrl = env('APP_URL');
if ($appUrl === '' || $appUrl === 'http://localhost:8080') {
    fail('APP_URL is still the localhost default; set the real https:// domain.');
} elseif (!str_starts_with($appUrl, 'https://')) {
    warn('APP_URL is not https://; Stripe success/cancel URLs will use it verbatim.');
} else { ok('APP_URL is an https:// URL'); }

$pool = strtolower(env('DB_POOL_MODE') ?: 'transaction');
if (in_array($pool, ['transaction', 'session'], true)) { ok('DB_POOL_MODE=' . $pool); }
else { warn('DB_POOL_MODE has an unexpected value; defaulting to transaction.'); }

// --- PHP extensions --------------------------------------------------------
$required = ['pdo_pgsql', 'curl', 'mbstring', 'gd', 'intl', 'zip', 'opcache', 'xml', 'fileinfo'];
foreach ($required as $ext) {
    if (extension_loaded($ext)) { ok('ext-' . $ext . ' loaded'); }
    else { fail('ext-' . $ext . ' is missing; add it to install-php-extensions.'); }
}
if (function_exists('curl_init')) { ok('curl_init() available for server-side HTTP'); }
else { fail('curl_init() unavailable; Storage/Stripe/Mail calls will be disabled.'); }

// --- Repository / container configuration ---------------------------------
$files = ['Dockerfile', 'Dockerfile.vercel', 'Caddyfile', 'vercel.json', '.dockerignore'];
foreach ($files as $f) {
    if (is_file($root . '/' . $f)) { ok($f . ' present'); }
    else { fail($f . ' missing'); }
}
$docker = (string)file_get_contents($root . '/Dockerfile');
if (str_contains($docker, 'dunglas/frankenphp:1.14.0-php8.4-trixie')) { ok('Dockerfile base image is pinned'); }
else { warn('Dockerfile base image is not the pinned 1.14.0-php8.4-trixie tag.'); }
if (str_contains($docker, 'PORT=80') && str_contains($docker, '"frankenphp", "run"') && str_contains($docker, '/etc/caddy/Caddyfile')) { ok('Dockerfile listens via FrankenPHP on $PORT'); }
else { fail('Dockerfile CMD/PORT shape changed; verify $PORT handling.'); }

$caddy = (string)file_get_contents($root . '/Caddyfile');
foreach (['order php_server before file_server', ':{$PORT:80}', 'root * /app', 'env REQUEST_URI {http.request.orig_uri}'] as $marker) {
    if (str_contains($caddy, $marker)) { ok('Caddyfile: ' . $marker); }
    else { fail('Caddyfile missing: ' . $marker); }
}

$vercelRaw = (string)file_get_contents($root . '/vercel.json');
$vercel = json_decode($vercelRaw, true);
if (is_array($vercel) && ($vercel['version'] ?? null) === 2) { ok('vercel.json is valid v2 config'); }
else { fail('vercel.json invalid or not version 2'); }
if (str_contains($vercelRaw, 'immutable')) { fail('vercel.json still uses immutable caching on unversioned assets'); }
else { ok('Asset cache is revalidatable (no immutable on unversioned files)'); }

$ignore = (string)file_get_contents($root . '/.dockerignore');
foreach (['.env', 'storage/*.sqlite', 'storage/app.key'] as $needle) {
    if (str_contains($ignore, $needle)) { ok('.dockerignore excludes ' . $needle); }
    else { fail('.dockerignore missing ' . $needle); }
}
if (is_file($root . '/.env')) { warn('Local .env exists in this checkout; .dockerignore keeps it out of the image.'); }

// --- Optional integrations (informational only) ---------------------------
foreach ([
    'SUPABASE_URL' => 'Supabase Storage / signed URLs',
    'SUPABASE_SECRET_KEY' => 'Supabase server-side secret',
    'MAIL_PROVIDER_URL' => 'Email delivery',
    'PAYMENT_GATEWAY_SECRET' => 'Stripe online payments',
] as $k => $label) {
    if (env($k) === '') { warn($label . ' not configured (' . $k . ' empty); feature stays disabled.'); }
    else { ok($label . ' configured'); }
}

echo str_repeat('-', 64) . PHP_EOL;
echo 'Result: ' . ($fail === 0 ? 'READY' : 'NOT READY') . ' | failures=' . $fail . ' | warnings=' . $warn . PHP_EOL;
echo 'Reminder: apply database/migrations/*.sql in Supabase before the first request.' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
