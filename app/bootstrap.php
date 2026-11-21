<?php

declare(strict_types=1);:

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/DatabaseSessionHandler.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Orders.php';
require_once __DIR__ . '/AccountServices.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/AdminService.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_name('allstore_session');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if ((string) (getenv('SESSION_DRIVER') ?: 'file') === 'database') {
    session_set_save_handler(new DatabaseSessionHandler(), true);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
$cspNonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
if ($isHttps) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function app_name(): string
{
    return trim((string) (getenv('APP_NAME') ?: 'AllStore')) ?: 'AllStore';
}

function app_url(string $path = '/'): string
{
    $base = rtrim((string) (getenv('APP_URL') ?: ''), '/');
    return $base === '' ? $path : $base . '/' . ltrim($path, '/');
}
