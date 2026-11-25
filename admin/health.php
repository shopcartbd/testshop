<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Database;
use AllStore\Core\Config;
use AllStore\Services\MailService;
use AllStore\Services\PaymentService;
use AllStore\Services\UploadService;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('dashboard.view');
$dbOk = false; $tables = 0;
try { Database::pdo(); $dbOk = true; $tables = (int)Database::value(Database::driver()==='pgsql' ? "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'" : "SELECT COUNT(*) FROM sqlite_master WHERE type='table'"); } catch (\Throwable) {}
$checks = [
    ['PHP runtime', PHP_VERSION, version_compare(PHP_VERSION,'8.3','>=')],
    ['Database connection', $dbOk ? Database::driver().' connected' : 'unavailable', $dbOk],
    ['Schema tables', (string)$tables.' tables', $tables >= 50],
    ['PDO PostgreSQL extension', extension_loaded('pdo_pgsql') ? 'installed' : 'not installed (local SQLite still works)', extension_loaded('pdo_pgsql') || Database::driver()!=='pgsql'],
    ['Mail integration', MailService::configured() ? 'configured' : 'not configured (optional)', true],
    ['Online payments', PaymentService::stripeConfigured() ? 'Stripe configured' : 'Cash on Delivery only (optional)', true],
    ['Supabase Storage', UploadService::configured() ? 'configured' : 'not configured; local uploads fallback', true],
    ['Application environment', (string)Config::get('app.env'), true],
];
admin_header(['title'=>'System health','subtitle'=>'Runtime and integration status, with no secrets displayed','active'=>'dashboard']);
echo '<div class="card card-pad"><h3 style="margin-bottom:16px">Checks</h3>';
foreach ($checks as [$label,$value,$ok]) { echo '<div class="order-row"><div class="grow"><strong style="font-size:13px">'.Helpers::e($label).'</strong><div class="text-subtle" style="font-size:11px">'.Helpers::e($value).'</div></div><span class="badge '.($ok?'badge-success':'badge-danger').'">'.($ok?'OK':'Check').'</span></div>'; }
echo '</div><div class="alert alert-info" style="margin-top:16px">'.icon('info',16).'<span>This page never prints credentials, DSNs, API keys or raw exception details. Use the public <a href="/api/health">/api/health</a> endpoint for a minimal JSON readiness probe.</span></div>';
admin_footer();
