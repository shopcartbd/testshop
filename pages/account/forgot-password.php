<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Auth;
use AllStore\Core\Config;
use AllStore\Services\MailService;
require_once APP_ROOT . '/includes/components.php';
$sent = false; $error = ''; $mailNote = '';
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $token = Auth::requestPasswordReset(Request::str('email','',254));
        $sent = true;
        if ($token !== null) {
            $link = rtrim((string)Config::get('app.url'),'/') . '/reset-password?token=' . rawurlencode($token);
            $mail = MailService::send(Request::str('email','',254), 'Reset your AllStore password', "Use this link within one hour to choose a new password:\n" . $link . "\n\nIf you did not request this, ignore this message.");
            if (!$mail['ok']) { $mailNote = 'Email delivery is not configured on this installation, so the reset link could not be sent. Please contact the store administrator.'; }
        }
    } catch (\AllStore\Core\AppException $e) { $error = $e->getMessage(); }
}
$page = ['title'=>'Forgot password · AllStore','description'=>'Reset your password.','noindex'=>true,'body_class'=>'page-auth'];
require APP_ROOT . '/includes/header.php';
echo '<section class="section"><div class="container-narrow"><div class="card card-pad" style="max-width:520px;margin:auto"><span class="eyebrow">Account recovery</span><h1 style="margin:8px 0 10px;font-size:var(--fs-2xl)">Forgot your password?</h1>';
if ($sent) { echo alert_box('info','If an account exists for that address, a reset link has been issued.'); if ($mailNote !== '') echo alert_box('warning',$mailNote); }
elseif ($error !== '') { echo alert_box('error',$error); }
echo '<p class="text-muted" style="font-size:13px;margin-bottom:var(--sp-5)">Enter your email and we will send a single-use link valid for one hour.</p>';
echo '<form method="post">'.Session::csrfField().'<div class="field"><label for="email">Email address</label><input class="input" type="email" id="email" name="email" required maxlength="254"></div><button class="btn btn-primary btn-block" type="submit">Send reset link</button></form>';
echo '<p style="margin-top:var(--sp-5);font-size:12px"><a class="text-brand" href="/login">← Back to sign in</a></p></div></div></section>';
require APP_ROOT . '/includes/footer.php';
