<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Auth;
use AllStore\Core\AppException;
require_once APP_ROOT . '/includes/account-layout.php';
$error = ''; $ok = '';
if (Request::isPost()) {
    Session::requireCsrf();
    try { Auth::changePassword((string)Request::input('current_password',''), (string)Request::input('new_password','')); $ok = 'Password changed successfully.'; }
    catch (AppException $e) { $error = $e->getMessage(); }
}
account_header('password','Change password','Use a unique password you do not use elsewhere.');
if ($ok !== '') echo alert_box('success',$ok);
if ($error !== '') echo alert_box('error',$error);
account_card_start();
echo '<form method="post" style="max-width:460px">'.Session::csrfField();
echo '<div class="field"><label for="current_password">Current password</label><input class="input" type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>';
echo '<div class="field"><label for="new_password">New password</label><input class="input" type="password" id="new_password" name="new_password" required minlength="10" autocomplete="new-password"></div>';
echo '<button class="btn btn-primary" type="submit">Update password</button></form>';
echo '<p class="field-hint" style="margin-top:14px">For your security, other active sessions are signed out when you change or reset your password.</p>';
account_card_end();
account_footer();
