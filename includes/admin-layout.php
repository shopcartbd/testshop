<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Config;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\NotificationService;
require_once __DIR__ . '/components.php';

function admin_header(array $opts = []): void
{
    Auth::requireAdmin($opts['permission'] ?? null);
    $page = array_merge([
        'title' => 'Dashboard', 'subtitle' => 'Store overview', 'active' => 'dashboard',
    ], $opts);
    Response::noCache();
    $groups = [
        'Overview' => [
            ['Dashboard','/admin','dashboard','dashboard'],
            ['Reports','/admin/reports','reports','chart'],
            ['Notifications','/admin/notifications','notifications','bell'],
            ['Activity logs','/admin/activity-logs','activity','file'],
        ],
        'Catalogue' => [
            ['Products','/admin/products','products','box'],
            ['Categories','/admin/categories','categories','layers'],
            ['Brands','/admin/brands','brands','award'],
            ['Attributes','/admin/attributes','attributes','sliders'],
            ['Inventory','/admin/inventory','inventory','package'],
            ['Warehouses','/admin/warehouses','warehouses','home'],
        ],
        'Sales' => [
            ['Orders','/admin/orders','orders','cart'],
            ['Customers','/admin/customers','customers','users'],
            ['Coupons','/admin/coupons','coupons','tag'],
            ['Promotions','/admin/promotions','promotions','percent'],
            ['Flash sales','/admin/flash-sales','flash','clock'],
            ['Payments','/admin/payments','payments','credit-card'],
            ['Transactions','/admin/transactions','transactions','file'],
            ['Returns','/admin/returns','returns','rotate'],
            ['Refunds','/admin/refunds','refunds','credit-card'],
        ],
        'Content' => [
            ['Homepage builder','/admin/homepage','homepage','grid'],
            ['Banners','/admin/banners','banners','image'],
            ['Pages','/admin/pages','pages','file'],
            ['Blog','/admin/blog','blog','edit'],
            ['Reviews','/admin/reviews','reviews','star'],
            ['Support tickets','/admin/tickets','tickets','message'],
        ],
        'System' => [
            ['Shipping','/admin/shipping','shipping','truck'],
            ['Bangladesh areas','/admin/locations','locations','package'],
            ['Couriers','/admin/couriers','couriers','package'],
            ['Admin users','/admin/admin-users','admin-users','users'],
            ['Roles','/admin/roles','roles','lock'],
            ['Permissions','/admin/permissions','permissions','shield'],
            ['Settings','/admin/settings','settings','settings'],
            ['Security','/admin/security','security','shield'],
        ],
    ];
    $appName = (string)Config::get('app.name');
    $u = Auth::user();
    $unread = NotificationService::adminUnread();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= Helpers::e($page['title']) ?> · <?= Helpers::e($appName) ?> Admin</title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/admin.css">
<link rel="stylesheet" href="/assets/css/responsive.css">
</head>
<body class="admin-body" data-csrf="<?= Helpers::e(Session::csrfToken()) ?>" data-logged-in="1" data-currency-symbol="<?= Helpers::e(Helpers::currencySymbol()) ?>">
<div class="admin-shell">
  <aside class="admin-sidebar" id="adminSidebar">
    <a class="admin-brand" href="/admin"><span class="brand-mark"><?= icon('layers',18) ?></span><span><?= Helpers::e($appName) ?><em>Admin console</em></span></a>
    <nav class="admin-nav" aria-label="Admin navigation">
      <?php foreach ($groups as $g => $links): ?>
      <div class="group"><?= Helpers::e($g) ?></div>
      <?php foreach ($links as $l):
        $label = $l[0]; $href = $l[1]; $key = $l[2]; $ic = is_array($l[3]) ? reset($l[3]) : $l[3]; ?>
      <a href="<?= Helpers::url($href) ?>" class="<?= $page['active'] === $key ? 'active' : '' ?>"><?= icon((string)$ic,17) ?><?= Helpers::e($label) ?><?php if ($key === 'notifications' && $unread > 0): ?><span class="pill"><?= $unread ?></span><?php endif; ?></a>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="admin-sidebar-foot"><a href="/" style="color:#aab7b3">← View storefront</a><br><?= Helpers::e(ucfirst((string)Config::get('app.env'))) ?> · PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?></div>
  </aside>
  <div class="admin-main">
    <header class="admin-topbar">
      <div style="display:flex;align-items:center;gap:12px">
        <button type="button" class="icon-btn" data-admin-menu aria-label="Toggle navigation" style="display:none"><?= icon('menu',20) ?></button>
        <div><h1><?= Helpers::e($page['title']) ?></h1><div class="crumbs">Admin / <?= Helpers::e($page['title']) ?></div></div>
      </div>
      <div class="right">
        <form class="search" action="<?= Helpers::url('/admin/orders') ?>" method="get"><?= icon('search',16) ?><input type="search" name="q" placeholder="Search orders…" aria-label="Search orders"></form>
        <a class="icon-btn" href="/admin/notifications" aria-label="Notifications"><?= icon('bell',19) ?></a>
        <div class="admin-user"><span class="avatar"><?= Helpers::e(mb_strtoupper(mb_substr((string)($u['full_name'] ?? 'A'),0,1))) ?></span><span><?= Helpers::e((string)($u['full_name'] ?? 'Admin')) ?></span></div>
        <a class="icon-btn" href="/admin/logout" aria-label="Sign out" data-confirm="Sign out of the admin console?" ><?= icon('logout',19) ?></a>
      </div>
    </header>
    <main class="admin-content">
<?php
}

function admin_footer(): void
{
    ?></main>
  </div>
</div>
<div class="toast-stack" id="toastStack" aria-live="polite">
  <?php foreach (Session::flashes() as $f): ?>
  <div class="toast <?= Helpers::e($f['type'] ?? 'info') ?>" data-server="1"><?= icon('info',18) ?><span><?= Helpers::e($f['message'] ?? '') ?></span></div>
  <?php endforeach; ?>
</div>
<div class="modal-backdrop" id="confirmModal" role="dialog" aria-modal="true"><div class="modal"><h3>Please confirm</h3><p data-modal-message>Are you sure?</p><div class="modal-actions"><button type="button" class="btn btn-outline" data-modal-cancel>Cancel</button><button type="button" class="btn btn-primary" data-modal-ok>Confirm</button></div></div></div>
<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/admin.js" defer></script>
</body></html>
<?php
}
