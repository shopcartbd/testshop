# API documentation

All endpoints are served under `/api/` by `api/index.php` and the files in `api/handlers/`. `GET /api` returns the endpoint list. There are **44 route patterns**, **43 distinct endpoint handlers** and one shared `_common.php` helper (`health.php` is mapped twice).

## Envelope and conventions

```json
{ "success": true, "message": "Request completed", "data": {}, "errors": [], "csrf_token": "..." }
```

- `data` is `{}` when empty; `errors` is an object/array of field errors on validation failures.

- API responses include a fresh `csrf_token` when a session is available. This matters after login/password change, which rotate the session identifier. `window.AllStore.request()` updates `document.body`, `data-csrf` and hidden `csrf_token` inputs automatically.

- Send `Accept: application/json`. For mutations send CSRF as the `X-CSRF-TOKEN` header (configurable via `CSRF_HEADER_NAME`) or a `csrf_token` form/JSON field. Missing/invalid CSRF returns HTTP 419.

- Every handler applies a method allowlist; wrong methods return HTTP 405. Mutating handlers apply DB-backed rate limits (HTTP 429 on excess).

- Admin endpoints require an authenticated admin and a matching permission. Unauthenticated requests return 401; insufficient permission returns 403. Public handlers return allowlisted fields only; secrets and password hashes are never returned.

- The Stripe webhook is the documented exception to CSRF: it requires `PAYMENT_WEBHOOK_SECRET` and a valid `Stripe-Signature` HMAC. It returns 503 when Stripe is not configured.

- Dynamic `{id}` values accept alphanumeric, underscore and hyphen identifiers; product endpoints accept an id or slug where documented.


## Endpoint reference

| Pattern | Methods | Access | CSRF | Handler | Description |
|---|---|---|---|---|---|
| `/api/products` | GET | Public | No | `products.php` | List/filter/sort/paginate active products. |
| `/api/products/{id}` | GET | Public | No | `product.php` | Product detail with images, variants, attributes, reviews and related items. |
| `/api/categories` | GET | Public | No | `categories.php` | List active categories. |
| `/api/brands` | GET | Public | No | `brands.php` | List active brands. |
| `/api/search` | GET | Public | No | `search.php` | Full catalogue search with filters and pagination. |
| `/api/search/suggest` | GET | Public | No | `suggest.php` | Type-ahead product/category suggestions. |
| `/api/cart` | GET | Public | No | `cart.php` | Current cart and server-calculated totals. |
| `/api/cart/add` | POST | Public | Yes | `cart-add.php` | Add a product/variant to the cart. |
| `/api/cart/update` | POST | Public | Yes | `cart-update.php` | Change quantity or saved-for-later state. |
| `/api/cart/remove` | POST | Public | Yes | `cart-remove.php` | Remove a cart line. |
| `/api/cart/coupon` | POST | Public | Yes | `cart-coupon.php` | Apply or clear a coupon. |
| `/api/wishlist` | GET | Signed in | No | `wishlist.php` | Current user wishlist. |
| `/api/wishlist/add` | POST | Signed in | Yes | `wishlist-add.php` | Toggle a product on the wishlist. |
| `/api/wishlist/remove` | POST | Signed in | Yes | `wishlist-remove.php` | Remove a wishlist item. |
| `/api/orders/create` | POST | Public | Yes | `orders-create.php` | Create an order from the current cart (CSRF + idempotency). |
| `/api/orders` | GET | Signed in | No | `orders.php` | Orders belonging to the signed-in customer. |
| `/api/orders/{id}` | GET | Signed in | No | `order.php` | One customer order by id or order number. |
| `/api/orders/{id}/cancel` | POST | Signed in | Yes | `order-cancel.php` | Cancel an eligible customer order. |
| `/api/auth/register` | POST | Public | Yes | `auth-register.php` | Register and sign in a customer. |
| `/api/auth/login` | POST | Public | Yes | `auth-login.php` | Sign in a customer or admin user. |
| `/api/auth/logout` | POST | Signed in | Yes | `auth-logout.php` | Destroy the current session. |
| `/api/auth/forgot-password` | POST | Public | Yes | `auth-forgot.php` | Issue a generic password-reset response; email delivery is reported safely. |
| `/api/auth/reset-password` | POST | Public | Yes | `auth-reset.php` | Consume a single-use reset token. |
| `/api/account/profile` | GET, POST | Signed in | Yes on POST | `account-profile.php` | Read or update the signed-in profile. |
| `/api/account/password` | POST | Signed in | Yes | `account-password.php` | Change password and invalidate other sessions. |
| `/api/reviews/create` | POST | Signed in | Yes | `review-create.php` | Submit a pending product review. |
| `/api/newsletter` | POST | Public | Yes | `newsletter.php` | Subscribe or reactivate an email address. |
| `/api/contact` | POST | Public | Yes | `contact.php` | Store a contact message and admin notification. |
| `/api/tickets/create` | POST | Signed in | Yes | `ticket-create.php` | Create a support ticket and first message. |
| `/api/notifications/read` | POST | Signed in | Yes | `notifications-read.php` | Mark one or all user notifications read. |
| `/api/admin/dashboard` | GET | Admin + permission | No | `admin-dashboard.php` | Dashboard metrics and charts. |
| `/api/admin/products` | GET, POST | Admin + permission | Yes | `admin-products.php` | Read products or create/update/bulk-manage them. |
| `/api/admin/categories` | GET, POST | Admin + permission | Yes | `admin-categories.php` | Read or save/delete categories and filter attributes. |
| `/api/admin/brands` | GET, POST | Admin + permission | Yes | `admin-brands.php` | Read or save/delete brands. |
| `/api/admin/orders` | GET, POST | Admin + permission | Yes | `admin-orders.php` | Read orders or update status. |
| `/api/admin/orders/update-status` | POST | Admin + permission | Yes | `admin-order-status.php` | Update an order status. |
| `/api/admin/coupons` | GET, POST | Admin + permission | Yes | `admin-coupons.php` | Read or save/delete coupons. |
| `/api/admin/banners` | GET, POST | Admin + permission | Yes | `admin-banners.php` | Read or save/delete banners. |
| `/api/admin/sections` | GET, POST | Admin + permission | Yes | `admin-sections.php` | Read or save/delete homepage sections. |
| `/api/admin/settings` | GET, POST | Admin + permission | Yes | `admin-settings.php` | Read or save allowlisted site settings. |
| `/api/admin/inventory` | GET, POST | Admin + permission | Yes | `admin-inventory.php` | Read stock or record an inventory movement. |
| `/api/admin/health` | GET | Public | No | `health.php` | Admin health alias. |
| `/api/health` | GET | Public | No | `health.php` | PHP/database/integration health (no secrets). |
| `/api/webhooks/stripe` | POST | Stripe signature | Signature | `webhook-stripe.php` | Verified Stripe payment webhook; marks a pending order paid. |

## Example: CSRF-aware cart flow

```js
// CSRF is available in document.body.dataset.csrf and in every API response.
const r = await AllStore.post('/api/cart/add', { product_id: '...', quantity: 1 });
AllStore.setCounts(r.data);
```

```bash
curl -H "X-CSRF-TOKEN: <token>" -H "Content-Type: application/json" \
  -d '{"product_id":"<uuid>","quantity":1}' http://localhost:8080/api/cart/add
```

## Checkout idempotency

`GET /checkout` issues a hidden `idem` key. `POST /checkout` and `POST /api/orders/create` submit that key; the server associates it with exactly one order and verifies ownership on replay. A successful response rotates the session to a fresh key for the next cart. Prices, stock, coupon, shipping and tax are recomputed server-side inside a transaction; no browser-submitted total is trusted.

## Stripe webhook

Configure the Stripe webhook endpoint as `https://<domain>/api/webhooks/stripe` with events `checkout.session.completed`, `checkout.session.async_payment_succeeded` and `payment_intent.succeeded`. The handler verifies the timestamped HMAC, matches amount/currency/order reference, records the charge idempotently inside a transaction, marks the order paid and writes notifications/audit. Cash on Delivery remains available without payment credentials.

## Health

`GET /api/health` returns PHP version, environment, DB connectivity, timestamp and boolean integration status (`mail`, `online_payments`, `storage`). It reports `degraded`/503 if the DB is unavailable and never exposes connection strings, keys or password hashes.

