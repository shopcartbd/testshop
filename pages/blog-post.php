<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Models\CmsRepository;
require_once APP_ROOT . '/includes/components.php';
$post = CmsRepository::blog((string)($_GET['slug'] ?? ''));
if (!$post) { http_response_code(404); require __DIR__ . '/404.php'; return; }
$page = ['title'=>$post['seo_title'] ?: $post['title'].' · AllStore','description'=>$post['seo_description'] ?: $post['excerpt'],'og_image'=>$post['image_url'] ?: '/assets/img/hero-marketplace.jpg','body_class'=>'page-content'];
require APP_ROOT . '/includes/header.php';
echo breadcrumbs([['name'=>'Journal','url'=>'/blog'],['name'=>$post['title']]]);
echo '<article class="section"><div class="container-narrow"><span class="eyebrow">'.Helpers::dateNice($post['published_at']).' · '.Helpers::e($post['author'] ?: 'AllStore Editorial').'</span><h1 style="font-size:var(--fs-3xl);margin:12px 0 16px">'.Helpers::e($post['title']).'</h1><p class="text-muted" style="font-size:var(--fs-lg)">'.Helpers::e($post['excerpt']).'</p>';
if ($post['image_url']) { echo '<img src="'.Helpers::e($post['image_url']).'" alt="" style="width:100%;border-radius:var(--radius-lg);margin:var(--sp-7) 0">'; }
echo '<div class="prose"><p>'.nl2br(Helpers::e($post['body'])).'</p></div><hr><a class="section-link" href="/blog">'.icon('arrow-left',15).' Back to the Journal</a></div></article>';
require APP_ROOT . '/includes/footer.php';
