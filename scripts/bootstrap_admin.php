<?php
declare(strict_types=1);
/**
 * ONE-TIME administrator provisioning. Run from a secure shell:
 *   ADMIN_BOOTSTRAP_EMAIL=... ADMIN_BOOTSTRAP_PASSWORD=... php scripts/bootstrap_admin.php
 * Refuses to run if any administrator already exists or bootstrap was completed.
 * The web application never reads these variables.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

use AllStore\Core\Database;
use AllStore\Core\Config;
use AllStore\Core\Helpers;

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$email = getenv('ADMIN_BOOTSTRAP_EMAIL') ?: '';
$password = getenv('ADMIN_BOOTSTRAP_PASSWORD') ?: '';
$name = getenv('ADMIN_BOOTSTRAP_NAME') ?: 'Store Owner';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "ADMIN_BOOTSTRAP_EMAIL is missing/invalid.\n"); exit(1); }
if (strlen($password) < 12) { fwrite(STDERR, "ADMIN_BOOTSTRAP_PASSWORD must be at least 12 characters.\n"); exit(1); }
if (Database::value("SELECT value FROM settings WHERE key='bootstrap_completed'") === '1') {
    fwrite(STDERR, "Bootstrap already completed. Refusing to run.\n"); exit(2);
}
$adminRole = Database::first("SELECT id FROM roles WHERE slug='super_admin'");
if (!$adminRole) { fwrite(STDERR, "Seed the roles first: php database/seed.php\n"); exit(1); }
$existing = Database::value('SELECT COUNT(*) FROM profiles p JOIN roles r ON r.id=p.role_id WHERE r.slug IN (\'super_admin\',\'admin\')');
if ((int)$existing > 0) {
    Database::exec("INSERT INTO settings(key,value,group_name) VALUES('bootstrap_completed','1','security') ON CONFLICT(key) DO UPDATE SET value='1'");
    fwrite(STDERR, "An administrator already exists. Bootstrap disabled. Refusing to run.\n"); exit(2);
}
if (Database::first('SELECT id FROM profiles WHERE email=:e', ['e' => strtolower($email)])) {
    fwrite(STDERR, "A profile with that email already exists; use the admin panel to promote it.\n"); exit(2);
}
Database::insert('profiles', [
    'id' => Helpers::uuid(), 'email' => strtolower($email),
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'full_name' => $name, 'role_id' => $adminRole['id'], 'status' => 'active',
    'email_verified' => 1, 'created_at' => Helpers::now(), 'updated_at' => Helpers::now(),
]);
Database::exec("INSERT INTO settings(key,value,group_name) VALUES('bootstrap_completed','1','security') ON CONFLICT(key) DO UPDATE SET value='1'");
Database::exec("INSERT INTO audit_logs(id,actor_email,action,entity,meta,severity,created_at) VALUES(:i,:e,'admin.bootstrap','profiles',:m,'critical',:t)", [
    'i'=>Helpers::uuid(), 'e'=>strtolower($email), 'm'=>'{}', 't'=>Helpers::now(),
]);
fwrite(STDOUT, "Administrator created and bootstrap disabled. Store the password in your secret manager, then unset the env vars.\n");
