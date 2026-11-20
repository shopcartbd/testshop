# AllStore

AllStore is a PHP 8.4, server-rendered marketplace storefront for Bangladesh, backed by PostgreSQL and designed to connect to Supabase. The existing `shopcartbd/testshop` repository is the source of truth; this README describes the code, not a verified live store. `APP_NAME` can replace the AllStore display name.

## Included in the source

The repository now contains catalog browsing/search/filter/sort/pagination, product details and moderated reviews, a session cart, customer registration/login/logout/password reset/account pages, addresses, wishlist, account-scoped order history, order tracking, support tickets and return requests. Checkout is transactional **Cash on Delivery only**, with stock locking, server-calculated prices/shipping/coupons/tax, coupon usage records, inventory history and order idempotency. The admin UI/services include product editing, categories, brands, stock adjustments, order status and tracking, customer status, review moderation, banners, summary and sales reports. A same-origin versioned JSON API, SMTP mailer, optional Supabase Storage upload, database-backed PHP sessions, owner bootstrap CLI, migrations and deployment/setup docs are included.

A number of those code paths still require a real staging database and end-to-end checks. Product variants/bundles are not checkout-enabled; online payments, refunds, courier booking/tracking APIs and mailing-list confirmation delivery are not connected. The example Bangladesh delivery method is **৳80 and must be changed**. See [TODO.md](TODO.md) for the full remaining list.

## Stack and deployment shape

The app uses PHP 8.4 / FrankenPHP + Caddy in the root `Dockerfile`, PHP PDO PostgreSQL and Supabase PostgreSQL/Storage. Vercel's Container Runtime is documented as beta; the single-container setup does not require `vercel.json`. The project/runtime, Supabase credentials, SMTP/Storage configuration and actual deployment have not been verified from this sandbox. A push to `main` may trigger Vercel if the project is linked to that repository/branch; a push is not proof of a successful deployment.

## Setup

1. Create a Supabase project. Apply `database/migrations/001_initial_schema.sql`, then `database/migrations/002_operational_marketplace.sql`, then optionally `database/seed.sql` in Supabase SQL Editor. Back up data and validate both migrations on a separate staging project first; migrations are not rerunnable.
2. Create a dedicated non-superuser `allstore_app` database login, apply the documented grants and trusted PHP runtime RLS policies, and keep its password server-side.
3. Configure the required Vercel variables in [docs/ENVIRONMENT_VARIABLES.md](docs/ENVIRONMENT_VARIABLES.md), including `SESSION_DRIVER=database`. Prefer Supabase direct or **Session pooler** connections for the advisory-locked PHP session handler; test network/connection capacity before production.
4. Configure `APP_URL` to the exact HTTPS origin. For a first owner, run `php scripts/bootstrap-admin.php` from a trusted terminal after migrations and database access are ready.
5. Set a real delivery rate and policy, then verify the app against a staging database and test customer/admin scenarios before accepting orders.

The detailed [Supabase and Vercel setup guide](docs/SUPABASE_VERCEL_SETUP.md) covers the SQL role/policies, migrations, Vercel values and optional integrations. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md), [docs/DATABASE.md](docs/DATABASE.md), [docs/API.md](docs/API.md), [docs/SECURITY.md](docs/SECURITY.md), [docs/TEST_REPORT.md](docs/TEST_REPORT.md) and [docs/ENVIRONMENT_VARIABLES.md](docs/ENVIRONMENT_VARIABLES.md).

## Local development

Use PHP 8.4 with PDO PostgreSQL, `mbstring`, `fileinfo`, `curl`, Composer, and a staging PostgreSQL database. Copy `.env.example` to an ignored `.env`, set database variables, apply the migrations/seed to the staging database, and run `composer install`. Serve the app with `php -S 127.0.0.1:8080 -t public public/index.php`. Do not connect local experiments to production data. Docker image build/boot has not been verified in this environment because Docker is unavailable.

## Secrets and safety

Never commit `.env`, database passwords, SMTP credentials, Supabase Storage service-role keys, private keys, SQL dumps or sensitive logs. `.env.example` is safe to commit and contains blank secret fields. PHP authorization must remain enforced in the application: the trusted database credential has server-side access and must never be exposed to browser JavaScript, PostgREST clients or public API output. Do not claim a production deployment or order/payment success until independently verified.
