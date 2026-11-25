# Security design

This document describes implemented controls and the boundaries of verification. It is not a penetration-test report; no live production deployment, external audit, or adversarial load test was performed.

## Authentication and sessions

- Passwords use `password_hash()`/`password_verify()` with PHP's current default algorithm and rehash on login when needed.
- Generic login errors, per-IP/email-domain rate limits, and active-status checks reduce account enumeration and credential-stuffing risk.
- Sessions are stored in the database. The browser receives only a random opaque token; the DB stores its SHA-256 hash. `HttpOnly`, `SameSite=Lax`, and secure-when-HTTPS cookies are used.
- `Session::regenerate()` rotates identifiers on login and privilege changes. Changing a password invalidates other sessions; password reset invalidates all sessions for that user.
- There is no default admin password. First-admin creation is a one-time CLI script that disables itself.
- Password-reset tokens are random, single-use, time-limited, and stored as HMAC hashes using the server-side `APP_KEY`. Production requires `APP_KEY`.

## Authorisation

- `Auth::requireAdmin()` and `Auth::can()` implement deny-by-default RBAC. Admin APIs call `api_require_admin()` with a specific permission; admin pages/helpers and `admin_header()` enforce administrator access.
- Customer account pages require login; order, address, wishlist, notification, review and ticket queries are scoped to the signed-in user's id in repositories/services.
- Guest order access is limited to a session-stored order number and the confirmation route; private order data is not exposed through public product/catalogue APIs.
- Direct PDO connections bypass Supabase RLS. Therefore the PHP-level RBAC/ownership checks are mandatory, and `004_least_privilege.sql` should be applied with a replaced, secret password for a DML-only runtime role.

## CSRF, methods, and rate limits

- Every mutating API handler calls `api_csrf()`; classic forms use `Session::requireCsrf()` and hidden `csrf_token` fields. The Stripe webhook instead requires a timestamped HMAC signature.
- API endpoint files declare method allowlists (`api_method()`), producing JSON 405 responses for wrong methods.
- DB-backed `Security::rateLimit()` protects login, registration, checkout, contact, reviews, tickets, newsletter, password changes and API mutations. It fails closed if the rate-limit store is unavailable.
- JSON responses include a fresh `csrf_token` after session rotation; `assets/js/app.js` updates the body dataset and hidden form inputs so SPA-style forms remain usable.

## Input, output, and data access

- `Request` helpers validate scalar values, integers, floats, emails, slugs, ids and id arrays. Identifier/sort/table names are allowlisted in repositories and `Database::ident()`.
- SQL uses bound parameters. Dynamic SQL fragments are built only from constant allowlists; raw user input is never concatenated into identifiers or sort clauses.
- `Helpers::e()` escapes HTML output. `Security::plain()` sanitises free text; `api_public_url()` allows only safe relative or HTTPS URLs.
- API responses use allowlisted public fields through `api_safe_product()`, `api_safe_user()`, `api_safe_order()`, `api_safe_cart_item()` and related helpers. Password hashes, secrets and raw env values are not returned.
- `Security::redact()` removes likely secret patterns from logs. Logs go to stdout/stderr-friendly output; technical exception details are not shown on error pages.
- Upload validation checks extension/MIME/size and writes non-executable local files only as a development fallback. Production should use the private/public Supabase Storage buckets.

## Commerce integrity

- Prices, stock, coupon eligibility, shipping, tax and totals are recomputed from the database at checkout; no browser-submitted total is trusted.
- Checkout uses a transaction, conditional stock decrements, stock movements, coupon usage, order/payment/shipment/invoice rows and notifications. A failed Stripe session start cancels the order and restocks items.
- A submitted idempotency key is tied to one order and ownership-checked on replay; a fresh key is issued after success. `orders.idempotency_key` is unique.
- Payment success is never inferred from a browser redirect. The Stripe webhook verifies HMAC, timestamp, order reference, method, amount and currency, then marks the order paid idempotently inside a transaction.
- COD is available without payment credentials; online card payment activates only when provider and webhook secrets are configured.

## Browser and transport security

- `Security::sendHeaders()` emits CSP, `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, and HSTS on production HTTPS. `Caddyfile` and `vercel.json` add platform-level headers.
- CSP allows same-origin scripts, inline styles (needed for dynamic widths/progress bars), same-origin connections, and local/data/HTTPS images; it disallows inline scripts, `unsafe-eval`, object embeds and third-party script origins.
- No external fonts, CDN scripts, trackers or stock-image dependencies are used.
- Private/admin/account/cart/checkout responses are `no-store`; public assets may be cached.

## Filesystem and deployment

- Vercel/FaaS filesystems are ephemeral. Sessions, rate limits, audit logs and idempotency are DB-backed; uploads require object storage; SQLite is local-development only.
- `router.php` serves only `/assets/*` and public `/storage/uploads/*` as static files. `Caddyfile` denies source/config/migration/docs/test/direct-PHP paths.
- `.env`, SQLite files, generated app keys, logs, uploads and local admin credential files are excluded from Git/Docker/ZIP.
- `004_least_privilege.sql` contains a placeholder password that must be replaced manually and never committed.

## Verification boundary

Local tests verified linting, route/handler resolution, class imports, security headers, source-file blocking, CSRF rejection, RBAC redirects, session/CSRF rotation, cart/checkout idempotency, and all page/API route sweeps without 5xx/PHP errors. Not verified: live Supabase RLS/Storage, Stripe webhook with real signature, SMTP, Vercel Container image build, production HSTS/TLS, browser cross-device rendering, concurrency/load, or a third-party security audit. See `TEST_REPORT.md` and `KNOWN_LIMITATIONS.md`.
