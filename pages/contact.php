<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\AppException;
use AllStore\Services\SupportService;
require_once APP_ROOT . '/includes/components.php';
$sent = false; $error = '';
if (Request::isPost()) {
    Session::requireCsrf();
    try { SupportService::contactMessage(Request::all()); $sent = true; }
    catch (AppException $e) { $error = $e->getMessage(); }
}
$page = ['title'=>'Contact us · AllStore','description'=>'Get in touch with the AllStore support team.','body_class'=>'page-content'];
require APP_ROOT . '/includes/header.php';
echo breadcrumbs([['name'=>'Contact us']]);
echo '<section class="page-hero"><div class="container"><span class="eyebrow">We are here to help</span><h1>Contact us</h1><p>Questions about an order, a product or a return? Send us a note and we will get back to you.</p></div></section>';
echo '<section class="section"><div class="container"><div class="checkout-layout" style="grid-template-columns:1fr 340px"><div class="card card-pad">';
if ($sent) { echo alert_box('success','Thank you — your message has been received. We will reply by email shortly.'); }
elseif ($error !== '') { echo alert_box('error',$error); }
echo '<h3 style="margin-bottom:var(--sp-5)">Send a message</h3><form method="post">'.Session::csrfField().'<div class="form-grid">';
echo '<div class="field"><label for="cname">Your name *</label><input class="input" id="cname" name="name" required maxlength="120" value="'.Helpers::e(Request::str('name')).'"></div>';
echo '<div class="field"><label for="cemail">Email address *</label><input class="input" type="email" id="cemail" name="email" required maxlength="254" value="'.Helpers::e(Request::str('email')).'"></div>';
echo '<div class="field span-2"><label for="csubject">Subject *</label><input class="input" id="csubject" name="subject" required maxlength="180" value="'.Helpers::e(Request::str('subject')).'"></div>';
echo '<div class="field span-2"><label for="cmessage">Message *</label><textarea class="textarea" id="cmessage" name="message" required minlength="10" maxlength="4000">'.Helpers::e(Request::str('message','',4000)).'</textarea></div></div>';
echo '<button class="btn btn-primary" type="submit">Send message '.icon('arrow-right',16).'</button></form></div>';
echo '<aside class="card card-pad" style="align-self:start"><h3 style="font-size:var(--fs-md);margin-bottom:var(--sp-4)">Store details</h3>';
echo '<div class="stack" style="gap:16px;font-size:var(--fs-sm)">';
echo '<div class="row" style="align-items:flex-start">'.icon('map-pin',18).'<span>'.Helpers::e(Helpers::setting('store_address','')).'</span></div>';
echo '<div class="row">'.icon('mail',18).'<a href="mailto:'.Helpers::e(Helpers::setting('contact_email','')).'">'.Helpers::e(Helpers::setting('contact_email','')).'</a></div>';
echo '<div class="row">'.icon('phone',18).'<span>'.Helpers::e(Helpers::setting('contact_phone','')).'</span></div>';
echo '<div class="row">'.icon('clock',18).'<span>Mon–Fri, 9:00–18:00</span></div></div>';
echo '<hr><p class="text-muted" style="font-size:12px">Looking for a quick answer? <a class="text-brand" href="/faq">Browse the FAQ</a> or <a class="text-brand" href="/account/tickets">open a support ticket</a>.</p></aside>';
echo '</div></div></section>';
require APP_ROOT . '/includes/footer.php';
