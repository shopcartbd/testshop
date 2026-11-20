# Environment variables

Configure these under the Vercel project's **Settings → Environment Variables** as server-side values. Add them to the environments that should run (Production and, separately, Preview). Do not prefix secret values with `NEXT_PUBLIC_` or expose them to the browser. Redeploy after changes.

| Variable | Required | Guidance |
| --- | --- | --- |
| `APP_URL` | Yes for production | Exact HTTPS origin without trailing slash; used for canonical URLs, sitemap and email links. |
| `SUPABASE_DB_URL` | Yes (or component variables) | Supabase PostgreSQL URI for dedicated `allstore_app`, with TLS (`sslmode=require`). Never use an anon key as a database password. |
| `SESSION_DRIVER` | Yes on Vercel | Set `database`; local default is `file`. Requires migration 002 and `app.sessions` privileges. |
| `APP_NAME` | Optional | Store display name; defaults to `AllStore`. |
| `APP_CURRENCY` | Optional | Defaults to `BDT`; checkout currently accepts BDT only. |
| `APP_KEY` | Strongly recommended | A unique random server-only secret (e.g. 64 hex characters) used to HMAC rate-limit bucket identifiers. Changing it resets existing rate-limit buckets. |
| `APP_ENV` | Optional/informational | `production` is a useful label, but the current application code does not branch on it. |
| `LOG_LEVEL` | Optional/not consumed | Listed as a convention only; current application logging does not read this setting. |
| `PORT` | Supplied by container | Vercel runtime supplies it; Docker/Caddy uses it. Usually do not set manually. |

Instead of `SUPABASE_DB_URL`, the code accepts all of `SUPABASE_DB_HOST`, `SUPABASE_DB_PORT` (default 5432), `SUPABASE_DB_NAME` (default `postgres`), `SUPABASE_DB_USER`, `SUPABASE_DB_PASSWORD` and `SUPABASE_DB_SSLMODE` (`require`, `verify-ca` or `verify-full`). `DATABASE_URL` is also accepted as a fallback. Use the exact Supabase connection host/user/port shown for the chosen connection mode; URL-encode reserved characters in a manually assembled URI.

Prefer Supabase direct or **Session pooler** connections. The database session handler holds a PostgreSQL advisory lock across a PHP session request to prevent concurrent requests from overwriting authentication/cart state. Transaction-mode poolers do not preserve session-level database state, so do not use one for this session-locking setup without replacing that design and verifying it. Connection capacity, networking and TLS have not been tested against the owner's Supabase project.

## Optional SMTP

To send password reset and order emails, configure all of: `MAIL_HOST`, `MAIL_PORT` (usually 587), `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`; `MAIL_FROM_NAME` is optional. The implementation uses SMTP with STARTTLS. Do not assume a message was delivered until the provider accepts and delivers a test.

## Optional Supabase Storage

For admin product-image uploads, configure `SUPABASE_URL`, `SUPABASE_STORAGE_SERVICE_KEY`, and optionally `SUPABASE_STORAGE_BUCKET` (default `product-media`). The app sends uploads server-to-server; keep the Storage service-role credential private. A public-read bucket is needed if product images are to load without signed URLs. Bucket policy and uploads remain unverified.

## Example file

`.env.example` lists the current settings with blank secrets. It is a template only and is safe to commit. Do not commit `.env`, `.env.local`, `.env.production`, credentials, private keys, backups or production logs.
