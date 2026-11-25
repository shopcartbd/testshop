<?php
declare(strict_types=1);
/** Reusable UI components. All dynamic values are escaped at output time. */
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Services\CartService;
use AllStore\Services\CustomerRepository;

if (!function_exists('component_bootstrap')) {
    function component_bootstrap(): void { require_once __DIR__ . '/icons.php'; }
}
component_bootstrap();

function product_card(array $p, array $opts = []): string
{
    $id = (string)$p['id'];
    $href = Helpers::url('/product/' . $p['slug']);
    $img = (string)($p['image_url'] ?: '/assets/img/products/headphones.jpg');
    $price = (int)($p['sale_price_cents'] ?? $p['price_cents']);
    $compare = (int)($p['compare_cents'] ?? 0);
    $off = Helpers::percentOff($price, $compare);
    $wishlisted = !empty($opts['wishlisted_ids']) && in_array($id, $opts['wishlisted_ids'], true);
    $badges = '';
    if ($off > 0) { $badges .= '<span class="badge badge-sale">-' . $off . '%</span>'; }
    if (!empty($p['is_new_arrival'])) { $badges .= '<span class="badge badge-new">New</span>'; }
    if (!empty($p['is_featured']) && $off === 0) { $badges .= '<span class="badge badge-featured">Featured</span>'; }
    $rating = '';
    if ((int)($p['rating_count'] ?? 0) > 0) {
        $rating = '<div class="product-rating">' . stars((float)$p['rating_avg']) . '<span>' . number_format((float)$p['rating_avg'],1) . ' (' . (int)$p['rating_count'] . ')</span></div>';
    }
    $stock = isset($p['stock']) && (int)$p['stock'] <= 0 ? '<div class="product-stock text-danger">Out of stock</div>' : '';
    return '<article class="product-card" itemscope itemtype="https://schema.org/Product">'
        . '<div class="product-media">'
        . '<a href="' . Helpers::e($href) . '" itemprop="url"><img src="' . Helpers::e($img) . '" alt="' . Helpers::e($p['name']) . '" loading="lazy" itemprop="image"></a>'
        . '<div class="badges">' . $badges . '</div>'
        . '<div class="product-quick">'
        . '<button type="button" data-wishlist="' . Helpers::e($id) . '" class="' . ($wishlisted ? 'active' : '') . '" aria-label="Add ' . Helpers::e($p['name']) . ' to wishlist">' . icon('heart',17) . '</button>'
        . '</div>'
        . '<div class="product-add"><button type="button" class="btn btn-dark btn-sm btn-block" data-add-to-cart="' . Helpers::e($id) . '" data-qty="1"' . (isset($p['stock']) && (int)$p['stock'] <= 0 ? ' disabled' : '') . '>Add to cart</button></div>'
        . '</div>'
        . '<div class="product-body">'
        . '<span class="product-brand" itemprop="brand">' . Helpers::e($p['brand_name'] ?? '') . '</span>'
        . '<a class="product-name" href="' . Helpers::e($href) . '" itemprop="name">' . Helpers::e($p['name']) . '</a>'
        . $rating
        . '<div class="product-price"><span class="now" itemprop="offers" itemscope itemtype="https://schema.org/Offer"><meta itemprop="priceCurrency" content="' . Helpers::e((string)\AllStore\Core\Config::get('app.currency')) . '"><span itemprop="price" content="' . number_format($price/100,2,'.','') . '">' . Helpers::money($price) . '</span></span>'
        . ($compare > $price ? '<span class="was">' . Helpers::money($compare) . '</span>' : '')
        . ($off > 0 ? '<span class="off">-' . $off . '%</span>' : '') . '</div>'
        . $stock
        . '</div></article>';
}

function category_tile(array $c): string
{
    return '<a class="category-tile" href="' . Helpers::url('/category/' . $c['slug']) . '">'
        . '<span class="cat-icon">' . icon((string)($c['icon'] ?: 'box'), 24) . '</span>'
        . '<span>' . Helpers::e($c['name']) . '</span></a>';
}

function breadcrumbs(array $items): string
{
    $out = '<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">Home</a>';
    $last = count($items) - 1;
    foreach ($items as $i => $it) {
        $out .= icon('chevron-right',13);
        if ($i === $last || empty($it['url'])) { $out .= '<span class="current">' . Helpers::e($it['name']) . '</span>'; }
        else { $out .= '<a href="' . Helpers::e(Helpers::url($it['url'])) . '">' . Helpers::e($it['name']) . '</a>'; }
    }
    return $out . '</nav>';
}

function stars(float $avg): string
{
    $out = '<span class="stars" aria-label="Rating ' . number_format($avg,1) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $filled = $avg >= $i - 0.25;
        $out .= '<span style="color:' . ($filled ? '#d9a441' : 'var(--c-border-strong)') . '">' . icon('star',13) . '</span>';
    }
    return $out . '</span>';
}

function pagination(array $result, string $basePath): string
{
    $pages = (int)($result['pages'] ?? 1);
    if ($pages <= 1) { return ''; }
    $page = (int)($result['page'] ?? 1);
    $params = $_GET; unset($params['page']);
    $link = function (int $n) use ($basePath, $params): string {
        $q = http_build_query(array_merge($params, ['page'=>$n]), '', '&', PHP_QUERY_RFC3986);
        return Helpers::e(Helpers::url($basePath) . ($q ? '?' . $q : ''));
    };
    $out = '<nav class="pagination" aria-label="Pagination">';
    $out .= $page > 1 ? '<a href="' . $link($page-1) . '" aria-label="Previous page">' . icon('chevron-left',16) . '</a>' : '<span class="disabled">' . icon('chevron-left',16) . '</span>';
    $start = max(1, min($page - 2, $pages - 4)); $end = min($pages, $start + 4);
    for ($i = $start; $i <= $end; $i++) {
        $out .= $i === $page ? '<span class="current" aria-current="page">' . $i . '</span>' : '<a href="' . $link($i) . '">' . $i . '</a>';
    }
    $out .= $page < $pages ? '<a href="' . $link($page+1) . '" aria-label="Next page">' . icon('chevron-right',16) . '</a>' : '<span class="disabled">' . icon('chevron-right',16) . '</span>';
    return $out . '</nav>';
}

function alert_box(string $type, string $message): string
{
    $i = match ($type) { 'success'=>'check-circle', 'error'=>'alert', 'warning'=>'alert', default=>'info' };
    return '<div class="alert alert-' . Helpers::e($type) . '" role="status">' . icon($i,18) . '<span>' . Helpers::e($message) . '</span></div>';
}

function empty_state(string $icon, string $title, string $text, string $link = '', string $label = ''): string
{
    $btn = $link !== '' ? '<a class="btn btn-primary" href="' . Helpers::e(Helpers::url($link)) . '">' . Helpers::e($label ?: 'Browse') . '</a>' : '';
    return '<div class="empty-state"><div class="icon-circle">' . icon($icon,28) . '</div><h3>' . Helpers::e($title) . '</h3><p>' . Helpers::e($text) . '</p>' . $btn . '</div>';
}

function section_head(string $title, string $subtitle = '', string $link = '', string $label = 'View all'): string
{
    return '<div class="section-head"><div><h2>' . Helpers::e($title) . '</h2>' . ($subtitle !== '' ? '<p>' . Helpers::e($subtitle) . '</p>' : '') . '</div>'
        . ($link !== '' ? '<a class="section-link" href="' . Helpers::e(Helpers::url($link)) . '">' . Helpers::e($label) . ' ' . icon('arrow-right',15) . '</a>' : '') . '</div>';
}

function product_grid(array $products, string $columns = '4', array $opts = []): string
{
    if ($products === []) { return empty_state('package','No products found','Try adjusting your filters or explore another category.', '/shop', 'Browse all products'); }
    $cls = 'product-grid' . ($columns !== '4' ? ' grid-' . $columns : '');
    $out = '<div class="' . $cls . '" data-product-grid>';
    foreach ($products as $p) { $out .= product_card($p, $opts); }
    return $out . '</div>';
}

function status_badge(string $status): string
{
    $map = [
        'pending'=>'warning','confirmed'=>'info','processing'=>'info','packed'=>'info','shipped'=>'brand','delivered'=>'success',
        'cancelled'=>'danger','return_requested'=>'warning','returned'=>'neutral','refunded'=>'neutral',
        'paid'=>'success','failed'=>'danger','authorized'=>'info','partially_refunded'=>'warning',
        'active'=>'success','draft'=>'neutral','inactive'=>'neutral','archived'=>'neutral','approved'=>'success','rejected'=>'danger',
        'open'=>'warning','resolved'=>'success','closed'=>'neutral','completed'=>'success',
    ];
    return '<span class="badge badge-' . ($map[$status] ?? 'neutral') . '">' . Helpers::e(ucwords(str_replace('_',' ',$status))) . '</span>';
}

function skeleton_grid(int $n = 4): string
{
    $out = '<div class="product-grid" aria-hidden="true">';
    for ($i=0;$i<$n;$i++) { $out .= '<div class="card" style="padding:0;overflow:hidden"><div class="skeleton" style="aspect-ratio:1;border-radius:0"></div><div style="padding:14px"><div class="skeleton" style="height:12px;width:40%;margin-bottom:10px"></div><div class="skeleton" style="height:14px;width:85%;margin-bottom:10px"></div><div class="skeleton" style="height:18px;width:35%"></div></div></div>'; }
    return $out . '</div>';
}
