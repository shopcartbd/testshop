# Architecture

## Runtime topology

```text
Browser
  ├── clean page routes ──> index.php / router.php ──> Router ──> pages/*.php, pages/account/*.php, admin/*.php
  ├── static assets ──────> /assets/css, /assets/js, /assets/img, public /storage/uploads
  └── /api/* ─────────────> api/index.php ──> ApiRoutes ──> api/handlers/*.php
                                      │
                                      └── src/Core, src/Models, src/Services ──> PDO ──> SQLite (local) / PostgreSQL (Supabase)
```

`includes/bootstrap.php` is the single bootstrap: autoloading, environment/config, timezone, error/exception handlers, security headers, DB-backed session start and pruning. `index.php` and `router.php` both route through `Router::dispatch()`. `api/index.php` resolves `ApiRoutes::map()` patterns, including named dynamic segments, to one handler file each.

On Vercel, the root `Dockerfile` builds a FrankenPHP/Caddy image. `Caddyfile` listens on `$PORT`, protects private paths, serves public assets, and rewrites unknown routes to `/index.php` while preserving the original `REQUEST_URI` for the router.

## Directory map

| Path | Contents |
|---|---|
| `src/Core/` | 14 framework classes: `Env`, `Config`, `Database`, `Helpers`, `Request`, `Response`, `Security`, `Logger`, `Session`, `Auth`, `Router`, `ApiRoutes`, `Http`, `AppException`. |
| `src/Models/` | 6 repositories: catalogue, CMS, customers, orders, inventory, reports. |
| `src/Services/` | 12 services: cart, checkout, payment, mail, upload, coupon, shipping, notification, search, homepage, review, support. |
| `includes/` | 11 shared PHP files: bootstrap, icons/components, storefront header/footer/home sections, listing/account/admin layouts, admin helpers and CRUD helpers. |
| `pages/` | 30 storefront/content/utility routes. |
| `pages/account/` | 20 authenticated customer routes. |
| `admin/` | 39 administration routes. |
| `api/handlers/` | `_common.php` + 43 endpoint handlers covering 44 API patterns (`health.php` is mapped twice). |
| `assets/` | 4 CSS files, 3 dependency-free JS files, local generated JPEGs/SVG favicon. |
| `database/migrations/` | `001_schema.sql`, `002_rls.sql`, `003_storage.sql`, `004_least_privilege.sql`. |
| `database/` | `migrate.php`, `seed.php` for local SQLite. |
| `scripts/` | One-time CLI admin bootstrap. |
| `tests/` | Static validators and HTTP smoke test. |
| `docs/` | Environment, API, admin, deployment, security, test report and limitations. |
| `storage/` | Local SQLite/logs/uploads directories; only `.gitkeep` files are shipped. |

## Data model

`001_schema.sql` defines 58 tables with UUID-like text primary keys, integer cent amounts, foreign keys/unique constraints/checks/indexes, soft deletes, and DB-backed sessions, rate limits, audit logs and idempotency. Major domains:

- **Identity:** `profiles`, `roles`, `permissions`, `role_permissions`, `sessions`, `password_resets`, `addresses`.
- **Catalogue:** `products`, `product_images`, `categories`, `brands`, `attributes`, `attribute_values`, `product_variants`, `product_attributes`, `category_attributes`, `inventory`, `stock_movements`, `warehouses`.
- **Commerce:** `carts`, `cart_items`, `orders`, `order_items`, `order_status_history`, `payments`, `transactions`, `shipments`, `invoices`, `coupons`, `coupon_usage`, `return_requests`, `refunds`.
- **Engagement/CMS:** `reviews`, `wishlists`, `newsletter_subscribers`, `contact_messages`, `support_tickets`, `ticket_messages`, `notifications`, `banners`, `homepage_sections`, `flash_sales`, `flash_sale_items`, `pages`, `blog_posts`, `faqs`, `settings`, `search_queries`, `popular_searches`, `shipping_zones`, `shipping_rates`, `couriers`.

`002_rls.sql` enables public catalogue reads and ownership/admin policies for PostgreSQL. `003_storage.sql` defines public product images and private documents. `004_least_privilege.sql` creates a DML-only app role (replace the placeholder password before use).

## Request lifecycle

1. `Env::load()` reads local `.env` if present; Vercel environment variables are already in the process environment.
2. `Config::init()` validates environment/driver, builds a PostgreSQL DSN with SSL and pooler mode, and enforces production requirements (`pgsql`, `APP_KEY`).
3. `Security::sendHeaders()` sends CSP and platform headers; `Session::start()` loads/creates a DB-backed session and CSRF token.
4. `Router::dispatch()` matches clean URLs, extracts named segments into `$_GET`, applies no-cache to private routes, and requires the page file. `ApiRoutes::map()` similarly maps API patterns to handlers.
5. Pages call shared components plus repositories/services. Handlers call `_common.php` guards and return a consistent JSON envelope through `Response::json()`.
6. `Session::save()` runs at shutdown; exceptions are logged and converted to a safe JSON or HTML error response.

## Business logic boundaries

- Repositories own SQL and return raw DB rows; services own multi-step workflows and validation.
- `CartService` always recomputes item prices/stock and totals from the DB.
- `CheckoutService` runs a transaction, conditionally decrements stock, recalculates totals/coupon/shipping/tax, writes order/payment/shipment/invoice/notification rows, and handles idempotency and failed Stripe startup.
- `PaymentService` offers COD by default and credential-gated Stripe Checkout; only a verified webhook marks an order paid.
- `MailService`/`UploadService` are integration adapters with explicit `configured()` checks; local fallbacks never pretend external delivery/storage succeeded.
- `Security::rateLimit()` and `Session` use the database, not local files, so they work across stateless instances.

## Local vs production

| Concern | Local sandbox | Production target |
|---|---|---|
| Web server | `php -S 0.0.0.0:8080 router.php` | FrankenPHP/Caddy in Vercel Container Runtime |
| Database | SQLite file `storage/allstore.sqlite` | Supabase PostgreSQL via transaction pooler 6543, `sslmode=require` |
| Sessions/rate limits/audit | SQLite tables | PostgreSQL tables |
| Uploads | `storage/uploads` fallback | Supabase Storage buckets |
| Secrets | dev-only `.env` | Vercel project environment variables/secret manager |
| Mail | disabled; status reported | generic HTTPS JSON provider via `MAIL_*` |
| Payments | COD only; Stripe webhook 503 | COD + credential-gated Stripe Checkout/webhook |
| Admin | one-time local bootstrap credentials outside ZIP | one-time CLI bootstrap against production DB |

The source has no Composer dependencies and no build step; PHP, CSS and JS files are copied as-is into the container.
