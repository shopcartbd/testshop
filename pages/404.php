<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
require_once APP_ROOT . '/includes/components.php';
http_response_code(404);
$page = ['title'=>'Page not found · AllStore','description'=>'The page you are looking for does not exist.','noindex'=>true];
require APP_ROOT . '/includes/header.php';
echo '<section class="section error-page"><div class="container"><div class="error-code">404</div><h1 style="margin-top:var(--sp-3)">We could not find that page</h1><p class="text-muted" style="max-width:52ch;margin:14px auto 24px">The link may be out of date, or the product may have moved. Let us get you back on track.</p><div class="row" style="justify-content:center;gap:10px"><a class="btn btn-primary" href="/">Back to homepage</a><a class="btn btn-outline" href="/shop">Browse products</a></div></div></section>';
require APP_ROOT . '/includes/footer.php';
