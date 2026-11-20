# Database setup and schema

The app connects to PostgreSQL using PDO. Supabase is the intended managed PostgreSQL provider; no live project has been connected or tested from this work session. Migrations assume the Supabase `auth` schema and roles supplied by a normal Supabase project.

## Apply in order

On a **separate staging project first**, apply using Supabase SQL Editor:

1. `database/migrations/001_initial_schema.sql` — core catalog, customer/order legacy tables, categories, products, variants, coupons, shipping and initial RLS.
2. `database/migrations/002_operational_marketplace.sql` — PHP-managed accounts/sessions/rate limits/password reset, addresses, attributes, stock movements, wishlist, marketplace reviews, shipping zones/methods, order/customer fields, audit/support/returns/banners/newsletter and additional indexes/constraints.
3. `database/seed.sql` (optional) — starter categories and a sample Bangladesh manual delivery method at ৳80. Edit the fee/policy before any actual order.

Migrations are one-time and not idempotent. Do not reorder or run against a database with conflicting schema. Back up before production schema changes. Check Supabase SQL Editor execution status and then verify the tables/indexes with a read-only query.

## Runtime role and RLS

`app.Database` uses `SUPABASE_DB_URL` or the documented component variables. Create a dedicated non-superuser `allstore_app` login with read access to existing app tables, targeted write grants, sequence usage and read-only future-table defaults. Use a direct or Supabase Session-pooler connection because the PHP session handler holds a session-level advisory lock; transaction pooling is not supported by this design. The detailed SQL is in [docs/SUPABASE_VERCEL_SETUP.md](SUPABASE_VERCEL_SETUP.md).

Migration 001 has RLS on legacy order tables. Because the PHP app connects with direct PDO rather than a PostgREST JWT, Supabase user identity/`auth.uid()` is not automatically present. After creating the role and applying migrations, the setup guide adds restrictive-to-role-name but table-wide trusted-server policies for `allstore_app` on `app.orders`, `app.order_items`, `app.order_status_history` and `app.coupon_usage`. These policies do **not** authenticate an end user; the PHP services must continue checking owner/admin rights. Do not grant `BYPASSRLS`, use `service_role` as a routine runtime login, expose `app` in the Data API, or place database credentials in client code.

The owner bootstrap CLI inserts only the first `admin`/`owner` account and must run from a trusted terminal after both migrations. Public registration cannot create privileged roles.

## Data and constraints

- Customer identity is PHP-managed in `app.accounts`; it is separate from Supabase Auth users.
- Storefront cart is currently held in the PHP session. `SESSION_DRIVER=database` stores sessions in `app.sessions` for multi-instance Vercel use.
- Checkout locks product rows, recalculates totals from database prices/rates, consumes stock in the same transaction, writes order/status/payment/shipment/inventory/coupon records, and uses an idempotency key.
- Current supported currency is BDT and current payment method is COD; integration/payment provider tables do not mean a card gateway is connected.
- Product variants and bundles are modeled but cannot currently be checked out.
- Supabase Storage is an optional server-side upload integration, not database seed data.

No database migration, RLS policy, trigger, checkout transaction, query plan, or live database connection has been executed/verified in this sandbox. Validate migrations, role policies and representative read/write scenarios on staging before production.


**Tax limitation:** checkout currently calculates tax from each product's `tax_rate` column and applies coupon discounts before calculating tax. The `app.tax_rules` table is schema-only at present; country/region tax rules are not read by checkout and tax-on-shipping is not modeled. Confirm local tax treatment and configure/test product rates before taking orders.
