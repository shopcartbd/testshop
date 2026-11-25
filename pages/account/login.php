<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\AppException;
require_once APP_ROOT . '/includes/components.php';
if (Auth::check()) { Response::redirect('/account'); }
$error = ''; $next = Request::str('next','',200);
if (!str_starts_with($next, '/') || str_starts_with($next, '//')) { $next = '/account'; }
if (Request::isPost()) {
    Session::requireCsrf();
    $r = Auth::attempt(Request::str('email','',254), (string)Request::input('password',''));
    if ($r['ok']) { Response::redirect($next); }
    $error = (string)$r['message'];
}
$page = ['title'=>'Sign in · AllStore','description'=>'Sign in to your AllStore account.','noindex'=>true,'body_class'=>'page-auth'];
require APP_ROOT . '/includes/header.php';
echo '<section class="section"><div class="container"><div class="auth-wrap"><div class="auth-art"><img src="/assets/img/hero-marketplace.jpg" alt=""><div class="art-overlay"><div><span class="eyebrow" style="color:#cfe6dc">Welcome back</span><h3>Everything in one place</h3><p style="color:#dbe5e1;font-size:13px;margin-top:8px">Track orders, save favourites and check out faster.</p></div></div></div>';
echo '<div class="auth-panel"><span class="eyebrow">Your account</span><h1 style="margin-top:8px">Sign in</h1><p class="text-muted" style="font-size:13px;margin-bottom:var(--sp-6)">New here? <a class="text-brand" href="/register">Create an account</a></p>';
if ($error !== '') { echo alert_box('error',$error); }
echo '<form method="post">'.Session::csrfField().'<input type="hidden" name="next" value="'.Helpers::e($next).'">';
echo '<div class="field"><label for="email">Email address</label><input class="input" type="email" id="email" name="email" required autocomplete="email" value="'.Helpers::e(Request::str('email','',254)).'"></div>';
echo '<div class="field"><label for="password">Password</label><input class="input" type="password" id="password" name="password" required autocomplete="current-password"></div>';
echo '<div class="row-between" style="margin-bottom:var(--sp-5)"><label class="checkbox"><input type="checkbox" name="remember" value="1"><span>Keep me signed in</span></label><a class="text-brand" style="font-size:12px" href="/forgot-password">Forgot password?</a></div>';
echo '<button class="btn btn-primary btn-block btn-lg" type="submit">Sign in '.icon('arrow-right',16).'</button></form>';
echo '<p class="field-hint text-center" style="margin-top:var(--sp-5)">Protected by rate limiting and secure, hashed passwords.</p></div></div></div></section>';
require APP_ROOT . '/includes/footer.php';
