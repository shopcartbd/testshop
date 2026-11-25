<?php
declare(strict_types=1);
/**
 * Static Vercel-readiness checks. No .env, database or running server needed.
 * Usage: php tests/validate_vercel.php
 */
require_once __DIR__ . '/static_bootstrap.php';

$root = APP_ROOT;
$errors = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$errors, &$checks): void {
    $checks++;
    if (!$condition) { $errors[] = $label; }
};
$read = static fn(string $f): string => is_file($root . '/' . $f) ? (string)file_get_contents($root . '/' . $f) : '';

$docker = $read('Dockerfile');
$dockerVercel = $read('Dockerfile.vercel');
$caddy = $read('Caddyfile');
$vercelRaw = $read('vercel.json');
$vercel = json_decode($vercelRaw, true);
$ignore = $read('.dockerignore');
$gitignore = $read('.gitignore');
$database = $read('src/Core/Database.php');
$logger = $read('src/Core/Logger.php');
$request = $read('src/Core/Request.php');

// Container image / build shape.
$check($docker !== '' && $dockerVercel !== '', 'Dockerfile + Dockerfile.vercel exist');
$check(hash('sha256', $docker) === hash('sha256', $dockerVercel), 'Dockerfile.vercel is byte-identical to Dockerfile');
$check(str_contains($docker, 'FROM dunglas/frankenphp:1.14.0-php8.4-trixie'), 'FrankenPHP base image is pinned to patched 1.14.0/PHP 8.4 Debian');
$check(str_contains($docker, 'install-php-extensions') && str_contains($docker, 'pdo_pgsql') && str_contains($docker, 'curl'), 'Dockerfile installs pdo_pgsql + curl');
$check(str_contains($docker, 'ENV APP_ENV=production') && str_contains($docker, 'PORT=80'), 'Dockerfile defaults to production + $PORT');
$check(str_contains($docker, '"frankenphp", "run"') && str_contains($docker, '/etc/caddy/Caddyfile'), 'Dockerfile starts FrankenPHP with the copied Caddyfile');
$check(str_contains($docker, 'error_log=/proc/self/fd/2'), 'PHP errors go to stderr for Vercel logs');

// Caddy / front-controller routing.
$check(str_contains($caddy, 'order php_server before file_server'), 'Caddy global directive order is present');
$check(str_contains($caddy, ':{$PORT:80}'), 'Caddy listens on injected $PORT');
$check(str_contains($caddy, 'root * /app'), 'Caddy document root matches COPY . /app');
$check(str_contains($caddy, 'php_server'), 'php_server front controller is used');
$check(str_contains($caddy, 'env REQUEST_URI {http.request.orig_uri}'), 'REQUEST_URI is preserved for clean routes');
$check(str_contains($caddy, 'env SCRIPT_NAME /index.php'), 'SCRIPT_NAME is pinned to the front controller');
$check(str_contains($caddy, 'respond @denied 404') && str_contains($caddy, 'respond @privatePhp 404'), 'Source/secret/direct-PHP paths are denied');

// vercel.json platform config.
$check(is_array($vercel) && ($vercel['version'] ?? null) === 2, 'vercel.json is valid version 2');
$check(!str_contains($vercelRaw, 'functions') && !str_contains($vercelRaw, 'runtime'), 'No archived community PHP runtime is active');
$check(!str_contains($vercelRaw, 'immutable'), 'Unversioned assets are not cached as immutable');
$check(str_contains($vercelRaw, 'max-age=3600, must-revalidate'), 'Assets use revalidatable caching');
$check(str_contains($vercelRaw, '/api/(.*)') && str_contains($vercelRaw, 'no-store'), 'API responses are explicitly no-store');
$check(str_contains($vercelRaw, 'Strict-Transport-Security'), 'HSTS header is configured at the edge');

// Secret / runtime exclusions and production-safe code paths.
$check(str_contains($ignore, '.env') && str_contains($gitignore, '.env'), '.env is excluded from Git and Docker context');
$check(str_contains($ignore, 'storage/*.sqlite') && str_contains($ignore, 'storage/app.key'), 'SQLite DB and app.key are excluded from the image');
$check(!str_contains($database, 'SET NAMES'), 'No session-level SET NAMES on the Supabase transaction pooler');
$check(str_contains($database, 'ATTR_EMULATE_PREPARES') && str_contains($database, 'pool_mode'), 'Emulated prepares follow DB_POOL_MODE');
$check(str_contains($logger, "Config::get('app.env') === 'production'") && str_contains($logger, 'error_log('), 'Production logging uses stderr only');
$check(str_contains($request, "explode(',', \$proto)") && str_contains($request, 'HTTP_FORWARDED'), 'Forwarded-proto chains and RFC 7239 Forwarded are handled');
$check(is_file($root . '/scripts/vercel_preflight.php'), 'Vercel preflight script is present');
$check(is_file($root . '/vercel.vercel-php.example.json'), 'Archived community-runtime fallback is kept separate');

echo 'Vercel readiness checks: ' . $checks . PHP_EOL;
if ($errors) {
    fwrite(STDERR, "Vercel readiness failures:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
echo "Vercel readiness passed.\n";
