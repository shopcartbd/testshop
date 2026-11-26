/* =====================================================================
   AllStore :: shop, filters, product detail
   ===================================================================== */
(() => {
  'use strict';
  const AS = window.AllStore;
  const qs = (s, r = document) => r.querySelector(s);
  const qsa = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  const money = c => (document.body.dataset.currencySymbol || '$') + (Number(c || 0) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

  /* --------------------------------------------------------- filter form */
  const filterForm = qs('[data-filter-form]');
  if (filterForm) {
    qsa('input[type="checkbox"], input[type="radio"], select', filterForm).forEach(el => {
      el.addEventListener('change', () => { if (!el.dataset.noAuto) filterForm.requestSubmit(); });
    });
    qs('[data-filter-reset]')?.addEventListener('click', () => { window.location.href = location.pathname; });
  }
  qs('[data-sort]')?.addEventListener('change', e => {
    const url = new URL(location.href);
    url.searchParams.set('sort', e.target.value);
    url.searchParams.delete('page');
    location.href = url.toString();
  });
  qs('[data-per-page]')?.addEventListener('change', e => {
    const url = new URL(location.href);
    url.searchParams.set('per_page', e.target.value);
    url.searchParams.delete('page');
    location.href = url.toString();
  });

  /* ---------------------------------------------------------- load more */
  const loadMore = qs('[data-load-more]');
  if (loadMore) {
    loadMore.addEventListener('click', async () => {
      const grid = qs('[data-product-grid]');
      if (!grid) return;
      const page = parseInt(loadMore.dataset.page || '2', 10);
      const url = new URL('/api/products', location.origin);
      new URL(location.href).searchParams.forEach((v, k) => { if (k !== 'page') url.searchParams.set(k, v); });
      url.searchParams.set('page', String(page));
      url.searchParams.set('per_page', loadMore.dataset.perPage || '12');
      loadMore.disabled = true; loadMore.textContent = 'Loading…';
      try {
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
        const json = await res.json();
        if (!json.success) throw new Error(json.message);
        (json.data.items || []).forEach(p => grid.insertAdjacentHTML('beforeend', productCard(p)));
        AS?.setCounts?.(json.data);
        if (page >= (json.data.pages || 1)) { loadMore.remove(); }
        else { loadMore.dataset.page = String(page + 1); loadMore.textContent = 'Load more products'; }
      } catch (err) { AS?.toast?.('error', err.message || 'Could not load more products.'); loadMore.textContent = 'Load more products'; }
      finally { loadMore.disabled = false; }
    });
  }

  function productCard(p) {
    const image = esc(p.image_url || '/assets/img/products/headphones.jpg');
    const href = '/product/' + encodeURIComponent(p.slug);
    const off = p.compare_cents > p.price_cents ? Math.round((p.compare_cents - p.price_cents) / p.compare_cents * 100) : 0;
    return '<article class="product-card">' +
      '<div class="product-media"><a href="' + href + '"><img src="' + image + '" alt="' + esc(p.name) + '" loading="lazy"></a>' +
      '<div class="badges">' + (off ? '<span class="badge badge-sale">-' + off + '%</span>' : '') + '</div>' +
      '<div class="product-quick"><button type="button" data-wishlist="' + esc(p.id) + '" aria-label="Add to wishlist"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21.2l7.8-7.7 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"/></svg></button></div>' +
      '<div class="product-add"><button class="btn btn-dark btn-sm btn-block" data-add-to-cart="' + esc(p.id) + '" data-qty="1">Add to cart</button></div></div>' +
      '<div class="product-body"><span class="product-brand">' + esc(p.brand_name || '') + '</span>' +
      '<a class="product-name" href="' + href + '">' + esc(p.name) + '</a>' +
      '<div class="product-price"><span class="now">' + money(p.price_cents) + '</span>' + (p.compare_cents > p.price_cents ? '<span class="was">' + money(p.compare_cents) + '</span>' : '') + '</div></div></article>';
  }

  /* ------------------------------------------------------------- gallery */
  const main = qs('[data-gallery-main]');
  if (main) {
    qsa('[data-gallery-thumb]').forEach(btn => {
      btn.addEventListener('click', () => {
        qsa('[data-gallery-thumb]').forEach(x => x.classList.remove('active'));
        btn.classList.add('active');
        const img = main.querySelector('img');
        img.src = btn.dataset.galleryThumb;
        img.alt = btn.dataset.alt || img.alt;
        main.classList.remove('zoomed');
      });
    });
    main.addEventListener('click', () => main.classList.toggle('zoomed'));
  }

  /* ------------------------------------------------------------- variants */
  const variantHidden = qs('[data-pdp-variant]');
  qsa('[data-variant]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (btn.classList.contains('disabled')) return;
      qsa('[data-variant]').forEach(x => x.classList.remove('active'));
      btn.classList.add('active');
      if (variantHidden) variantHidden.value = btn.dataset.variantId || '';
      const price = qs('[data-pdp-price]'); if (price) price.textContent = money(btn.dataset.price);
      const old = qs('[data-pdp-compare]'); if (old && btn.dataset.compare) old.textContent = money(btn.dataset.compare);
      const sku = qs('[data-pdp-sku]'); if (sku) sku.textContent = btn.dataset.sku || '';
      const stock = qs('[data-pdp-stock]');
      if (stock) {
        const n = parseInt(btn.dataset.stock || '0', 10);
        stock.textContent = n > 0 ? 'In stock (' + n + ' available)' : 'Out of stock';
        stock.className = n > 0 ? 'text-success' : 'text-danger';
      }
      const addButtons = qsa('.pdp-actions [data-add-to-cart]');
      addButtons.forEach(a => { a.disabled = parseInt(btn.dataset.stock || '0', 10) <= 0; });
      const img = btn.dataset.image; if (img && main) main.querySelector('img').src = img;
      addButtons.forEach(a => { a.dataset.variantId = btn.dataset.variantId || ''; });
    });
  });
  // Preselect the first available option so Add to cart never sends an empty
  // variant id on a variant product when the shopper taps immediately.
  const firstAvailableVariant = qsa('[data-variant]:not(.disabled)')[0];
  if (firstAvailableVariant && variantHidden && variantHidden.value === '') {
    firstAvailableVariant.click();
  }

  /* ------------------------------------------------------------- PDP tabs */
  qsa('[data-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
      qsa('[data-tab]').forEach(x => x.classList.remove('active'));
      qsa('[data-tab-panel]').forEach(x => x.classList.remove('active'));
      btn.classList.add('active');
      qs('[data-tab-panel="' + btn.dataset.tab + '"]')?.classList.add('active');
    });
  });

  /* ------------------------------------------------------------ review form */
  const reviewForm = qs('[data-review-form]');
  reviewForm?.addEventListener('submit', async e => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(reviewForm).entries());
    try {
      const json = await AS.post('/api/reviews/create', data);
      AS.toast('success', json.message);
      reviewForm.reset();
    } catch (err) { AS.toast('error', err.message); }
  });
})();

/* ------------------------------------------------------------- compare bar */
(() => {
  const bar = document.getElementById('compareBar');
  const text = document.getElementById('compareText');
  if (!bar || !text) return;
  let list = [];
  try { list = JSON.parse(localStorage.getItem('as_compare') || '[]'); } catch (_) { list = []; }
  const render = () => {
    bar.style.display = list.length ? 'block' : 'none';
    text.textContent = 'Compare: ' + list.map(x => x.name).join(' · ') + (list.length > 1 ? '  (' + list.length + ' selected)' : '');
    localStorage.setItem('as_compare', JSON.stringify(list));
  };
  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-compare]');
    if (!btn) return;
    e.preventDefault();
    const id = btn.dataset.compare;
    const i = list.findIndex(x => x.id === id);
    if (i >= 0) list.splice(i, 1); else list.push({ id, name: btn.dataset.compareName || 'Product' });
    list = list.slice(-4);
    btn.classList.toggle('active', list.some(x => x.id === id));
    render();
    window.AllStore?.toast?.('info', list.some(x => x.id === id) ? 'Added to compare.' : 'Removed from compare.');
  });
  render();
})();
