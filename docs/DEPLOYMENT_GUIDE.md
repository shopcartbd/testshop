# Deployment guide — Supabase + Vercel Container Runtime

**Status:** configuration-ready and pushed to `shopcartbd/testshop` on `main`. No live Vercel project, Supabase instance, production URL, Docker/FrankenPHP/Caddy image build, or `vercel dev --local` run was performed in this workspace. A static Vercel-readiness audit, `scripts/vercel_preflight.php`, and `tests/validate_vercel.php` were added; real deployment still requires Vercel/Supabase credentials.

## Architecture decision

Vercel has no first-party PHP runtime. The official Vercel knowledge-base guide recommends deploying PHP with a Docker image containing **FrankenPHP + Caddy**, listening on `$PORT`. The root `Dockerfile` and `Caddyfile` in this repository follow that shape. The `vercel-community/php` runtime is archived and community-maintained; `vercel.vercel-php.example.json` is retained only as a fallback example, not a production recommendation.

Official references consulted while writing the configuration:

- Vercel KB: `https://vercel.com/kb/guide/deploy-php-on-vercel-with-docker`
- Vercel Container Runtime: `https://vercel.com/docs/functions/runtimes/container`
- Vercel `vercel.json`: `https://vercel.com/docs/project-configuration/vercel-json`
- FrankenPHP Docker docs: `https://frankenphp.dev/docs/docker/`
- Supabase Postgres connections: `https://supabase.com/docs/guides/database/connecting-to-postgres`

## 1. Prepare Supabase

1. Create a Supabase project and note the project reference, DB password, and pooler host.
2. For runtime/serverless connections, use the **Shared pooler / transaction mode** URI on port `6543` with `sslmode=require`. Example shape:
   ```text
   postgresql://postgres.<REF>:<PASSWORD>@<POOLER_HOST>:6543/postgres?sslmode=require
   ```
   Supabase transaction mode does not support server-side prepared statements, so `DB_POOL_MODE=transaction` keeps PDO emulated prepares enabled.
3. For migrations/backup use the direct connection on `5432` from a trusted machine or the Supabase SQL editor. Do not bake the direct DB password into browser code.
4. Run SQL migrations in order:
   ```text
   database/migrations/001_schema.sql   # 58 tables, constraints, indexes
   database/migrations/002_rls.sql     # PostgreSQL/Supabase RLS policies
   database/migrations/003_storage.sql # public product-images + private documents buckets
   database/migrations/004_least_privilege.sql # DML-only app role (replace placeholder password)
   ```
   Replace the literal placeholder password in `004_least_privilege.sql` manually before running it, and never commit the replaced value.
5. Because the PHP app connects with PDO as a privileged DB role, RLS does not protect that connection. Keep PHP-level RBAC/ownership checks enabled and use the least-privilege role for runtime where practical.
6. Create/confirm Storage buckets and policies from `003_storage.sql`. Uploads and file sessions must not be stored on the container filesystem.

## 2. Configure Vercel environment variables

Set these in **Vercel Project → Settings → Environment Variables** (or `vercel env add`), separately for Preview and Production where needed. See `ENVIRONMENT_VARIABLES.md` for the full reference.

Minimum production set:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
APP_KEY=<32+ random bytes>
DB_DRIVER=pgsql
DATABASE_URL=postgresql://postgres.<REF>:<PASSWORD>@<POOLER_HOST>:6543/postgres?sslmode=require
DB_POOL_MODE=transaction
SESSION_IDLE_TIMEOUT=7200
CSRF_HEADER_NAME=X-CSRF-TOKEN
```

Optional integrations (omit to disable cleanly): `SUPABASE_URL`, `SUPABASE_SECRET_KEY` (or legacy `SUPABASE_SERVICE_ROLE_KEY`), `SUPABASE_STORAGE_BUCKET`, `SUPABASE_PRIVATE_BUCKET`, `MAIL_*`, `PAYMENT_PROVIDER=stripe`, `PAYMENT_GATEWAY_SECRET`, `PAYMENT_WEBHOOK_SECRET`, `LOG_LEVEL`.

Vercel injects `PORT`; the `Caddyfile` listens on `:{$PORT:80}` and binds all interfaces. `EXPOSE 80` in the Dockerfile is documentary only and does not set Vercel's routing port.

## 3. Repository configuration

- `Dockerfile` — pinned `dunglas/frankenphp:1.14.0-php8.4-trixie` (Debian Trixie; patched PHP 8.4 / FrankenPHP 1.14.0). FrankenPHP's Docker docs recommend Debian variants. Production `php.ini`, extensions (`pdo_pgsql`, `pgsql`, `pdo_sqlite`, `sqlite3`, `curl`, `mbstring`, `intl`, `gd`, `zip`, `opcache`), copies the app and Caddyfile, and runs `frankenphp run --config /etc/caddy/Caddyfile`.
- `Dockerfile.vercel` — byte-identical copy; a root `Dockerfile.vercel` explicitly selects the Container preset when another framework is detected.
- `Caddyfile` — compresses responses, sets security headers, denies source/config/docs/secrets/local DB/direct PHP paths, keeps `REQUEST_URI` from the original URI, and rewrites missing files to `/index.php` via `php_server`.
- `vercel.json` — `version: 2`, edge security headers, revalidatable 1-hour asset caching (no `immutable`, because asset filenames are not content-hashed), and explicit `no-store` for `/api/*`. With root `Dockerfile`/`Dockerfile.vercel` and no other framework, Vercel selects the Container preset; no `functions.runtime` or catch-all `routes` entry is required.
- `vercel.vercel-php.example.json` — archived community-runtime fallback. Do **not** enable it at the same time as the Container Dockerfile.
- `.dockerignore` — excludes local `.env`, SQLite DBs, generated keys/logs/uploads, VCS metadata and archives from the build context.

If Vercel's framework detection does not select the Container preset, set it explicitly with the Vercel CLI:

```bash
vercel project update --framework container --yes
```

## 4. Deploy

Before the first deploy, run the preflight locally with production-shaped environment variables. It does **not** connect to the database and never prints secret values:

```bash
APP_ENV=production APP_KEY='<random-secret>' DB_DRIVER=pgsql \
DATABASE_URL='postgresql://postgres.<REF>:<PASSWORD>@<POOLER_HOST>:6543/postgres?sslmode=require' \
APP_URL='https://your-domain.example' \
php scripts/vercel_preflight.php
```

It checks required env vars, PHP extensions, the pinned image, Caddy markers, secret exclusions and cache config. `php tests/validate_vercel.php` enforces the same static invariants and is part of `tests/run.sh`.

Then deploy:

```bash
vercel link                 # or import the Git repository in the Vercel dashboard
vercel env add ...          # or configure variables in the dashboard
vercel deploy --prod
```

Do not commit `.env`, `storage/*.sqlite`, `storage/app.key`, logs, uploads, or local admin credential files. `.gitignore` and `.dockerignore` already exclude them.

After the first deploy check:

```text
GET /api/health            # JSON: status ok, php, database, integrations; no secrets
GET /                      # storefront HTML and CSP/security headers
GET /admin/login           # admin sign-in page
GET /assets/js/app.js      # same-origin static asset
GET /.env                  # 404
GET /src/Core/Database.php # 404
```

On the production HTTPS domain, confirm HSTS and CSP in the response headers. Note that `/api/health` confirms PHP and DB connectivity, not Supabase Storage, RLS, SMTP, or Stripe.

## 5. First administrator

Run the CLI bootstrap from a trusted machine against the production DB, not from a public web route:

```bash
APP_ENV=production DB_DRIVER=pgsql DATABASE_URL='<production-pooler-uri>' \
ADMIN_BOOTSTRAP_EMAIL='owner@example.com' \
ADMIN_BOOTSTRAP_PASSWORD='<unique-generated-secret>' \
php scripts/bootstrap_admin.php
```

Then sign in at `/admin/login`. See `ADMIN_SETUP.md`.

## 6. Optional integrations

- **Email:** set `MAIL_PROVIDER_URL`, `MAIL_API_KEY`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. Without them, password-reset tokens are issued but the API reports `email_not_configured` instead of pretending delivery succeeded.
- **Stripe:** set `PAYMENT_PROVIDER=stripe`, `PAYMENT_GATEWAY_SECRET`, `PAYMENT_WEBHOOK_SECRET`. Configure the Stripe webhook URL as `https://<domain>/api/webhooks/stripe` with the success events listed in `API_DOCUMENTATION.md`. Cash on Delivery works without Stripe.
- **Supabase Storage:** set `SUPABASE_URL` and a server-side secret key. The app uses a local non-executable fallback for development; production uploads should use Storage because container files are ephemeral.
- **Background work:** Vercel functions scale to zero; do not rely on in-process queue workers. Use scheduled/cron HTTP calls or a separate worker service for future asynchronous jobs.

## 7. Operational constraints

- The container filesystem is read-only/ephemeral except temporary paths. Sessions, rate limits, audit logs and idempotency are DB-backed; **production logging writes only to stderr** (Dockerfile sets `error_log=/proc/self/fd/2`); uploads must use object storage.
- PHP file sessions and local SQLite are development-only and are not shared across instances.
- Use one PDO connection per warm instance and a conservative Supabase pool size; `DB_POOL_MODE=transaction` avoids prepared statements incompatible with Supavisor transaction mode. No session-level `SET`/`RESET` is issued on connect, because transaction mode discards session state between transactions.
- `Request::isHttps()` accepts comma-separated `X-Forwarded-Proto` chains and RFC 7239 `Forwarded`, so `Secure` session cookies and HSTS behave correctly behind Vercel's proxy.
- Rebuild/redeploy after changing files copied into the image. A 502 usually means FrankenPHP is not listening on all interfaces or `$PORT` does not match; a 404 on health usually means the front controller was not reached.
- Migrations and secrets must be managed outside the container image.

## 8. Vercel readiness audit — 2026-11-25

A code-level audit against the official Vercel Container, FrankenPHP, Caddy and Supabase guidance found and fixed six deployment risks:

1. **Production file logging** — `Logger` no longer tries to write `storage/logs/*.log` when `APP_ENV=production`; only stderr is used.
2. **Supabase transaction pooler** — removed `SET NAMES 'UTF8'` on connect; session state does not survive between transactions on port 6543.
3. **HTTPS detection behind the proxy** — `Request::isHttps()` now handles `https,http` chains and `Forwarded: proto=https`.
4. **Asset caching** — `vercel.json` no longer marks unversioned CSS/JS/images as `immutable`; uses `max-age=3600, must-revalidate` and explicit API `no-store`.
5. **Reproducible base image** — pinned `dunglas/frankenphp:1.14.0-php8.4-trixie` (Debian) and added `curl` to the extension list.
6. **Preflight / regression guard** — added `scripts/vercel_preflight.php` and `tests/validate_vercel.php` (wired into `tests/run.sh`).

Confirmed **not** broken: root `Dockerfile`/`Dockerfile.vercel` Container detection, `$PORT` default 80, `order php_server before file_server`, `root * /app`, `REQUEST_URI` preservation via `{http.request.orig_uri}`, `SCRIPT_NAME`, source/secret deny rules, DB-backed sessions/rate limits/audit/idempotency, and production upload guard.

Still requires real credentials / a real build: Supabase migrations + env vars, Vercel project import, Docker/FrankenPHP image build, `vercel dev --local`, live PostgreSQL/Storage/RLS, Stripe/SMTP, and production header inspection. See `VERCEL_READINESS.md` for the full checklist.
