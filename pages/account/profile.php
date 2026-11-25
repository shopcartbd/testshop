<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\AppException;
use AllStore\Models\CustomerRepository;
require_once APP_ROOT . '/includes/account-layout.php';
$uid = (string)Auth::id();
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        CustomerRepository::updateProfile($uid, Request::all());
        Session::flash('success', 'Profile updated.');
    } catch (AppException $e) {
        Session::flash('error', $e->getMessage());
    }
    \AllStore\Core\Response::redirect('/account/profile');
}
$u = Auth::user();
account_header('profile','Profile','Keep your contact details up to date.');
account_card_start('Personal information');
echo '<form method="post">'.Session::csrfField().'<div class="form-grid">';
echo '<div class="field"><label for="full_name">Full name</label><input class="input" id="full_name" name="full_name" required maxlength="120" value="'.Helpers::e($u['full_name']).'"></div>';
echo '<div class="field"><label for="email">Email (read-only)</label><input class="input" id="email" value="'.Helpers::e($u['email']).'" disabled></div>';
echo '<div class="field span-2"><label for="phone">Phone</label><input class="input" id="phone" name="phone" maxlength="30" value="'.Helpers::e($u['phone']).'"></div></div>';
echo '<label class="checkbox" style="margin-bottom:var(--sp-5)"><input type="checkbox" name="newsletter_opt_in" value="1" '.((int)($u['newsletter_opt_in'] ?? 0) ? 'checked':'').'><span>Receive promotional emails</span></label>';
echo '<button class="btn btn-primary" type="submit">Save changes</button></form>';
account_card_end();
account_footer();
