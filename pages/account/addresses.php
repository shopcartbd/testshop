<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\AppException;
use AllStore\Models\CustomerRepository;
require_once APP_ROOT . '/includes/account-layout.php';
$uid = (string)Auth::id();
$err = '';
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        if (Request::str('action','',20) === 'delete') { CustomerRepository::deleteAddress($uid, Request::id('id')); Session::flash('success','Address removed.'); }
        else { CustomerRepository::saveAddress($uid, Request::all(), Request::id('id') ?: null); Session::flash('success','Address saved.'); }
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); }
    Response::redirect('/account/addresses');
}
$addresses = CustomerRepository::addresses($uid);
$edit = null;
if (($id = Request::id('edit')) !== '') { foreach ($addresses as $a) { if ($a['id'] === $id) { $edit = $a; } } }
account_header('addresses','Address book','Manage where your orders are delivered.');
echo '<div class="row wrap" style="gap:14px;align-items:flex-start">';
foreach ($addresses as $a) {
    echo '<div class="card card-pad" style="width:min(100%,320px)"><div class="row-between"><strong>'.Helpers::e($a['label']).'</strong>'.((int)$a['is_default_shipping']?'<span class="badge badge-brand">Default</span>':'').'</div>';
    echo '<div class="text-muted" style="font-size:13px;margin-top:10px;line-height:1.6">'.Helpers::e($a['full_name']).'<br>'.Helpers::e($a['line1']).($a['line2']?'<br>'.Helpers::e($a['line2']):'').'<br>'.Helpers::e($a['city']).($a['region']?', '.Helpers::e($a['region']):'').' '.Helpers::e($a['postal_code']).'<br>'.Helpers::e($a['country']).'<br>'.Helpers::e($a['phone']).'</div>';
    echo '<div class="row" style="margin-top:14px"><a class="btn btn-outline btn-sm" href="?edit='.Helpers::e($a['id']).'">Edit</a><form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'.Helpers::e($a['id']).'"><button class="btn btn-ghost btn-sm text-danger" data-confirm="Delete this address?" type="submit">Delete</button></form></div></div>';
}
echo '</div>';
if ($addresses === []) echo empty_state('map-pin','No addresses saved','Add an address to check out faster next time.');
account_card_start($edit ? 'Edit address' : 'Add a new address');
$v = fn($k) => Helpers::e((string)($edit[$k] ?? Request::str($k)));
echo '<form method="post">'.Session::csrfField().'<input type="hidden" name="id" value="'.Helpers::e((string)($edit['id'] ?? '')).'"><div class="form-grid">';
echo '<div class="field"><label for="label">Label</label><input class="input" id="label" name="label" value="'.$v('label').'" maxlength="40"></div>';
echo '<div class="field"><label for="full_name">Full name</label><input class="input" id="full_name" name="full_name" required value="'.$v('full_name').'" maxlength="120"></div>';
echo '<div class="field"><label for="phone">Phone</label><input class="input" id="phone" name="phone" required value="'.$v('phone').'" maxlength="30"></div>';
echo '<div class="field"><label for="country">Country</label><input class="input" id="country" name="country" maxlength="2" value="'.($edit ? Helpers::e($edit['country']) : 'US').'"></div>';
echo '<div class="field span-2"><label for="line1">Street address</label><input class="input" id="line1" name="line1" required value="'.$v('line1').'" maxlength="180"></div>';
echo '<div class="field span-2"><label for="line2">Apartment / suite</label><input class="input" id="line2" name="line2" value="'.$v('line2').'" maxlength="180"></div>';
echo '<div class="field"><label for="city">City</label><input class="input" id="city" name="city" required value="'.$v('city').'" maxlength="80"></div>';
echo '<div class="field"><label for="region">State / Region</label><input class="input" id="region" name="region" value="'.$v('region').'" maxlength="80"></div>';
echo '<div class="field"><label for="postal_code">Postal code</label><input class="input" id="postal_code" name="postal_code" value="'.$v('postal_code').'" maxlength="20"></div></div>';
echo '<label class="checkbox" style="margin-bottom:var(--sp-4)"><input type="checkbox" name="is_default_shipping" value="1" '.((int)($edit['is_default_shipping'] ?? 0)?'checked':'').'><span>Use as default shipping address</span></label>';
echo '<button class="btn btn-primary" type="submit">Save address</button></form>';
account_card_end();
account_footer();
