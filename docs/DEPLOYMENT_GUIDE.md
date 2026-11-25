# Deployment guide — Supabase + Vercel Container Runtime

**Status:** this guide is configuration-ready; no live Vercel project, Supabase instance, GitHub push, or production URL was created or verified in this workspace. Docker/FrankenPHP/Caddy image build and deployment remain to be validated with real credentials.

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

- `Dockerfile` — root image based on `dunglas/frankenphp:1-php8.4-alpine`; production `php.ini`, required extensions (`pdo_pgsql`, `pgsql`, `pdo_sqlite`, `sqlite3`, `mbstring`, `intl`, `gd`, `zip`, `opcache`), copies the app and Caddyfile, and runs `frankenphp run --config /etc/caddy/Caddyfile`.
- `Dockerfile.vercel` — identical copy, useful when a deployment convention looks for that filename.
- `Caddyfile` — compresses responses, sets security headers, denies source/config/docs/secrets/local DB/direct PHP paths, keeps `REQUEST_URI` from the original URI, and rewrites missing files to `/index.php` via `php_server`.
- `vercel.json` — minimal `version` and platform headers only. With a root Dockerfile and no auto-detected framework, Vercel selects the Container preset; no `functions.runtime` or catch-all `routes` entry is required.
- `vercel.vercel-php.example.json` — archived community-runtime fallback. Do **not** enable it at the same time as the Container Dockerfile.
- `.dockerignore` — excludes local `.env`, SQLite DBs, generated keys/logs/uploads, VCS metadata and archives from the build context.

If Vercel's framework detection does not select the Container preset, set it explicitly with the Vercel CLI:

```bash
vercel project update --framework container --yes
```

## 4. Deploy

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

- The container filesystem is read-only/ephemeral except temporary paths. Sessions, rate limits, audit logs and idempotency are DB-backed; logs go to stdout/stderr; uploads must use object storage.
- PHP file sessions and local SQLite are development-only and are not shared across instances.
- Use one PDO connection per warm instance and a conservative Supabase pool size; `DB_POOL_MODE=transaction` avoids prepared statements incompatible with Supavisor transaction mode.
- Rebuild/redeploy after changing files copied into the image. A 502 usually means FrankenPHP is not listening on all interfaces or `$PORT` does not match; a 404 on health usually means the front controller was not reached.
- Migrations and secrets must be managed outside the container image.
