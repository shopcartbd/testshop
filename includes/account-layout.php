<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Services\NotificationService;
require_once __DIR__ . '/components.php';

// This file is included before account page logic. Guard here so no DB query,
// POST mutation or redirect happens for a guest before authentication.
Auth::requireLogin();

function account_header(string $active, string $title, string $subtitle = ''): void
{
    global $page;
    $page = ['title'=>$title.' · My Account','description'=>$subtitle ?: 'Manage your AllStore account.','noindex'=>true,'body_class'=>'page-account'];
    Auth::requireLogin();
    $links = [
        'overview'=>['Overview','/account','dashboard'],'orders'=>['Orders','/account/orders','package'],
        'tracking'=>['Order tracking','/account/orders','truck'],'wishlist'=>['Wishlist','/account/wishlist','heart'],
        'reviews'=>['My reviews','/account/reviews','star'],'addresses'=>['Address book','/account/addresses','map-pin'],
        'notifications'=>['Notifications','/account/notifications','bell'],'coupons'=>['Coupons','/account/coupons','tag'],
        'returns'=>['Return requests','/account/returns','rotate'],'refunds'=>['Refund status','/account/refunds','credit-card'],
        'tickets'=>['Support tickets','/account/tickets','message'],'profile'=>['Profile','/account/profile','user'],
        'password'=>['Change password','/account/change-password','lock'],
    ];
    $unread = NotificationService::unreadCount(Auth::id());
    require __DIR__ . '/header.php';
    echo breadcrumbs([['name'=>'My account','url'=>'/account'],['name'=>$title]]);
    echo '<section class="section" style="padding-top:0"><div class="container"><div class="account-layout"><aside class="account-nav card" style="padding:8px">';
    echo '<div class="nav-group">My account</div>';
    $i = 0;
    foreach ($links as $k=>[$label,$href,$ic]) {
        if ($i === 5) { echo '<div class="nav-group">Preferences</div>'; }
        echo '<a href="'.Helpers::url($href).'" class="'.($active===$k?'active':'').'">'.icon($ic,17).Helpers::e($label).($k==='notifications'&&$unread>0?' <span class="badge badge-brand" style="margin-left:auto">'.$unread.'</span>':'').'</a>';
        $i++;
    }
    echo '<div class="divider" style="margin:8px 0"></div><a href="/logout">'.icon('logout',17).'Sign out</a>';
    echo '</aside><div class="grow"><div class="row-between wrap" style="margin-bottom:var(--sp-5)"><div><h1 style="font-size:var(--fs-2xl)">'.Helpers::e($title).'</h1>'.($subtitle!==''?'<p class="text-muted" style="font-size:var(--fs-sm);margin-top:6px">'.Helpers::e($subtitle).'</p>':'').'</div></div>';
}

function account_footer(): void
{
    echo '</div></div></div></section>';
    require __DIR__ . '/footer.php';
}

function account_card_start(string $title = '', string $action = ''): void
{
    echo '<div class="card" style="margin-bottom:var(--sp-5)">';
    if ($title !== '') { echo '<div class="card-header"><h3>'.Helpers::e($title).'</h3>'.$action.'</div>'; }
    echo '<div class="card-body">';
}
function account_card_end(): void { echo '</div></div>'; }
