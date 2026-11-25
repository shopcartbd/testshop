<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Models\CmsRepository;
require_once APP_ROOT . '/includes/components.php';
$faqs = CmsRepository::faqs();
$groups = [];
foreach ($faqs as $f) { $groups[$f['category']][] = $f; }
$page = ['title'=>'Frequently asked questions · AllStore','description'=>'Answers about delivery, returns, payments and your account.','body_class'=>'page-content'];
require APP_ROOT . '/includes/header.php';
echo breadcrumbs([['name'=>'FAQ']]);
echo '<section class="page-hero"><div class="container"><span class="eyebrow">Help centre</span><h1>Frequently asked questions</h1><p>Everything you need to know about shopping with AllStore.</p></div></section>';
echo '<section class="section"><div class="container-narrow">';
if ($faqs === []) { echo empty_state('message','No questions yet','Our help centre is being updated. Please contact support.','/contact','Contact support'); }
foreach ($groups as $cat=>$items) {
    echo '<h3 style="margin:var(--sp-8) 0 var(--sp-4);font-size:var(--fs-lg)">'.Helpers::e(ucfirst((string)$cat)).'</h3><div class="card" style="overflow:hidden">';
    foreach ($items as $f) { echo '<details style="border-bottom:1px solid var(--c-border);padding:16px 20px"><summary style="cursor:pointer;font-weight:600;font-size:14px">'.Helpers::e($f['question']).'</summary><p class="text-muted" style="font-size:13px;margin:12px 0 2px">'.Helpers::e($f['answer']).'</p></details>'; }
    echo '</div>';
}
echo '<div class="card card-pad text-center" style="margin-top:var(--sp-8)"><h3 style="font-size:var(--fs-md)">Still need help?</h3><p class="text-muted" style="font-size:13px;margin:8px 0 16px">Our team usually replies within one business day.</p><a class="btn btn-primary btn-sm" href="/contact">Contact support</a></div>';
echo '</div></section>';
require APP_ROOT . '/includes/footer.php';
