<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\AppException;
use AllStore\Services\SupportService;
require_once APP_ROOT . '/includes/account-layout.php';
$uid = (string)Auth::id();
$ok = ''; $err = '';
if (Request::isPost()) {
    Session::requireCsrf();
    try { SupportService::createTicket($uid, (string)(Auth::user()['email'] ?? ''), Request::all(), $_FILES['attachment'] ?? null); $ok = 'Support ticket created. We will reply in your account.'; }
    catch (AppException $e) { $err = $e->getMessage(); }
}
$tickets = SupportService::ticketsForUser($uid);
account_header('tickets','Support tickets','Ask a question and follow the conversation.');
if ($ok !== '') echo alert_box('success',$ok);
if ($err !== '') echo alert_box('error',$err);
account_card_start('Your tickets');
if ($tickets === []) echo '<p class="text-muted" style="font-size:13px">No support tickets yet.</p>';
foreach ($tickets as $t) { echo '<div class="order-row"><div class="grow"><strong style="font-size:13px">'.Helpers::e($t['subject']).'</strong><div class="text-subtle" style="font-size:11px">'.Helpers::e($t['ticket_number']).' · '.Helpers::dateNice($t['created_at']).'</div></div>'.status_badge((string)$t['status']).'<a class="btn btn-outline btn-sm" href="/account/tickets/'.Helpers::e($t['id']).'">Open</a></div>'; }
account_card_end();
account_card_start('Open a new ticket');
echo '<form method="post" enctype="multipart/form-data">'.Session::csrfField().'<div class="form-grid"><div class="field span-2"><label for="subject">Subject</label><input class="input" id="subject" name="subject" required maxlength="180"></div>';
echo '<div class="field"><label for="category">Category</label><select class="select" id="category" name="category"><option value="general">General</option><option value="order">Order</option><option value="returns">Returns</option><option value="payment">Payment</option><option value="technical">Technical</option><option value="other">Other</option></select></div>';
echo '<div class="field"><label for="priority">Priority</label><select class="select" id="priority" name="priority"><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>';
echo '<div class="field span-2"><label for="message">Message</label><textarea class="textarea" id="message" name="message" required minlength="10" maxlength="4000"></textarea></div>';
echo '<div class="field span-2"><label for="attachment">Attachment (optional, max 5 MB)</label><input class="input" type="file" id="attachment" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf"></div></div><button class="btn btn-primary" type="submit">Create ticket</button></form>';
account_card_end();
account_footer();
