/* AllStore :: product-detail gallery switcher.
   Loaded only on /product/... so the shared shop.js stays untouched.
   Shows one large image with clickable thumbnails underneath. */
(() => {
  'use strict';
  const main = document.querySelector('[data-pdp-main]');
  const thumbWrap = document.querySelector('[data-pdp-thumbs]');
  const thumbs = Array.from(document.querySelectorAll('[data-pdp-thumb]'));
  if (!main || !thumbWrap || thumbs.length === 0) return;
  const img = main.querySelector('img');
  if (!img) return;

  const setActive = (btn) => {
    thumbs.forEach((x) => {
      const active = x === btn;
      x.classList.toggle('active', active);
      x.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  };
  const show = (btn) => {
    const url = btn.getAttribute('data-pdp-thumb');
    if (!url) return;
    img.src = url;
    img.alt = btn.getAttribute('data-pdp-alt') || img.alt;
    main.classList.remove('zoomed');
    setActive(btn);
  };

  thumbs.forEach((btn) => btn.addEventListener('click', () => show(btn)));
  main.addEventListener('click', () => main.classList.toggle('zoomed'));
  thumbWrap.addEventListener('keydown', (e) => {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    const current = thumbs.indexOf(document.activeElement);
    if (current < 0) return;
    e.preventDefault();
    const next = e.key === 'ArrowRight'
      ? thumbs[(current + 1) % thumbs.length]
      : thumbs[(current - 1 + thumbs.length) % thumbs.length];
    next.focus();
    show(next);
  });
})();
