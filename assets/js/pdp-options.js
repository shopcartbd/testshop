/* AllStore :: product-detail option chooser (variant / size).
 *
 * The shopper must pick a size explicitly: nothing is preselected, and
 * "Add to cart" is stopped with a clear message until a choice is made.
 * The chosen variant also drives the price, SKU, stock line and the gallery
 * image, so what the shopper sees is what goes into the cart.
 */
(() => {
  'use strict';
  const groups = Array.from(document.querySelectorAll('[data-option-group][data-option-required]'));
  if (!groups.length) return;

  const money = (cents) => {
    const n = Number(cents || 0) / 100;
    const symbol = document.body.dataset.currencySymbol || '৳';
    return symbol + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  };
  const toast = (type, message) => window.AllStore?.toast?.(type, message);

  const mainImage = () => document.querySelector('[data-pdp-main] img');
  const thumbs = () => Array.from(document.querySelectorAll('[data-pdp-thumb]'));

  const activateThumbFor = (url) => {
    if (!url) return;
    const img = mainImage();
    if (img) img.src = url;
    const match = thumbs().find((t) => t.getAttribute('data-pdp-thumb') === url);
    if (!match) return;
    thumbs().forEach((t) => {
      t.classList.toggle('active', t === match);
      t.setAttribute('aria-pressed', t === match ? 'true' : 'false');
    });
  };

  groups.forEach((group) => {
    const chips = Array.from(group.querySelectorAll('[data-option]'));
    const hidden = group.querySelector('[data-pdp-variant], input[type="hidden"]');
    const summary = group.querySelector('[data-option-summary]');
    const hint = group.querySelector('[data-option-hint]');
    const label = group.dataset.optionLabel || 'option';
    let selected = null;

    const clearError = () => {
      group.classList.remove('has-error');
      if (hint) { hint.classList.remove('is-error'); hint.hidden = selected !== null; }
    };

    const select = (chip) => {
      if (!chip || chip.getAttribute('aria-disabled') === 'true') {
        if (chip) toast('error', chip.dataset.optionName + ' is out of stock.');
        return;
      }
      selected = chip;
      chips.forEach((c) => {
        const on = c === chip;
        c.classList.toggle('active', on);
        c.setAttribute('aria-checked', on ? 'true' : 'false');
      });
      if (hidden) hidden.value = chip.dataset.variantId || chip.dataset.optionValue || '';
      if (summary) {
        const stock = parseInt(chip.dataset.stock || '0', 10);
        const note = stock <= 0 ? ' · out of stock' : (stock <= 5 ? ' · only ' + stock + ' left' : ' · in stock');
        summary.textContent = 'Selected: ' + chip.dataset.optionName + note;
      }
      clearError();

      const price = document.querySelector('[data-pdp-price]');
      if (price && chip.dataset.price) price.textContent = money(chip.dataset.price);
      const compare = document.querySelector('[data-pdp-compare]');
      if (compare && chip.dataset.optionCompare && parseInt(chip.dataset.optionCompare, 10) > parseInt(chip.dataset.price || '0', 10)) {
        compare.textContent = money(chip.dataset.optionCompare);
        compare.hidden = false;
      } else if (compare) {
        compare.hidden = true;
      }
      const sku = document.querySelector('[data-pdp-sku]');
      if (sku && chip.dataset.sku) sku.textContent = chip.dataset.sku;
      const stockLine = document.querySelector('[data-pdp-stock]');
      if (stockLine) {
        const n = parseInt(chip.dataset.stock || '0', 10);
        stockLine.textContent = n > 0 ? 'In stock (' + n + ' available)' : 'Out of stock';
        stockLine.className = n > 0 ? 'text-success' : 'text-danger';
      }
      document.querySelectorAll('.pdp-actions [data-add-to-cart]').forEach((btn) => {
        btn.dataset.variantId = chip.dataset.variantId || '';
        btn.disabled = parseInt(chip.dataset.stock || '0', 10) <= 0;
      });
      if (chip.dataset.image) activateThumbFor(chip.dataset.image);
    };

    chips.forEach((chip, index) => {
      chip.addEventListener('click', () => select(chip));
      chip.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          select(chip);
          return;
        }
        if (!['ArrowRight', 'ArrowLeft', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;
        e.preventDefault();
        const step = (e.key === 'ArrowRight' || e.key === 'ArrowDown') ? 1 : -1;
        const next = chips[(index + step + chips.length) % chips.length];
        next.focus();
      });
    });

    // Block "Add to cart" until the shopper has chosen an option. Capture phase
    // so the shared cart handler never sees the click.
    document.addEventListener('click', (e) => {
      const addBtn = e.target.closest('[data-add-to-cart]');
      if (!addBtn || !group.isConnected) return;
      if (selected) return;
      e.preventDefault();
      e.stopPropagation();
      group.classList.add('has-error');
      if (hint) { hint.classList.add('is-error'); hint.hidden = false; }
      group.scrollIntoView({ behavior: 'smooth', block: 'center' });
      toast('error', 'Choose ' + (group.dataset.optionLabel === 'Size' ? 'a size' : 'an option') + ' first.');
    }, true);

    // Keep the hidden field in sync if the browser restores form state.
    if (hidden && hidden.value) {
      const preset = chips.find((c) => (c.dataset.variantId || '') === hidden.value);
      if (preset) select(preset);
    }
  });
})();
