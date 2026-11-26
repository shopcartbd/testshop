# Test report

**Date:** 2026-11-25 (UTC workspace date)  
**Scope:** local sandbox only. A static Vercel-readiness audit and preflight were added; no production deployment, live Supabase connection, Storage upload, RLS enforcement, Stripe live event, SMTP delivery, Docker image build, or browser/device QA was performed.

## Environment

| Item | Value |
|---|---|
| PHP | 8.4.28 CLI with `pdo_sqlite`, `pdo_pgsql`, `pgsql`, `sqlite3`, `curl`, `gd`, `mbstring`, `xml` |
| Web server | PHP built-in server bound to `0.0.0.0:8080` using `router.php` (Arena live preview) |
| Database | local SQLite `storage/allstore.sqlite`, created with `migrate.php --reset` and seeded |
| JavaScript syntax | Node.js (`node --check`) |
| HTTP smoke test | Python 3 standard library, no external packages |
| Composer | Not required; no Composer dependencies |
| Static checks | `tests/static_bootstrap.php` provides only the class autoloader; no `.env`, database, session, cookie or header setup |
| Vercel checks | `tests/validate_vercel.php` (static config guard) and `scripts/vercel_preflight.php` (env/extension preflight); live deployment verification is recorded separately in `/home/user/VERCEL_DEPLOY_RESULT.md` |

## Commands executed and results

| Check | Command | Result |
|---|---|---|
| Schema migration | `php database/migrate.php --reset` | `SQLite schema applied`; `Tables created: 58` |
| Seed | `php database/seed.php` | `Seed complete: 24 products, 23 categories, 8 brands.` |
| Admin bootstrap | `ADMIN_BOOTSTRAP_* php scripts/bootstrap_admin.php` | One unique local `super_admin` created; bootstrap disabled. No default password. |
| Full PHP syntax | `php -l` on every PHP file | **187/187 passed** |
| JS syntax | `node --check assets/js/*.js` | **3/3 passed** |
| Python test syntax | `python3 -m py_compile tests/smoke.py` | passed |
| Static class imports | `php tests/validate_classes.php` | scanned **187 PHP files**; all static class references resolve |
| Route/handler/method validation | `php tests/validate.php` | **607 static checks**; 44 API routes, 90 page routes, 44 handler files; passed |
| JSON config | `python3 -m json.tool vercel.json` and `vercel.vercel-php.example.json` | valid |
| Vercel static readiness | `php tests/validate_vercel.php` | **34 checks**, passed; includes DSN aliases, Vercel fallbacks and sanitized health diagnostics |
| Vercel preflight (local, dummy env) | `php scripts/vercel_preflight.php` | all config checks passed; correctly flags 3 sandbox-only missing extensions (`intl`, `zip`, `opcache`) that the Dockerfile installs |
| Static verification wrapper | `SKIP_SMOKE=1 bash tests/run.sh` | passed (PHP/JS/Python syntax, class/route/Vercel validators, JSON) |
| Fresh-clone static wrapper | `SKIP_SMOKE=1 bash tests/run.sh` in a clone with no `.env`/SQLite | passed after adding `tests/static_bootstrap.php`; no 500 HTML |
| Local HTTP smoke test | `ADMIN_PASSWORD=... python3 tests/smoke.py` | **71 checks, 0 failures** |
| Local health diagnostics | `GET /api/health` | `status=ok`, `database=local`, `schema_ready=true`, sanitized `config` block; no secret values |

## HTTP smoke test coverage

The final smoke run exercised:

- Homepage, CSP/nosniff/Referrer/X-Frame security headers, four static assets, and 11 blocked source/config/secret paths (`.env`, `Dockerfile`, migrations, handlers, SQLite DB, tests, docs).
- `GET /api/health`, product listing/detail, search suggestions, empty cart, method allowlists, missing CSRF rejection, and unconfigured Stripe webhook rejection.
- A safe `GET` against **all 44 declared API patterns**; no 5xx, PHP fatal markers, or invalid JSON.
- Cart add → coupon → quantity update → remove → re-add, checkout page, hidden idempotency key, JSON order creation, replay returning the same order, real HTML checkout form submission, and order confirmation page.
- Newsletter, contact message, customer registration, account overview, orders API, API profile update, wishlist add/list/remove, review submission, support ticket creation, notification read, password change with CSRF/session rotation, stale-token rejection, password-reset request, logout, login with the new password, HTML profile form save and success flash.
- Guest admin-page/admin-API blocking, guest admin-login page rendering, administrator login, dashboard/settings APIs, and a **12-pattern authenticated admin API sweep** with no server errors.
- An authenticated sweep of **87 page routes** (all 90 declared page routes except `/logout`, `/admin/logout`, and the deliberately 500-status `/500` page). No 5xx response or PHP error marker was observed.

A check of the development-server log tail after the final HTTP and Chromium runs found no `Class "Helpers" not found`, `php.error`, `Uncaught`, `SQLSTATE`, or `Fatal error` entries. Expected informational entries (audit/order, admin login, mail-not-configured, CSRF rejection tests) are not failures.

## Chromium visual capture

Actual browser screenshots were captured with headless Chromium 156 and Playwright against the running local preview. The script rendered homepage, shop, product detail, admin login, authenticated admin dashboard, and a 390×844 mobile homepage; it collected page errors, console errors and HTTP 5xx responses. The first capture exposed a missing `includes/components.php` import on the standalone guest admin-login page (`Call to undefined function icon()`), which was fixed. A fresh capture after the fix reported **0 browser errors**. A repeat capture after the Vercel-readiness fixes again reported **0 browser errors**, confirming the runtime changes introduced no visual/console regression. The labelled contact sheet is `docs/design-preview.jpg`; individual PNG screenshots are outside the source ZIP at `/home/user/allstore-design-preview/*.png`. This is a visual smoke capture, not a full cross-browser/accessibility audit.

## Defects found and fixed during verification

The first full HTTP sweep exposed three real runtime defects: missing `use AllStore\Core\Helpers;` imports in `admin/brands.php`, `admin/coupons.php`, and `admin/reviews.php`. A token-based static validator (`tests/validate_classes.php`) was added to catch this class of bug, and the imports were fixed. An ambiguous SQL fixture in the smoke test was also corrected so `/admin/customers/{id}` receives a real profile id. A later Chromium capture found that the standalone `admin/login.php` used `icon()` without loading `includes/components.php`; the import and a guest-login regression check were added. Post-push verification on a clean GitHub clone showed that `validate.php` and `validate_classes.php` unnecessarily loaded the full application bootstrap and emitted a 500 page when `.env`/SQLite were absent; both now use `tests/static_bootstrap.php`, which registers only the autoloader. A later Vercel-readiness audit found six deployment risks (production file logging, session-level `SET NAMES` on the Supabase pooler, strict `X-Forwarded-Proto` parsing, `immutable` caching of unversioned assets, a floating Alpine base image, and no preflight guard); all were fixed and are now covered by `tests/validate_vercel.php`. A follow-up live Vercel API diagnosis found the project `allstorbd` with a READY Container deployment but only three env vars (`APP_URL`, `SESSION_DRIVER`, `SUPABASE_DB_URL`); missing `APP_KEY`/`DB_DRIVER`/`DATABASE_URL` caused a runtime 500. Config now accepts Supabase/Vercel DSN aliases and Vercel fallbacks, `/api/health` bypasses session bootstrap and reports sanitized DB/schema state, and the missing production env names were set through the Vercel API. See `VERCEL_READINESS.md` and the workspace report `VERCEL_DEPLOY_RESULT.md`.

Earlier code review and runtime work also fixed: checkout idempotency key replay/ownership and rotated keys; the nullable cart id used after checkout; profile flash messages after redirect; password-change session invalidation and CSRF token refresh; support-ticket lookup by ticket number; admin header RBAC defence in depth; static-file exposure in `router.php`; and Caddy/Container configuration alignment.

## Verification boundary

The following remain **unverified** and are listed in `KNOWN_LIMITATIONS.md`:

- Docker/FrankenPHP/Caddy image build or `vercel dev --local` container validation (Docker/Caddy binaries were unavailable in this sandbox).
- Vercel deployment, framework/Container preset selection, production `$PORT` routing, HSTS/TLS header inspection on a public domain.
- Live Supabase PostgreSQL connectivity, transaction-pooler behaviour, RLS policies, Storage buckets and least-privilege DB role.
- Stripe checkout/webhook with real credentials and signature; SMTP delivery; external courier/SMS/push providers.
- Full interactive responsive-breakpoint inspection, cross-browser JS execution, accessibility audit, visual design sign-off, load/concurrency and third-party security audit. Headless Chromium screenshots were captured, but they are a visual smoke check rather than full QA.

`tests/smoke.py` mutates the local SQLite database and generates unique customer/order/newsletter/ticket/review fixtures. It must not be run against production. The final ZIP excludes the local `.env`, SQLite database and local admin credential file.

## Release archive verification

`/home/user/allstore.zip` was built from the final source tree. Archive verification results:

- ZIP integrity: `ok`; **234 entries**, approximately **1.38 MB** compressed (includes `tests/validate_bugfixes.py` and `docs/BUGFIXES_2026-11-26.md`).
- All **187 source PHP files** (including `tests/static_bootstrap.php`, `tests/validate_vercel.php` and `scripts/vercel_preflight.php`), all **9** Markdown docs (incl. `VERCEL_READINESS.md`) plus `README.md`, and `docs/design-preview.jpg` are present.
- Includes `README.md`, `Caddyfile`, `Dockerfile`, `Dockerfile.vercel`, `.dockerignore`, `.gitignore`, `vercel.json`, `vercel.vercel-php.example.json`, all tests, `.env.example`, and both `storage/*/.gitkeep` placeholders.
- Zero forbidden entries: no `.env`, `.env.*` other than `.env.example`, SQLite DB/WAL/SHM, `app.key`, logs, uploads, `__pycache__`, `.pyc`, archives, or local admin credential file.

## 2026-11-26 cart / Buy-now bug-fix release

A follow-up live report found the product-view Add to cart flow appearing stuck for ~10–15 seconds, Buy now landing on `/cart`, and guest `/account/wishlist` / `/account/reviews` returning 500. The whole route set was swept again and the following were fixed: repeated cross-region DB round trips, the Buy-now destination, variant-id handling, early account authentication, PostgreSQL-invalid review SQL, coupon-removal markup, newsletter error placement, CSRF auto-retry, request timeout and optimistic cart feedback. See `docs/BUGFIXES_2026-11-26.md`.

Verification after these fixes:

- `bash tests/run.sh`: 187 PHP syntax, 3 JS syntax, **15/0** new bug-fix static checks, 623 route/handler checks, 40 Vercel-readiness checks, **73/0 HTTP smoke checks** (including new guest-account redirect checks).
- Real headless Chromium at 390×844 on a variant product: first variant preselected, cart badge 0→1 in ~260 ms, one success toast, 0 console/page errors. Buy now reached `/checkout`; coupon apply/remove and newsletter error placement worked; guest wishlist redirected to `/login?next=…`.
- Clean `/home/user/allstore.zip`: 234 entries, 187 PHP files, integrity `ok`, `regions:["sin1"]`, no exact token literals or local secrets.

These are **local** results. Promotion of this specific release to the existing Vercel production deployment is recorded in the workspace handoff separately; it is not implied by the local test run.

## 2026-11-25 feature release — ordered media, all product kinds, Bangladesh checkout

A second follow-up added admin order product images/downloads, generic product support and an admin-managed Bangladesh division/district/upazila/thana hierarchy. See `docs/FEATURE_RELEASE_2026-11-25.md`. No new SQL table or user-run migration is required; new state uses bundled JSON, `settings`, existing attribute/order/address JSON columns and existing `order_items` fields.

Verification after these changes:

- `bash tests/run.sh`: **192 PHP** syntax, **5 JS** syntax, **15/0** bug-fix static checks, **32/0** new feature static checks, **650** route/handler checks, **40** Vercel-readiness checks, **73/0 HTTP smoke checks**.
- `tests/validate_release_features.py`: bundled 8 divisions / 64 districts / 495 upazilas with CC-BY-4.0 attribution, reusable BD cascade, admin location CRUD/storage, checkout + address book, admin image/CSV download, SSRF guards, all product kinds, custom order fields, private digital files, digital-only checkout, shipping-zone matching, customer order views and BD defaults; **32/0**.
- Local runtime verification: **35/35** checks covering `/api/locations`, `/api/checkout/shipping-rates`, admin division/district/thana CRUD and reset, custom product → cart → BD checkout → admin image/CSV/download, digital-only checkout, account address book, missing-area rejection and private digital upload.
- Headless Chromium: **8/8** checks; add-to-cart, BD cascade auto-fill, live rates, shipping methods, admin order media, exact custom selections, **0 console/page errors**. Screenshots are outside the source ZIP at `/home/user/checkout_bd_local.png` and `/home/user/admin_order_media_local.png`.
- Final clean `/home/user/allstore.zip`: approximately **1.42 MB**, **244 entries**, **192 PHP**, **11 Markdown docs**, integrity `ok`, forbidden entries `0`, `regions:["sin1"]`, no `.env`/SQLite/local admin credential/exact token literal.

These are **local-source** results; the local test run does not itself verify live deployment. Live GitHub/Vercel promotion and REST/browser verification are recorded separately in `/home/user/FEATURE_DEPLOY_RESULT.md` and `/home/user/FEATURE_RELEASE_REPORT_BN.md`.
