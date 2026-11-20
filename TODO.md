# AllStore delivery checklist

**Legend:** `[x]` means the repository contains the described artifact/code; it does not mean a live integration has passed. `[ ]` means the outcome remains unverified or incomplete. No production deployment or customer-data operation has been performed from this session.

## Repository, documentation and secret handling

- [x] Source repository contains the PHP storefront, modular services/views, Docker/Caddy deployment files, `.env.example`, `.gitignore`, SQL migrations and seed, setup/deployment/security/API/environment docs, owner bootstrap and test report.
- [ ] Review complete Git history for secrets before pushing. Never commit `.env`, passwords, API/service-role keys, database credentials, private keys, private storage credentials, sensitive logs, dumps or backup files. Rotate a secret if it has ever been committed/exposed; deleting it from the latest revision alone is insufficient.
- [ ] Confirm Vercel's Git integration points at `shopcartbd/testshop` and `main`, verify its deployment result and record the exact successful production URL only after testing.

## Database and launch setup

- [ ] In Supabase staging, apply `001_initial_schema.sql`, then `002_operational_marketplace.sql`, then optionally `database/seed.sql`; verify role grants, default privileges, non-superuser access and the four trusted PHP RLS policies on orders/order items/status history/coupon usage.
- [ ] Create a dedicated database runtime login; configure TLS and an appropriate direct/Session-pooler connection. Verify `SESSION_DRIVER=database` and its cross-instance session locking.
- [ ] Configure required server environment values: `APP_NAME`, `APP_ENV`, `APP_CURRENCY=BDT`, exact HTTPS `APP_URL`, `SUPABASE_DB_URL`, `SESSION_DRIVER=database`; set recommended `LOG_LEVEL=error`. `PORT` is runtime-provided. Keep DB/SMTP/Storage credentials private.
- [ ] Run `scripts/bootstrap-admin.php` once from a trusted environment against the intended database; verify login and admin denial/permission boundaries without publishing bootstrap credentials.
- [ ] Replace example ৳80 delivery fee with actual rates and zones; publish accurate shipping, return/refund, privacy and contact policies before launch.

## Storefront, catalog and cart

- [x] Source contains a branded storefront, responsive layout/styles, category/brand navigation, catalog/search/filter/sort/pagination, product details/images, cart forms, empty/error states and SEO metadata/routes.
- [ ] Verify against real staging products, images and available stock on desktop, tablet and mobile; inspect images, keyboard/focus behavior, contrast, alt text, semantic labels, screen readers and reduced motion; aim for WCAG 2.2 AA.
- [ ] Finish attribute-value filtering and test query/index performance, pagination, image sizes/lazy loading, asset compression and avoiding N+1 queries.
- [ ] Product variants/bundles/digital products are modeled in schema but not checkout-supported. Implement validated variant selection, SKU/price/stock/image resolution and order-item persistence before selling them.
- [ ] Cart is PHP-session-based; verify add/update/remove against staging, maximum quantities and out-of-stock transitions. A persistent cross-device cart is not implemented.

## Customer identity and services

- [x] Source contains PHP-managed customer register/login/logout/reset flows, password hashing, rate limits, account/order/address/wishlist pages, verified post-delivery review submission, support ticket submission and owner-scoped return requests.
- [ ] Verify all identity/session/access-control paths in staging, including same-account address/wishlist/order/return/review access, disabled accounts, password reset token expiry/reuse, concurrent session requests and logout.
- [ ] SMTP password-reset/order emails require configured authenticated SMTP and delivery tests; account email verification and newsletter confirmation/unsubscribe workflow are not fully integrated.
- [ ] Guest order-history/tracking lookup is not provided; customer order details currently require an authenticated account.
- [ ] Verify saved addresses can be managed and, if required, selected/prefilled at checkout; account profile edit and multi-session management UX are incomplete.

## Checkout, orders, stock and payments

- [x] Source implements BDT Cash on Delivery only; it derives prices/stock/shipping/coupons/tax server-side, locks product rows, writes order/payment/shipment/history/stock/coupon rows transactionally, and tracks COD payment as pending.
- [ ] Test successful/failed/retried orders, authorization, duplicate submissions, competing stock transactions, coupon limits, eligible-product/category rules, tax-after-discount behavior, cancellation and stock restoration in a staging database.
- [ ] Define tax rules, shipping-taxon-shipping treatment, refunds and local legal/receipt obligations with the store operator; configure real shipping prices/zones. Current fee/tax data are not verified production policy.
- [ ] Online card/mobile-wallet payments, payment provider, webhooks, reconciliation and refunds are not connected; never show or claim fake payment success.
- [ ] Courier provider APIs/booking, delivery-status webhooks and actual negotiated rates are not connected. Admin-entered tracking links are manual only.

## Administration and operations

- [x] Source includes admin summary/reports and pages/services for product editing, category/brand management, stock adjustments, order state transitions/manual tracking, customer status, review moderation and banners, with audit writes.
- [ ] Verify all admin role checks, CSRF, workflow transitions, stock invariants, actor attribution, audit retention and staging CRUD/report queries.
- [ ] Coupon administration, warehouse/inventory-level UI, product variant/attribute CRUD, return/support queue management, refund controls, deletion/archival workflows, site settings, content/blog and audit-log viewer are incomplete or absent from the admin interface.
- [ ] Implement operational notifications and scheduled cleanup/retention as needed; no external workflow or trigger is configured.

## API and external services

- [x] Source contains same-origin cookie/CSRF versioned JSON endpoints for catalog, customer accounts, orders, addresses, wishlist/reviews/returns, support/newsletter, admin summaries/reports and selected admin writes.
- [ ] Validate the documented endpoints against the live schema, status codes, error shapes, CSRF/session behavior, rate limits, response fields and idempotency in a staging API client.
- [ ] API cart mutation routes, shipping-method discovery, guest lookup, coupon preview, product/category/brand updates/deletes, coupons, returns/support administration and payment/provider webhooks are not complete.
- [ ] Supabase Storage uploads require a configured private Storage key and bucket; test file limits, public image reads, authorization, deletion lifecycle and abuse controls.
- [ ] SMTP and Storage credentials are optional; payments/courier credentials are not configured.

## Acceptance checks still required

- [ ] Functional: register, login, logout, password reset, product browse/search/filter/sort/variants, cart add/update/remove, wishlist, checkout/coupon/order, tracking/reviews, admin login, product/category/brand CRUD, stock, order statuses, banners, customers and reports.
- [ ] Security: SQL injection, XSS, CSRF, unauthorized admin access, broken access control/IDOR, session fixation, weak authentication/rate limiting, file-upload abuse/path traversal, secrets exposure, manipulated prices/coupons/totals, unauthorized order access, stock race and duplicate order submissions. Use safe staging-only tests; do not attack third-party systems.
- [ ] UI: desktop/tablet/mobile navigation, forms, dialogs, product grids, checkout, admin tables and empty/error/loading states.
- [ ] Deployment: Vercel deploy, production/staging database connectivity, env validation, HTTPS, static assets, PHP/API routes, Storage, logs and safe error handling.
- [ ] User-facing errors must remain safe (404/403/500, database/API/validation/upload/payment); production must not display stack traces, SQL errors, internal paths or secrets, and must log safely.

## Verified delivery

- [ ] Final PHP lint, Composer validation, migration static parse, safe HTTP health smoke test, diff review, secret scan and GitHub push to `main` (record remote SHA).
- [ ] Vercel deployment and Supabase production connection remain explicitly unverified until the user confirms/configures the services and the live deployment is checked.
