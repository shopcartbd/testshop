<?php
declare(strict_types=1);
/**
 * Single application entrypoint shared by PHP pages, API handlers and CLI scripts.
 */
if (defined('ALLSTORE_BOOTSTRAPPED')) { return; }
define('ALLSTORE_BOOTSTRAPPED', true);
define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'AllStore\\')) { return; }
    $path = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    if (is_file($path)) { require $path; }
});

use AllStore\Core\Env;
use AllStore\Core\Config;
use AllStore\Core\Security;
use AllStore\Core\Session;
use AllStore\Core\Logger;
use AllStore\Core\Response;
use AllStore\Core\AppException;
use AllStore\Core\Request;

Env::load(APP_ROOT . '/.env');
Config::init();
date_default_timezone_set((string)Config::get('app.timezone'));
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(static function (\Throwable $e): void {
    Logger::exception($e);
    $isApi = str_starts_with(Request::path(), '/api/');
    $status = $e instanceof AppException ? $e->httpStatus() : 500;
    $message = $e instanceof AppException ? $e->getMessage() : 'Something went wrong. Our team has been notified.';
    if ($isApi || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) {
        Response::json(false, $message, [], $e instanceof AppException ? $e->errors() : [], $status);
    }
    http_response_code($status);
    $GLOBALS['error_message'] = $message;
    $GLOBALS['error_code'] = $status;
    if (!headers_sent()) { Security::sendHeaders(); }
    $page = $status === 404 ? APP_ROOT . '/pages/404.php' : ($status === 403 ? APP_ROOT . '/pages/403.php' : APP_ROOT . '/pages/500.php');
    if (is_file($page)) { require $page; }
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    Logger::log('error', 'php.error: ' . $message, ['file'=>basename($file),'line'=>$line]);
    return true;
});

Security::sendHeaders();
// /api/health and the temporary one-time setup route must be able to report
// database/schema problems without first requiring a sessions table.
// All other routes keep DB-backed sessions.
$isHealthCheck = PHP_SAPI !== 'cli' && (
    str_starts_with(Request::path(), '/api/health') ||
    str_starts_with(Request::path(), '/api/setup/')
);
if (!$isHealthCheck) {
    Session::start();
    Session::prune();
}
if (Config::get('app.debug')) { Response::noCache(); }
