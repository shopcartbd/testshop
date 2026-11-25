<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Auth;
use AllStore\Models\CatalogRepository;
use AllStore\Models\CustomerRepository;
use AllStore\Services\SearchService;
require_once __DIR__ . '/components.php';

/**
 * Shared catalogue renderer used by shop/category/brand/search/deals pages.
 * Every page keeps its own PHP file and passes its own filters and metadata.
 */
function render_catalog_page(array $o): void
{
    $page = [
        'title' => $o['title'], 'description' => $o['description'] ?? '',
        'canonical' => $o['canonical'] ?? null, 'og_image' => $o['image'] ?? '/assets/img/hero-marketplace.jpg',
        'body_class' => 'page-shop',
    ];
    $get = $_GET;
    $filters = array_merge($o['filters'] ?? [], [
        'q' => Request::str('q','',120),
        'sort' => Request::str('sort','recommended',30),
        'page' => Request::int('page',1,1,10000),
        'per_page' => Request::int('per_page',12,1,60),
        'min_price' => Request::int('min_price',0,0,100000000),
        'max_price' => Request::int('max_price',0,0,100000000),
        'brand' => Request::slug('brand'),
        'category' => Request::slug('category') ?: (string)($o['filters']['category'] ?? ''),
        'rating' => Request::int('rating',0,0,5),
        'in_stock' => Request::int('in_stock',0,0,1),
        'on_sale' => Request::int('on_sale',0,0,1),
        'attributes' => [],
    ]);
    // Attribute filters arrive as attr_<slug>[] arrays.
    foreach ($_GET as $k => $v) {
        if (str_starts_with($k, 'attr_') && is_array($v)) {
            $filters['attributes'][substr($k, 5)] = array_slice(array_map('strval', $v), 0, 20);
        }
    }
    if (($o['use_search'] ?? false) || $filters['q'] !== '') {
        $result = SearchService::search($filters);
    } else {
        $result = CatalogRepository::listing($filters);
    }
    $cats = CatalogRepository::categories();
    $brands = CatalogRepository::brands();
    $range = CatalogRepository::priceRange();
    $attrDefs = [];
    if (!empty($o['category_id'])) { $attrDefs = CatalogRepository::categoryAttributes((string)$o['category_id']); }
    $wishIds = [];
    if (Auth::id()) {
        $wishIds = array_column(CustomerRepository::wishlistItems((string)Auth::id()), 'product_id');
    }
    require __DIR__ . '/header.php';
    ?>
    <?= breadcrumbs($o['crumbs'] ?? [['name'=>$o['heading']]]) ?>
    <section class="page-hero" style="padding-block:var(--sp-6)"><div class="container">
      <div class="row-between wrap">
        <div><h1><?= Helpers::e($o['heading']) ?></h1><p><?= Helpers::e($o['intro'] ?? 'A curated selection, ready to ship.') ?></p></div>
        <?php if (!empty($o['banner'])): ?><img src="<?= Helpers::e($o['banner']) ?>" alt="" style="width:220px;height:96px;object-fit:cover;border-radius:var(--radius)"><?php endif; ?>
      </div>
    </div></section>
    <section class="section" style="padding-top:var(--sp-6)"><div class="container">
      <div class="shop-layout">
        <aside class="filter-sidebar" id="filterSidebar" aria-label="Product filters">
          <div class="row-between" style="margin-bottom:var(--sp-4)"><strong>Filter products</strong><button type="button" class="icon-btn icon-btn-sm" data-close-drawer aria-label="Close filters"><?= icon('x',16) ?></button></div>
          <form method="get" action="<?= Helpers::e(Request::path()) ?>" data-filter-form>
            <?php if (($o['use_search'] ?? false) || $filters['q'] !== ''): ?><input type="hidden" name="q" value="<?= Helpers::e($filters['q']) ?>"><?php endif; ?>
            <div class="filter-block"><h4>Categories</h4><div class="filter-list">
              <?php foreach ($cats as $c): ?>
              <label class="checkbox"><input type="radio" name="category" value="<?= Helpers::e($c['slug']) ?>" <?= (Request::slug('category') === $c['slug']) ? 'checked' : '' ?>><span><?= Helpers::e($c['name']) ?></span></label>
              <?php endforeach; ?>
              <a href="<?= Helpers::e(Request::path()) ?>" class="text-brand" style="font-size:12px;margin-top:4px">Clear category</a>
            </div></div>
            <div class="filter-block"><h4>Price range</h4><div class="filter-range">
              <input class="input" type="number" name="min_price" min="0" max="1000000" step="1" placeholder="Min" value="<?= $filters['min_price'] ?: '' ?>" aria-label="Minimum price">
              <span class="text-subtle">–</span>
              <input class="input" type="number" name="max_price" min="0" max="1000000" step="1" placeholder="Max" value="<?= $filters['max_price'] ?: '' ?>" aria-label="Maximum price">
            </div><small class="field-hint">Range: <?= Helpers::money($range['min']) ?> – <?= Helpers::money($range['max']) ?></small>
            <button class="btn btn-outline btn-sm" type="submit" style="margin-top:10px">Apply price</button></div>
            <div class="filter-block"><h4>Brand</h4><div class="filter-list">
              <?php foreach ($brands as $b): ?>
              <label class="checkbox"><input type="radio" name="brand" value="<?= Helpers::e($b['slug']) ?>" <?= $filters['brand'] === $b['slug'] ? 'checked' : '' ?>><span><?= Helpers::e($b['name']) ?></span></label>
              <?php endforeach; ?>
            </div></div>
            <div class="filter-block"><h4>Availability</h4><div class="filter-list">
              <label class="checkbox"><input type="checkbox" name="in_stock" value="1" <?= $filters['in_stock'] ? 'checked' : '' ?>><span>In stock only</span></label>
              <label class="checkbox"><input type="checkbox" name="on_sale" value="1" <?= $filters['on_sale'] ? 'checked' : '' ?>><span>On sale</span></label>
            </div></div>
            <div class="filter-block"><h4>Customer rating</h4><div class="filter-list">
              <?php foreach ([4,3,2] as $r): ?>
              <label class="checkbox"><input type="radio" name="rating" value="<?= $r ?>" <?= $filters['rating'] === $r ? 'checked' : '' ?>><span><?= stars((float)$r) ?> <?= $r ?>+ stars</span></label>
              <?php endforeach; ?>
            </div></div>
            <?php foreach ($attrDefs as $a):
              $opts = CatalogRepository::attributeOptions((string)$a['id']); if ($opts === []) continue; ?>
            <div class="filter-block"><h4><?= Helpers::e($a['name']) ?></h4><div class="filter-list">
              <?php foreach ($opts as $v): ?>
              <label class="checkbox"><input type="checkbox" name="attr_<?= Helpers::e($a['slug']) ?>[]" value="<?= Helpers::e($v['value']) ?>" <?= in_array($v['value'], $filters['attributes'][$a['slug']] ?? [], true) ? 'checked' : '' ?>><span><?= Helpers::e($v['label']) ?></span></label>
              <?php endforeach; ?>
            </div></div>
            <?php endforeach; ?>
            <button class="btn btn-primary btn-block" type="submit">Apply filters</button>
            <a class="btn btn-ghost btn-block btn-sm" data-filter-reset href="<?= Helpers::e(Request::path()) ?>" style="margin-top:8px">Reset all</a>
          </form>
        </aside>
        <div>
          <div class="shop-toolbar">
            <span class="result-count"><strong><?= (int)$result['total'] ?></strong> products found</span>
            <div class="controls">
              <button type="button" class="btn btn-outline btn-sm" data-open-filters style="display:none"><?= icon('filter',15) ?> Filters</button>
              <label class="visually-hidden" for="sortSelect">Sort products</label>
              <select class="select" id="sortSelect" data-sort>
                <?php foreach (['recommended'=>'Recommended','newest'=>'Newest','price_asc'=>'Price: low to high','price_desc'=>'Price: high to low','best_selling'=>'Best selling','best_rated'=>'Best rated','discount'=>'Highest discount'] as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $filters['sort'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
              <label class="visually-hidden" for="perPage">Products per page</label>
              <select class="select" id="perPage" data-per-page style="min-width:110px">
                <?php foreach ([12,24,36,48] as $n): ?><option value="<?= $n ?>" <?= $filters['per_page'] === $n ? 'selected' : '' ?>><?= $n ?> / page</option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php if (!empty($result['did_you_mean'])): ?><?= alert_box('info','No exact match — showing the closest products instead.') ?><?php endif; ?>
          <?= product_grid($result['items'], '3', ['wishlisted_ids'=>$wishIds]) ?>
          <?php if ($result['total'] > count($result['items']) && $result['page'] < $result['pages']): ?>
          <div class="text-center" style="margin-top:var(--sp-6)"><button type="button" class="btn btn-outline" data-load-more data-page="<?= $result['page']+1 ?>" data-per-page="<?= $result['per_page'] ?>">Load more products</button></div>
          <?php endif; ?>
          <?= pagination($result, (string)Request::path()) ?>
        </div>
      </div>
    </div></section>
    <?php
    require __DIR__ . '/footer.php';
}
