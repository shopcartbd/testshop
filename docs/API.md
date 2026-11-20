# JSON API v1

The API is same-origin under `/api/v1/`, returns JSON and sends `Cache-Control: private, no-store`. Browser/session cookie authentication is used; there is no bearer-token or public cross-origin CORS flow. CSRF-protected writes require a token from `GET /api/v1/csrf` in `X-CSRF-Token` (or the `_csrf` body field) and the same session cookie. The API shares PHP-managed accounts and authorization with the website.

## Routes

- `GET /csrf` — CSRF token.
- `GET /categories`, `GET /brands` — active categories and brands.
- `GET /products?q=&category=&brand=&sort=&page=&limit=` — catalog page and pagination metadata; `limit` is capped at 48 and `page` at 200.
- `GET /products/{slug}` — product and its public reviews.
- `GET /cart` — current session cart and `checkout_key` metadata. Cart mutations are currently website form routes, not API routes.
- `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`; `GET /me`.
- `GET /me/orders`, `GET /me/orders/{uuid}` — authenticated, account-scoped orders.
- `GET /me/addresses`, `POST /me/addresses`, `DELETE /me/addresses/{uuid}` — authenticated address book.
- `GET /me/wishlist`, `POST /me/wishlist` — authenticated wishlist (POST toggles a `product_id`).
- `POST /me/reviews`, `POST /me/returns` — authenticated and owner-checked review/return requests.
- `POST /orders` — COD order from the current session cart. Supply an `Idempotency-Key` request header or `idempotency_key` body value of 16–128 allowed characters for every logical order. Send checkout fields (`name`, `email` when guest, `phone`, `line1`, `line2`, `locality`, `region`, `postal_code`, `country_code`, `shipping_method_id`, optional `coupon_code`, `customer_note`). Prices and totals are server-calculated; never send a trusted total. Replay with the same key returns the existing order; use a new key for a new order.
- `POST /contact` — create a support ticket.
- `POST /newsletter` — records pending-consent subscription; no confirmation-mail provider is connected.
- Admin-only: `GET /admin/summary`, `/admin/orders`, `/admin/customers?q=`, `/admin/reports?days=`, `/admin/products?q=&page=`; `POST /admin/orders/{uuid}/status`, `/admin/products`, `/admin/categories`, `/admin/brands`.

All paths above are relative to `/api/v1`. Admin writes require an admin/owner session and CSRF token. Server validation and SQL parameter binding apply. Rate-limited authentication/newsletter endpoints may return `429`.

## Response shape

Success responses use `{"data": ...}` (some catalog routes also include `meta`). Errors use `{"error":{"status":422,"message":"..."}}`; common codes include `400`, `401`, `403`, `404`, `419` (CSRF), `422` (validation), `429` and `500`. Internal SQL details are not returned. There is no documented stable compatibility guarantee yet; the API contract has not been verified against a live database/client.
