<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Config;
use AllStore\Models\CatalogRepository;
use AllStore\Models\CustomerRepository;
use AllStore\Services\ShippingService;
use AllStore\Services\DeliveryZoneService;
require_once APP_ROOT . '/includes/components.php';
$slug = (string)($_GET['slug'] ?? '');
$p = CatalogRepository::findBySlug($slug);
if (!$p) { http_response_code(404); require __DIR__ . '/404.php'; return; }
$userKey = Auth::id() ?: Session::id();
CatalogRepository::recordView((string)$p['id'], $userKey);
$images = CatalogRepository::images((string)$p['id']);
if ($images === []) { $images = [['url'=>$p['image_url'] ?: '/assets/img/products/headphones.jpg','alt'=>$p['name']]]; }
$variants = CatalogRepository::variants((string)$p['id']);
$attrs = CatalogRepository::productAttributes((string)$p['id']);
$meta = CatalogRepository::productMeta((string)$p['id']);
$reviews = CatalogRepository::reviews((string)$p['id'], 12);
$ratingSummary = CatalogRepository::ratingSummary((string)$p['id']);
$related = CatalogRepository::related((string)$p['id'], (string)($p['category_id'] ?? ''), 8);
$recent = CatalogRepository::recentProducts($userKey, 6);
$wishIds = Auth::id() ? array_column(CustomerRepository::wishlistItems((string)Auth::id()), 'product_id') : [];
$chain = $p['category_id'] ? CatalogRepository::breadcrumbs((string)$p['category_id']) : [];
$crumbs = [['name'=>'Shop','url'=>'/shop']];
foreach ($chain as $c) { $crumbs[] = ['name'=>$c['name'],'url'=>'/category/'.$c['slug']]; }
$crumbs[] = ['name'=>$p['name']];
$off = Helpers::percentOff((int)$p['price_cents'], (int)$p['compare_cents']);
$rates = ShippingService::rates((string)(Session::get('checkout_country') ?: Helpers::setting('default_country','US')), (int)$p['weight_grams'], (int)$p['price_cents']);
$delivery = DeliveryZoneService::publicSummary();
$page = [
    'title'=>($p['seo_title'] ?: $p['name']) . ' · AllStore',
    'description'=>$p['seo_description'] ?: Helpers::truncate((string)$p['short_description'], 160),
    'og_image'=>$p['image_url'] ?: '/assets/img/hero-marketplace.jpg',
    'body_class'=>'page-product',
    'extra_css'=>['/assets/css/product.css'],
];
require APP_ROOT . '/includes/header.php';
?>
<?= breadcrumbs($crumbs) ?>
<section class="section" style="padding-top:var(--sp-4)" itemscope itemtype="https://schema.org/Product">
<meta itemprop="sku" content="<?= Helpers::e($p['sku']) ?>">
<div class="container"><div class="pdp-shell"><div class="pdp-layout">
  <div class="pdp-gallery-wrap">
    <div class="gallery">
      <div class="gallery-thumbs">
        <?php foreach ($images as $i=>$im): ?><button type="button" data-gallery-thumb="<?= Helpers::e($im['url']) ?>" data-alt="<?= Helpers::e($im['alt'] ?? $p['name']) ?>" class="<?= $i===0?'active':'' ?>" aria-label="View image <?= $i+1 ?>"><img src="<?= Helpers::e($im['url']) ?>" alt="" loading="lazy"></button><?php endforeach; ?>
      </div>
      <div class="gallery-main" data-gallery-main><span class="pdp-gallery-badge"><?= icon('check-circle',13) ?> Quality checked</span><img src="<?= Helpers::e($images[0]['url']) ?>" alt="<?= Helpers::e($p['name']) ?>" itemprop="image" id="pdpMainImage"></div>
    </div>
    <?php if (!empty($p['video_url'])): ?><div style="margin-top:var(--sp-4)"><video controls style="width:100%;border-radius:var(--radius)" src="<?= Helpers::e($p['video_url']) ?>"></video></div><?php endif; ?>
  </div>
  <div class="pdp-summary">
    <div class="pdp-kicker"><span class="eyebrow"><?= Helpers::e($p['brand_name'] ?? 'AllStore') ?></span><?php if (!empty($p['category_name'])): ?><span class="badge badge-brand"><?= Helpers::e($p['category_name']) ?></span><?php endif; ?></div>
    <h1 itemprop="name" style="margin-top:8px"><?= Helpers::e($p['name']) ?></h1>
    <div class="pdp-meta" style="margin-top:12px">
      <span><?= stars((float)$p['rating_avg']) ?> <a href="#reviews" class="text-brand"><?= (int)$p['rating_count'] ?> reviews</a></span>
      <span>SKU: <strong data-pdp-sku><?= Helpers::e($p['sku']) ?></strong></span>
      <?php if ($p['category_name']): ?><span><a class="text-brand" href="<?= Helpers::url('/category/'.$p['category_slug']) ?>"><?= Helpers::e($p['category_name']) ?></a></span><?php endif; ?>
    </div>
    <div class="pdp-price" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
      <meta itemprop="priceCurrency" content="<?= Helpers::e((string)Config::get('app.currency')) ?>">
      <span class="now" data-pdp-price itemprop="price" content="<?= number_format($p['price_cents']/100,2,'.','') ?>"><?= Helpers::money((int)$p['price_cents']) ?></span>
      <?php if ($off > 0): ?><span class="was" data-pdp-compare><?= Helpers::money((int)$p['compare_cents']) ?></span><span class="badge badge-danger">-<?= $off ?>%</span><?php endif; ?>
      <link itemprop="availability" href="<?= (int)$p['stock'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock' ?>">
    </div>
    <p class="pdp-short text-muted"><?= Helpers::e($p['short_description']) ?></p>
    <div class="pdp-block">
      <div class="pdp-stock-card"><strong data-pdp-stock class="<?= (int)$p['stock'] > 0 ? 'text-success' : 'text-danger' ?>"><?= (int)$p['is_digital'] === 1 ? 'Digital delivery — unlimited availability' : ((int)$p['stock'] > 0 ? 'In stock ('.(int)$p['stock'].' available)' : 'Out of stock') ?></strong></div>
    </div>
    <?php if ($variants !== []): ?>
    <div class="pdp-block"><h4>Choose an option</h4>
      <div class="variant-options">
        <?php foreach ($variants as $v): ?>
        <button type="button" class="variant-option <?= (int)$v['stock'] <= 0 ? 'disabled' : '' ?>" data-variant data-variant-id="<?= Helpers::e($v['id']) ?>"
          data-price="<?= (int)$v['price_cents'] ?>" data-compare="<?= (int)$v['compare_cents'] ?>" data-sku="<?= Helpers::e($v['sku']) ?>"
          data-stock="<?= (int)$v['stock'] ?>" data-image="<?= Helpers::e($v['image_url'] ?: $p['image_url']) ?>"><?= Helpers::e($v['name']) ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" data-pdp-variant value="">
    </div>
    <?php else: ?><input type="hidden" data-pdp-variant value=""><?php endif; ?>
    <?php if (!empty($meta['custom_options'])): ?>
    <div class="pdp-block pdp-custom"><h4><?= $meta['kind'] === 'custom' ? 'Customise your order' : 'Order details' ?></h4>
      <?php foreach ($meta['custom_options'] as $option):
        $fieldId = 'custom_' . $option['slug'];
        $label = Helpers::e($option['label']) . (!empty($option['required']) ? ' *' : '');
      ?>
      <div class="field" style="margin-bottom:12px">
        <label for="<?= Helpers::e($fieldId) ?>"><?= $label ?></label>
        <?php if ($option['type'] === 'select'): ?>
          <select class="select" id="<?= Helpers::e($fieldId) ?>" name="custom_option[<?= Helpers::e($option['slug']) ?>]" data-custom-option data-label="<?= Helpers::e($option['label']) ?>" data-required="<?= !empty($option['required']) ? '1' : '0' ?>">
            <option value="">Choose an option</option>
            <?php foreach ($option['choices'] as $choice): ?><option value="<?= Helpers::e($choice) ?>"><?= Helpers::e($choice) ?></option><?php endforeach; ?>
          </select>
        <?php elseif ($option['type'] === 'textarea'): ?>
          <textarea class="textarea" id="<?= Helpers::e($fieldId) ?>" name="custom_option[<?= Helpers::e($option['slug']) ?>]" maxlength="300" style="min-height:72px" data-custom-option data-label="<?= Helpers::e($option['label']) ?>" data-required="<?= !empty($option['required']) ? '1' : '0' ?>"></textarea>
        <?php else: ?>
          <input class="input" id="<?= Helpers::e($fieldId) ?>" name="custom_option[<?= Helpers::e($option['slug']) ?>]" maxlength="300" data-custom-option data-label="<?= Helpers::e($option['label']) ?>" data-required="<?= !empty($option['required']) ? '1' : '0' ?>">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="pdp-block"><h4>Quantity</h4>
      <div class="qty-control"><button type="button" data-cart-step="-1" aria-label="Decrease quantity"><?= icon('minus',16) ?></button><input type="number" value="1" min="1" max="99" data-pdp-qty aria-label="Quantity"><button type="button" data-cart-step="1" aria-label="Increase quantity"><?= icon('plus',16) ?></button></div>
    </div>
    <div class="pdp-actions">
      <button type="button" class="btn btn-primary btn-lg" data-add-to-cart="<?= Helpers::e($p['id']) ?>" <?= (int)$p['stock'] <= 0 ? 'disabled' : '' ?>><?= icon('cart',18) ?> Add to cart</button>
      <button type="button" class="btn btn-dark btn-lg" data-add-to-cart="<?= Helpers::e($p['id']) ?>" data-buy-now="1" <?= (int)$p['stock'] <= 0 ? 'disabled' : '' ?>>Buy now</button>
      <button type="button" class="btn btn-outline btn-lg" data-wishlist="<?= Helpers::e($p['id']) ?>" aria-label="Save to wishlist" style="flex:0 0 50px;padding:0" ><?= icon('heart',18) ?></button>
      <button type="button" class="btn btn-outline btn-lg" data-compare="<?= Helpers::e($p['id']) ?>" data-compare-name="<?= Helpers::e($p['name']) ?>" aria-label="Add to compare" style="flex:0 0 50px;padding:0"><?= icon('sliders',18) ?></button>
    </div>
    <?php if ($delivery['enabled']): ?>
    <div class="pdp-delivery-card">
      <?= icon('truck',20) ?>
      <div><strong>Delivery</strong><span>Calculated at checkout</span></div>
    </div>
    <?php endif; ?>
  </div>
</div></div></div>
<div class="container" style="margin-top:var(--sp-10)">
  <div class="pdp-tabs" role="tablist">
    <button type="button" class="active" data-tab="description" role="tab">Description</button>
    <button type="button" data-tab="specs" role="tab">Specifications</button>
    <button type="button" data-tab="reviews" role="tab">Reviews (<?= (int)$p['rating_count'] ?>)</button>
    <button type="button" data-tab="qa" role="tab">Questions</button>
  </div>
  <div class="tab-panel active" data-tab-panel="description"><div class="prose" style="max-width:80ch"><p itemprop="description"><?= nl2br(Helpers::e((string)$p['full_description'])) ?></p>
    <?php if ($meta['kind'] !== 'physical'): ?><p><strong>Product type:</strong> <?= Helpers::e(ucwords(str_replace('_',' ', $meta['kind']))) ?>.</p><?php endif; ?>
    <?php if ($meta['service_details'] !== ''): ?><p><?= nl2br(Helpers::e($meta['service_details'])) ?></p><?php endif; ?>
    <?php if ($meta['kind'] === 'digital' || $meta['kind'] === 'service' || $meta['kind'] === 'subscription'): ?><p>Digital fulfilment is shown in your order after the store confirms payment. No courier is required.</p><?php endif; ?>
    <?php if (!empty($meta['bundle_items'])): ?><h3>Included in this bundle</h3><ul><?php foreach ($meta['bundle_items'] as $bundle): ?><li><?= Helpers::e($bundle['name']) ?><?= $bundle['sku'] !== '' ? ' ('.Helpers::e($bundle['sku']).')' : '' ?> × <?= (int)$bundle['qty'] ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div></div>
  <div class="tab-panel" data-tab-panel="specs"><table class="spec-table">
    <tr><th>Brand</th><td><?= Helpers::e($p['brand_name'] ?? '—') ?></td></tr>
    <tr><th>SKU</th><td><?= Helpers::e($p['sku']) ?></td></tr>
    <?php if ($p['barcode']): ?><tr><th>Barcode</th><td><?= Helpers::e($p['barcode']) ?></td></tr><?php endif; ?>
    <tr><th>Category</th><td><?= Helpers::e($p['category_name'] ?? '—') ?></td></tr>
    <tr><th>Weight</th><td><?= (int)$p['weight_grams'] ?> g</td></tr>
    <?php if ($p['dimensions']): ?><tr><th>Dimensions</th><td><?= Helpers::e($p['dimensions']) ?></td></tr><?php endif; ?>
    <?php if ($p['warranty']): ?><tr><th>Warranty</th><td><?= Helpers::e($p['warranty']) ?></td></tr><?php endif; ?>
    <?php foreach ($attrs as $a): ?><tr><th><?= Helpers::e($a['attribute_name']) ?></th><td><?= Helpers::e($a['value_label']) ?><?= $a['unit'] ? ' '.Helpers::e($a['unit']) : '' ?></td></tr><?php endforeach; ?>
  </table></div>
  <div class="tab-panel" data-tab-panel="reviews" id="reviews">
    <div class="rating-summary" style="margin-bottom:var(--sp-6)">
      <div class="text-center"><div class="rating-big"><?= number_format((float)$p['rating_avg'],1) ?></div><?= stars((float)$p['rating_avg']) ?><div class="text-muted" style="font-size:12px;margin-top:6px"><?= (int)$ratingSummary['count'] ?> verified reviews</div></div>
      <div class="rating-bars"><?php for ($i=5;$i>=1;$i--): $n=$ratingSummary['distribution'][$i]; $pct=$ratingSummary['count']>0?round($n/$ratingSummary['count']*100):0; ?>
        <div class="rating-bar"><span><?= $i ?> star</span><span class="track"><span class="fill" style="width:<?= $pct ?>%"></span></span><span><?= $n ?></span></div><?php endfor; ?></div>
    </div>
    <?php if ($reviews === []): ?><p class="text-muted">No published reviews yet. Be the first to share your experience.</p><?php endif; ?>
    <?php foreach ($reviews as $r): ?>
    <div class="review-item"><div class="row-between"><div><strong><?= Helpers::e($r['full_name']) ?></strong> <?= stars((float)$r['rating']) ?> <?php if ($r['is_verified']): ?><span class="badge badge-success">Verified purchase</span><?php endif; ?></div><small class="text-subtle"><?= Helpers::dateNice($r['created_at']) ?></small></div>
      <?php if ($r['title']): ?><h5 style="margin:10px 0 4px"><?= Helpers::e($r['title']) ?></h5><?php endif; ?>
      <p style="font-size:var(--fs-sm);color:var(--c-muted);margin:0"><?= nl2br(Helpers::e($r['body'])) ?></p>
      <?php if ($r['admin_reply']): ?><div class="alert alert-info" style="margin-top:12px"><?= icon('message',16) ?><span><strong>Store reply:</strong> <?= Helpers::e($r['admin_reply']) ?></span></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (Auth::check()):
      $eligible = CustomerRepository::eligibleReviews((string)Auth::id());
      $canReview = in_array($p['id'], array_column($eligible,'product_id'), true);
      if ($canReview): ?>
      <form data-review-form method="post" style="margin-top:var(--sp-6);max-width:640px">
        <?= Session::csrfField() ?><input type="hidden" name="product_id" value="<?= Helpers::e($p['id']) ?>">
        <h4>Write a review</h4>
        <div class="field"><label for="rating">Your rating</label><select class="select" name="rating" id="rating"><option value="5">5 — Excellent</option><option value="4">4 — Very good</option><option value="3">3 — Good</option><option value="2">2 — Fair</option><option value="1">1 — Poor</option></select></div>
        <div class="field"><label for="rtitle">Title</label><input class="input" id="rtitle" name="title" maxlength="120"></div>
        <div class="field"><label for="rbody">Review</label><textarea class="textarea" id="rbody" name="body" minlength="10" maxlength="2000" required></textarea></div>
        <button class="btn btn-primary" type="submit">Submit review</button><span class="field-hint" style="margin-left:10px">Reviews are moderated before publication.</span>
      </form>
      <?php else: ?><p class="text-muted" style="font-size:12px;margin-top:16px">You can review this product after it has been delivered to you.</p><?php endif; ?>
    <?php else: ?><p style="margin-top:16px"><a class="btn btn-outline btn-sm" href="/login?next=<?= rawurlencode('/product/'.$p['slug']) ?>">Sign in to write a review</a></p><?php endif; ?>
  </div>
  <div class="tab-panel" data-tab-panel="qa"><p class="text-muted">Have a question about this product? <a class="text-brand" href="/contact">Contact our support team</a> and we will add the answer here.</p></div>
</div>
<?php if ($related !== []): ?>
<div class="container" style="margin-top:var(--sp-9)"><?= section_head('You may also like','More from this category','/category/'.($p['category_slug'] ?? ''),'View category') ?><?= product_grid($related,'4',['wishlisted_ids'=>$wishIds]) ?></div>
<?php endif; ?>
<?php if ($recent !== []): ?>
<div class="container" style="margin-top:var(--sp-9);margin-bottom:var(--sp-9)"><?= section_head('Recently viewed','Pick up where you left off') ?><?= product_grid($recent,'4',['wishlisted_ids'=>$wishIds]) ?></div>
<?php endif; ?>
</section>
<div id="compareBar" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:85;background:var(--c-ink);color:#fff;padding:12px 20px"><div class="container row-between"><span id="compareText">Compare products</span><a class="btn btn-sm btn-outline" style="border-color:#fff;color:#fff" href="/shop">Continue shopping</a></div></div>
<?php require APP_ROOT . '/includes/footer.php';
