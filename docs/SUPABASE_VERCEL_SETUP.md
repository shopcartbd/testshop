# Supabase + Vercel setup (AllStore)

This guide is for the PHP server-rendered store in this repository. It uses **PHP-managed accounts and PostgreSQL over PDO**, not Supabase Auth/PostgREST from the browser. Do not put database passwords or Storage service keys in frontend variables, public GitHub files, or client JavaScript.

## 1. Create the Supabase database schema

1. Create a Supabase project and choose a region appropriate for your customers. Keep the database password private.
2. In **SQL Editor**, apply these files in order, once each:
   - `database/migrations/001_initial_schema.sql`
   - `database/migrations/002_operational_marketplace.sql`
   - `database/seed.sql` (optional, but it adds two sample categories and one editable manual Bangladesh delivery method).
3. The first migration uses the Supabase `auth.users`, `anon`, and `authenticated` roles. The second adds PHP-managed accounts, sessions, orders, support, reviews, shipping, audit and other operational records. Do not skip or reorder migrations. Review them against a staging Supabase project before production. They are not rerunnable migrations.
4. The seed's `bd_standard` method starts at **৳80** as an example only. Change it to your actual courier rate before taking real orders; this is not a courier integration.

### Create a server database role

The app uses direct PDO SQL; Supabase RLS policies do **not** automatically supply customer authorization to a direct connection. The PHP services perform role, ownership, validation, transaction and CSRF checks. Do not use the public `anon` key as a database credential. Do not grant `BYPASSRLS` to the runtime role.

For a dedicated role, create a strong password and run the following as a trusted database administrator, replacing the placeholder with a SQL-escaped generated password:

```sql
CREATE ROLE allstore_app LOGIN PASSWORD '<GENERATE-A-UNIQUE-STRONG-PASSWORD>' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE postgres TO allstore_app;
GRANT USAGE ON SCHEMA app TO allstore_app;
GRANT SELECT ON ALL TABLES IN SCHEMA app TO allstore_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA app TO allstore_app;
GRANT INSERT, UPDATE ON app.accounts, app.products, app.categories, app.brands, app.banners, app.orders, app.shipments, app.marketplace_reviews, app.newsletter_subscribers, app.password_reset_tokens TO allstore_app;
GRANT INSERT, UPDATE, DELETE ON app.account_addresses, app.sessions, app.rate_limits TO allstore_app;
GRANT INSERT, DELETE ON app.product_images, app.wishlist_items TO allstore_app;
GRANT INSERT ON app.audit_logs, app.coupon_usage, app.order_items, app.order_status_history, app.payments, app.return_requests, app.search_events, app.stock_movements, app.support_tickets, app.ticket_messages, app.wishlists TO allstore_app;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA app GRANT SELECT ON TABLES TO allstore_app;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA app GRANT USAGE, SELECT ON SEQUENCES TO allstore_app;
```

These grants give the runtime role read access to existing application tables and targeted writes only on tables the PHP services mutate; new tables receive read-only access by default and require review before granting writes. They do **not** make `allstore_app` a database owner or superuser. If your migrations were executed by a role other than `postgres`, adjust the `ALTER DEFAULT PRIVILEGES FOR ROLE` statements to that migration role. Use the Supabase direct connection or **Session pooler** connection string; the PHP database session handler holds a session-level advisory lock across each request, so transaction-mode pooling is not supported by this design. Copy the exact host, user and port from the Supabase dashboard, require TLS, and validate connection limits before launch. URL-encode reserved characters in the username/password if composing a URI manually.

## 2. Configure Vercel server-side variables

In the Vercel project, open **Settings → Environment Variables**. Set these for the environments you intend to deploy (at minimum Production; set Preview too if you want preview stores to connect to a staging database):

| Variable | Required | Value |
| --- | --- | --- |
| `APP_URL` | Yes for production | Exact public HTTPS origin, e.g. `https://your-project.vercel.app` (no trailing slash). |
| `SUPABASE_DB_URL` | Yes (or component variables) | PostgreSQL connection URI copied from Supabase, with `sslmode=require` and the `allstore_app` credential. Keep server-side only. |
| `SESSION_DRIVER` | Yes for Vercel | `database`; this stores PHP sessions in `app.sessions` instead of a container's temporary filesystem. |
| `APP_NAME` | Optional | `AllStore` or your chosen store name; defaults to `AllStore`. |
| `APP_CURRENCY` | Optional | `BDT` (the current checkout only accepts BDT). |
| `APP_KEY` | Strongly recommended | Unique server-side random value used to HMAC rate-limit bucket identifiers; changing it resets buckets. |
| `APP_ENV` | Optional/informational | Set `production` if useful; the application currently does not branch on this value. |

The component form is also supported if you do not use a URI: `SUPABASE_DB_HOST`, `SUPABASE_DB_PORT`, `SUPABASE_DB_NAME`, `SUPABASE_DB_USER`, `SUPABASE_DB_PASSWORD`, and `SUPABASE_DB_SSLMODE=require`. The application prefers `SUPABASE_DB_URL` over `DATABASE_URL`. `PORT` is supplied by the container platform and normally does not need to be entered manually. There is no need to add `SUPABASE_ANON_KEY` for the browser; this PHP app does not use one.

After changing environment variables, redeploy the Vercel project so the new container receives them. For a public Vercel deployment, `APP_URL` must match the actual deployed origin to generate canonical URLs and a sitemap.

## 3. Create the first admin/owner account

After both migrations are applied and the database role can access the schema, run the CLI command once from a trusted terminal that has PHP with PDO PostgreSQL and the database environment variables:

```sh
php scripts/bootstrap-admin.php
```

The command asks for a display name and email, then reads and confirms a minimum-16-character password without echoing it. It takes a PostgreSQL advisory lock and refuses to create another privileged account once an `admin` or `owner` exists. Do not add bootstrap credentials to source control or run this command from a public web route. When using a local `.env`, keep it ignored and permission-restricted, export its values only in the trusted shell, then run the command. Sign in at `/login`; keep the owner password in a password manager.

## 4. Optional email and product image uploads

**Email:** To enable password-reset and order email messages, configure `MAIL_HOST`, `MAIL_PORT` (normally `587`), `MAIL_USERNAME`, `MAIL_PASSWORD`, and `MAIL_FROM_ADDRESS`; `MAIL_FROM_NAME` is optional. The implementation uses authenticated SMTP with STARTTLS. Test with a non-customer address first. Password reset links are only generated when the SMTP settings are present. Email delivery/provider behavior is not verified by this repository check.

**Supabase Storage:** To use product image uploads, create a `product-media` bucket (or set `SUPABASE_STORAGE_BUCKET`). Public product images require a public-read bucket. Uploads are sent from the PHP server using `SUPABASE_URL` and `SUPABASE_STORAGE_SERVICE_KEY` (or the code's legacy `SUPABASE_SERVICE_ROLE_KEY` fallback). That credential must be a server-only secret with the necessary Storage write permission; never use it in browser code. The upload handler accepts JPG, PNG and WebP up to 5 MB, validates MIME/dimensions, and writes unique object names. Storage credentials/bucket policies have not been tested in a live project.

## 5. What is and is not connected

The current working payment method is **Cash on Delivery**. There is no online payment gateway or courier API connected. The sample delivery method is a database configuration, not a real courier rate or booking. Customer email, order status, stock, and coupon totals are stored by the PHP app when a live database is configured. Account data is PHP-managed rather than Supabase Auth-managed.

You said your GitHub repository is connected to Vercel. A push to the configured production branch may therefore start a deployment, but this session cannot verify your Vercel project, runtime variables, deployment result, live HTTPS, or production database connection. Check the Vercel Deployments page after the GitHub push; do not take real orders until database access, owner login, delivery rates, and a test COD order have been verified in a safe/staging project.

### RLS policies for the PHP runtime

Migration 001 enables RLS on several legacy tables, including `app.orders`, `app.order_items`, `app.order_status_history`, and `app.coupon_usage`. A non-superuser PHP role will need an explicit trusted-server policy on the order tables it writes. After creating `allstore_app` and applying both migrations, run these policies as a database administrator (once):

```sql
CREATE POLICY allstore_server_runtime ON app.orders
  FOR ALL TO allstore_app USING (true) WITH CHECK (true);
CREATE POLICY allstore_server_runtime ON app.order_items
  FOR ALL TO allstore_app USING (true) WITH CHECK (true);
CREATE POLICY allstore_server_runtime ON app.order_status_history
  FOR ALL TO allstore_app USING (true) WITH CHECK (true);
CREATE POLICY allstore_server_runtime ON app.coupon_usage
  FOR ALL TO allstore_app USING (true) WITH CHECK (true);
```

These policies allow the trusted PHP database role to execute its server-side queries; they are **not** browser/user policies. Keep this credential only in Vercel server environment variables. The PHP app must continue to enforce account ownership and admin authorization in its parameterized queries and service methods. Do not add the runtime role to `anon` or `authenticated`, expose `app` tables to the Data API, or treat these policies as a replacement for PHP authorization. Review the complete required table set and grants on a staging project before production.
