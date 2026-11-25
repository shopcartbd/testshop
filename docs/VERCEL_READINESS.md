# Vercel readiness audit — AllStore

**Date:** 2026-11-25 (UTC workspace date)  
**Scope:** static/code-level readiness for **Vercel Container Runtime + FrankenPHP/Caddy + Supabase PostgreSQL**.  
**Status:** ✅ code hardened and statically verified; ❌ **not deployed** to Vercel, no live URL, no Docker image build, no live Supabase/Stripe/SMTP test.

This is the detailed companion to `DEPLOYMENT_GUIDE.md` §8 and `KNOWN_LIMITATIONS.md`.

## Official guidance checked

- Vercel Container Runtime: root `Dockerfile` / `Containerfile` selects the Container preset when no other framework is detected; `Dockerfile.vercel` forces it when another framework is detected; default port is `80`, overridable with `PORT`; logs come from stdout/stderr. <https://vercel.com/docs/functions/runtimes/container>
- FrankenPHP Docker: Debian variants are recommended; `install-php-extensions` is provided in the base image. <https://frankenphp.dev/docs/docker/>
- FrankenPHP Caddy config: `php_server` is the recommended high-level directive; it handles index rewriting and static files. <https://frankenphp.dev/docs/config/>
- Caddy placeholders: `{http.request.orig_uri}` / `{orig_uri}` are valid runtime placeholders for the original URI. <https://caddyserver.com/docs/caddyfile/concepts>
- Supabase Postgres: transaction mode (port `6543`) does **not** preserve session state (`SET`/`RESET`, prepared statements, advisory locks); use emulated prepares and `sslmode=require`. <https://supabase.com/docs/guides/database/connecting-to-postgres>

## What was already correct

| Area | Finding |
|---|---|
| Container detection | Root `Dockerfile` **and** byte-identical `Dockerfile.vercel` are present; `vercel.json` has no `functions.runtime`, so the archived community PHP runtime is not active. |
| Port | `Caddyfile` listens on `:{$PORT:80}`; Dockerfile sets `ENV PORT=80`; `EXPOSE 80` is documentary only. |
| Caddy | `frankenphp` global option, mandatory `order php_server before file_server`, `root * /app`, `php_server`, compression, security headers, `respond @denied 404`, `respond @privatePhp 404`, `respond @privateStorage 404`. |
| Clean URLs | `env REQUEST_URI {http.request.orig_uri}` and `env SCRIPT_NAME /index.php` keep `$_SERVER['REQUEST_URI']` on the original path, so `Request::path()` / `Router::dispatch()` resolve `/shop`, `/api/health`, etc. FrankenPHP's `php_server env` reaches `$_SERVER`. |
| Persistence | Sessions, CSRF, rate limits, audit logs, idempotency and carts are DB-backed. PHP file sessions / local SQLite are dev-only. |
| Uploads | `UploadService` refuses local disk writes when `APP_ENV=production` and Storage is unconfigured; returns `503` instead of pretending success. |
| Headers | CSP/HSTS/X-Frame-Options/Referrer/Permissions-Policy are set both in PHP (`Security::sendHeaders`) and at the edge (`vercel.json` / Caddy). |

## Issues found and fixed in this audit

| # | Risk | Fix | File(s) |
|---|---|---|---|
| 1 | Production still attempted `file_put_contents(storage/logs/app-*.log)` on Vercel's read-only FS. | Production path now returns after `error_log()` → stderr only. Dockerfile already sets `error_log=/proc/self/fd/2`. | `src/Core/Logger.php` |
| 2 | `SET NAMES 'UTF8'` on every PostgreSQL connect is session-level state; Supabase transaction pooler discards it between transactions. | Removed the `SET NAMES` call; UTF-8 is the driver default and client encoding can be set via DSN/options if ever needed. | `src/Core/Database.php` |
| 3 | `X-Forwarded-Proto: https,http` (or RFC 7239 `Forwarded`) made strict `=== 'https'` fail, risking non-`Secure` session cookies / missing PHP HSTS. | `Request::isHttps()` now reads the first comma-separated value, `REQUEST_SCHEME`, and `Forwarded: proto=https`. | `src/Core/Request.php` |
| 4 | `vercel.json` cached unversioned `/assets/*` as `immutable, max-age=31536000`; CSS/JS updates would be stuck in browsers. | Changed to `public, max-age=3600, must-revalidate`; added explicit `no-store` for `/api/*`. | `vercel.json` |
| 5 | Floating `dunglas/frankenphp:1-php8.4-alpine` base; Alpine is not the recommended variant. | Pinned `dunglas/frankenphp:1.14.0-php8.4-trixie` (Debian Trixie, patched PHP 8.4 / FrankenPHP 1.14.0) and added `curl` to `install-php-extensions`. `Dockerfile.vercel` kept byte-identical. | `Dockerfile`, `Dockerfile.vercel` |
| 6 | No pre-deploy env/extension check and no regression guard for the above. | Added `scripts/vercel_preflight.php` and `tests/validate_vercel.php`; the latter is wired into `tests/run.sh`. | `scripts/`, `tests/` |

## Static verification actually run

```text
SKIP_SMOKE=1 bash tests/run.sh
php -l passed for 187 PHP files
node --check passed for 3 JS files
python3 -m py_compile passed
Scanned 187 PHP files. All static class references resolve.
Static checks: 593
API routes: 44, page routes: 90, handler files: 44
Validation passed.
JSON configuration is valid
Vercel readiness checks: 28
Vercel readiness passed.
Static checks passed.
```

`php scripts/vercel_preflight.php` was also run with **dummy** production-shaped env. All config checks passed; it correctly reported `NOT READY` only because this **local sandbox** lacks `intl`, `zip` and `opcache`. Those three are explicitly installed by the Dockerfile, so this is a sandbox gap, not a deployment defect.

Local HTTP checks after the fixes: `/api/health` → 200, `/404` → 404, `/500` → 500 with correct CSP/Content-Type/Cache-Control and no header-after-output warnings in the server log tail. Full HTTP smoke stayed at **71 checks / 0 failures**, and a repeat headless Chromium capture reported **0 browser errors** across desktop/mobile/admin screens.

## Preflight usage (run before importing to Vercel)

```bash
APP_ENV=production APP_DEBUG=false APP_KEY='<32+ random bytes>' \
DB_DRIVER=pgsql \
DATABASE_URL='postgresql://postgres.<REF>:<PASSWORD>@<POOLER_HOST>:6543/postgres?sslmode=require' \
DB_POOL_MODE=transaction \
APP_URL='https://your-domain.example' \
php scripts/vercel_preflight.php
```

It prints presence/absence only — never secret values. Exit `0` = ready; exit `1` = fix the listed failures.

## Live Vercel API diagnosis — 2026-11-25

A project-scoped `vcp_` token was supplied and used transiently against the Vercel REST API. Findings:

- Project **`allstorbd`** (`prj_GbBkTWgvm3v4NrCNlP2HYTYBk5CJ`), framework **`container`**, linked to `shopcartbd/testshop@main`, production aliases `allstorbd.vercel.app` plus two team-protected aliases.
- Latest deployment `dpl_JCyo1zWDh4sMCnoi9X5jvjec4szg` was `READY / PROMOTED` on commit `cd5c467…`, yet `https://allstorbd.vercel.app/` returned **HTTP 500** with an empty body. The earlier `404 DEPLOYMENT_NOT_FOUND` is consistent with a hostname that had no ready deployment alias assigned at that moment, or with a different/custom domain not attached to this project.
- Only **three** production env vars existed: `APP_URL`, `SESSION_DRIVER`, `SUPABASE_DB_URL` — all write-only sensitive values. `APP_KEY`, `DB_DRIVER`, `DATABASE_URL`, `APP_ENV`, `APP_DEBUG` and `DB_POOL_MODE` were absent. Vercel marks sensitive env values non-readable through the API [1](https://vercel.com/docs/environment-variables/managing-environment-variables), so the existing `SUPABASE_DB_URL` value was never printed or copied.
- Fix: `Config.php` now accepts `SUPABASE_DB_URL` / `POSTGRES_URL` / `POSTGRES_URL_NON_POOLING` as `DATABASE_URL` aliases, `VERCEL_ENV` / `VERCEL_URL` as fallbacks, and derives `SUPABASE_URL` from a Supabase DB URI. `/api/health` bypasses DB-backed session bootstrap and reports `database_connected`, `schema_ready`, `db_source`, `db_host`, `db_port`, `db_ssl` and presence flags — never values.
- Fix: missing non-secret production env names were upserted via `POST /v10/projects/{id}/env?upsert=true`; `APP_KEY` was generated with `secrets.token_hex(32)` and stored as a **sensitive** Vercel env var. `PORT` was deliberately left unset.
- `tests/validate_vercel.php` grew from 28 to **34 checks**; `tests/validate.php` from 593 to **607 checks**. Local HTTP smoke remained **71/0**.

Live redeploy verification and the final public URL are recorded in `/home/user/VERCEL_DEPLOY_RESULT.md` (workspace report outside the repository). The Vercel token was pasted in chat and must be revoked after use.

## Still required by you

1. **Use the correct domain.** The only project domain currently attached is `allstorbd.vercel.app`. If you opened a custom/older domain, it is not assigned to this project; add it in Vercel → Project → Settings → Domains (or tell us the exact hostname) instead of expecting the project alias to answer. If auto-detection ever misses the Container preset: `vercel project update --framework container --yes`.
2. **Confirm Vercel env vars** in the dashboard. The minimum production names were already upserted through the API: `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` (generated, write-only), `DB_DRIVER=pgsql`, `DB_POOL_MODE=transaction`; `APP_URL`, `SESSION_DRIVER` and `SUPABASE_DB_URL` already existed. Do **not** set `PORT` manually. `SUPABASE_DB_URL` is read through the new alias support, so its write-only value was never inspected or copied.
3. **Prepare Supabase:** apply `database/migrations/001…004` in order from a trusted SQL client (replace the placeholder password in `004`), create Storage buckets/policies, then bootstrap the first admin with `scripts/bootstrap_admin.php`. `/api/health` now reports `schema_ready` so a missing migration is distinguishable from a bad DSN.
4. **Container build / public checks:** Vercel built the OCI image during deployment; a local `docker build` / `vercel dev --local` is still not available in this sandbox. After deploy check `/api/health`, `/`, `/admin/login`, `/.env` → 404, and HSTS/CSP on the public HTTPS domain.
5. **Optional integrations:** `SUPABASE_URL` + server-side secret, `MAIL_*`, `PAYMENT_PROVIDER=stripe` + secrets + webhook URL. Until configured, those features stay disabled and never fake success. A Supabase URL may be derived from the DB URI, but the server-side secret key was not supplied, so Storage remains disabled.
6. **Revoke both chat-exposed tokens:** the classic `ghp_` GitHub PAT in **Tokens (classic)**, and the `vcp_` Vercel personal access token in Vercel → Account Settings → Tokens. Deleting chat messages or local files does not disable them.

## Honest boundary

This audit is **static + local**. It did not build the OCI image, connect to Supabase, run Vercel, inspect a production URL, exercise Stripe/SMTP/Storage with real credentials, or perform full cross-browser/accessibility/load QA. `TEST_REPORT.md` and `KNOWN_LIMITATIONS.md` remain the source of truth for what was and was not executed.
