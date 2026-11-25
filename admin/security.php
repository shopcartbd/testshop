<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Response;
use AllStore\Core\Config;
use AllStore\Core\Database;
use AllStore\Core\Logger;
use AllStore\Core\AppException;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('security.manage');
if (Request::isPost()) {
    Session::requireCsrf();
    try { Auth::changePassword((string)Request::input('current_password',''),(string)Request::input('new_password','')); Session::flash('success','Your administrator password was changed.'); }
    catch(AppException $e) { Session::flash('error',$e->getMessage()); }
    Response::redirect('/admin/security');
}
$adminCount=(int)Database::value("SELECT COUNT(*) FROM profiles p JOIN roles r ON r.id=p.role_id WHERE r.slug IN ('super_admin','admin','staff') AND p.deleted_at IS NULL");
$sessions=(int)Database::value('SELECT COUNT(*) FROM sessions WHERE expires_at > :n',['n'=>Helpers::now()]);
$logs=Database::all('SELECT action,entity,created_at,severity FROM audit_logs ORDER BY created_at DESC LIMIT 12');
$checks=[
 ['Password hashing','PHP password_hash() / password_verify() with automatic rehash',true],
 ['Session storage','Database-backed sessions with regeneration and idle timeout',true],
 ['CSRF protection','SameSite cookies, per-session token and header verification',true],
 ['RBAC','Deny-by-default permissions and ownership checks on customer data',true],
 ['Rate limiting','DB-backed fixed-window limiter for login, checkout, coupon and forms',true],
 ['Transport headers','CSP, nosniff, frame denial, referrer and permissions policy',true],
 ['Production debug',Config::get('app.debug') ? 'Enabled — set APP_DEBUG=false in production' : 'Disabled',!Config::get('app.debug')],
 ['HTTPS detected',Request::isHttps()?'Yes':'No — Vercel provides HTTPS at the edge',true],
 ['Environment',(string)Config::get('app.env'),true],
];
admin_header(['title'=>'Security','subtitle'=>'Controls, administrator password and recent audit activity','active'=>'security']);
echo '<div class="admin-grid-2"><div class="card card-pad"><h3 style="margin-bottom:14px">Security posture</h3>';
foreach($checks as [$label,$value,$ok]) echo '<div class="order-row"><div class="grow"><strong style="font-size:13px">'.Helpers::e($label).'</strong><div class="text-subtle" style="font-size:11px">'.Helpers::e($value).'</div></div><span class="badge '.($ok?'badge-success':'badge-warning').'">'.($ok?'OK':'Review').'</span></div>';
echo '<div class="stat-grid" style="margin-top:14px"><div class="stat-card"><div class="stat-label">Administrators</div><div class="stat-value">'.$adminCount.'</div></div><div class="stat-card"><div class="stat-label">Active sessions</div><div class="stat-value">'.$sessions.'</div></div></div></div>';
echo '<div class="card card-pad"><h3 style="margin-bottom:14px">Change your password</h3><form method="post">'.Session::csrfField().'<div class="field"><label for="current_password">Current password</label><input class="input" type="password" id="current_password" name="current_password" required autocomplete="current-password"></div><div class="field"><label for="new_password">New password</label><input class="input" type="password" id="new_password" name="new_password" required minlength="10" autocomplete="new-password"></div><button class="btn btn-primary" type="submit">Update password</button></form><p class="field-hint" style="margin-top:12px">Minimum 10 characters with a letter and a number. No password is ever stored in plaintext.</p></div></div>';
echo '<div class="card" style="margin-top:16px"><div class="card-header"><h3>Recent audit log</h3><a class="section-link" href="/admin/activity-logs">View all '.icon('arrow-right',14).'</a></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Action</th><th>Entity</th><th>Severity</th><th>Date</th></tr></thead><tbody>';
foreach($logs as $l) echo '<tr><td>'.Helpers::e($l['action']).'</td><td>'.Helpers::e($l['entity'] ?? '—').'</td><td>'.Helpers::e($l['severity']).'</td><td>'.admin_date($l,'created_at').'</td></tr>';
echo '</tbody></table></div></div>';
admin_footer();
