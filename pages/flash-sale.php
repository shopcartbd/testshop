<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Models\CmsRepository;
require_once APP_ROOT . '/includes/components.php';
$sale = CmsRepository::flashSale();
$products = CmsRepository::flashProducts(24);
$page = ['title'=>'Flash sale · AllStore','description'=>'Limited-time prices on selected products.','body_class'=>'page-flash'];
require APP_ROOT . '/includes/header.php';
echo breadcrumbs([['name'=>'Flash sale']]);
echo '<section class="section" style="padding-top:var(--sp-5)"><div class="container"><div class="flash-shell">';
echo '<div class="section-head"><div><span class="eyebrow" style="color:#a7d8c7">Limited time</span><h2>'.Helpers::e($sale['name'] ?? 'Flash sale').'</h2><p>These prices end when the timer does — or when stock runs out.</p></div>';
echo '<div class="countdown" data-countdown="'.Helpers::e($sale['ends_at'] ?? '').'"><div class="cd-box"><strong data-cd>00</strong><span>Days</span></div><div class="cd-box"><strong data-cd>00</strong><span>Hrs</span></div><div class="cd-box"><strong data-cd>00</strong><span>Min</span></div><div class="cd-box"><strong data-cd>00</strong><span>Sec</span></div></div></div>';
echo product_grid($products, '4');
echo '</div></div></section>';
require APP_ROOT . '/includes/footer.php';
