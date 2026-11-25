<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Config;
require_once APP_ROOT . '/includes/components.php';
\AllStore\Core\Response::noCache();
if (Auth::isAdmin()) { Response::redirect('/admin'); }
$error = '';
$next = Request::str('next','/admin',200);
if (Request::isPost()) {
    Session::requireCsrf();
    $r = Auth::attempt(Request::str('email','',254), (string)Request::input('password',''), true);
    if ($r['ok']) {
        if (!str_starts_with($next,'/admin') || str_starts_with($next,'//')) { $next = '/admin'; }
        Response::redirect($next);
    }
    $error = (string)$r['message'];
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Administrator sign in · <?= Helpers::e((string)Config::get('app.name')) ?></title>
<link rel="icon" href="/assets/img/favicon.svg"><link rel="stylesheet" href="/assets/css/tokens.css"><link rel="stylesheet" href="/assets/css/app.css"></head>
<body class="admin-login"><main class="auth-wrap" style="margin:7vh auto;max-width:920px"><div class="auth-art"><img src="/assets/img/hero-marketplace.jpg" alt=""><div class="art-overlay"><div><span class="eyebrow" style="color:#cfe6dc">Protected area</span><h3>Store administration</h3><p style="font-size:13px;color:#dbe5e1;margin-top:8px">Every action is authenticated, permission-checked and audit logged.</p></div></div></div>
<div class="auth-panel"><span class="eyebrow"><?= Helpers::e((string)Config::get('app.name')) ?></span><h1 style="margin-top:8px">Admin sign in</h1><p class="text-muted" style="font-size:13px;margin-bottom:22px">Use the account created with the one-time CLI bootstrap.</p>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= icon('alert',16) ?><span><?= Helpers::e($error) ?></span></div><?php endif; ?>
<form method="post"><?= Session::csrfField() ?><input type="hidden" name="next" value="<?= Helpers::e($next) ?>">
<div class="field"><label for="email">Email address</label><input class="input" type="email" id="email" name="email" required autocomplete="username"></div>
<div class="field"><label for="password">Password</label><input class="input" type="password" id="password" name="password" required autocomplete="current-password"></div>
<button class="btn btn-primary btn-block btn-lg" type="submit">Sign in <?= icon('arrow-right',16) ?></button></form>
<p class="field-hint" style="margin-top:18px">There is no default password. Run <code>scripts/bootstrap_admin.php</code> once with server-side environment variables to create the first administrator.</p>
<a class="section-link" style="margin-top:14px;display:inline-flex" href="/"><?= icon('arrow-left',14) ?> Back to store</a></div></main></body></html>
