<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Models\CustomerRepository;
require_once APP_ROOT . '/includes/account-layout.php';
$refunds = CustomerRepository::refundsForUser((string)Auth::id());
account_header('refunds','Refund status','Refunds are marked complete only after confirmation.');
account_card_start('Your refunds');
if ($refunds === []) { echo empty_state('credit-card','No refunds','Approved refunds will be listed here with their status.'); }
foreach ($refunds as $f) { echo '<div class="order-row"><div class="grow"><strong style="font-size:13px">'.Helpers::e($f['order_number']).'</strong><div class="text-subtle" style="font-size:11px">'.Helpers::dateNice($f['created_at']).'</div></div>'.status_badge((string)$f['status']).'<strong>'.Helpers::money((int)$f['amount_cents']).'</strong></div>'; }
account_card_end();
account_footer();
