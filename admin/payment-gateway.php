<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Config;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Response;
use AllStore\Core\Logger;
use AllStore\Core\AppException;
use AllStore\Services\PaymentGatewayService;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('settings.manage');

if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $saved = PaymentGatewayService::save(Request::all());
        Logger::audit('admin.payment_gateway.saved', 'settings', PaymentGatewayService::KEY_PREFIX . 'provider', [
            'provider' => $saved['provider'], 'online_enabled' => $saved['online_enabled'],
        ]);
        $live = PaymentGatewayService::activeProvider();
        Session::flash('success', $live !== ''
            ? PaymentGatewayService::label($live) . ' is enabled and will appear at checkout.'
            : 'Payment settings saved. Checkout keeps showing Cash on Delivery only.');
    } catch (AppException $e) {
        Session::flash('error', $e->getMessage());
    } catch (\Throwable $e) {
        Logger::exception($e);
        Session::flash('error', 'Payment settings could not be saved.');
    }
    Response::redirect('/admin/payment-gateway');
}

$config = PaymentGatewayService::all();
$status = PaymentGatewayService::status();
$csrf = Session::csrfField();
$liveProvider = PaymentGatewayService::activeProvider();
$baseUrl = rtrim((string)Config::get('app.url'), '/');

admin_header(['title' => 'Payment settings', 'subtitle' => 'Cash on Delivery and online payment gateways', 'active' => 'payment-gateway']);

echo '<div class="alert alert-info" style="margin-bottom:16px">' . icon('info', 16) . ' '
    . ($liveProvider === ''
        ? 'Online payment is <strong>not active</strong>. The checkout shows <strong>Cash on Delivery</strong> only — nothing to configure until you are ready.'
        : 'Live online gateway: <strong>' . Helpers::e(PaymentGatewayService::label($liveProvider)) . '</strong>.')
    . ' Secrets are stored masked and are never shown again.</div>';

echo '<form method="post">' . $csrf . '<input type="hidden" name="action" value="save">';

// ---------------- Cash on Delivery ----------------
echo '<div class="card card-pad" style="margin-bottom:16px"><div class="row-between wrap" style="margin-bottom:10px"><h3>Cash on Delivery (COD)</h3><span class="badge ' . ($config['cod_enabled'] ? 'badge-brand' : 'badge-neutral') . '">' . ($config['cod_enabled'] ? 'Active' : 'Off') . '</span></div>';
echo '<label class="checkbox"><input type="checkbox" name="cod_enabled" value="1" ' . ($config['cod_enabled'] ? 'checked' : '') . '><span>Offer Cash on Delivery at checkout</span></label>';
echo '<p class="field-hint" style="margin-top:8px">COD orders need no online payment. Confirm them from <a href="/admin/orders">Orders</a> — order status changes without marking the payment paid; mark “Cash received” after the courier hands over the cash.</p></div>';

// ---------------- Online gateway ----------------
echo '<div class="card card-pad" style="margin-bottom:16px"><div class="row-between wrap" style="margin-bottom:10px"><h3>Online payment gateway</h3><span class="badge ' . ($liveProvider !== '' ? 'badge-brand' : 'badge-neutral') . '">' . ($liveProvider !== '' ? 'Live' : 'Not configured') . '</span></div>';
echo '<label class="checkbox" style="margin-bottom:12px"><input type="checkbox" name="online_enabled" value="1" data-gateway-toggle ' . ($config['online_enabled'] ? 'checked' : '') . '><span>Enable an online gateway at checkout</span></label>';
echo '<div class="form-grid"><div class="field"><label for="provider">Gateway</label><select class="select" id="provider" name="provider" data-gateway-provider>';
echo '<option value="" ' . ($config['provider'] === '' ? 'selected' : '') . '>None — Cash on Delivery only</option>';
foreach (PaymentGatewayService::PROVIDERS as $key => $meta) {
    echo '<option value="' . Helpers::e($key) . '" ' . ($config['provider'] === $key ? 'selected' : '') . '>' . Helpers::e($meta['label']) . '</option>';
}
echo '</select></div><div class="field"><label>Mode</label><label class="checkbox" style="min-height:42px"><input type="checkbox" name="sandbox" value="1" ' . ($config['sandbox'] ? 'checked' : '') . '><span>Sandbox / test mode</span></label></div></div>';

foreach (PaymentGatewayService::PROVIDERS as $key => $meta) {
    $hidden = $config['provider'] === $key ? '' : ' hidden';
    echo '<div class="card card-pad gateway-fields" data-gateway-fields="' . Helpers::e($key) . '"' . ($hidden !== '' ? ' hidden' : '') . ' style="margin-top:14px;background:var(--c-bg-subtle,#f7f8f7)">';
    echo '<div class="row-between wrap" style="margin-bottom:8px"><strong>' . Helpers::e($meta['label']) . '</strong>'
        . ((bool)$meta['live'] ? '<span class="badge badge-brand">Integration ready</span>' : '<span class="badge badge-neutral">Credentials only — integration comes later</span>') . '</div>';
    echo '<p class="field-hint" style="margin-bottom:10px">' . Helpers::e((string)$meta['hint']) . '</p><div class="form-grid">';
    foreach ($meta['fields'] as $field => [$label, $type]) {
        $value = (string)($config['credentials'][$key][$field] ?? '');
        $id = 'cred_' . $key . '_' . $field;
        echo '<div class="field' . ($type === 'textarea' ? ' span-2' : '') . '"><label for="' . Helpers::e($id) . '">' . Helpers::e($label) . '</label>';
        if ($type === 'textarea') {
            echo '<textarea class="textarea" id="' . Helpers::e($id) . '" name="' . Helpers::e($id) . '" rows="3" placeholder="' . Helpers::e($value !== '' ? 'Saved — paste a new value to replace it' : '') . '"></textarea>';
        } else {
            $inputType = $type === 'secret' ? 'password' : 'text';
            $placeholder = $value !== '' ? PaymentGatewayService::mask($value) . ' (saved)' : 'Not set';
            echo '<input class="input" type="' . $inputType . '" id="' . Helpers::e($id) . '" name="' . Helpers::e($id) . '" autocomplete="new-password" placeholder="' . Helpers::e($placeholder) . '">';
        }
        echo '</div>';
        if ($type === 'secret' && $value !== '') {
            echo '<div class="field"><label class="checkbox"><input type="checkbox" name="clear_' . Helpers::e($key . '_' . $field) . '" value="1"><span>Remove saved ' . Helpers::e(strtolower($label)) . '</span></label></div>';
        }
    }
    echo '</div></div>';
}
echo '<p class="field-hint" style="margin-top:12px">Leave a secret empty to keep the stored value. Webhook endpoint for Stripe: <code>' . Helpers::e($baseUrl . '/api/webhook/stripe') . '</code></p></div>';

// ---------------- status ----------------
echo '<div class="card card-pad" style="margin-bottom:16px"><h3 style="margin-bottom:12px">Gateway status</h3><div class="table-wrap"><table class="table"><thead><tr><th>Gateway</th><th>Selected</th><th>Integration</th><th>Missing before it can go live</th></tr></thead><tbody>';
foreach ($status as $row) {
    echo '<tr><td><strong>' . Helpers::e($row['label']) . '</strong></td>'
        . '<td>' . ($row['selected'] ? '<span class="badge badge-brand">Selected</span>' : '—') . '</td>'
        . '<td>' . ($row['live'] ? '<span class="badge badge-brand">Live</span>' : ($row['integration'] ? '<span class="badge badge-neutral">Ready to enable</span>' : '<span class="badge badge-neutral">Not built yet</span>')) . '</td>'
        . '<td>' . ($row['live'] ? '—' : ($row['missing'] !== [] ? Helpers::e(implode(', ', $row['missing'])) : (($row['integration'] && $row['selected']) ? 'Turn “Enable” on to go live' : 'Selection + credentials'))) . '</td></tr>';
}
echo '</tbody></table></div><p class="field-hint" style="margin-top:8px">Gateways marked “Not built yet” keep your credentials safely saved; their checkout integration is not enabled, so they never appear to customers as a payment option.</p></div>';

echo '<div class="row wrap" style="gap:8px"><button class="btn btn-primary btn-lg" type="submit">' . icon('check', 16) . 'Save payment settings</button><a class="btn btn-outline btn-lg" href="/admin/payments">Payment records</a><a class="btn btn-ghost" href="/admin/setup-guide">Setup guide</a></div></form>';

admin_footer();
