<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\AppException;
use AllStore\Services\SupportService;
use AllStore\Services\UploadService;
require_once APP_ROOT . '/includes/account-layout.php';
$uid = (string)Auth::id();
$id = (string)($_GET['id'] ?? '');
$ticket = SupportService::ticketForUser($id, $uid);
if (Request::isPost()) {
    Session::requireCsrf();
    if ($ticket) {
        try { SupportService::addMessage($id, $uid, 'customer', Request::str('message','',4000), $_FILES['attachment'] ?? null); Session::flash('success','Message added.'); }
        catch (AppException $e) { Session::flash('error',$e->getMessage()); }
    }
    \AllStore\Core\Response::redirect('/account/tickets/'.$id);
}
account_header('tickets','Support conversation','Messages are private to your account and our team.');
if (!$ticket) { echo empty_state('lock','Ticket not found','This ticket does not belong to your account.','/account/tickets','My tickets'); account_footer(); return; }
$messages = SupportService::messages($id);
account_card_start(Helpers::e($ticket['subject']).' · '.Helpers::e($ticket['ticket_number']), status_badge((string)$ticket['status']));
foreach ($messages as $m) {
    $isAdmin = $m['sender_role'] === 'admin';
    echo '<div style="max-width:80%;margin:'.($isAdmin?'0 auto 14px 0':'0 0 14px auto').';background:'.($isAdmin?'var(--c-surface)':'var(--c-brand-tint)').';padding:14px;border-radius:var(--radius)"><strong style="font-size:11px;color:var(--c-muted)">'.($isAdmin?'AllStore support':'You').' · '.Helpers::timeAgo((string)$m['created_at']).'</strong><p style="font-size:13px;margin:8px 0 0;white-space:pre-line">'.Helpers::e($m['message']).'</p>';
    if ($m['attachment_url']) { $url = UploadService::url((string)$m['attachment_url']); if ($url !== '') echo '<a class="section-link" style="margin-top:8px;display:inline-flex" href="'.Helpers::e($url).'" target="_blank" rel="noopener">View attachment '.icon('external',13).'</a>'; }
    echo '</div>';
}
echo '<form method="post" enctype="multipart/form-data" style="margin-top:var(--sp-5)">'.Session::csrfField().'<div class="field"><label for="message">Reply</label><textarea class="textarea" id="message" name="message" required maxlength="4000" style="min-height:90px"></textarea></div><div class="row wrap"><input class="input" type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf" style="max-width:260px"><button class="btn btn-primary" type="submit">Send reply</button></div></form>';
account_card_end();
account_footer();
