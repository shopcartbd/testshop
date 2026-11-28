/* =====================================================================
   AllStore :: admin dashboard
   ===================================================================== */
(() => {
  'use strict';
  const qs = (s, r = document) => r.querySelector(s);
  const qsa = (s, r = document) => Array.from(r.querySelectorAll(s));

  /* ------------------------------------------------------------ sidebar */
  qs('[data-admin-menu]')?.addEventListener('click', () => {
    qs('.admin-sidebar')?.classList.toggle('open');
  });
  document.addEventListener('click', e => {
    if (e.target.closest('.admin-nav a')) qs('.admin-sidebar')?.classList.remove('open');
  });

  /* ------------------------------------------------- copy shipping address */
  const fallbackCopy = (text) => {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (_) { /* no-op */ }
    ta.remove();
  };
  qsa('[data-copy-address]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const text = btn.dataset.copyAddress || '';
      if (!text) return;
      try {
        if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
        else fallbackCopy(text);
        const original = btn.innerHTML;
        btn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px">✓ Copied</span>';
        btn.classList.add('copied');
        window.AllStore?.toast?.('success', 'Shipping address copied.');
        window.setTimeout(() => { btn.innerHTML = original; btn.classList.remove('copied'); }, 1600);
      } catch (_) {
        fallbackCopy(text);
        window.AllStore?.toast?.('error', 'Copy failed. Select the address manually.');
      }
    });
  });

  /* ------------------------------------------------------ bulk selection */
  const selectAll = qs('[data-select-all]');
  selectAll?.addEventListener('change', () => {
    qsa('[data-row-check]').forEach(cb => { cb.checked = selectAll.checked; });
  });
  qs('[data-bulk-form]')?.addEventListener('submit', e => {
    const action = qs('[data-bulk-action]')?.value;
    const checked = qsa('[data-row-check]:checked').length;
    if (!action) { e.preventDefault(); window.AllStore?.toast?.('error', 'Choose a bulk action first.'); return; }
    if (!checked) { e.preventDefault(); window.AllStore?.toast?.('error', 'Select at least one row.'); return; }
    if (!window.confirm('Apply "' + action + '" to ' + checked + ' selected item(s)?')) e.preventDefault();
  });

  /* --------------------------------------------------------- image rows */
  qs('[data-add-image-row]')?.addEventListener('click', () => {
    const wrap = qs('[data-image-rows]');
    if (!wrap) return;
    const div = document.createElement('div');
    div.className = 'row';
    div.style.marginBottom = '8px';
    div.innerHTML = '<input class="input" type="url" name="images[]" placeholder="https://… or /assets/img/…"><button type="button" class="icon-btn icon-btn-sm" data-remove-row aria-label="Remove">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg></button>';
    wrap.appendChild(div);
  });
  document.addEventListener('click', e => { if (e.target.closest('[data-remove-row]')) e.target.closest('.row')?.remove(); });

  /* --------------------------------------------------------- charts hover */
  qsa('.chart .bar').forEach(bar => {
    bar.addEventListener('mouseenter', () => { bar.style.background = 'var(--c-brand)'; });
    bar.addEventListener('mouseleave', () => { bar.style.background = ''; });
  });

  /* --------------------------------------------------- section reorder */
  qsa('[data-section-move]').forEach(btn => {
    btn.addEventListener('click', () => {
      const form = document.createElement('form');
      form.method = 'POST';
      form.innerHTML = '<input type="hidden" name="csrf_token" value="' + (document.body.dataset.csrf || '') + '">' +
        '<input type="hidden" name="action" value="move">' +
        '<input type="hidden" name="id" value="' + btn.dataset.sectionMove + '">' +
        '<input type="hidden" name="direction" value="' + btn.dataset.direction + '">';
      document.body.appendChild(form);
      form.submit();
    });
  });

  /* ----------------------------------------------------------- CSV import */
  const csv = qs('[data-csv-file]');
  csv?.addEventListener('change', () => {
    const file = csv.files && csv.files[0];
    if (!file) return;
    const out = qs('[data-csv-preview]');
    if (!out) return;
    const reader = new FileReader();
    reader.onload = () => { out.textContent = String(reader.result).split('\n').slice(0, 6).join('\n'); };
    reader.readAsText(file.slice(0, 4096));
  });

  /* --------------------------------------------------- media upload (Cloudinary) */
  qsa('[data-media-upload]').forEach(input => {
    input.addEventListener('change', async () => {
      const files = Array.from(input.files || []);
      if (!files.length) return;
      const endpoint = input.dataset.mediaEndpoint || '/api/admin/media/upload';
      const target = input.dataset.mediaTarget ? qs(input.dataset.mediaTarget) : null;
      const box = input.dataset.mediaPreview ? qs(input.dataset.mediaPreview) : null;
      const token = input.dataset.mediaCsrf || document.body.dataset.csrf || '';
      const form = new FormData();
      files.slice(0, 12).forEach(f => form.append('files[]', f, f.name));
      if (box) {
        box.hidden = false;
        box.innerHTML = '<span class="field-hint">Uploading ' + files.length + ' image(s)…</span>';
      }
      input.disabled = true;
      try {
        const res = await fetch(endpoint, {
          method: 'POST',
          body: form,
          headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
          credentials: 'same-origin'
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || data.success !== true) {
          throw new Error((data && data.message) || 'Upload failed.');
        }
        const images = (data.data && data.data.images) || [];
        if (!images.length) throw new Error('No image URL was returned.');
        if (target) {
          const existing = target.value.trim();
          const added = images.map(i => i.url).join('\n');
          target.value = existing ? existing + '\n' + added : added;
          target.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (box) {
          box.innerHTML = '<span class="field-hint">Uploaded to ' + (data.data.provider === 'cloudinary' ? 'Cloudinary' : data.data.provider) + ' — only the secure URL is saved.</span>' +
            '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">' + images.map(i =>
              '<img src="' + i.url + '" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--c-border)">').join('') + '</div>';
        }
        // Uploaded through the API already: clear the picker so the form POST
        // does not upload the same file a second time.
        input.value = '';
        window.AllStore?.toast?.('success', images.length + ' image(s) uploaded.');
      } catch (err) {
        // Leave the files selected: the normal form submit still uploads them
        // server-side, so the admin is never blocked.
        if (box) box.innerHTML = '<span class="field-hint" style="color:var(--c-danger,#b42318)">' + (err && err.message ? err.message : 'Upload failed.') + '</span>';
        window.AllStore?.toast?.('error', err && err.message ? err.message : 'Upload failed.');
      } finally {
        input.disabled = false;
      }
    });
  });

  /* ------------------------------------------------- payment gateway fields */
  const gatewaySelect = qs('[data-gateway-provider]');
  if (gatewaySelect) {
    const syncGateway = () => {
      qsa('[data-gateway-fields]').forEach(el => { el.hidden = el.dataset.gatewayFields !== gatewaySelect.value; });
    };
    gatewaySelect.addEventListener('change', syncGateway);
    syncGateway();
  }
})();
