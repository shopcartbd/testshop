<?php
/** Simplified Bangladesh checkout fields. Included from pages/checkout.php. */
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Request;
$nameV = Helpers::e((string)($u['full_name'] ?? $default['full_name'] ?? ($old['full_name'] ?? Request::str('full_name'))));
$phoneV = Helpers::e((string)($u['phone'] ?? $default['phone'] ?? ($old['phone'] ?? Request::str('phone'))));
$line1V = $input('line1', (string)($default['line1'] ?? ''));
$districtSelected = (string)($old['district_id'] ?? ($default['district_id'] ?? ''));
$areaSelected = (string)($old['area_id'] ?? ($default['area_id'] ?? ''));
$divisionSelected = (string)($old['division_id'] ?? ($default['division_id'] ?? ''));
?>
<input type="hidden" name="line2" value="">
<input type="hidden" name="postal_code" value="">
<div class="checkout-step">
  <div class="step-head"><span class="step-num">1</span><h3>Customer</h3></div>
  <div class="form-grid">
    <div class="field"><label for="full_name">Full name *</label><input class="input" id="full_name" name="full_name" autocomplete="name" required maxlength="120" value="<?= $nameV ?>"></div>
    <div class="field"><label for="phone">Phone number *</label><input class="input" type="tel" inputmode="tel" id="phone" name="phone" autocomplete="tel" required maxlength="30" value="<?= $phoneV ?>"></div>
  </div>
</div>
<div id="bdLocationFields" class="checkout-step bd-location-card" data-simple-bd="1" data-needs-shipping="<?= $needsShipping ? '1' : '0' ?>"<?= $needsShipping ? '' : ' hidden' ?>>
  <div class="step-head"><span class="step-num">2</span><h3>Delivery area</h3></div>
  <div id="checkoutDeliveryZone" class="delivery-zone-note"<?= $zoneLabel !== '' ? '' : ' hidden' ?>><?= Helpers::e($zoneLabel !== '' ? ($zoneCost !== '' ? $zoneLabel.' · '.$zoneCost : $zoneLabel) : '') ?></div>
  <select class="select" id="country" name="country" hidden><option value="BD" selected>Bangladesh</option></select>
  <input type="hidden" id="city" name="city" value="">
  <input type="hidden" id="region" name="region" value="">
  <div class="form-grid">
    <div class="field">
      <label for="division_id">Division / বিভাগ *</label>
      <select class="select" id="division_id" name="division_id" data-selected="<?= Helpers::e($divisionSelected) ?>"<?= $needsShipping ? ' required' : '' ?>>
        <option value="">Select division</option>
        <?php foreach ($bd['divisions'] as $d): ?>
        <option value="<?= Helpers::e($d['id']) ?>"><?= Helpers::e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="district_id">District / জেলা *</label>
      <select class="select" id="district_id" name="district_id" data-selected="<?= Helpers::e($districtSelected) ?>"<?= $needsShipping ? ' required' : '' ?>>
        <option value="">Select district</option>
        <?php foreach ($bd['divisions'] as $d): foreach ($d['districts'] as $x): ?>
        <option value="<?= Helpers::e($x['id']) ?>" data-division="<?= Helpers::e($d['id']) ?>"><?= Helpers::e($x['name']) ?><?= $x['en'] !== '' ? ' · '.Helpers::e($x['en']) : '' ?></option>
        <?php endforeach; endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="area_id">Thana / Upazila *</label>
      <select class="select" id="area_id" name="area_id" data-selected="<?= Helpers::e($areaSelected) ?>"<?= $needsShipping ? ' required' : '' ?>>
        <option value="">Select thana</option>
      </select>
    </div>
    <div class="field span-2">
      <label for="line1">Full address *</label>
      <textarea class="textarea" id="line1" name="line1" rows="3" maxlength="300" placeholder="House, road, area, landmark"<?= $needsShipping ? ' required' : '' ?>><?= $line1V ?></textarea>
    </div>
  </div>
</div>
