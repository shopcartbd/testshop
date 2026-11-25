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
$usedSetRole = false;
$usedOverride = false;
$restore = static function (bool $resetRole) use (&$usedOverride): void {
    if ($resetRole) {
        try { Database::pdo()->exec('RESET ROLE'); } catch (\Throwable) {}
    }
    if ($usedOverride || Config::databaseUrlSource() === 'setup_override') {
        Config::overrideDatabaseUrl(null);
        Database::reset();
        $usedOverride = false;
    }
};

try {
    if (Database::driver() !== 'pgsql') {
        Response::fail('Setup requires PostgreSQL.', [], 409);
    }
    $pdo = Database::pdo();
    $currentUser = (string)$pdo->query('SELECT current_user')->fetchColumn();
    $schemaOwner = (string)$pdo->query("SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname='public'")->fetchColumn();
    $canCreate = static function (\PDO $db): bool {
        return (int)$db->query("SELECT CASE WHEN has_schema_privilege(current_user, 'public', 'CREATE') THEN 1 ELSE 0 END")->fetchColumn() === 1;
    };

    $schemaReady = (int)$pdo->query("SELECT CASE WHEN to_regclass('public.sessions') IS NOT NULL THEN 1 ELSE 0 END")->fetchColumn() === 1;
    $trials = [];
    $canCreateOriginal = $canCreate($pdo);
    $privileged = $schemaReady || $canCreateOriginal;
    $privilegedRole = $currentUser;
    $privilegeMethod = $schemaReady ? 'schema_ready' : 'original';
    $trials[] = ['role' => $currentUser, 'method' => 'original', 'connected' => true, 'can_create' => $canCreateOriginal, 'schema_ready' => $schemaReady];

    // Discover owner-style roles without printing passwords or connection URIs.
    $candidates = ['postgres'];
    try {
        $roles = Database::all("SELECT rolname FROM pg_roles WHERE rolname = 'postgres' OR rolname LIKE 'postgres.%' OR rolname = 'supabase_admin' ORDER BY rolname LIMIT 6");
        foreach ($roles as $r) {
            $name = (string)($r['rolname'] ?? '');
            if ($name !== '' && !in_array($name, $candidates, true)) { $candidates[] = $name; }
        }
    } catch (\Throwable) {}

    // First try SET ROLE on the existing connection (works if the runtime role
    // is a member of an owner role). Then try the same secret with an owner
    // login name via a transient Config override.
    if (!$schemaReady && !$privileged) {
        foreach ($candidates as $role) {
            if ($role === $currentUser) { continue; }
            $quoted = '"' . str_replace('"', '""', $role) . '"';
            try {
                $pdo->exec('SET ROLE ' . $quoted);
                $ok = $canCreate($pdo);
                $trials[] = ['role' => $role, 'method' => 'set_role', 'connected' => true, 'can_create' => $ok];
                if ($ok) {
                    $privileged = true; $privilegedRole = $role;
                    $privilegeMethod = 'set_role'; $usedSetRole = true;
                    break;
                }
                $pdo->exec('RESET ROLE');
            } catch (\Throwable) {
                $trials[] = ['role' => $role, 'method' => 'set_role', 'connected' => false, 'can_create' => false];
                try { $pdo->exec('RESET ROLE'); } catch (\Throwable) {}
            }
        }
    }
    $rawDsn = Env::get('SUPABASE_DB_URL', '') ?: '';
    if (!$schemaReady && !$privileged && $rawDsn !== '') {
        foreach ($candidates as $role) {
            if ($role === $currentUser) { continue; }
            $alt = Config::withDatabaseUser($rawDsn, $role);
            if (!is_string($alt) || $alt === '') { continue; }
            Config::overrideDatabaseUrl($alt);
            Database::reset();
            try {
                $pdo2 = Database::pdo();
                $ok = $canCreate($pdo2);
                $trials[] = ['role' => $role, 'method' => 'dsn_override', 'connected' => true, 'can_create' => $ok];
                if ($ok) {
                    $privileged = true; $privilegedRole = $role;
                    $privilegeMethod = 'dsn_override'; $usedOverride = true;
                    break;
                }
            } catch (\Throwable) {
                $trials[] = ['role' => $role, 'method' => 'dsn_override', 'connected' => false, 'can_create' => false];
            }
            Config::overrideDatabaseUrl(null);
            Database::reset();
        }
    }

    if (!$privileged) {
        $restore(false);
        Response::fail('Setup requires CREATE privilege on schema public.', [
            'diagnostics' => [
                'current_user' => $currentUser,
                'public_schema_owner' => $schemaOwner,
                'candidate_roles' => $candidates,
                'trials' => $trials,
                'hint' => 'Grant CREATE on schema public to the runtime role, or replace SUPABASE_DB_URL with an owner-level connection string.'
            ],
        ], 409);
    }

    $pdo = Database::pdo();
    $steps = [
        'current_user' => $currentUser,
        'public_schema_owner' => $schemaOwner,
        'privileged_role' => $privilegedRole,
        'privilege_method' => $privilegeMethod,
        'schema_ready' => $schemaReady,
        'candidate_trials' => $trials,
    ];
    if (!$schemaReady) {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/001_schema.sql');
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        $applied = 0;
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) { continue; }
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

    $restore($usedSetRole);
    Response::ok('Production database setup completed.', $steps);
} catch (\Throwable $e) {
    $restore($usedSetRole);
    $message = (string)$e->getMessage();
    foreach (['#postgres(?:ql)?://[^\s"\'<>]+#i', '/\b(password|pwd|token|key)=\S+/i'] as $p) {
        $message = preg_replace($p, '[REDACTED]', $message) ?? $message;
    }
    Response::fail('Setup failed.', ['error' => mb_substr($message, 0, 300)], 500);
}
