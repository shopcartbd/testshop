<?php
declare(strict_types=1);
/**
 * Lightweight bootstrap for static analysis only.
 *
 * The validators inspect source files and class/route maps; they do not need
 * .env, a database, sessions, cookies, headers or the exception handler.
 * Using this instead of includes/bootstrap.php lets `tests/run.sh` pass on a
 * freshly cloned repository where local secrets and SQLite are intentionally
 * absent.
 */
if (defined('ALLSTORE_STATIC_BOOTSTRAPPED')) { return; }
define('ALLSTORE_STATIC_BOOTSTRAPPED', true);
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__)); }

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'AllStore\\')) { return; }
    $path = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    if (is_file($path)) { require $path; }
});
