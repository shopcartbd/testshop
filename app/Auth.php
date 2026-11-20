<?php

declare(strict_types=1);

final class Auth
{
    private static ?array $cached = null;
    private const INACTIVITY_SECONDS = 1800;
    private const ABSOLUTE_SECONDS = 43200;

    public static function current(): ?array
    {
        if (self::$cached !== null) return self::$cached ?: null;
        $id = (string) ($_SESSION['account_id'] ?? '');
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) return null;
        $now = time();
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        $created = (int) ($_SESSION['created_at'] ?? 0);
        if ($last < 1 || $created < 1 || $now - $last > self::INACTIVITY_SECONDS || $now - $created > self::ABSOLUTE_SECONDS) {
            self::logout();
            return null;
        }
        $stmt = Database::connection()->prepare("SELECT id,email,display_name,phone,role,status FROM app.accounts WHERE id=:id AND status='active' LIMIT 1");
        $stmt->execute(['id' => $id]);
        $account = $stmt->fetch();
        if (!$account) {
            self::logout();
            return null;
        }
        $_SESSION['last_activity'] = $now;
        self::$cached = $account;
        return $account;
    }

    public static function requireLogin(): array
    {
        $account = self::current();
        if (!$account) redirect('/login?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/account')));
        return $account;
    }

    public static function requireAdmin(): array
    {
        $account = self::requireLogin();
        if (!in_array($account['role'], ['admin','owner'], true)) {
            http_response_code(403);
            throw new DomainException('এই কাজের অনুমতি নেই।');
        }
        return $account;
    }

    public static function register(string $name, string $email, string $password): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $email = strtolower(trim($email));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new InvalidArgumentException('নাম ২–১২০ অক্ষরের মধ্যে লিখুন।');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320) throw new InvalidArgumentException('সঠিক ইমেইল ঠিকানা লিখুন।');
        if (strlen($password) < 12 || strlen($password) > 200) throw new InvalidArgumentException('পাসওয়ার্ড কমপক্ষে ১২ অক্ষরের দিন।');
        if (!RateLimiter::allow('register', $email, 5, 3600)) throw new DomainException('অনেকবার চেষ্টা হয়েছে। পরে আবার চেষ্টা করুন।');
        $stmt = Database::connection()->prepare('INSERT INTO app.accounts(email,password_hash,display_name,role) VALUES(:email,:hash,:name,\'customer\') RETURNING id');
        try {
            $stmt->execute(['email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'name' => $name]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23505') throw new DomainException('এই ইমেইল দিয়ে account করা আছে। লগইন করুন।');
            throw $error;
        }
        self::establish((string) $stmt->fetchColumn());
        RateLimiter::clear('register', $email);
        return self::current() ?? throw new RuntimeException('Account session could not be created.');
    }

    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        if (!RateLimiter::allow('login', $email, 8, 900)) throw new DomainException('ইমেইল বা পাসওয়ার্ড সঠিক নয়।');
        $stmt = Database::connection()->prepare('SELECT id,password_hash,role,status FROM app.accounts WHERE email=:email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        $dummy = '$2y$10$KbQiUP4tHboEYP6r7hKVDe95w3l0nJ.gyjbq0lQtcWQQTw0Xq7p6W';
        if (!$row || !password_verify($password, (string) $row['password_hash']) || $row['status'] !== 'active') {
            password_verify($password, $dummy);
            throw new DomainException('ইমেইল বা পাসওয়ার্ড সঠিক নয়।');
        }
        $stmt = Database::connection()->prepare('UPDATE app.accounts SET last_login_at=now() WHERE id=:id');
        $stmt->execute(['id' => $row['id']]);
        self::establish((string) $row['id']);
        RateLimiter::clear('login', $email);
        return self::current() ?? throw new RuntimeException('Login session could not be created.');
    }

    private static function establish(string $id): void
    {
        session_regenerate_id(true);
        $_SESSION = ['account_id' => $id, 'created_at' => time(), 'last_activity' => time(), '_csrf' => bin2hex(random_bytes(32))];
        self::$cached = null;
    }

    public static function logout(): void
    {
        self::$cached = null;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }

    public static function changePassword(string $current, string $new): void
    {
        $account = self::requireLogin();
        if (strlen($new) < 12 || strlen($new) > 200) throw new InvalidArgumentException('নতুন পাসওয়ার্ড কমপক্ষে ১২ অক্ষরের দিন।');
        $stmt = Database::connection()->prepare('SELECT password_hash FROM app.accounts WHERE id=:id');
        $stmt->execute(['id' => $account['id']]);
        $hash = (string) $stmt->fetchColumn();
        if (!password_verify($current, $hash)) throw new DomainException('বর্তমান পাসওয়ার্ড সঠিক নয়।');
        $stmt = Database::connection()->prepare('UPDATE app.accounts SET password_hash=:hash WHERE id=:id');
        $stmt->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $account['id']]);
        Database::connection()->prepare('DELETE FROM app.sessions WHERE account_id=:id')->execute(['id' => $account['id']]);
        self::establish((string) $account['id']);
    }

    public static function createResetToken(string $email): ?string
    {
        $email = strtolower(trim($email));
        if (!RateLimiter::allow('password-reset', $email, 4, 3600)) return null;
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT id FROM app.accounts WHERE email=:email AND status='active' LIMIT 1 FOR UPDATE");
            $stmt->execute(['email' => $email]);
            $id = $stmt->fetchColumn();
            if (!$id) { $db->commit(); return null; }
            $db->prepare('UPDATE app.password_reset_tokens SET used_at=now() WHERE account_id=:id AND used_at IS NULL')->execute(['id' => $id]);
            $token = bin2hex(random_bytes(32));
            $stmt = $db->prepare('INSERT INTO app.password_reset_tokens(account_id,token_hash,expires_at) VALUES(:id,:hash,now()+interval \'30 minutes\')');
            $stmt->execute(['id' => $id, 'hash' => hash('sha256', $token)]);
            $db->commit();
            return $token;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    public static function resetPassword(string $token, string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 200) throw new InvalidArgumentException('পাসওয়ার্ড কমপক্ষে ১২ অক্ষরের দিন।');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT id,account_id FROM app.password_reset_tokens WHERE token_hash=:hash AND used_at IS NULL AND expires_at>now() FOR UPDATE');
            $stmt->execute(['hash' => hash('sha256', $token)]);
            $row = $stmt->fetch();
            if (!$row) throw new DomainException('রিসেট লিংকটি মেয়াদোত্তীর্ণ বা অবৈধ।');
            $stmt = $db->prepare('UPDATE app.accounts SET password_hash=:password WHERE id=:id');
            $stmt->execute(['password' => password_hash($password, PASSWORD_DEFAULT), 'id' => $row['account_id']]);
            $stmt = $db->prepare('UPDATE app.password_reset_tokens SET used_at=now() WHERE account_id=:id AND used_at IS NULL');
            $stmt->execute(['id' => $row['account_id']]);
            $stmt = $db->prepare('DELETE FROM app.sessions WHERE account_id=:id');
            $stmt->execute(['id' => $row['account_id']]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
