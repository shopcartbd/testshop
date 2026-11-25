<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Config;
use AllStore\Core\Database;
use AllStore\Core\Env;

api_method('POST');

$expected = Env::get('ALLSTORE_SETUP_TOKEN', '') ?: '';
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$provided = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : (string)($_SERVER['HTTP_X_ALLSTORE_SETUP'] ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>'Forbidden.'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

ini_set('display_errors', '1');
error_reporting(E_ALL);
ob_start();
$done = false;
register_shutdown_function(static function () use (&$done): void {
    if ($done) { return; }
    $out = ob_get_clean();
    $last = error_get_last();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['success'=>false,'message'=>'Fatal shutdown','stdout'=>$out];
    if ($last) { $payload['fatal'] = $last; }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
});
$redact = static function (string $s): string {
    $s = preg_replace('#postgres(?:ql)?://[^\s"\'<>]+#i', '[REDACTED]', $s) ?? $s;
    $s = preg_replace('/\b(password|pwd|token|key)=\S+/i', '[REDACTED]', $s) ?? $s;
    return (string)mb_substr($s, 0, 500);
};
$send = static function (array $payload, int $status = 200) use (&$done): void {
    $done = true;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

try {
    if (Database::driver() !== 'pgsql') { $send(['success'=>false,'message'=>'PostgreSQL required'], 409); }
    $pdo = Database::pdo();
    $schemaReady = (int)$pdo->query("SELECT CASE WHEN to_regclass('public.sessions') IS NOT NULL THEN 1 ELSE 0 END")->fetchColumn() === 1;
    $steps = ['schema_ready'=>$schemaReady,'db_source'=>Config::databaseUrlSource()];
    if (!$schemaReady) { $send(['success'=>false,'message'=>'Schema not ready','data'=>$steps], 409); }
    $steps['settings_before'] = (int)Database::value('SELECT COUNT(*) FROM settings');
    $steps['products_before'] = (int)Database::value('SELECT COUNT(*) FROM products');
    $steps['seeded_before'] = Database::value("SELECT value FROM settings WHERE key='seeded_v1'") === '1';
    if (!$steps['seeded_before']) {
        if (!defined('ALLSTORE_SETUP_CONTEXT')) { define('ALLSTORE_SETUP_CONTEXT', true); }
        $GLOBALS['allstore_setup_log'] = [];
        Database::tx(static function (\PDO $db): void {
            require dirname(__DIR__, 2) . '/database/seed.php';
        });
        $steps['seed_log'] = array_values(array_map('trim', $GLOBALS['allstore_setup_log']));
    }
    $steps['seeded_after'] = Database::value("SELECT value FROM settings WHERE key='seeded_v1'") === '1';
    $steps['settings_after'] = (int)Database::value('SELECT COUNT(*) FROM settings');
    $steps['products_after'] = (int)Database::value('SELECT COUNT(*) FROM products');
    $steps['categories_after'] = (int)Database::value('SELECT COUNT(*) FROM categories');
    $steps['brands_after'] = (int)Database::value('SELECT COUNT(*) FROM brands');
    $steps['roles_after'] = (int)Database::value('SELECT COUNT(*) FROM roles');
    $send(['success'=>true,'message'=>'Diagnostic setup completed','data'=>$steps], 200);
} catch (\Throwable $e) {
    $send(['success'=>false,'message'=>'Setup exception','error'=>[
        'class'=>get_class($e),
        'message'=>$redact((string)$e->getMessage()),
        'file'=>basename($e->getFile()),
        'line'=>$e->getLine(),
        'trace'=>array_slice(array_map(static fn($f): string => basename($f['file'] ?? '').':'.($f['line'] ?? ''), $e->getTrace()), 0, 8)
    ]], 500);
}
