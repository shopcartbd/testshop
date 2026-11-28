/* =====================================================================
   AllStore :: global storefront behaviour (ES6+, no dependencies)
   ===================================================================== */
(() => {
  'use strict';
  const body = document.body;
  let csrf = body.dataset.csrf || '';
  const AS = {
    get csrf() { return csrf; },
    async request(path, options = {}) {
      const opts = {
        method: options.method || 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      };
      if (options.data !== undefined) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(options.data);
      } else if (options.form) {
        opts.body = new URLSearchParams(new FormData(options.form));
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
      }
      let timer = null, controller = null;
      if (options.timeout > 0 && typeof AbortController !== 'undefined') {
        controller = new AbortController();
        opts.signal = controller.signal;
        timer = setTimeout(() => controller.abort(), options.timeout);
      }
      let res;
      try {
        res = await fetch(path, opts);
      } catch (_) {
        if (controller && controller.signal.aborted) {
          throw new Error('This is taking longer than expected. Please try again.');
        }
        throw new Error('Network error. Please check your connection.');
      } finally {
        if (timer) clearTimeout(timer);
      }
      let json;
      try { json = await res.json(); } catch (_) { throw new Error('Unexpected server response.'); }
      if (json.csrf_token) {
        csrf = json.csrf_token;
        body.dataset.csrf = csrf;
        document.querySelectorAll('input[name="csrf_token"]').forEach(el => { el.value = csrf; });
      }
      if (!res.ok || !json.success) {
        const err = new Error(json.message || 'Request failed.');
        err.errors = json.errors || [];
        err.status = res.status;
        // A 419 happens before the write. The response carries the fresh token,
        // so retry exactly once instead of forcing the shopper to reload.
        if (res.status === 419 && !options.csrfRetried) {
          return AS.request(path, Object.assign({}, options, { csrfRetried: true }));
        }
        throw err;
      }
      return json;
    },
    post(path, data, options = {}) {
      return this.request(path, Object.assign({ method: 'POST', data }, options));
    },
    toast(type, message) {
      const stack = document.getElementById('toastStack');
      if (!stack) return;
      const el = document.createElement('div');
      el.className = 'toast ' + type;
      const icons = { success: '<path d="M20 6 9 17l-5-5"/>', error: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/>', info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4m0-4h.01"/>' };
      el.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (icons[type] || icons.info) + '</svg><span></span>';
      el.querySelector('span').textContent = message;
      stack.appendChild(el);
      setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(8px)'; setTimeout(() => el.remove(), 250); }, 4200);
      return el;
    },
    setCounts(data) {
      if (!data) return;
      if (data.cart_count !== undefined) {
        document.querySelectorAll('[data-cart-count]').forEach(el => {
          el.textContent = data.cart_count;
          el.dataset.zero = data.cart_count > 0 ? '0' : '1';
        });
      }
      if (data.wishlist_count !== undefined) {
        document.querySelectorAll('[data-wishlist-count]').forEach(el => {
          el.textContent = data.wishlist_count;
          el.dataset.zero = data.wishlist_count > 0 ? '0' : '1';
        });
      }
    },
    adjustCartCount(delta) {
      document.querySelectorAll('[data-cart-count]').forEach(el => {
        const current = parseInt(el.textContent, 10) || 0;
        const next = Math.max(0, current + delta);
        el.textContent = next;
        el.dataset.zero = next > 0 ? '0' : '1';
      });
    },
  };
  window.AllStore = AS;

  /* --------------------------------------------------------- toasts from server */
  document.querySelectorAll('#toastStack .toast[data-server]').forEach(t => setTimeout(() => t.remove(), 5000));

  /* --------------------------------------------------------------- add to cart */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-add-to-cart]');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    const qty = Math.max(1, Math.min(99,
      parseInt(btn.dataset.qty || document.querySelector('[data-pdp-qty]')?.value || '1', 10) || 1));
    const productId = btn.dataset.productId || btn.dataset.addToCart;
    if (!productId) { AS.toast('error', 'Choose a product.'); return; }
    const variantId = btn.dataset.variantId || document.querySelector('[data-pdp-variant]')?.value || '';
    // Product-specific custom fields are validated before any optimistic UI change.
    const customOptions = {};
    for (const field of document.querySelectorAll('[data-custom-option]')) {
      const match = /\[([^\]]+)\]/.exec(field.name || '');
      const key = match ? match[1] : (field.id || '').replace(/^custom_/, '');
      const label = field.dataset.label || key || 'This field';
      const value = String(field.value || '').trim();
      if (field.dataset.required === '1' && !value) {
        AS.toast('error', label + ' is required.');
        field.focus();
        return;
      }
      if (key && value) customOptions[key] = { label: label, value: value };
    }
    const scope = btn.closest('.pdp-actions');
    const buttons = Array.from((scope || btn.parentElement || document).querySelectorAll('[data-add-to-cart]'));
    if (!buttons.includes(btn)) buttons.unshift(btn);
    const snapshots = buttons.map(b => ({ el: b, disabled: b.disabled, html: b.innerHTML, busy: b.getAttribute('aria-busy') }));
    const restore = () => {
      if (restore.done) return;
      restore.done = true;
      snapshots.forEach(s => {
        s.el.disabled = s.disabled;
        s.el.innerHTML = s.html;
        if (s.busy === null) s.el.removeAttribute('aria-busy'); else s.el.setAttribute('aria-busy', s.busy);
      });
    };
    buttons.forEach(b => { b.disabled = true; });
    btn.innerHTML = '<span>Adding…</span>';
    btn.setAttribute('aria-busy', 'true');
    // Optimistic feedback: the header count changes immediately, then the
    // authoritative server count replaces it. Any failure rolls it back.
    AS.adjustCartCount(qty);
    const progressToast = AS.toast('info', 'Saving to your cart…');
    let succeeded = false;
    try {
      const json = await AS.post('/api/cart/add', {
        product_id: productId,
        variant_id: variantId,
        quantity: qty,
        custom_options: customOptions,
      }, { timeout: 30000 });
      succeeded = true;
      if (progressToast) progressToast.remove();
      AS.setCounts(json.data);
      AS.toast('success', json.message || 'Added to your cart.');
      btn.innerHTML = '<span>Added ✓</span>';
      if (btn.dataset.buyNow === '1' || btn.dataset.goToCart === '1') {
        restore();
        window.location.href = '/checkout';
        return;
      }
      setTimeout(restore, 900);
    } catch (err) {
      if (progressToast) progressToast.remove();
      AS.adjustCartCount(-qty);
      AS.toast('error', err.message);
      restore();
    } finally {
      if (!succeeded) restore();
    }
  });

  /* ---------------------------------------------------------------- wishlist */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-wishlist]');
    if (!btn) return;
    e.preventDefault();
    if (body.dataset.loggedIn !== '1') {
      AS.toast('info', 'Please sign in to save items.');
      window.location.href = '/login?next=' + encodeURIComponent(location.pathname);
      return;
    }
    try {
      const json = await AS.post('/api/wishlist/add', { product_id: btn.dataset.wishlist });
      btn.classList.toggle('active', !!(json.data && json.data.added));
      AS.setCounts(json.data);
      AS.toast('success', json.message);
    } catch (err) { AS.toast('error', err.message); }
  });

  /* ------------------------------------------------------------ cart quantity */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-cart-step]');
    if (!btn) return;
    e.preventDefault();
    const wrap = btn.closest('.qty-control');
    const input = wrap.querySelector('input');
    const next = Math.max(0, Math.min(99, (parseInt(input.value, 10) || 1) + parseInt(btn.dataset.cartStep, 10)));
    input.value = Math.max(1, next);
    if (!btn.dataset.cartItem) return;
    try {
      const json = await AS.post('/api/cart/update', { item_id: btn.dataset.cartItem, quantity: next });
      AS.setCounts(json.data);
      if (next <= 0) { window.location.reload(); return; }
      const line = document.querySelector('[data-line-total="' + btn.dataset.cartItem + '"]');
      if (line && json.data && json.data.line_total) { line.textContent = json.data.line_total; }
      if (json.data && json.data.totals) {
        ['subtotal','discount','shipping','tax','total'].forEach(k => {
          const el = document.querySelector('[data-summary="' + k + '"]');
          if (!el || !json.data.totals[k]) return;
          // The cart shows a delivery placeholder until an area is chosen; keep it.
          if (k === 'shipping' && el.dataset.deliveryPending === '1') return;
          el.textContent = json.data.totals[k];
        });
      }
    } catch (err) { AS.toast('error', err.message); window.location.reload(); }
  });

  /* --------------------------------------------------------- remove / save later */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-cart-remove], [data-save-later]');
    if (!btn) return;
    e.preventDefault();
    const remove = btn.hasAttribute('data-cart-remove');
    const payload = { item_id: remove ? btn.dataset.cartRemove : btn.dataset.saveLater };
    if (!remove) payload.saved = btn.dataset.saved === '1' ? 1 : 0;
    try {
      const json = await AS.post(remove ? '/api/cart/remove' : '/api/cart/update', payload);
      AS.setCounts(json.data);
      AS.toast('success', json.message);
      setTimeout(() => window.location.reload(), 350);
    } catch (err) { AS.toast('error', err.message); }
  });

  /* -------------------------------------------------------------- coupon form */
  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-ajax-form]');
    if (!form) return;
    e.preventDefault();
    const btn = form.querySelector('[type="submit"]');
    if (btn) btn.disabled = true;
    try {
      const data = Object.fromEntries(new FormData(form).entries());
      const json = await AS.post(form.dataset.ajaxForm, data);
      AS.toast('success', json.message);
      if (form.dataset.reload !== '0') setTimeout(() => window.location.reload(), 450);
    } catch (err) {
      AS.toast('error', err.message);
      const box = form.querySelector('[data-form-error]');
      if (box) box.textContent = err.message;
    } finally { if (btn) btn.disabled = false; }
  });

  /* ------------------------------------------------- link-style ajax actions */
  // Some compact controls (e.g. "remove coupon") are anchors rather than forms.
  document.addEventListener('click', async (e) => {
    const el = e.target.closest('a[data-ajax-form], button[data-ajax-form]');
    if (!el || el.tagName === 'FORM' || el.closest('form[data-ajax-form]')) return;
    e.preventDefault();
    const data = {};
    if (el.dataset.ajaxRemove) data.remove = Number(el.dataset.ajaxRemove);
    if (el.dataset.ajaxPayload) {
      try { Object.assign(data, JSON.parse(el.dataset.ajaxPayload)); } catch (_) {}
    }
    el.setAttribute('aria-busy', 'true');
    try {
      const json = await AS.post(el.dataset.ajaxForm, data, { timeout: 20000 });
      AS.toast('success', json.message);
      if (el.dataset.reload !== '0') setTimeout(() => window.location.reload(), 350);
    } catch (err) { AS.toast('error', err.message); }
    finally { el.removeAttribute('aria-busy'); }
  });

  /* ------------------------------------------------------------ confirmation modal */
  const modal = document.getElementById('confirmModal');
  let pendingAction = null;
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-confirm]');
    if (trigger) {
      e.preventDefault();
      pendingAction = () => {
        if (trigger.dataset.confirmForm) { document.getElementById(trigger.dataset.confirmForm)?.submit(); }
        else if (trigger.tagName === 'A') { window.location.href = trigger.getAttribute('href'); }
        else if (trigger.form) { trigger.form.submit(); }
      };
      if (modal) {
        modal.querySelector('[data-modal-message]').textContent = trigger.dataset.confirm;
        modal.classList.add('open');
      } else if (window.confirm(trigger.dataset.confirm)) { pendingAction(); }
      return;
    }
    if (e.target.closest('[data-modal-cancel]')) { modal?.classList.remove('open'); pendingAction = null; }
    if (e.target.closest('[data-modal-ok]')) { modal?.classList.remove('open'); if (pendingAction) pendingAction(); pendingAction = null; }
  });

  /* ------------------------------------------------------------ mobile drawers */
  const drawer = document.getElementById('mobileDrawer');
  const drawerBackdrop = document.getElementById('drawerBackdrop');
  const closeDrawers = () => {
    document.querySelectorAll('.drawer, .drawer-backdrop').forEach(el => el.classList.remove('open'));
  };
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-open-menu]')) { drawer?.classList.add('open'); drawerBackdrop?.classList.add('open'); }
    if (e.target.closest('[data-open-filters]')) {
      const f = document.getElementById('filterSidebar');
      f?.classList.add('as-drawer', 'open'); drawerBackdrop?.classList.add('open');
    }
    if (e.target.closest('[data-close-drawer]') || e.target === drawerBackdrop) { closeDrawers(); }
  });

  /* --------------------------------------------------------------- mega menu */
  const mega = document.getElementById('megaMenu');
  const megaToggle = document.querySelector('[data-mega-toggle]');
  if (mega && megaToggle) {
    megaToggle.addEventListener('click', (e) => { e.preventDefault(); mega.classList.toggle('open'); });
    document.addEventListener('click', (e) => { if (!e.target.closest('.mega-menu') && !e.target.closest('[data-mega-toggle]')) mega.classList.remove('open'); });
    document.querySelectorAll('[data-mega-cat]').forEach(item => {
      const activate = () => {
        document.querySelectorAll('[data-mega-cat]').forEach(x => x.classList.remove('active'));
        item.classList.add('active');
        const slug = item.dataset.megaCat;
        document.querySelectorAll('[data-mega-panel]').forEach(panel => {
          panel.hidden = panel.dataset.megaPanel !== slug;
        });
      };
      item.addEventListener('mouseenter', activate);
      item.addEventListener('click', activate);
    });
  }

  /* ---------------------------------------------------------- search suggest */
  const searchInput = document.querySelector('[data-search-input]');
  const suggestBox = document.getElementById('searchSuggest');
  if (searchInput && suggestBox) {
    let timer = null, controller = null;
    const render = (data) => {
      let html = '';
      if (data.products && data.products.length) {
        html += '<div class="suggest-label">Products</div>';
        data.products.forEach(p => {
          html += '<a class="suggest-item" href="/product/' + encodeURIComponent(p.slug) + '"><img src="' + escapeAttr(p.image_url || '') + '" alt="" loading="lazy"><span class="grow">' + escapeHtml(p.name) + '</span><strong>' + escapeHtml(p.price || '') + '</strong></a>';
        });
      }
      if (data.categories && data.categories.length) {
        html += '<div class="suggest-label">Categories</div><div style="padding:4px 8px">';
        data.categories.forEach(c => { html += '<a class="suggest-chip" href="/category/' + encodeURIComponent(c.slug) + '">' + escapeHtml(c.name) + '</a>'; });
        html += '</div>';
      }
      if (data.brands && data.brands.length) {
        html += '<div class="suggest-label">Brands</div><div style="padding:4px 8px">';
        data.brands.forEach(b => { html += '<a class="suggest-chip" href="/brand/' + encodeURIComponent(b.slug) + '">' + escapeHtml(b.name) + '</a>'; });
        html += '</div>';
      }
      if (data.recent && data.recent.length && !searchInput.value.trim()) {
        html += '<div class="suggest-label">Recent searches</div><div style="padding:4px 8px">';
        data.recent.forEach(q => { html += '<a class="suggest-chip" href="/search?q=' + encodeURIComponent(q) + '">' + escapeHtml(q) + '</a>'; });
        html += '</div>';
      }
      if (data.popular && data.popular.length && !searchInput.value.trim()) {
        html += '<div class="suggest-label">Popular searches</div><div style="padding:4px 8px">';
        data.popular.forEach(q => { html += '<a class="suggest-chip" href="/search?q=' + encodeURIComponent(q) + '">' + escapeHtml(q) + '</a>'; });
        html += '</div>';
      }
      if (!html) html = '<div class="suggest-empty">No suggestions yet. Try a product name or brand.</div>';
      suggestBox.innerHTML = html;
      suggestBox.hidden = false;
    };
    searchInput.addEventListener('focus', () => {
      if (suggestBox.innerHTML.trim()) suggestBox.hidden = false;
    });
    searchInput.addEventListener('input', () => {
      clearTimeout(timer);
      const q = searchInput.value.trim();
      timer = setTimeout(async () => {
        if (controller) controller.abort();
        controller = new AbortController();
        try {
          const res = await fetch('/api/search/suggest?q=' + encodeURIComponent(q), { signal: controller.signal, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
          const json = await res.json();
          if (json.success) {
            json.data.products = (json.data.products || []).map(p => Object.assign({}, p, { price: money(p.price_cents) }));
            render(json.data);
          }
        } catch (_) { /* aborted or offline - keep the native form usable */ }
      }, 220);
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('.search-wrap')) suggestBox.hidden = true; });
    searchInput.addEventListener('keydown', (e) => { if (e.key === 'Escape') suggestBox.hidden = true; });
  }

  /* ------------------------------------------------------------ announcement */
  const ann = document.querySelector('[data-announcement]');
  const annClose = document.querySelector('[data-announcement-close]');
  if (ann && localStorage.getItem('as_ann_hidden') === '1') ann.remove();
  annClose?.addEventListener('click', () => { localStorage.setItem('as_ann_hidden', '1'); ann.remove(); });

  /* -------------------------------------------------------------- countdown */
  document.querySelectorAll('[data-countdown]').forEach(box => {
    const end = new Date(box.dataset.countdown).getTime();
    if (!end || isNaN(end)) return;
    const tick = () => {
      let diff = Math.max(0, Math.floor((end - Date.now()) / 1000));
      const d = Math.floor(diff / 86400); diff -= d * 86400;
      const h = Math.floor(diff / 3600); diff -= h * 3600;
      const m = Math.floor(diff / 60); const s = diff - m * 60;
      const vals = [d, h, m, s];
      box.querySelectorAll('[data-cd]').forEach((el, i) => { el.textContent = String(vals[i] ?? 0).padStart(2, '0'); });
    };
    tick(); setInterval(tick, 1000);
  });

  /* ----------------------------------------------------------- notifications */
  document.querySelectorAll('[data-mark-read]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try { await AS.post('/api/notifications/read', { id: btn.dataset.markRead || '' }); window.location.reload(); }
      catch (err) { AS.toast('error', err.message); }
    });
  });

  /* --------------------------------------------------------------- helpers */
  function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
  function escapeAttr(s) { return escapeHtml(s); }
  function money(cents) {
    const n = Number(cents || 0) / 100;
    return (body.dataset.currencySymbol || '$') + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }
})();
