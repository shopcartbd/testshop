<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Config;
use AllStore\Core\Database;
use AllStore\Core\Response;
use AllStore\Services\MailService;
use AllStore\Services\PaymentService;
use AllStore\Services\UploadService;
api_method('GET');

$connected = false;
$schema = false;
$dbError = '';
try {
    Database::pdo();
    $connected = (bool)Database::value('SELECT 1');
    if ($connected) {
        $schema = Database::driver() === 'pgsql'
            ? (int)Database::value("SELECT CASE WHEN to_regclass('public.sessions') IS NOT NULL THEN 1 ELSE 0 END") === 1
            : (bool)Database::value("SELECT name FROM sqlite_master WHERE type='table' AND name='sessions'");
    }
} catch (\Throwable) {
    $dbError = 'connection_failed';
}

// Diagnostics only: host / port / presence never secret values.
$dsn = (string)Config::get('db.dsn');
$dsnParts = [];
if (str_starts_with($dsn, 'pgsql:')) {
    foreach (explode(';', substr($dsn, 6)) as $part) {
        if (str_contains($part, '=')) { [$k, $v] = explode('=', $part, 2); $dsnParts[$k] = $v; }
    }
}
$appUrl = (string)Config::get('app.url');
$appHost = (string)parse_url($appUrl, PHP_URL_HOST);
$healthy = $connected && $schema;
$data = [
    'status' => $healthy ? 'ok' : 'degraded',
    'php' => PHP_VERSION,
    'environment' => (string)Config::get('app.env'),
    'database' => $connected ? Database::driver() : 'unavailable',
    'database_connected' => $connected,
    'schema_ready' => $schema,
    'database_error' => $dbError,
    'timestamp' => gmdate('c'),
    'integrations' => [
        'mail' => MailService::configured(),
        'online_payments' => PaymentService::stripeConfigured(),
        'storage' => UploadService::configured(),
    ],
    'config' => [
        'app_url_host' => $appHost,
        'app_debug' => (bool)Config::get('app.debug'),
        'app_key_present' => Config::secretKey() !== '',
        'db_driver' => (string)Config::get('db.driver'),
        'db_source' => Database::driver() === 'local' ? 'local' : Config::databaseUrlSource(),
        'db_host' => $dsnParts['host'] ?? ($connected && Database::driver() === 'local' ? 'sqlite' : ''),
        'db_port' => isset($dsnParts['port']) ? (int)$dsnParts['port'] : null,
        'db_ssl' => $dsnParts['sslmode'] ?? null,
        'db_pool_mode' => (string)Config::get('db.pool_mode'),
        'supabase_url_present' => (string)Config::get('supabase.url') !== '',
        'supabase_secret_present' => (string)Config::get('supabase.secret') !== '',
    ],
];
Response::json($healthy, $healthy ? 'Health check completed.' : 'Service is degraded.', $data, [], $healthy ? 200 : 503);
