<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Models\CustomerRepository;
require_once APP_ROOT . '/includes/account-layout.php';
$uid = (string)Auth::id();
$notes = CustomerRepository::notifications($uid);
account_header('notifications','Notifications','Updates about your orders and account.');
echo '<div class="row" style="justify-content:flex-end;margin-bottom:12px"><button type="button" class="btn btn-outline btn-sm" data-mark-read>Mark all as read</button></div>';
account_card_start('All notifications');
if ($notes === []) { echo empty_state('bell','Nothing new','Notifications about your orders will appear here.'); }
foreach ($notes as $n) {
    echo '<div class="order-row" style="align-items:flex-start;opacity:'.((int)$n['is_read']?'.65':'1').'"><span style="width:32px;height:32px;border-radius:9px;background:var(--c-brand-soft);color:var(--c-brand);display:grid;place-items:center;flex:0 0 auto">'.icon('bell',16).'</span><div class="grow"><strong style="font-size:13px">'.Helpers::e($n['title']).'</strong><div class="text-muted" style="font-size:12px">'.Helpers::e($n['body']).'</div><small class="text-subtle">'.Helpers::timeAgo((string)$n['created_at']).'</small></div>'.((int)$n['is_read']?'':'<button type="button" class="btn btn-ghost btn-sm" data-mark-read="'.Helpers::e($n['id']).'">Mark read</button>').'</div>';
}
account_card_end();
account_footer();
