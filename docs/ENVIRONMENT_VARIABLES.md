# Environment variables

All variables are read by `src/Core/Env.php` and mapped in `src/Core/Config.php`. Copy `.env.example` to `.env` for local development only. On Vercel, set values in **Project → Settings → Environment Variables** (or `vercel env add`); a `.env` file inside the image is not a reliable production secret source and must not be committed.

`.env`, `.env.*` (except `.env.example`), `storage/*.sqlite`, `storage/app.key`, logs and uploads are excluded from Git/ZIP/Docker build contexts where appropriate. Never put a secret key in a browser-visible variable.

## App

| Variable | Required | Default | Notes |
|---|---:|---|---|
| `APP_NAME` | No | `AllStore` | Store name used in UI and emails. |
| `APP_ENV` | Yes in prod | `local` | `local`, `staging`, or `production`. Production forbids SQLite. |
| `APP_DEBUG` | No | `true` | Forced off in production. |
| `APP_URL` | Yes in prod | `http://localhost:8080` | Canonical absolute URL; used for payment success/cancel and password-reset links. |
| `APP_TIMEZONE` | No | `UTC` | PHP timezone, e.g. `Asia/Dhaka`. |
| `APP_CURRENCY` | No | `USD` | Three-letter ISO currency code; amounts are stored as integer cents. |
| `APP_LOCALE` | No | `en` | Reserved for locale-aware formatting. |
| `PORT` | Container runtime | `80` | Vercel injects it; `Caddyfile` listens on `:{$PORT:80}`. `EXPOSE` alone does not set Vercel routing. |
| `APP_KEY` | **Yes in prod** | dev fallback local | 32+ random bytes (hex/base64). Server-side HMAC pepper for reset tokens. Must be unique and secret. |

## Database

| Variable | Required | Notes |
|---|---:|---|
| `DB_DRIVER` | Yes | `local` for SQLite development/tests; `pgsql` for Supabase/PostgreSQL production. |
| `DATABASE_URL` | Yes in prod (or DB_* set) | Prefer Supabase **transaction pooler** URI on port `6543` with `sslmode=require` for serverless. Use direct `5432` only for migrations/backup or a persistent host. |
| `SUPABASE_DB_HOST` | Alternative | Discrete DB host if not using `DATABASE_URL`. |
| `SUPABASE_DB_PORT` | No | Default `6543`. Use `5432` for direct/session pooler. |
| `SUPABASE_DB_NAME` | No | Default `postgres`. |
| `SUPABASE_DB_USER` | Alternative | DB user when not using `DATABASE_URL`. |
| `SUPABASE_DB_PASSWORD` | Alternative | DB password when not using `DATABASE_URL`; server-side only. |
| `SUPABASE_DB_SSLMODE` | No | `require` (default), `verify-ca`, or `verify-full`. |
| `DB_POOL_MODE` | No | `transaction` (default; emulated prepares) or `session`. Supabase transaction pooler does not support server-side prepared statements. |

`001_schema.sql` is portable PostgreSQL 15+/SQLite 3.35+ DDL. `002_rls.sql` and `004_least_privilege.sql` are PostgreSQL/Supabase-specific. Replace the placeholder password in `004_least_privilege.sql` manually before running it; never commit the replacement.

**Vercel / Supabase alias support (added 2026-11-25):** `Config.php` also accepts `SUPABASE_DB_URL`, `POSTGRES_URL` and `POSTGRES_URL_NON_POOLING` as `DATABASE_URL`; `VERCEL_ENV=production` as `APP_ENV`; `VERCEL_URL` as an `APP_URL` fallback when `APP_URL` is missing/localhost; and it derives `SUPABASE_URL` from a Supabase DB URI when no explicit URL is supplied. `SESSION_DRIVER` is a common platform variable but is ignored — sessions are always DB-backed. These are aliases/fallbacks, not a replacement for a real PostgreSQL DSN.

## Supabase Storage and optional REST

| Variable | Required | Notes |
|---|---:|---|
| `SUPABASE_URL` | For Storage | `https://<REF>.supabase.co`. |
| `SUPABASE_SECRET_KEY` | For Storage | New `sb_secret_...` key; **server-side only**. Newer alternative to the legacy service-role JWT. |
| `SUPABASE_SERVICE_ROLE_KEY` | Legacy alternative | Legacy service-role JWT; **server-side only**. Never expose to the browser. |
| `SUPABASE_STORAGE_BUCKET` | No | Public product-image bucket; default `product-images`. |
| `SUPABASE_PRIVATE_BUCKET` | No | Private documents/attachments bucket; default `private-documents`. |

`003_storage.sql` creates the public product-images bucket and a private documents bucket. The PHP app uses a direct PDO connection; RLS does not constrain that privileged DB role, so PHP-level RBAC and ownership checks are mandatory.

## Security and rate limiting

| Variable | Required | Default | Notes |
|---|---:|---|---|
| `SESSION_IDLE_TIMEOUT` | No | `7200` | Seconds; minimum 300. DB-backed session expiry. |
| `CSRF_HEADER_NAME` | No | `X-CSRF-TOKEN` | Browser JS sends this header; classic forms send hidden `csrf_token`. |
| `RATE_LIMIT_LOGIN_MAX` | No | `5` | Login attempts per bucket/window. |
| `RATE_LIMIT_LOGIN_WINDOW` | No | `900` | Seconds; minimum 60. |

## One-time administrator bootstrap

These are read **only** by `scripts/bootstrap_admin.php` from a secure CLI shell, never by the web application:

| Variable | Required | Notes |
|---|---:|---|
| `ADMIN_BOOTSTRAP_EMAIL` | Yes | Valid email for the first `super_admin`. |
| `ADMIN_BOOTSTRAP_PASSWORD` | Yes | At least 12 characters; use a unique generated secret. |
| `ADMIN_BOOTSTRAP_NAME` | No | Default `Store Owner`. |

The script refuses to run if bootstrap was completed or an admin already exists. There is no default password in seed data.

## Optional integrations

Leaving these empty disables the integration rather than faking delivery/payment.

| Variable | Purpose |
|---|---|
| `MAIL_PROVIDER_URL` | HTTPS JSON mail API endpoint. |
| `MAIL_API_KEY` | Bearer token for the mail provider; server-side only. |
| `MAIL_FROM_ADDRESS` | Verified sender address. |
| `MAIL_FROM_NAME` | Sender display name. |
| `PAYMENT_PROVIDER` | Set to `stripe` to enable online card payments. |
| `PAYMENT_GATEWAY_SECRET` | Stripe secret API key; server-side only. |
| `PAYMENT_WEBHOOK_SECRET` | Stripe `whsec_...` signing secret for `POST /api/webhooks/stripe`. |
| `LOG_LEVEL` | `info` by default; logger uses stdout/stderr-friendly redacted output. |

No `SHIPPING_API_KEY` is required because shipping rates are computed from the local database. External courier/SMS/push providers are not wired in and would require additional credentials and code.

## Production checklist

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY=<random secret>`, canonical HTTPS `APP_URL`.
- `DB_DRIVER=pgsql` and transaction-pooler `DATABASE_URL` with `sslmode=require`.
- Supabase migrations applied in order; Storage buckets and private-document policies configured.
- SMTP/Stripe/Storage secrets set only in Vercel project settings/secret manager.
- `.env`, SQLite files, generated logs, uploads and local admin credential files excluded from Git/container/ZIP.
- Do **not** set `PORT` manually in Vercel settings; the platform injects it and `Caddyfile` defaults to `80`.
- Run `php scripts/vercel_preflight.php` with production-shaped env before deploying; see `VERCEL_READINESS.md`.
- `/api/health`, `/`, `/admin/login`, CSP/HSTS headers and one authenticated admin page checked after deployment.
