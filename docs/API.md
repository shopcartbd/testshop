# Route overview

Public page routes currently provided: `GET /`, `GET /shop?q=&category=`, `GET /product/{slug}`, `GET /cart`, `GET /checkout`, `GET /about`, `GET /contact`, and `GET /faq`. `GET /health` returns a minimal JSON health response.

Cart forms: `POST /cart/add` accepts a product identifier, optional quantity, and CSRF token. It looks up the live product and stock on the server. `POST /cart/update` accepts cart quantities and a CSRF token. Prices are never accepted from the browser; the cart renders the current catalog price. The session cart is guest-only and is not persistent across devices.

There is no active order-creation API, customer account API, admin API, payment API, or storage-upload endpoint yet. The checkout page intentionally does not claim to accept an order or payment.