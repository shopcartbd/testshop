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
$error = ''; $errors = [];
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        Auth::register(Request::all());
        Auth::attempt(Request::str('email','',254), (string)Request::input('password',''));
        Session::flash('success','Welcome to AllStore — your account is ready.');
        Response::redirect('/account');
    } catch (AppException $e) { $error = $e->getMessage(); $errors = $e->errors(); }
}
$page = ['title'=>'Create an account · AllStore','description'=>'Join AllStore.','noindex'=>true,'body_class'=>'page-auth'];
require APP_ROOT . '/includes/header.php';
echo '<section class="section"><div class="container"><div class="auth-wrap"><div class="auth-art"><img src="/assets/img/promo-wide.jpg" alt=""><div class="art-overlay"><div><span class="eyebrow" style="color:#cfe6dc">Join AllStore</span><h3>A calmer way to shop</h3></div></div></div>';
echo '<div class="auth-panel"><span class="eyebrow">Get started</span><h1 style="margin-top:8px">Create your account</h1><p class="text-muted" style="font-size:13px;margin-bottom:var(--sp-6)">Already a member? <a class="text-brand" href="/login">Sign in</a></p>';
if ($error !== '') { echo alert_box('error',$error); }
echo '<form method="post">'.Session::csrfField().'<div class="form-grid">';
echo '<div class="field span-2"><label for="full_name">Full name</label><input class="input" id="full_name" name="full_name" required maxlength="120" value="'.Helpers::e(Request::str('full_name')).'"></div>';
echo '<div class="field span-2"><label for="email">Email address</label><input class="input" type="email" id="email" name="email" required maxlength="254" value="'.Helpers::e(Request::str('email')).'"></div>';
echo '<div class="field"><label for="phone">Phone (optional)</label><input class="input" id="phone" name="phone" maxlength="30" value="'.Helpers::e(Request::str('phone')).'"></div>';
echo '<div class="field"><label for="password">Password</label><input class="input" type="password" id="password" name="password" required minlength="10" autocomplete="new-password"></div></div>';
echo '<label class="checkbox" style="margin-bottom:var(--sp-5)"><input type="checkbox" name="newsletter" value="1"><span>Email me about new arrivals and offers (optional)</span></label>';
echo '<button class="btn btn-primary btn-block btn-lg" type="submit">Create account '.icon('arrow-right',16).'</button></form>';
echo '<p class="field-hint" style="margin-top:var(--sp-5)">Passwords are hashed with PHP password_hash(). Minimum 10 characters with a letter and a number.</p></div></div></div></section>';
require APP_ROOT . '/includes/footer.php';
