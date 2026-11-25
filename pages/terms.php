<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Models\CmsRepository;
require_once APP_ROOT . '/includes/components.php';
$c = CmsRepository::page('terms');
$page = ['title'=>($c['seo_title'] ?? 'Terms & Conditions') . ' · AllStore','description'=>$c['seo_description'] ?? 'Terms & Conditions','body_class'=>'page-content'];
require APP_ROOT . '/includes/header.php';
echo breadcrumbs([['name'=>'Terms & Conditions']]);
echo '<section class="page-hero"><div class="container"><span class="eyebrow">AllStore</span><h1>'.Helpers::e($c['title'] ?? 'Terms & Conditions').'</h1></div></section>';
echo '<section class="section"><div class="container-narrow"><div class="prose"><p>'.nl2br(Helpers::e($c['body'] ?? 'This page is being prepared by the store administrator.')).'</p></div>';
echo '<div class="card card-pad" style="margin-top:var(--sp-8)"><h3 style="font-size:var(--fs-md)">Need a hand?</h3><p class="text-muted" style="font-size:var(--fs-sm);margin:8px 0 14px">Our support team is happy to help with any question.</p><a class="btn btn-outline btn-sm" href="/contact">Contact support</a></div></div></section>';
require APP_ROOT . '/includes/footer.php';
