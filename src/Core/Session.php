<?php
declare(strict_types=1);
namespace AllStore\Core;

/**
 * Database-backed sessions. Native PHP sessions are file-based and therefore
 * unreliable on Vercel's read-only, ephemeral serverless filesystem. Only a
 * random opaque token is stored in the browser; its SHA-256 hash is stored in
 * the database, so a database leak cannot be replayed as a session cookie.
 */
final class Session
{
    private const COOKIE = 'as_session';
    private static array $data = [];
    private static string $token = '';
    private static string $id = '';
    private static string $csrf = '';
    private static bool $started = false;
    private static bool $dirty = false;

    public static function start(): void
    {
        if (self::$started) { return; }
        self::$started = true;
        $raw = (string)($_COOKIE[self::COOKIE] ?? '');
        $row = null;
        if (preg_match('/^[a-f0-9]{64}$/', $raw)) {
            $hash = hash('sha256', $raw);
            try {
                $row = Database::first('SELECT * FROM sessions WHERE id=:id AND expires_at > :now', ['id'=>$hash,'now'=>Helpers::now()]);
            } catch (\Throwable $e) { Logger::log('error','session.read_failed'); }
        }
        if ($row) {
            self::$token = $raw;
            self::$id = (string)$row['id'];
            self::$data = (array)Helpers::decode((string)$row['data'], []);
            self::$csrf = (string)$row['csrf_token'];
            self::$data['user_id'] = $row['user_id'] ?? null;
        } else {
            self::create();
        }
        register_shutdown_function([self::class, 'save']);
    }

    private static function create(): void
    {
        self::$token = Helpers::token(32);
        self::$id = hash('sha256', self::$token);
        self::$csrf = Helpers::token(24);
        self::$data = ['user_id' => null, 'checkout_idem' => null];
        try {
            Database::insert('sessions', [
                'id'=>self::$id, 'user_id'=>null, 'data'=>Helpers::json(self::$data),
                'csrf_token'=>self::$csrf, 'ip_address'=>Request::ip(), 'user_agent'=>Request::ua(),
                'created_at'=>Helpers::now(), 'last_activity'=>Helpers::now(),
                'expires_at'=>Helpers::fromOffset((int)Config::get('security.session_idle')),
            ]);
        } catch (\Throwable $e) { Logger::exception($e); throw new AppException('Session storage unavailable.', 500); }
        self::setCookie(self::$token);
        // The INSERT above already persisted the initial state.
        self::$dirty = false;
    }

    private static function setCookie(string $value): void
    {
        if (headers_sent()) { return; }
        setcookie(self::COOKIE, $value, [
            'expires' => 0, 'path' => '/', 'domain' => '',
            'secure' => Request::isHttps(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $value;
    }

    public static function save(): void
    {
        if (!self::$started || !self::$dirty || self::$id === '') { return; }
        try {
            Database::exec('UPDATE sessions SET data=:d, user_id=:u, expires_at=:e, last_activity=:l WHERE id=:id', [
                'd'=>Helpers::json(self::$data), 'u'=>self::$data['user_id'] ?? null,
                'e'=>Helpers::fromOffset((int)Config::get('security.session_idle')),
                'l'=>Helpers::now(), 'id'=>self::$id,
            ]);
        } catch (\Throwable $e) { Logger::log('error','session.save_failed'); }
        self::$dirty = false;
    }

    public static function get(string $key, mixed $default = null): mixed { return self::$data[$key] ?? $default; }
    public static function set(string $key, mixed $value): void { self::$data[$key] = $value; self::$dirty = true; }
    public static function forget(string $key): void { unset(self::$data[$key]); self::$dirty = true; }
    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = self::get($key, $default); self::forget($key); return $v;
    }
    public static function flash(string $type, string $message): void
    {
        $f = (array)self::get('_flash', []);
        $f[] = ['type'=>$type,'message'=>$message];
        self::set('_flash', $f);
    }
    public static function flashes(): array { return (array)self::pull('_flash', []); }
    public static function id(): string { return self::$id; }
    public static function csrfToken(): string { return self::$csrf; }
    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . Helpers::e(self::$csrf) . '">';
    }
    public static function verifyCsrf(?string $token = null): bool
    {
        $token ??= Request::csrf();
        return $token !== '' && self::$csrf !== '' && Security::equals(self::$csrf, $token);
    }
    public static function requireCsrf(): void
    {
        if (!self::verifyCsrf()) {
            Logger::log('warning', 'csrf.rejected', ['path'=>Request::path()]);
            throw new AppException('Your session expired. Please refresh the page and try again.', 419);
        }
    }
    /** Rotate the session identifier after login / privilege change. */
    public static function regenerate(): void
    {
        $old = self::$id;
        $data = self::$data;
        self::$token = Helpers::token(32);
        self::$id = hash('sha256', self::$token);
        self::$csrf = Helpers::token(24);
        try {
            Database::insert('sessions', [
                'id'=>self::$id, 'user_id'=>$data['user_id'] ?? null, 'data'=>Helpers::json($data),
                'csrf_token'=>self::$csrf, 'ip_address'=>Request::ip(), 'user_agent'=>Request::ua(),
                'created_at'=>Helpers::now(), 'last_activity'=>Helpers::now(),
                'expires_at'=>Helpers::fromOffset((int)Config::get('security.session_idle')),
            ]);
            Database::delete('sessions', 'id=:id', ['id'=>$old]);
        } catch (\Throwable $e) { Logger::exception($e); throw new AppException('Session storage unavailable.', 500); }
        self::setCookie(self::$token);
        self::$dirty = true;
    }
    public static function destroy(): void
    {
        try { Database::delete('sessions', 'id=:id', ['id'=>self::$id]); } catch (\Throwable) {}
        self::$data = []; self::$dirty = false;
        self::setCookie('');
        if (isset($_COOKIE[self::COOKIE])) { unset($_COOKIE[self::COOKIE]); }
    }
    public static function prune(): void
    {
        // A DELETE on every request adds a cross-region DB round trip. Run
        // housekeeping on a small random sample of requests instead.
        try {
            if (random_int(1, 200) !== 1) { return; }
            Database::delete('sessions', 'expires_at < :now', ['now'=>Helpers::now()]);
        } catch (\Throwable) {}
    }
}
