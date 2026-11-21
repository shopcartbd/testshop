<?php

declare(strict_types=1);:

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $url = (string) (getenv('SUPABASE_DB_URL') ?: getenv('DATABASE_URL') ?: '');
        if ($url !== '') {
            $parts = parse_url($url);
            if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
                throw new RuntimeException('Database configuration is invalid.');
            }
            $host = $parts['host'];
            $port = (int) ($parts['port'] ?? 5432);
            $database = ltrim($parts['path'], '/');
            $user = rawurldecode((string) ($parts['user'] ?? ''));
            $password = rawurldecode((string) ($parts['pass'] ?? ''));
            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);
            $sslmode = (string) ($query['sslmode'] ?? 'require');
        } else {
            $host = (string) getenv('SUPABASE_DB_HOST');
            $port = (int) (getenv('SUPABASE_DB_PORT') ?: 5432);
            $database = (string) (getenv('SUPABASE_DB_NAME') ?: 'postgres');
            $user = (string) getenv('SUPABASE_DB_USER');
            $password = (string) getenv('SUPABASE_DB_PASSWORD');
            $sslmode = (string) (getenv('SUPABASE_DB_SSLMODE') ?: 'require');
        }

        if ($host === '' || $user === '' || $password === '' || !in_array($sslmode, ['require', 'verify-ca', 'verify-full'], true)) {
            throw new RuntimeException('Database configuration is incomplete.');
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $database, $sslmode);
        self::$connection = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        return self::$connection;
    }
}
