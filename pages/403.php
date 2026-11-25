<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
require_once APP_ROOT . '/includes/components.php';
http_response_code(403);
$page = ['title'=>'Access denied · AllStore','description'=>'You do not have permission to view this page.','noindex'=>true];
require APP_ROOT . '/includes/header.php';
echo '<section class="section error-page"><div class="container"><div class="error-code">403</div><h1 style="margin-top:var(--sp-3)">Access denied</h1><p class="text-muted" style="max-width:52ch;margin:14px auto 24px">'.Helpers::e((string)($GLOBALS['error_message'] ?? 'You do not have permission to view this page.')).'</p><div class="row" style="justify-content:center;gap:10px"><a class="btn btn-primary" href="/">Back to homepage</a><a class="btn btn-outline" href="/account">My account</a></div></div></section>';
require APP_ROOT . '/includes/footer.php';
