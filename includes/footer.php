<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Config;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
$appName = (string)Config::get('app.name');
$flashes = Session::flashes();
?></main>
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="newsletter" id="newsletter">
      <div>
        <span class="eyebrow">The AllStore letter</span>
        <h2><?= Helpers::e(Helpers::setting('newsletter_heading','Stay in the loop')) ?></h2>
        <p><?= Helpers::e(Helpers::setting('newsletter_text','New arrivals, editorial picks and member-only offers. No noise.')) ?></p>
      </div>
      <form class="newsletter-form" data-ajax-form="/api/newsletter" data-reload="0" method="post">
        <?= Session::csrfField() ?>
        <input class="input" type="email" name="email" placeholder="Your email address" aria-label="Your email address" required maxlength="254">
        <button class="btn btn-primary" type="submit">Subscribe</button>
        <div class="field-error" data-form-error></div>
      </form>
    </div>
  </div>
</section>
<footer class="site-footer" itemscope itemtype="https://schema.org/Organization">
  <meta itemprop="name" content="<?= Helpers::e($appName) ?>">
  <div class="container footer-top">
    <div class="footer-brand">
      <a class="brand" href="/"><span class="brand-mark"><?= icon('layers',20) ?></span><?= Helpers::e($appName) ?></a>
      <p><?= Helpers::e(Helpers::setting('footer_about','A modern marketplace for considered everyday goods.')) ?></p>
      <div class="socials">
        <a href="<?= Helpers::e(Helpers::setting('social_instagram','#')) ?>" aria-label="Instagram" rel="noopener noreferrer" target="_blank"><?= icon('image',16) ?></a>
        <a href="<?= Helpers::e(Helpers::setting('social_x','#')) ?>" aria-label="X" rel="noopener noreferrer" target="_blank"><?= icon('message',16) ?></a>
        <a href="<?= Helpers::e(Helpers::setting('social_facebook','#')) ?>" aria-label="Facebook" rel="noopener noreferrer" target="_blank"><?= icon('globe',16) ?></a>
      </div>
    </div>
    <div class="footer-col"><h4>Shop</h4><a href="/shop">All products</a><a href="/new-arrivals">New arrivals</a><a href="/best-sellers">Best sellers</a><a href="/deals">Deals &amp; offers</a><a href="/flash-sale">Flash sale</a><a href="/brands">Brands</a></div>
    <div class="footer-col"><h4>Support</h4><a href="/contact">Contact us</a><a href="/faq">FAQ</a><a href="/shipping-policy">Shipping policy</a><a href="/return-refund-policy">Returns &amp; refunds</a><a href="/account/orders">Track an order</a><a href="/account/tickets">Support tickets</a></div>
    <div class="footer-col"><h4>Company</h4><a href="/about">About us</a><a href="/blog">Journal</a><a href="/privacy-policy">Privacy policy</a><a href="/terms">Terms &amp; conditions</a></div>
    <div class="footer-col"><h4>Get in touch</h4><p><?= Helpers::e(Helpers::setting('store_address','')) ?></p><a href="mailto:<?= Helpers::e(Helpers::setting('contact_email','hello@example.com')) ?>"><?= Helpers::e(Helpers::setting('contact_email','hello@example.com')) ?></a><a href="tel:<?= Helpers::e(preg_replace('/\s+/','',Helpers::setting('contact_phone',''))) ?>"><?= Helpers::e(Helpers::setting('contact_phone','')) ?></a></div>
  </div>
  <div class="container footer-bottom">
    <span>© <?= gmdate('Y') ?> <?= Helpers::e($appName) ?>. All rights reserved.</span>
    <div class="footer-pay"><span>COD</span><span>VISA</span><span>Mastercard</span><span>Secure checkout</span></div>
  </div>
</footer>

<nav class="bottom-nav" aria-label="Mobile quick navigation"><div class="inner">
  <a href="/" class="<?= Request::path() === '/' ? 'active' : '' ?>"><?= icon('home',20) ?><span>Home</span></a>
  <a href="/shop"><?= icon('grid',20) ?><span>Shop</span></a>
  <a href="/search"><?= icon('search',20) ?><span>Search</span></a>
  <a href="/account/wishlist"><?= icon('heart',20) ?><span>Wishlist</span></a>
  <a href="/cart" class="<?= Request::path() === '/cart' ? 'active' : '' ?>"><?= icon('cart',20) ?><span>Cart</span><span class="count" data-cart-count data-zero="1" style="position:absolute;top:4px;right:22%;min-width:15px;height:15px;background:var(--c-accent);color:#fff;border-radius:8px;font-size:9px;display:grid;place-items:center"></span></a>
</div></nav>

<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="false">
  <?php foreach ($flashes as $f): ?>
  <div class="toast <?= Helpers::e($f['type'] ?? 'info') ?>" data-server="1"><?= icon(($f['type'] ?? '') === 'success' ? 'check-circle' : 'info',18) ?><span><?= Helpers::e($f['message'] ?? '') ?></span></div>
  <?php endforeach; ?>
</div>
<div class="modal-backdrop" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
  <div class="modal"><h3 id="confirmTitle">Please confirm</h3><p data-modal-message>Are you sure?</p>
    <div class="modal-actions"><button type="button" class="btn btn-outline" data-modal-cancel>Cancel</button><button type="button" class="btn btn-primary" data-modal-ok>Confirm</button></div>
  </div>
</div>
<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/shop.js" defer></script>
</body></html>
