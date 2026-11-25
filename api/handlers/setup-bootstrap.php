<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Config;
use AllStore\Core\Database;
use AllStore\Core\Env;
use AllStore\Core\Response;

api_method('POST');

// One-time, token-protected production bootstrap. The token lives only in a
// write-only Vercel env var and is removed immediately after use. No DSN,
// password or token is ever logged or returned.
$expected = Env::get('ALLSTORE_SETUP_TOKEN', '') ?: '';
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$provided = str_starts_with($auth, 'Bearer ')
    ? substr($auth, 7)
    : (string)($_SERVER['HTTP_X_ALLSTORE_SETUP'] ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    Response::fail('Forbidden.', [], 403);
}

$GLOBALS['allstore_setup_log'] = [];
$steps = [];
try {
    $pdo = Database::pdo();
    if (Database::driver() !== 'pgsql') {
        Response::fail('Setup requires PostgreSQL.', [], 409);
    }

    $schemaReady = (int)$pdo->query("SELECT CASE WHEN to_regclass('public.sessions') IS NOT NULL THEN 1 ELSE 0 END")->fetchColumn() === 1;
    if (!$schemaReady) {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/001_schema.sql');
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        $applied = 0;
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) { continue; }
            // Strip whole-line comments; harmless to keep inline SQL comments.
            $statement = implode("\n", array_filter(
                array_map('trim', explode("\n", $statement)),
                static fn(string $l): bool => $l !== '' && !str_starts_with($l, '--')
            ));
            if ($statement === '') { continue; }
            $pdo->exec($statement);
            $applied++;
        }
        $steps['schema_statements'] = $applied;
        $schemaReady = (int)$pdo->query("SELECT CASE WHEN to_regclass('public.sessions') IS NOT NULL THEN 1 ELSE 0 END")->fetchColumn() === 1;
    }
    $steps['schema_ready'] = $schemaReady;
    if (!$schemaReady) { throw new \RuntimeException('Schema verification failed.'); }

    $seeded = Database::value("SELECT value FROM settings WHERE key='seeded_v1'") === '1';
    if (!$seeded) {
        if (!defined('ALLSTORE_SETUP_CONTEXT')) { define('ALLSTORE_SETUP_CONTEXT', true); }
        Database::tx(static function (): void {
            require dirname(__DIR__, 2) . '/database/seed.php';
        });
        $seeded = Database::value("SELECT value FROM settings WHERE key='seeded_v1'") === '1';
    }
    $steps['seeded'] = $seeded;
    $steps['seed_log'] = array_values(array_map('trim', $GLOBALS['allstore_setup_log']));
    $steps['tables'] = (int)Database::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'");
    $steps['products'] = (int)Database::value('SELECT COUNT(*) FROM products');
    $steps['categories'] = (int)Database::value('SELECT COUNT(*) FROM categories');
    $steps['brands'] = (int)Database::value('SELECT COUNT(*) FROM brands');
    $steps['database'] = Database::driver();
    $steps['db_source'] = Config::databaseUrlSource();
    Response::ok('Production database setup completed.', $steps);
} catch (\Throwable $e) {
    $message = preg_replace('/\b(password|pwd|token|key)=\S+/i', '$1=[REDACTED]', $e->getMessage()) ?? 'Setup failed.';
    Response::fail('Setup failed.', ['error' => mb_substr($message, 0, 300)], 500);
}
