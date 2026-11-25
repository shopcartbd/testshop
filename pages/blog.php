<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Models\CmsRepository;
require_once APP_ROOT . '/includes/components.php';
$posts = CmsRepository::blogs(true);
$page = ['title'=>'The Journal · AllStore','description'=>'Stories, guides and notes from the AllStore editorial team.','body_class'=>'page-content'];
require APP_ROOT . '/includes/header.php';
echo breadcrumbs([['name'=>'Journal']]);
echo '<section class="page-hero"><div class="container"><span class="eyebrow">AllStore Journal</span><h1>Notes on everyday objects</h1><p>Short reads on materials, routines and the things worth keeping.</p></div></section>';
echo '<section class="section"><div class="container"><div class="category-grid" style="grid-template-columns:repeat(3,1fr)">';
foreach ($posts as $b) {
    echo '<a class="card" style="overflow:hidden" href="'.Helpers::url('/blog/'.$b['slug']).'"><img src="'.Helpers::e($b['image_url'] ?: '/assets/img/promo-wide.jpg').'" alt="" style="width:100%;height:190px;object-fit:cover"><div class="card-body"><span class="eyebrow">'.Helpers::dateNice($b['published_at']).'</span><h3 style="font-size:var(--fs-lg);margin:10px 0 8px">'.Helpers::e($b['title']).'</h3><p class="text-muted" style="font-size:13px">'.Helpers::e($b['excerpt']).'</p><span class="section-link" style="margin-top:12px;display:inline-flex">Read article '.icon('arrow-right',14).'</span></div></a>';
}
echo '</div></div></section>';
require APP_ROOT . '/includes/footer.php';
