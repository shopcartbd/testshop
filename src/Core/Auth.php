<?php
declare(strict_types=1);
namespace AllStore\Core;

final class Auth
{
    private static ?array $user = null;
    private static ?array $permissions = null;

    public static function user(): ?array
    {
        if (self::$user !== null) { return self::$user; }
        $id = Session::get('user_id');
        if (!$id || !is_string($id)) { return null; }
        try {
            $u = Database::first('SELECT p.*, r.slug AS role_slug, r.name AS role_name FROM profiles p LEFT JOIN roles r ON r.id=p.role_id WHERE p.id=:id AND p.deleted_at IS NULL AND p.status=\'active\'', ['id'=>$id]);
        } catch (\Throwable) { return null; }
        if (!$u) { Session::forget('user_id'); return null; }
        return self::$user = $u;
    }
    public static function id(): ?string { $u = self::user(); return $u['id'] ?? null; }
    public static function check(): bool { return self::user() !== null; }
    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && in_array($u['role_slug'] ?? '', ['super_admin','admin','staff'], true);
    }
    public static function permissions(): array
    {
        if (self::$permissions !== null) { return self::$permissions; }
        $u = self::user();
        if (!$u || !$u['role_id']) { return self::$permissions = []; }
        try {
            $rows = Database::all('SELECT pm.slug FROM role_permissions rp JOIN permissions pm ON pm.id=rp.permission_id WHERE rp.role_id=:r', ['r'=>$u['role_id']]);
            return self::$permissions = array_column($rows, 'slug');
        } catch (\Throwable) { return self::$permissions = []; }
    }
    public static function can(string $slug): bool
    {
        if (!self::isAdmin()) { return false; }
        if (in_array((self::user()['role_slug'] ?? ''), ['super_admin'], true)) { return true; }
        return in_array($slug, self::permissions(), true);
    }
    public static function requireLogin(): void
    {
        if (self::check()) { return; }
        Session::flash('info', 'Please sign in to continue.');
        Response::redirect('/login?next=' . rawurlencode(Request::path()));
    }
    public static function requireAdmin(?string $permission = null): void
    {
        if (!self::isAdmin()) {
            Session::flash('error', 'Administrator access required.');
            Response::redirect('/admin/login?next=' . rawurlencode(Request::path()));
        }
        if ($permission !== null && !self::can($permission)) {
            http_response_code(403);
            throw new AppException('You do not have permission to perform this action.', 403);
        }
    }
    public static function requireCsrf(): void { Session::requireCsrf(); }

    public static function attempt(string $email, string $password, bool $admin = false): array
    {
        $email = strtolower(trim($email));
        $bucket = 'login:' . ($admin ? 'admin:' : '') . Request::ip() . ':' . substr(md5($email), 0, 12);
        if (!Security::rateLimit($bucket, (int)Config::get('security.login_max'), (int)Config::get('security.login_window'))) {
            return ['ok'=>false, 'message'=>'Too many attempts. Please try again later.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            return ['ok'=>false, 'message'=>'Invalid email or password.'];
        }
        try {
            $u = Database::first('SELECT p.*, r.slug AS role_slug FROM profiles p LEFT JOIN roles r ON r.id=p.role_id WHERE p.email=:e AND p.deleted_at IS NULL', ['e'=>$email]);
        } catch (\Throwable $e) { Logger::exception($e); return ['ok'=>false,'message'=>'Service temporarily unavailable.']; }
        $hash = $u['password_hash'] ?? '$2y$12$usesomesillystringforsalttokeeptimingequalxxxxxxxxxxxxxxxx';
        $valid = password_verify($password, (string)$hash);
        if (!$u || !$valid || ($u['status'] ?? '') !== 'active' || ($admin && !in_array($u['role_slug'] ?? '', ['super_admin','admin','staff'], true))) {
            Logger::log('warning', 'auth.login_failed', ['email_domain'=>substr(strrchr($email,'@') ?: '',1), 'admin'=>$admin]);
            return ['ok'=>false, 'message'=>'Invalid email or password.'];
        }
        if (password_needs_rehash((string)$u['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('profiles', ['password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'updated_at'=>Helpers::now()], 'id=:id', ['id'=>$u['id']]);
        }
        Session::regenerate();
        Session::set('user_id', $u['id']);
        Session::set('login_at', time());
        self::$user = null; self::$permissions = null;
        Database::update('profiles', ['last_login_at'=>Helpers::now()], 'id=:id', ['id'=>$u['id']]);
        Logger::audit($admin ? 'admin.login' : 'auth.login', 'profiles', (string)$u['id']);
        try { \AllStore\Services\CartService::mergeGuestCart((string)$u['id']); } catch (\Throwable) {}
        return ['ok'=>true, 'message'=>'Signed in successfully.', 'user'=>$u];
    }

    public static function register(array $in): string
    {
        $name = Security::plain($in['full_name'] ?? '', 120);
        $email = strtolower(trim((string)($in['email'] ?? '')));
        $password = (string)($in['password'] ?? '');
        $phone = preg_replace('/[^0-9+\-\s()]/', '', (string)($in['phone'] ?? '')) ?? '';
        $errors = [];
        if (mb_strlen($name) < 2) { $errors['full_name'] = 'Please enter your full name.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Please enter a valid email address.'; }
        if (strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Use at least 10 characters including a letter and a number.';
        }
        if ($errors) { throw new AppException('Please correct the highlighted fields.', 422, $errors); }
        if (!Security::rateLimit('register:' . Request::ip(), 5, 3600)) {
            throw new AppException('Too many registrations. Please try again later.', 429);
        }
        if (Database::first('SELECT id FROM profiles WHERE email=:e', ['e'=>$email])) {
            throw new AppException('An account with this email already exists.', 409, ['email'=>'Email already registered.']);
        }
        $role = Database::first("SELECT id FROM roles WHERE slug='customer'");
        $id = Helpers::uuid();
        Database::insert('profiles', [
            'id'=>$id, 'email'=>$email, 'password_hash'=>password_hash($password, PASSWORD_DEFAULT),
            'full_name'=>$name, 'phone'=>$phone, 'role_id'=>$role['id'] ?? null,
            'status'=>'active', 'newsletter_opt_in'=>!empty($in['newsletter']) ? 1 : 0,
            'created_at'=>Helpers::now(), 'updated_at'=>Helpers::now(),
        ]);
        Logger::audit('auth.register', 'profiles', $id);
        try { \AllStore\Services\NotificationService::welcome($id, $name); } catch (\Throwable) {}
        return $id;
    }

    public static function logout(): void
    {
        $u = self::user();
        if ($u) { Logger::audit('auth.logout', 'profiles', (string)$u['id']); }
        Session::destroy();
        self::$user = null; self::$permissions = null;
    }

    /** Generic response; token is returned only to the server-side mail path. */
    public static function requestPasswordReset(string $email): ?string
    {
        $email = strtolower(trim($email));
        if (!Security::rateLimit('reset:' . Request::ip(), 5, 3600)) {
            throw new AppException('Too many requests. Please try again later.', 429);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return null; }
        $u = Database::first('SELECT id FROM profiles WHERE email=:e AND deleted_at IS NULL AND status=\'active\'', ['e'=>$email]);
        if (!$u) { return null; }
        $token = Helpers::token(32);
        Database::insert('password_resets', [
            'id'=>Helpers::uuid(), 'user_id'=>$u['id'],
            'token_hash'=>hash_hmac('sha256', $token, (string)Config::secretKey()),
            'expires_at'=>Helpers::fromOffset(3600), 'created_at'=>Helpers::now(),
        ]);
        Logger::log('info','auth.password_reset_requested');
        return $token;
    }

    public static function resetPassword(string $token, string $password): bool
    {
        if (strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new AppException('Use at least 10 characters including a letter and a number.', 422);
        }
        $hash = hash_hmac('sha256', $token, (string)Config::secretKey());
        $row = Database::first('SELECT * FROM password_resets WHERE token_hash=:h AND used_at IS NULL AND expires_at > :n', ['h'=>$hash,'n'=>Helpers::now()]);
        if (!$row) { return false; }
        Database::tx(function () use ($row, $hash, $password): void {
            Database::update('profiles', ['password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'updated_at'=>Helpers::now()], 'id=:id', ['id'=>$row['user_id']]);
            Database::update('password_resets', ['used_at'=>Helpers::now()], 'token_hash=:h', ['h'=>$hash]);
            Database::delete('sessions', 'user_id=:u', ['u'=>$row['user_id']]);
        });
        Logger::audit('auth.password_reset', 'profiles', (string)$row['user_id']);
        return true;
    }

    public static function changePassword(string $current, string $new): void
    {
        $u = self::user();
        if (!$u || !password_verify($current, (string)$u['password_hash'])) {
            throw new AppException('Current password is incorrect.', 422);
        }
        if (strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            throw new AppException('Use at least 10 characters including a letter and a number.', 422);
        }
        $currentSession = Session::id();
        Database::tx(function () use ($u, $new, $currentSession): void {
            Database::update('profiles', ['password_hash'=>password_hash($new, PASSWORD_DEFAULT), 'updated_at'=>Helpers::now()], 'id=:id', ['id'=>$u['id']]);
            Database::delete('sessions', 'user_id=:u AND id<>:s', ['u'=>$u['id'], 's'=>$currentSession]);
        });
        Session::regenerate();
        Database::delete('sessions', 'user_id=:u AND id<>:s', ['u'=>$u['id'], 's'=>Session::id()]);
        self::$user = null; self::$permissions = null;
        Logger::audit('auth.password_changed', 'profiles', (string)$u['id']);
    }
}
