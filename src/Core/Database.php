<?php
declare(strict_types=1);
namespace AllStore\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO layer. Every value is bound through prepare()/execute(); identifiers
 * are validated against an allowlist regex. No user input is ever concatenated
 * into SQL except validated integers for LIMIT/OFFSET.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $txDepth = 0;

    /** Drop the cached connection so a setup-only DSN override can be applied. */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$txDepth = 0;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) { return self::$pdo; }
        $c = Config::get('db');
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($c['driver'] === 'pgsql') {
            // Supavisor transaction mode (port 6543) does not support named
            // server-side prepared statements. Emulated prepares still use the
            // parameterized API and proper driver quoting. Session-mode pooler
            // (5432) uses native prepares.
            $opts[PDO::ATTR_EMULATE_PREPARES] = ($c['pool_mode'] === 'transaction');
            $opts[PDO::ATTR_PERSISTENT] = false;
            self::$pdo = new PDO($c['dsn'], $c['user'], $c['password'], $opts);
            // Do not issue session-level SET/RESET here: Supabase's transaction
            // pooler (port 6543) discards session state between transactions.
            // The connection is already UTF-8; client encoding belongs in the
            // DSN/options if a deployment ever needs to override it.
        } else {
            $opts[PDO::ATTR_EMULATE_PREPARES] = false;
            self::$pdo = new PDO($c['dsn'], null, null, $opts);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = is_int($v) ? PDO::PARAM_INT : (is_bool($v) ? PDO::PARAM_BOOL : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
            $st->bindValue($name, $v, $type);
        }
        $st->execute();
        return $st;
    }

    public static function all(string $sql, array $params = []): array { return self::run($sql, $params)->fetchAll(); }
    public static function first(string $sql, array $params = []): ?array { $r = self::run($sql, $params)->fetch(); return $r === false ? null : $r; }
    public static function value(string $sql, array $params = []): mixed
    {
        $row = self::run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }
    public static function exec(string $sql, array $params = []): int { return self::run($sql, $params)->rowCount(); }

    private static function ident(string $s): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $s)) { throw new \InvalidArgumentException('Invalid identifier'); }
        return $s;
    }

    public static function insert(string $table, array $data): void
    {
        if ($data === []) { return; }
        $t = self::ident($table);
        $cols = [];
        foreach ($data as $k => $v) { $cols[] = self::ident($k); }
        $ph = [];
        foreach ($data as $k => $v) { $ph[] = ':' . self::ident($k); }
        self::run('INSERT INTO ' . $t . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')', $data);
    }

    /** $where must be a constant SQL fragment with named placeholders, never raw user input. */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) { return 0; }
        $t = self::ident($table);
        $sets = [];
        foreach ($data as $k => $v) { $sets[] = self::ident($k) . ' = :set_' . self::ident($k); }
        $params = [];
        foreach ($data as $k => $v) { $params['set_' . $k] = $v; }
        foreach ($whereParams as $k => $v) { $params[ltrim($k, ':')] = $v; }
        return self::exec('UPDATE ' . $t . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where, $params);
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::exec('DELETE FROM ' . self::ident($table) . ' WHERE ' . $where, $params);
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$txDepth === 0) { $pdo->beginTransaction(); }
        self::$txDepth++;
        try {
            $result = $fn($pdo);
            self::$txDepth--;
            if (self::$txDepth === 0) { $pdo->commit(); }
            return $result;
        } catch (\Throwable $e) {
            self::$txDepth--;
            if (self::$txDepth === 0 && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    public static function driver(): string { return (string)Config::get('db.driver'); }
}
