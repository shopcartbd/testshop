<?php
$account = $data['account'] ?? null;
$pageTitle = match ($view) {
    'home' => app_name() . ' — প্রতিদিনের পছন্দ, সুন্দরভাবে বেছে নেওয়া',
    'shop' => 'Shop — ' . app_name(),
    'product' => e($data['product']['seo_title'] ?? $data['product']['name'] ?? 'Product') . ' — ' . app_name(),
    'cart' => 'আপনার ব্যাগ — ' . app_name(),
    'checkout' => 'Checkout — ' . app_name(),
    'login' => 'লগইন — ' . app_name(),
    'register' => 'নতুন account — ' . app_name(),
    'account' => 'আপনার account — ' . app_name(),
    'admin' => 'Admin — ' . app_name(),
    default => app_name(),
};
$metaDescription = $view === 'product' ? (string)($data['product']['seo_description'] ?: $data['product']['short_description'] ?: $data['product']['name']) : app_name() . ' — দৈনন্দিন প্রয়োজনের পণ্য এক জায়গায়, যত্ন করে বেছে নেওয়া।';
$privatePage = $account !== null || $view === 'checkout' || $view === 'cart' || $view === 'admin' || str_starts_with((string)($_SERVER['REQUEST_URI']??''), '/account');
$canonicalBase = rtrim((string)(getenv('APP_URL') ?: ''), '/');
$canonical = $canonicalBase !== '' && !$privatePage ? $canonicalBase . (parse_url($_SERVER['REQUEST_URI']??'/', PHP_URL_PATH) ?: '/') : '';
$flash = (string)($data['flash'] ?? $_SESSION['flash'] ?? '');
unset($_SESSION['flash']);
?><!doctype html>
<html lang="bn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#173d31">
  <meta name="description" content="<?= e($metaDescription) ?>">
  <?php if($privatePage): ?><meta name="robots" content="noindex,nofollow,noarchive"><?php endif; ?>
  <?php if($canonical !== ''): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
  <title><?= $pageTitle ?></title>
  <link rel="icon" type="image/png" href="<?= e(asset('allstore-mark.png')) ?>">
  <link rel="stylesheet" href="/assets/styles.css">
  <script src="/assets/store.js" defer></script>
</head>
<body>
  <div class="announcement"><span>আপনার প্রতিদিনের পছন্দ, এখন এক জায়গায়—যত্ন করে বেছে নেওয়া।</span><a href="/shop">দোকান ঘুরে দেখুন <span aria-hidden="true">↗</span></a></div>
  <header class="site-header">
    <div class="header-main shell">
      <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="primary-nav" aria-label="মেনু খুলুন"><span></span><span></span></button>
      <a class="brand" href="/" aria-label="<?= e(app_name()) ?> home"><img src="<?= e(asset('allstore-mark.png')) ?>" alt="" width="40" height="40"><span><?= e(app_name()) ?><small>CURATED EVERYDAY</small></span></a>
      <form class="search" action="/shop" method="get" role="search"><label class="sr-only" for="site-search">পণ্য খুঁজুন</label><input id="site-search" name="q" value="<?= e($data['search'] ?? '') ?>" placeholder="আপনি কী খুঁজছেন?" autocomplete="off"><button type="submit" aria-label="খুঁজুন"><span class="search-icon" aria-hidden="true"></span></button></form>
      <nav class="header-actions" aria-label="দ্রুত লিংক">
        <?php if($account): ?><a href="/account" aria-label="Account"><?= e($account['display_name']) ?></a><?php else: ?><a href="/login">লগইন</a><?php endif; ?>
        <?php if($account && in_array($account['role'],['admin','owner'],true)): ?><a href="/admin">Admin</a><?php endif; ?>
        <a class="bag-link" href="/cart">ব্যাগ <span class="bag-count"><?= cart_count() ?></span></a>
      </nav>
    </div>
    <nav id="primary-nav" class="primary-nav shell" aria-label="প্রধান নেভিগেশন"><a href="/shop">সব পণ্য</a><?php foreach (($data['categories'] ?? []) as $navCategory): ?><a href="/shop?category=<?= rawurlencode((string)$navCategory['slug']) ?>"><?= e($navCategory['name']) ?></a><?php endforeach; ?><a href="/about">আমাদের গল্প</a><a href="/faq">সাহায্য</a></nav>
  </header>
  <main id="main-content">
    <?php if($flash!==''): ?><div class="shell"><p class="notice" role="status" aria-live="polite"><?= e($flash) ?></p></div><?php endif; ?>
    <?php if(!empty($data['db_error'])): ?><div class="shell"><div class="database-notice" role="status"><strong>ক্যাটালগ সংযোগ এখনো প্রস্তুত নয়।</strong><span>Supabase migration এবং server-side database environment variables সেট হলে live পণ্য এখানে দেখা যাবে।</span></div></div><?php endif; ?>
    <?php require __DIR__ . '/' . $view . '.php'; ?>
  </main>
  <footer class="site-footer"><div class="shell footer-grid"><div class="footer-brand"><a class="brand brand-light" href="/"><img src="<?= e(asset('allstore-mark.png')) ?>" alt="" width="36" height="36"><span><?= e(app_name()) ?><small>CURATED EVERYDAY</small></span></a><p>প্রতিদিনের পছন্দ, একটু ভালোভাবে বেছে নেওয়া।</p></div><div><h2>দোকান</h2><a href="/shop">সব পণ্য</a><a href="/about">আমাদের গল্প</a></div><div><h2>সাহায্য</h2><a href="/faq">সাধারণ প্রশ্ন</a><a href="/contact">যোগাযোগ</a><?php if($account): ?><a href="/account/orders">আমার অর্ডার</a><?php endif; ?></div><div><h2>তথ্য</h2><a href="/privacy">গোপনীয়তা</a><a href="/returns">রিটার্ন পলিসি</a></div></div><div class="shell footer-bottom"><span>© <?= date('Y') ?> <?= e(app_name()) ?>. সর্বস্বত্ব সংরক্ষিত।</span><span>সুন্দরভাবে বেছে নিন।</span></div></footer>
</body>
</html>
