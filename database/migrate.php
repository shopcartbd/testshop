<?php
declare(strict_types=1);
/**
 * Applies the portable schema to SQLite (local) or prints instructions for
 * Supabase PostgreSQL. Usage: php database/migrate.php [--reset]
 */
require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';

use AllStore\Core\Env;
use AllStore\Core\Config;
use AllStore\Core\Database;

Env::load(dirname(__DIR__) . '/.env');
Config::init();
$driver = Config::get('db.driver');
if ($driver !== 'local') {
    fwrite(STDOUT, "Driver is '{$driver}'. Apply database/migrations/001_schema.sql, then\n");
    fwrite(STDOUT, "002_rls.sql, 003_storage.sql and 004_least_privilege.sql in the Supabase SQL editor.\n");
    fwrite(STDOUT, "Testing direct connectivity...\n");
    try {
        $v = Database::value('SELECT 1 AS ok');
        fwrite(STDOUT, "Connected. SELECT 1 => " . ($v ?? 'null') . "\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "Connection FAILED: " . preg_replace('/\b(password|pwd|token|key)=\S+/i', '$1=[REDACTED]', $e->getMessage()) . "\n");
        exit(1);
    }
}
$dbFile = Config::get('db.sqlite_path');
if (in_array('--reset', $argv ?? [], true) && is_file($dbFile)) { unlink($dbFile); }
$sql = file_get_contents(__DIR__ . '/migrations/001_schema.sql');
$pdo = Database::pdo();
$pdo->exec($sql);
fwrite(STDOUT, "SQLite schema applied: {$dbFile}\n");
$tables = Database::all("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
fwrite(STDOUT, 'Tables created: ' . count($tables) . "\n");
