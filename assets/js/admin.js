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
})();
