# Delivery checklist — AllStore

## Base application and visual identity

- [x] Provide a premium, responsive, coherent customer storefront with a configurable brand name, reusable page components, prominent search, category navigation, product imagery, accessible controls, and refined visual hierarchy.
- [x] Provide generated logo artwork, hero artwork, and category imagery; use descriptive alt text and responsive image sizing.
- [x] Provide mobile, tablet, laptop, desktop, and wide desktop layouts without horizontal overflow.
- [x] Provide reusable headers, navigation, footer, product/category cards, alerts, and forms rather than duplicating markup.
- [x] Provide setup, architecture, security, testing, deployment, and known-limitation documentation.

## Catalog, search, cart, and checkout

- [ ] Load live product/category/brand information from Supabase PostgreSQL; do not treat hardcoded products as the production data source.
- [ ] Add dynamic categories, subcategories, attributes, brands, product variants, images, stock, SEO fields, filters, sorting, pagination, search, suggestions, and product details.
- [ ] Add a session cart with server-side product/stock/price validation, quantity updates, coupon validation, guest cart, and persistent customer cart.
- [ ] Add secure checkout with address/shipping/payment choice, server-side tax/discount/total calculation, idempotency, transactional stock decrement, COD, order confirmation, status history, and invoice.
- [ ] Add wishlist, review, and recently-viewed capabilities where implemented.

## Accounts, administration, and operations

- [ ] Add secure customer registration, login/logout, password reset, profiles, address book, order history, tracking, notification settings, coupons, returns, and support tickets.
- [ ] Add a separately protected admin area with role/permission checks, product/category/brand CRUD, inventory history, order management, customer data, coupons, campaigns, homepage CMS, moderation, reports, activity log, and settings.
- [ ] Implement secure one-time admin bootstrap without a hardcoded password and document provisioning.
- [ ] Add safe, authorized product-media uploads with type/content/size validation and Supabase Storage policies.
- [ ] Add configured email, online payments, shipping/courier APIs, and customer/admin notifications only when valid credentials and documented APIs are supplied.

## Security, data, quality, and deployment

- [ ] Enforce parameterized SQL, server-side validation/authorization, CSRF protection, secure sessions, contextual output escaping, rate limits, safe uploads, least-privilege DB access, RLS/storage policies, security headers, safe error handling, and secret hygiene.
- [ ] Complete normalized PostgreSQL schema/migrations, seed data, constraints, indexes, and documented RLS review.
- [ ] Add API routes with consistent responses, auth, validation, rate limiting, and ownership checks.
- [ ] Add SEO metadata, canonical URLs, sitemap, robots rules, breadcrumbs, and structured data.
- [ ] Run functional, security, accessibility, responsive UI, route, database, storage, and deployment checks; record only actually verified results.
- [ ] Connect the repository to a Vercel account, configure secrets and Supabase connectivity, deploy, and verify production HTTPS, critical routes, logs, and runtime behavior.
