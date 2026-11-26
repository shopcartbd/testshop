<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Config;
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Models\CatalogRepository;
use AllStore\Models\CustomerRepository;
use AllStore\Services\CartService;
use AllStore\Services\DeliveryZoneService;

require_once __DIR__ . '/components.php';
$page = array_merge([
    'title' => (string)Config::get('app.name') . ' — Modern marketplace',
    'description' => Helpers::setting('store_tagline', 'A considered marketplace for everyday goods.'),
    'canonical' => null, 'og_image' => '/assets/img/hero-marketplace.jpg', 'noindex' => false,
    'body_class' => '', 'schema' => null,
], $page ?? []);
$appName = (string)Config::get('app.name');
$tree = CatalogRepository::tree();
$cartCount = CartService::count();
$wishCount = Auth::id() ? CustomerRepository::wishlistCount((string)Auth::id()) : 0;
$canonical = $page['canonical'] ?: rtrim((string)Config::get('app.url'), '/') . Request::path();
$current = Request::path();
$announcement = Helpers::setting('announcement_text', '');
// Replace only the original placeholder USD promo; admin-edited announcements are preserved.
if (str_contains($announcement, '$75') || stripos($announcement, 'complimentary shipping on orders over') !== false) {
    $announcement = DeliveryZoneService::publicHeadline() . '  •  New arrivals every week';
}
$deliveryHeadline = DeliveryZoneService::publicHeadline();
$annEnabled = Helpers::setting('announcement_enabled', '1') === '1';
?><!doctype html>
<html lang="<?= Helpers::e((string)Config::get('app.locale')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#14624f">
<title><?= Helpers::e($page['title']) ?></title>
<meta name="description" content="<?= Helpers::e(Helpers::truncate((string)$page['description'], 160)) ?>">
<link rel="canonical" href="<?= Helpers::e($canonical) ?>">
<?php if (!empty($page['noindex'])): ?><meta name="robots" content="noindex, nofollow"><?php else: ?><meta name="robots" content="index, follow, max-image-preview:large"><?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= Helpers::e($appName) ?>">
<meta property="og:title" content="<?= Helpers::e($page['title']) ?>">
<meta property="og:description" content="<?= Helpers::e(Helpers::truncate((string)$page['description'], 160)) ?>">
<meta property="og:url" content="<?= Helpers::e($canonical) ?>">
<meta property="og:image" content="<?= Helpers::e((string)Config::get('app.url') . (string)$page['og_image']) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/responsive.css">
<?php foreach ((array)($page['extra_css'] ?? []) as $extraCss): ?><link rel="stylesheet" href="<?= Helpers::e((string)$extraCss) ?>"><?php endforeach; ?>
</head>
<body class="<?= Helpers::e((string)$page['body_class']) ?>" data-csrf="<?= Helpers::e(Session::csrfToken()) ?>" data-logged-in="<?= Auth::check() ? '1' : '0' ?>" data-currency-symbol="<?= Helpers::e(Helpers::currencySymbol()) ?>">
<a class="visually-hidden" href="#main">Skip to content</a>
<?php if ($annEnabled && $announcement !== ''): ?>
<div class="announcement" data-announcement><div class="container">
  <span><?= Helpers::e($announcement) ?></span>
  <button type="button" class="announcement-close" data-announcement-close aria-label="Dismiss announcement"><?= icon('x',14) ?></button>
</div></div>
<?php endif; ?>
<header class="site-header">
  <div class="container header-main">
    <button type="button" class="icon-btn menu-toggle" data-open-menu aria-label="Open menu" aria-expanded="false"><?= icon('menu',22) ?></button>
    <a class="brand" href="/" aria-label="<?= Helpers::e($appName) ?> home">
      <span class="brand-mark"><?= icon('layers',20) ?></span>
      <span><?= Helpers::e($appName) ?><small>Marketplace</small></span>
    </a>
    <div class="search-wrap">
      <form class="search-form" action="/search" method="get" role="search" autocomplete="off">
        <?= icon('search',19) ?>
        <input type="search" name="q" data-search-input value="<?= Helpers::e(Request::str('q','',120)) ?>" placeholder="Search products, brands and categories" aria-label="Search products" maxlength="120">
        <button type="submit">Search</button>
      </form>
      <div class="search-suggest" id="searchSuggest" hidden aria-live="polite"></div>
    </div>
    <div class="header-actions">
      <a class="header-action" href="<?= Auth::check() ? '/account' : '/login' ?>" aria-label="My account"><?= icon('user',21) ?><span class="label"><?= Auth::check() ? Helpers::e(Helpers::truncate((string)(Auth::user()['full_name'] ?? 'Account'), 14)) : 'Account' ?></span></a>
      <a class="header-action" href="/account/wishlist" aria-label="Wishlist"><?= icon('heart',21) ?><span class="label">Wishlist</span><span class="count" data-wishlist-count data-zero="<?= $wishCount > 0 ? '0' : '1' ?>"><?= $wishCount ?></span></a>
      <a class="header-action" href="/cart" aria-label="Cart"><?= icon('cart',21) ?><span class="label">Cart</span><span class="count" data-cart-count data-zero="<?= $cartCount > 0 ? '0' : '1' ?>"><?= $cartCount ?></span></a>
    </div>
  </div>
  <nav class="main-nav" aria-label="Main navigation"><div class="container nav-inner">
    <button type="button" class="nav-all" data-mega-toggle aria-expanded="false"><?= icon('menu',17) ?> All categories <?= icon('chevron-down',14) ?></button>
    <div class="nav-links">
      <a href="/shop" class="<?= $current === '/shop' ? 'active' : '' ?>">Shop all</a>
      <a href="/new-arrivals" class="<?= $current === '/new-arrivals' ? 'active' : '' ?>">New arrivals</a>
      <a href="/best-sellers" class="<?= $current === '/best-sellers' ? 'active' : '' ?>">Best sellers</a>
      <a href="/deals" class="<?= $current === '/deals' ? 'active' : '' ?>">Deals</a>
      <a href="/flash-sale" class="<?= $current === '/flash-sale' ? 'active' : '' ?>">Flash sale <span class="hot">HOT</span></a>
      <a href="/brands">Brands</a>
      <a href="/blog">Journal</a>
    </div>
    <div class="nav-extra"><?= icon('truck',15) ?> <?= Helpers::e($deliveryHeadline) ?></div>
  </div></nav>
</header>

<div class="mega-menu" id="megaMenu"><div class="container mega-inner">
  <div class="mega-side">
    <div class="mega-side-title">Browse categories</div>
    <?php foreach ($tree as $i => $c): ?>
    <button type="button" class="mega-cat <?= $i === 0 ? 'active' : '' ?>" data-mega-cat="<?= Helpers::e($c['slug']) ?>"><?= Helpers::e($c['name']) ?> <?= icon('chevron-right',15) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="mega-columns">
    <?php foreach ($tree as $i => $c): ?>
    <div class="mega-group" data-mega-panel="<?= Helpers::e($c['slug']) ?>" <?= $i > 3 ? 'hidden' : '' ?>>
      <h4><a href="<?= Helpers::url('/category/'.$c['slug']) ?>"><?= Helpers::e($c['name']) ?></a></h4>
      <?php foreach (($c['children'] ?? []) as $child): ?>
      <a href="<?= Helpers::url('/category/'.$child['slug']) ?>"><?= Helpers::e($child['name']) ?></a>
      <?php endforeach; ?>
      <?php if (empty($c['children'])): ?><a href="<?= Helpers::url('/category/'.$c['slug']) ?>">Explore all <?= Helpers::e($c['name']) ?></a><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="mega-promo">
    <img src="/assets/img/promo-wide.jpg" alt="Featured collection">
    <div><strong>The everyday edit</strong><p style="font-size:12px;color:var(--c-muted);margin:6px 0 10px">Considered essentials, now with up to 30% off.</p><a class="section-link" href="/deals">Shop deals <?= icon('arrow-right',14) ?></a></div>
  </div>
</div></div>

<div class="drawer-backdrop" id="drawerBackdrop"></div>
<aside class="drawer" id="mobileDrawer" aria-label="Mobile navigation">
  <div class="drawer-head"><a class="brand" href="/"><span class="brand-mark"><?= icon('layers',18) ?></span><?= Helpers::e($appName) ?></a><button type="button" class="icon-btn" data-close-drawer aria-label="Close menu"><?= icon('x',20) ?></button></div>
  <div class="drawer-body">
    <?php if (Auth::check()): ?>
    <a class="btn btn-outline btn-block" href="/account" style="margin-bottom:14px"><?= icon('user',16) ?> My account</a>
    <?php else: ?>
    <a class="btn btn-primary btn-block" href="/login" style="margin-bottom:14px"><?= icon('user',16) ?> Sign in</a>
    <?php endif; ?>
    <div class="mega-side-title">Categories</div>
    <?php foreach ($tree as $c): ?>
    <div class="drawer-cat"><a href="<?= Helpers::url('/category/'.$c['slug']) ?>"><?= Helpers::e($c['name']) ?> <?= icon('chevron-right',14) ?></a>
      <?php if (!empty($c['children'])): ?><ul><?php foreach ($c['children'] as $ch): ?><li><a href="<?= Helpers::url('/category/'.$ch['slug']) ?>"><?= Helpers::e($ch['name']) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="mega-side-title" style="margin-top:18px">Discover</div>
    <?php foreach (['Shop all'=>'/shop','New arrivals'=>'/new-arrivals','Best sellers'=>'/best-sellers','Deals & offers'=>'/deals','Flash sale'=>'/flash-sale','Brands'=>'/brands','Journal'=>'/blog','Help & FAQ'=>'/faq','Contact'=>'/contact'] as $label=>$href): ?>
    <a class="drawer-cat" style="display:block;padding:11px 0;border-bottom:1px solid var(--c-border);font-size:14px" href="<?= Helpers::url($href) ?>"><?= Helpers::e($label) ?></a>
    <?php endforeach; ?>
  </div>
</aside>
<main id="main">
