<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';

use AllStore\Core\AppException;
use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Logger;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\NotificationService;
use AllStore\Services\PaymentService;

api_method('POST');
if (!PaymentService::stripeConfigured()) {
    Response::fail('Online payment webhook is not configured.', [], 503);
}
$payload = (string)file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$event = PaymentService::verifyWebhook($payload, $signature);
if (!is_array($event)) {
    Logger::log('warning', 'payment.webhook.rejected');
    Response::fail('Invalid webhook signature.', [], 400);
}
$type = (string)($event['type'] ?? '');
if (!in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'payment_intent.succeeded'], true)) {
    Response::ok('Event ignored.', ['event_type'=>$type]);
}
$obj = (array)($event['data']['object'] ?? []);
$metadata = (array)($obj['metadata'] ?? []);
$number = (string)($obj['client_reference_id'] ?? ($metadata['order_number'] ?? ''));
$eventId = (string)($event['id'] ?? '');
$sessionId = (string)($obj['id'] ?? '');
$amount = (int)($obj['amount_total'] ?? ($obj['amount_received'] ?? 0));
$currency = strtoupper((string)($obj['currency'] ?? ''));
if ($number === '' || $eventId === '') {
    Response::fail('Webhook is missing an order or event reference.', [], 400);
}
$order = Database::first('SELECT * FROM orders WHERE order_number=:n', ['n'=>$number]);
if (!$order) {
    Logger::log('warning', 'payment.webhook.order_missing', ['event'=>$eventId]);
    Response::fail('Order not found for this payment event.', [], 404);
}
if ((string)$order['payment_method'] !== 'stripe') {
    Response::fail('Payment method does not match this provider.', [], 409);
}
if (in_array((string)$order['status'], ['cancelled','refunded'], true)) {
    Response::fail('Order is no longer payable.', [], 409);
}
if ($amount > 0 && $amount !== (int)$order['total_cents']) {
    Logger::log('error', 'payment.webhook.amount_mismatch', ['event'=>$eventId]);
    Response::fail('Payment amount does not match the order total.', [], 409);
}
if ($currency !== '' && strtoupper((string)$order['currency']) !== $currency) {
    Response::fail('Payment currency does not match the order.', [], 409);
}
$changed = Database::tx(static function () use ($eventId, $sessionId, $event, $type, $amount, $order): bool {
    if (Database::first("SELECT id FROM transactions WHERE type='charge' AND reference=:r", ['r'=>$eventId])) {
        return false;
    }
    $safePayload = Helpers::json(['event_id'=>$eventId,'event_type'=>$type,'amount_cents'=>$amount,'session_id'=>$sessionId]);
    $affected = Database::exec("UPDATE payments SET status='paid', transaction_ref=:ref, payload=:payload, updated_at=:t
        WHERE order_id=:o AND provider='stripe' AND status<>'paid'",
        ['ref'=>$sessionId ?: $eventId, 'payload'=>$safePayload, 't'=>Helpers::now(), 'o'=>$order['id']]);
    if ($affected === 0) {
        if ((string)$order['payment_status'] === 'paid') { return false; }
        throw new AppException('No pending payment record was found for this order.', 409);
    }
    Database::update('orders', ['payment_status'=>'paid','updated_at'=>Helpers::now()], 'id=:i', ['i'=>$order['id']]);
    Database::insert('transactions', [
        'id'=>Helpers::uuid(),'order_id'=>$order['id'],
        'payment_id'=>(string)(Database::value('SELECT id FROM payments WHERE order_id=:o ORDER BY created_at DESC LIMIT 1',['o'=>$order['id']]) ?: ''),
        'type'=>'charge','amount_cents'=>(int)$order['total_cents'],'status'=>'completed',
        'reference'=>$eventId,'created_at'=>Helpers::now(),
    ]);
    Database::insert('order_status_history', [
        'id'=>Helpers::uuid(),'order_id'=>$order['id'],'from_status'=>$order['status'],
        'to_status'=>$order['status'],'note'=>'Payment confirmed by verified Stripe webhook',
        'changed_by'=>null,'created_at'=>Helpers::now(),
    ]);
    if ($order['user_id']) {
        NotificationService::create((string)$order['user_id'],'payment_confirmed','Payment confirmed','Payment for order '.$order['order_number'].' was confirmed.');
    }
    NotificationService::create(null,'payment_confirmed','Payment confirmed','Order '.$order['order_number'].' is paid.',true);
    return true;
});
if ($changed) { Logger::audit('payment.webhook.confirmed','orders',(string)$order['id'],['event'=>$eventId]); }
Response::ok($changed ? 'Payment confirmed.' : 'Event already processed.', ['order_number'=>$order['order_number'],'duplicate'=>!$changed]);
