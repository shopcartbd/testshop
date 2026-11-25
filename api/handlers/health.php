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
$dbUser = '';
$dbOwner = null;
$canCreate = null;
$canUsage = null;
$publicTables = null;
$dbSuper = null;
$sessionPriv = ['select' => null, 'insert' => null, 'update' => null, 'delete' => null, 'settings_insert' => null];
$sessionWriteOk = null;
$sessionWriteError = '';

try {
    Database::pdo();
    $connected = (bool)Database::value('SELECT 1');
    if ($connected) {
        if (Database::driver() === 'pgsql') {
            $schema = (int)Database::value("SELECT CASE WHEN to_regclass('public.sessions') IS NOT NULL THEN 1 ELSE 0 END") === 1;
            $dbUser = (string)Database::value('SELECT current_user');
            $dbOwner = (string)Database::value("SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname='public'");
            $canCreate = (int)Database::value("SELECT CASE WHEN has_schema_privilege(current_user, 'public', 'CREATE') THEN 1 ELSE 0 END") === 1;
            $canUsage = (int)Database::value("SELECT CASE WHEN has_schema_privilege(current_user, 'public', 'USAGE') THEN 1 ELSE 0 END") === 1;
            $publicTables = (int)Database::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'");
            $dbSuper = (int)Database::value("SELECT CASE WHEN rolsuper THEN 1 ELSE 0 END FROM pg_roles WHERE rolname = current_user") === 1;
            $sessionPriv['select'] = (int)Database::value("SELECT CASE WHEN has_table_privilege(current_user, 'public.sessions', 'SELECT') THEN 1 ELSE 0 END") === 1;
            $sessionPriv['insert'] = (int)Database::value("SELECT CASE WHEN has_table_privilege(current_user, 'public.sessions', 'INSERT') THEN 1 ELSE 0 END") === 1;
            $sessionPriv['update'] = (int)Database::value("SELECT CASE WHEN has_table_privilege(current_user, 'public.sessions', 'UPDATE') THEN 1 ELSE 0 END") === 1;
            $sessionPriv['delete'] = (int)Database::value("SELECT CASE WHEN has_table_privilege(current_user, 'public.sessions', 'DELETE') THEN 1 ELSE 0 END") === 1;
            $sessionPriv['settings_insert'] = (int)Database::value("SELECT CASE WHEN has_table_privilege(current_user, 'public.settings', 'INSERT') THEN 1 ELSE 0 END") === 1;
        } else {
            $schema = (bool)Database::value("SELECT name FROM sqlite_master WHERE type='table' AND name='sessions'");
            $publicTables = (int)Database::value("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        }

        // Reproduce Session::create() inside a rolled-back transaction. This
        // catches missing grants, FK/constraint problems and prepare-mode issues
        // without leaving a row behind or exposing secret values.
        try {
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Database::insert('sessions', [
                'id' => bin2hex(random_bytes(16)),
                'user_id' => null,
                'data' => '{"health":"1"}',
                'csrf_token' => bin2hex(random_bytes(12)),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'allstore-health',
                'created_at' => $now,
                'last_activity' => $now,
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 300),
            ]);
            $pdo->rollBack();
            $sessionWriteOk = true;
        } catch (\Throwable $e) {
            $sessionWriteOk = false;
            $msg = (string)$e->getMessage();
            $msg = preg_replace('/\b(pass\w*|pwd|token|key)=\S+/i', '[REDACTED]', $msg) ?? $msg;
            $msg = preg_replace('#postgres(?:ql)?://\S+#i', '[REDACTED]', $msg) ?? $msg;
            $sessionWriteError = mb_substr($msg, 0, 250);
            try { Database::pdo()->rollBack(); } catch (\Throwable) {}
        }
    }
} catch (\Throwable) {
    $dbError = 'connection_or_privilege_check_failed';
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
$healthy = $connected && $schema && $sessionWriteOk !== false;
$data = [
    'status' => $healthy ? 'ok' : 'degraded',
    'php' => PHP_VERSION,
    'environment' => (string)Config::get('app.env'),
    'database' => $connected ? Database::driver() : 'unavailable',
    'database_connected' => $connected,
    'schema_ready' => $schema,
    'database_error' => $dbError,
    'session_write_test' => $sessionWriteOk,
    'session_write_error' => $sessionWriteError,
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
        'db_current_user' => $dbUser,
        'db_public_schema_owner' => $dbOwner,
        'db_can_create_public' => $canCreate,
        'db_can_usage_public' => $canUsage,
        'db_public_tables' => $publicTables,
        'db_role_superuser' => $dbSuper,
        'db_sessions_select' => $sessionPriv['select'],
        'db_sessions_insert' => $sessionPriv['insert'],
        'db_sessions_update' => $sessionPriv['update'],
        'db_sessions_delete' => $sessionPriv['delete'],
        'db_settings_insert' => $sessionPriv['settings_insert'],
        'supabase_url_present' => (string)Config::get('supabase.url') !== '',
        'supabase_secret_present' => (string)Config::get('supabase.secret') !== '',
    ],
];
Response::json($healthy, $healthy ? 'Health check completed.' : 'Service is degraded.', $data, [], $healthy ? 200 : 503);
