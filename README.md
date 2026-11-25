# AllStore

**AllStore** is a dependency-free PHP e-commerce application with a complete storefront, customer account area, administration panel, JSON API, PostgreSQL/Supabase-ready schema, and a FrankenPHP/Caddy container for Vercel.

> সংক্ষেপে: সম্পূর্ণ PHP ই-কমার্স প্রজেক্ট, লোকাল SQLite ডেমো, Supabase/PostgreSQL-এর জন্য migration, Vercel Container Runtime-এর জন্য Dockerfile/Caddyfile, API ডকুমেন্টেশন, টেস্ট রিপোর্ট ও ZIP ডেলিভারি তৈরি করা হয়েছে। লাইভ Vercel/Supabase/Stripe/SMTP ডিপ্লয়মেন্ট বা ক্রেডেনশিয়াল যাচাই করা হয়নি।

## What is included

- **Storefront:** home, shop, category/subcategory, brand, product detail, search, deals, flash sale, best sellers, new arrivals, trending, cart, checkout, order confirmation, blog, FAQ, contact, policies, sitemap/robots and error pages.
- **Customer account:** register, login/logout, password reset request, overview, profile, addresses, orders, tracking, wishlist, reviews, notifications, coupons, returns/refunds, support tickets and password change.
- **Admin:** dashboard, reports, products/forms/variants, categories, attributes, brands, warehouses/inventory, orders/customers, coupons/promotions/flash sales, banners/homepage builder, reviews, payments/transactions, shipping/couriers, returns/refunds, CMS/blog/pages, roles/permissions/admin users, activity logs, notifications, settings/security and tickets.
- **API:** 44 route patterns mapped to 43 endpoint handlers plus `api/handlers/_common.php`; JSON envelope, method allowlists, CSRF on mutations, rate limits, RBAC and redacted public fields.
- **Data:** portable PostgreSQL 15+/SQLite schema with 58 tables, Supabase RLS/Storage/least-privilege migrations, idempotent local seed, DB-backed sessions/rate limits/audit/idempotency.
- **Frontend:** four CSS files, three dependency-free JavaScript files, local generated imagery and SVG favicon; no external fonts, CDNs or tracking scripts.
- **Ops:** `Dockerfile`, `Dockerfile.vercel`, `Caddyfile`, `vercel.json`, `.dockerignore`, `.env.example`, one-time admin bootstrap and validation/smoke tests.
- **Docs:** `docs/` contains environment, API, admin, deployment, security, architecture, test report and limitations.

## Quick start (local)

Requires PHP 8.3+ with `pdo_sqlite`, `pdo_pgsql`, `mbstring`, `gd`, `curl`, `xml`; Python 3 and Node are only needed to run the optional test checks. Composer is **not** required.

```bash
cd allstore
cp .env.example .env              # local development only; never commit .env
php database/migrate.php --reset  # creates storage/allstore.sqlite
php database/seed.php             # 24 products, 23 categories, 8 brands, demo content
php -S 0.0.0.0:8080 router.php   # open http://localhost:8080
```

The local `.env` uses `DB_DRIVER=local` and a development-only `APP_KEY`. Production requires `APP_ENV=production`, `DB_DRIVER=pgsql`, a real `DATABASE_URL`/`SUPABASE_DB_*`, and a secret `APP_KEY`.

Create the first administrator from a secure shell (there is no default password):

```bash
ADMIN_BOOTSTRAP_EMAIL='owner@example.com' \
ADMIN_BOOTSTRAP_PASSWORD='use-a-long-unique-secret' \
ADMIN_BOOTSTRAP_NAME='Store Owner' \
php scripts/bootstrap_admin.php
```

For this Arena workspace only, a unique local-preview administrator was created and its password saved outside the project at `/home/user/allstore-local-admin.txt`. The local `.env`, SQLite database and that credentials file are **not** included in `allstore.zip`.

## Test commands

```bash
bash tests/run.sh                         # lint + static validators + HTTP smoke test
php tests/validate.php                    # route/handler/static-call checks
php tests/validate_classes.php            # unresolved class-import check
python3 tests/smoke.py                    # requires the server to be running
```

`tests/smoke.py` mutates the local SQLite database and must never be run against production. It generated unique customer/order/newsletter/ticket/review fixtures during verification. See `docs/TEST_REPORT.md` for actual results and `docs/KNOWN_LIMITATIONS.md` for items not verified.

## Deployment shape

The primary deployment path is the official Vercel PHP-on-Docker/Container Runtime approach: root `Dockerfile` with FrankenPHP, `Caddyfile` listening on `$PORT`, PostgreSQL through Supabase's transaction pooler, and environment variables from Vercel project settings. No framework-specific `vercel.json` functions config is needed. `vercel.vercel-php.example.json` is kept only as a documented, unsupported community-runtime fallback. See `docs/DEPLOYMENT_GUIDE.md`.

## Documentation index

| File | Purpose |
|---|---|
| `docs/ENVIRONMENT_VARIABLES.md` | Every supported environment variable and required/optional status |
| `docs/API_DOCUMENTATION.md` | JSON envelope, CSRF/auth rules and all 44 endpoint patterns |
| `docs/ADMIN_SETUP.md` | One-time administrator bootstrap and RBAC notes |
| `docs/DEPLOYMENT_GUIDE.md` | Supabase + Vercel Container Runtime deployment |
| `docs/SECURITY.md` | Threat model and implemented controls |
| `docs/ARCHITECTURE.md` | Request lifecycle, directories, data and services |
| `docs/TEST_REPORT.md` | Exact tests executed and results |
| `docs/KNOWN_LIMITATIONS.md` | Credential-blocked and unverified areas |
| `docs/design-preview.jpg` | Chromium screenshot contact sheet of the actual local UI |

## Important status

This workspace contains a working **local** build with a live preview. It does **not** claim a live production URL, GitHub push, Vercel deployment, Supabase project, Storage bucket, RLS enforcement, Stripe live payment, SMTP delivery or browser cross-device QA. Those require credentials and an actual deployment; the code, migrations, configuration and documentation are provided to make those steps repeatable.
