/* AllStore :: live delivery rates and totals for checkout.
   The reusable Bangladesh cascade lives in bd-locations.js and dispatches
   bd-location-change whenever country/division/district/area changes. */
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const country = $('country');
  const division = $('division_id');
  const district = $('district_id');
  const area = $('area_id');
  const shippingBox = $('checkoutShippingMethods');
  const shippingTitle = $('checkoutShippingTitle');
  const zoneNote = $('checkoutDeliveryZone');
  if (!country || !shippingBox || !division || !district || !area) return;
  let requestSeq = 0;
  let lastKey = '';

  const renderTotals = (totals, shippingKnown) => {
    if (!totals) return;
    ['subtotal', 'discount', 'shipping', 'tax', 'total'].forEach((key) => {
      const el = document.querySelector('[data-checkout-total="' + key + '"]');
      if (el && totals[key] !== undefined) el.textContent = totals[key];
    });
    // The delivery line shows a placeholder until the area is known; as soon as
    // the server returns a real zone charge the placeholder and the hint go away.
    const deliveryCell = document.querySelector('[data-checkout-total="shipping"]');
    const hint = document.querySelector('[data-delivery-hint]');
    if (shippingKnown) {
      if (deliveryCell) delete deliveryCell.dataset.deliveryPending;
      if (hint) hint.hidden = true;
    } else {
      if (deliveryCell) deliveryCell.dataset.deliveryPending = '1';
      if (deliveryCell) deliveryCell.textContent = 'Select area';
      if (hint) {
        hint.hidden = false;
        hint.textContent = 'Delivery charge is added after you choose division, district and thana — the total above is before delivery.';
      }
    }
  };
  const showZone = (zone) => {
    if (!zoneNote || !zone) return;
    const label = zone.label || '';
    const cost = zone.cost_label || '';
    const showCost = cost && label !== 'Select delivery area';
    zoneNote.textContent = label ? (showCost ? label + ' · ' + cost : label) : '';
    zoneNote.hidden = !label;
    if (shippingTitle && label && label !== 'Select delivery area') {
      shippingTitle.textContent = 'Delivery — ' + label;
    }
  };
  const renderRates = (data) => {
    const rates = Array.isArray(data.rates) ? data.rates : [];
    const previous = document.querySelector('input[name="shipping_method"]:checked');
    const previousValue = previous ? previous.value : '';
    shippingBox.textContent = '';
    showZone(data.zone);
    if (rates.length === 0 || (rates.length === 1 && rates[0].method === 'select_area')) {
      const note = document.createElement('div');
      note.className = 'delivery-empty';
      note.textContent = rates[0]?.estimate || 'Select your delivery area to see the exact charge.';
      shippingBox.appendChild(note);
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'shipping_method';
      hidden.value = rates[0]?.method || 'select_area';
      shippingBox.appendChild(hidden);
      return;
    }
    rates.forEach((rate, index) => {
      const element = document.createElement('label');
      element.className = 'method-option';
      const input = document.createElement('input');
      input.type = 'radio';
      input.name = 'shipping_method';
      input.value = rate.method || '';
      input.checked = previousValue ? previousValue === input.value : index === 0;
      input.addEventListener('change', refreshRates);
      const holder = document.createElement('span');
      const strong = document.createElement('strong');
      strong.textContent = rate.cost_cents > 0
        ? rate.name + ' — ' + (rate.cost_label || '')
        : rate.name + ' — Free';
      const small = document.createElement('span');
      small.textContent = rate.estimate || 'Delivery estimate will be confirmed with your order.';
      holder.appendChild(strong);
      holder.appendChild(small);
      element.appendChild(input);
      element.appendChild(holder);
      shippingBox.appendChild(element);
    });
  };

  /** One canonical parameter set. Partial BD cascades are normalised to an
      empty selection so the server returns the “Select delivery area” state
      instead of a 422. This also prevents duplicate custom-event/direct-listener
      requests and stale full-area responses from being ignored. */
  const buildParams = () => {
    const isBd = country.value === 'BD';
    const complete = Boolean(division.value && district.value && area.value);
    const partial = isBd && Boolean(division.value || district.value || area.value) && !complete;
    const values = {
      country: country.value,
      division_id: (isBd && partial) ? '' : (division.value || ''),
      district_id: (isBd && partial) ? '' : (district.value || ''),
      area_id: (isBd && partial) ? '' : (area.value || ''),
    };
    const params = new URLSearchParams(values);
    const chosen = document.querySelector('input[name="shipping_method"]:checked');
    if (chosen && chosen.value && chosen.value !== 'select_area') {
      params.set('shipping_method', chosen.value);
    }
    return params;
  };

  function refreshRates() {
    const params = buildParams();
    const key = params.toString();
    if (key === lastKey) return; // one request per distinct checkout state
    lastKey = key;
    const seq = ++requestSeq; // increment only for an actual in-flight request
    fetch('/api/checkout/shipping-rates?' + key, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then((r) => r.json())
      .then((json) => {
        if (seq !== requestSeq || !json.success || !json.data) return;
        if (shippingTitle) {
          shippingTitle.textContent = json.data.requires_shipping === false
            ? 'Digital delivery — no courier needed'
            : 'Shipping method';
        }
        const rates = Array.isArray(json.data.rates) ? json.data.rates : [];
        const shippingKnown = rates.length > 0 && !(rates.length === 1 && rates[0].method === 'select_area');
        renderRates(json.data);
        renderTotals(json.data.totals, shippingKnown);
      })
      .catch(() => {
        if (lastKey === key) lastKey = ''; // allow a retry after a network error
      });
  }
  document.addEventListener('bd-location-change', refreshRates);
  // Also listen directly: a cached /api/locations response can dispatch the
  // initial custom event before this deferred script attaches its listener.
  [country, division, district, area].forEach((el) => el.addEventListener('change', refreshRates));
  shippingBox.addEventListener('change', (event) => {
    if (event.target && event.target.name === 'shipping_method') refreshRates();
  });
  refreshRates();
})();
