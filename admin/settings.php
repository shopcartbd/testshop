<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Response;
use AllStore\Core\Logger;
use AllStore\Core\AppException;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('settings.manage');
$fields=[
 'store_tagline'=>['Store tagline','text','A considered marketplace for everyday goods.'],
 'announcement_text'=>['Announcement text','text',''],
 'announcement_enabled'=>['Show announcement','bool','1'],
 'footer_about'=>['Footer description','textarea','A modern marketplace for considered everyday goods.'],
 'newsletter_heading'=>['Newsletter heading','text','Stay in the loop'],
 'newsletter_text'=>['Newsletter text','text','New arrivals, editorial picks and member-only offers. No noise.'],
 'contact_email'=>['Contact email','email','hello@example.com'],
 'contact_phone'=>['Contact phone','text',''],
 'store_address'=>['Store address','textarea',''],
 'default_country'=>['Legacy default country (2 letters)','text','BD'],
 'checkout_default_country'=>['Checkout default country (2 letters)','text','BD'],
 'default_shipping_cents'=>['Fallback shipping cost (cents)','number','799'],
 'free_shipping_threshold_cents'=>['Free shipping threshold (cents)','number','7500'],
 'cod_enabled'=>['Enable Cash on Delivery','bool','1'],
 'social_instagram'=>['Instagram URL','url','#'],
 'social_x'=>['X URL','url','#'],
 'social_facebook'=>['Facebook URL','url','#'],
];
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        foreach($fields as $key=>[$label,$type,$default]) {
            $v=trim((string)Request::input($key,Helpers::setting($key,$default)));
            if($type==='bool') $v=Request::bool($key)?'1':'0';
            if($type==='email' && $v!=='' && !filter_var($v,FILTER_VALIDATE_EMAIL)) throw new AppException('Enter a valid contact email.',422);
            if($type==='url' && $v!=='' && $v!=='#' && !preg_match('#^https://[a-z0-9.\-]+#i',$v)) throw new AppException('Social links must be HTTPS URLs or #.',422);
            if($type==='number' && (!is_numeric($v) || (int)$v<0)) throw new AppException($label.' must be a non-negative number.',422);
            if($key==='default_country' || $key==='checkout_default_country') $v=strtoupper(substr(preg_replace('/[^A-Za-z]/','',$v) ?: 'BD',0,2));
            Helpers::setSetting($key,$v,'general');
        }
        Logger::audit('admin.settings.updated','settings'); Session::flash('success','Store settings saved.');
    } catch(AppException $e) { Session::flash('error',$e->getMessage()); }
      catch(\Throwable $e) { Logger::exception($e); Session::flash('error','Settings could not be saved.'); }
    Response::redirect('/admin/settings');
}
admin_header(['title'=>'Settings','subtitle'=>'Storefront identity, contact details and checkout defaults','active'=>'settings']);
echo '<div class="card card-pad"><h3 style="margin-bottom:16px">General settings</h3><form method="post">'.Session::csrfField().'<div class="form-grid">';
foreach($fields as $key=>[$label,$type,$default]) {
    $v=Helpers::setting($key,$default);
    echo '<div class="field'.($type==='textarea'?' span-2':'').'"><label for="s_'.Helpers::e($key).'">'.Helpers::e($label).'</label>';
    if($type==='bool') echo '<label class="checkbox"><input type="checkbox" id="s_'.Helpers::e($key).'" name="'.Helpers::e($key).'" value="1" '.($v==='1'?'checked':'').'><span>Enabled</span></label>';
    elseif($type==='textarea') echo '<textarea class="textarea" id="s_'.Helpers::e($key).'" name="'.Helpers::e($key).'" maxlength="1000">'.Helpers::e($v).'</textarea>';
    else { $t=$type==='number'?'number':($type==='email'?'email':($type==='url'?'url':'text')); echo '<input class="input" type="'.$t.'" id="s_'.Helpers::e($key).'" name="'.Helpers::e($key).'" value="'.Helpers::e($v).'"'.($t==='number'?' step="1" min="0"':'').'>'; }
    echo '</div>';
}
echo '</div><button class="btn btn-primary btn-lg" type="submit" style="margin-top:18px">'.icon('check',16).'Save settings</button></form></div>';
echo '<div class="alert alert-info" style="margin-top:16px">'.icon('info',16).'<span>Secrets, database credentials, payment keys and the one-time admin bootstrap password are never stored here or shown in the browser. Configure them only as server-side environment variables.</span></div>';
admin_footer();
