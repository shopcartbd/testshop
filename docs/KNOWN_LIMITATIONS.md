# Known limitations and pending integrations

This file separates **implemented and locally verified** work from work that needs credentials, a real deployment, or human QA. No live production claims are made.

## Credentials / accounts not available here

- No Supabase project URL, DB password, Storage credentials, or API keys were provided. Live PostgreSQL connectivity, RLS enforcement, Storage upload/download, and least-privilege role behaviour are unverified.
- Arena's GitHub connector was unavailable, but a user-supplied classic `ghp_` PAT was used once to push the filtered source to `shopcartbd/testshop` on `main`. Because that token appeared in chat, it must be revoked immediately. No Vercel account/project was connected; no Vercel deployment or production URL exists.
- No Stripe secret/webhook secret or SMTP provider credentials were provided. Stripe Checkout, live webhook HMAC verification, and email delivery are code-complete but inactive until configured.
- No external courier, SMS, push, analytics or search-provider credentials are wired in. Shipping, search and notifications use the local database.

## Container/deployment validation still required

- `Dockerfile`, `Dockerfile.vercel` and `Caddyfile` follow the official Vercel PHP-on-Docker/FrankenPHP guidance and passed a static readiness audit, but Docker/Caddy/FrankenPHP binaries were unavailable in this sandbox. The image was not built and `vercel dev --local` was not run.
- `Caddyfile` syntax, `php_server` rewrite behaviour, original `REQUEST_URI` preservation, and platform `$PORT` routing were checked statically against official Caddy/FrankenPHP docs but still need confirmation in a real Container Runtime deployment.
- `vercel.json` contains `version: 2`, edge security headers, revalidatable asset caching and API `no-store`. If framework detection does not select the Container preset, run `vercel project update --framework container --yes` as documented.
- `vercel.vercel-php.example.json` uses the archived `vercel-community/php` community runtime; it is a fallback example only and should not be enabled together with the Container Dockerfile.
- `scripts/vercel_preflight.php` and `tests/validate_vercel.php` check env/extensions/config statically; they are not a substitute for a real image build or live deployment.

## Production data and persistence

- Local SQLite is for development/tests only and is excluded from Git/Docker/ZIP. Production requires PostgreSQL (`DB_DRIVER=pgsql`).
- `database/migrations/004_least_privilege.sql` contains a literal placeholder password. Replace it manually in a secure environment; never commit the real value.
- `002_rls.sql` is PostgreSQL/Supabase-only. Direct PDO connections bypass RLS, so the PHP RBAC/ownership checks must remain enabled and the runtime DB role should be least-privileged.
- Vercel container filesystems are ephemeral/read-only except temporary paths. Uploads must use Supabase Storage; file sessions and local logs must not be relied upon. Sessions/rate limits/audit/idempotency are DB-backed, and production logging is stderr-only.
- Database migrations are SQL files, not an automatic production migration runner. Apply them in order from a trusted client/SQL editor.

## Optional feature boundaries

- Email uses a generic HTTPS JSON provider via `MAIL_PROVIDER_URL`/`MAIL_API_KEY`. Provider-specific payload mapping may need adjustment. Without mail credentials, password-reset tokens are issued but the API reports `email_not_configured` rather than pretending delivery.
- Stripe online payments activate only with `PAYMENT_PROVIDER=stripe`, `PAYMENT_GATEWAY_SECRET`, and `PAYMENT_WEBHOOK_SECRET`. Configure the real webhook URL/events after deployment. COD works without credentials.
- The webhook handler accepts the documented success event types and verifies HMAC/timestamp/order/amount/currency, but has not been tested with a real Stripe event.
- UploadService has a local non-executable fallback for development. Production should use Supabase Storage buckets and private-document policies.
- No queue worker or long-running background process is included. Scheduled tasks would need authenticated cron HTTP endpoints or a separate worker service.

## QA not performed

- Headless Chromium desktop/mobile screenshots were captured with zero console/HTTP errors and are included as `docs/design-preview.jpg`. Full interactive responsive-breakpoint inspection, cross-browser JavaScript execution, screen-reader/accessibility audit, and visual design sign-off were not performed.
- No load/concurrency/oversell stress test against PostgreSQL was performed. Checkout uses conditional stock updates and transactions, but production pooler behaviour should be tested under load.
- No third-party penetration test or formal security audit was performed. `SECURITY.md` documents controls; `TEST_REPORT.md` documents the local checks actually run.
- No production HSTS/TLS/CSP header inspection on a public HTTPS domain was possible. App/Caddy/Vercel header configuration is present.
- No automated visual comparison of generated images or generated-image licensing review beyond using locally generated assets was performed.

## Local-preview specifics

- The local preview uses a development-only `.env`, a generated local admin password at `/home/user/allstore-local-admin.txt`, and a SQLite database containing smoke-test fixtures. These are intentionally excluded from `/home/user/allstore.zip`.
- Static checks (`SKIP_SMOKE=1 bash tests/run.sh`) now pass on a fresh clone without `.env`/SQLite because the validators use `tests/static_bootstrap.php`. Runtime pages and the HTTP smoke test still require a configured database.
- Re-running `tests/smoke.py` repeatedly can hit the application's intentional rate limits; use a fresh local database or wait for the configured windows. Do not run it against production.
- `scripts/bootstrap_admin.php` is one-time and refuses to run after `bootstrap_completed=1` or when an admin already exists.

## Vercel readiness audit (2026-11-25) — fixed but not live-verified

The following were found by a fresh audit and fixed in code; each is guarded by `tests/validate_vercel.php`, but none has been exercised on a real Vercel deployment:

- Production `Logger` no longer writes to `storage/logs` (read-only container FS).
- PostgreSQL connect no longer issues session-level `SET NAMES` (Supabase transaction pooler discards session state).
- `Request::isHttps()` handles comma-separated `X-Forwarded-Proto` and RFC 7239 `Forwarded`.
- Unversioned assets are no longer cached as `immutable`; `/api/*` is explicitly `no-store`.
- Base image pinned to `dunglas/frankenphp:1.14.0-php8.4-trixie` with `curl` in the extension list.
- `scripts/vercel_preflight.php` added for env/extension checks before deploy.

Remaining blockers are external: Vercel app/account not connected in Arena, no Supabase credentials/migrations applied, no Docker/FrankenPHP build, no live Stripe/SMTP/Storage test, and no production URL/header inspection.
