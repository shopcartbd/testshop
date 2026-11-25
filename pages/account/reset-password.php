<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Auth;
use AllStore\Core\AppException;
require_once APP_ROOT . '/includes/components.php';
$token = Request::str('token','',200);
$done = false; $error = '';
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        if (Auth::resetPassword(Request::str('token','',200), (string)Request::input('password',''))) {
            Session::flash('success','Your password has been reset. Please sign in.');
            \AllStore\Core\Response::redirect('/login');
        }
        $error = 'This reset link is invalid or has expired.';
    } catch (AppException $e) { $error = $e->getMessage(); }
}
$page = ['title'=>'Reset password · AllStore','description'=>'Choose a new password.','noindex'=>true,'body_class'=>'page-auth'];
require APP_ROOT . '/includes/header.php';
echo '<section class="section"><div class="container-narrow"><div class="card card-pad" style="max-width:520px;margin:auto"><span class="eyebrow">Account recovery</span><h1 style="margin:8px 0 10px;font-size:var(--fs-2xl)">Choose a new password</h1>';
if ($error !== '') echo alert_box('error',$error);
if ($token === '') echo alert_box('warning','No reset token was provided. Request a new link below.');
echo '<form method="post">'.Session::csrfField().'<input type="hidden" name="token" value="'.Helpers::e($token).'"><div class="field"><label for="password">New password</label><input class="input" type="password" id="password" name="password" required minlength="10" autocomplete="new-password"></div><button class="btn btn-primary btn-block" type="submit" '.($token===''?'disabled':'').'>Update password</button></form>';
echo '<p style="margin-top:var(--sp-5);font-size:12px"><a class="text-brand" href="/forgot-password">Request a new link</a></p></div></div></section>';
require APP_ROOT . '/includes/footer.php';
