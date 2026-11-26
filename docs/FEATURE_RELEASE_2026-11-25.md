# Feature release — ordered product media, all product kinds and Bangladesh checkout

**Status:** implemented and verified locally on 2026-11-25. This document describes the source bundle only. Promotion of this specific feature release to GitHub/Vercel is recorded separately in `/home/user/FEATURE_RELEASE_REPORT_BN.md` and is **not** implied by the local tests.

## Why no new SQL migration was needed

Production already has 58 tables and the app role cannot create new public tables (`db_can_create_public=false`). Every feature below uses existing tables:

| Feature | Existing storage |
|---|---|
| Admin-managed BD hierarchy | `settings.bd_locations_json` (JSON document) + bundled `config/bd_locations.default.json` |
| Default checkout country | `settings.checkout_default_country` |
| Generic product kinds, custom fields, digital file, bundle contents, specs | `attributes`, `product_attribute_values`, `product_images`, `product_variants` |
| Structured delivery address | `orders.shipping_address` JSON + existing `city`/`region` fields |
| Ordered item identity | existing `order_items.image_url` and `order_items.options` JSON |
| Address book | existing `addresses` columns; structured BD labels are written into `city`, `region`, `line2` |
| Shipping zone targeting | existing `shipping_zones.countries`, `regions`, `cities` JSON arrays |

No `CREATE TABLE`, no user-run SQL, and no RLS/grant changes are required.

## 1. Admin order panel: see and download exactly what was ordered

- `/admin/orders` now shows the first ordered product's image and name.
- `/admin/orders/{number}` shows a thumbnail for every line item, plus:
  - product type / kind,
  - all selected options and custom fields,
  - bundle contents with SKU, name and quantity,
  - **View image**, **Download image**, **Digital file**, and **Live product** links,
  - **Download item list** CSV containing options, bundle contents, kind, image URL and digital file URL.
- `OrderMediaService` streams the ordered image as an attachment. If the original image is missing, it emits a labelled SVG with the order number, SKU, product name and quantity instead of a broken download.
- Remote image downloads are SSRF-guarded: HTTPS only, DNS pinned to a public IP, no redirects, 8 MB cap, MIME allowlist and `nosniff`. Local media is restricted to `assets/` and `storage/uploads/` via `realpath()`.
- Admin order CSV export now includes division, district, area, city, region, postal code and country.

Private Supabase Storage values (`supabase-private:…`) are resolved to short-lived signed URLs when order pages render. Customer downloads are shown only after a payable/confirmed order status.

## 2. Support for all product kinds without new tables

The admin product form now offers:

- Physical product
- Digital download / file
- Service or booking
- Subscription / membership
- Custom-made / personalised product
- Bundle or kit
- Variable product (options and variants)

It also supports:

- Customer order fields (`text`, `textarea`, `select`, required flag, choices), e.g. `Name on mug|text|required|`;
- a digital delivery URL **or** a private uploaded file (PDF/ZIP/image/text);
- bundle contents (`SKU|Name|Qty` per line);
- service/subscription details;
- reusable specification fields from `/admin/attributes`;
- CSV import columns for `product_kind`, `product_type`, `digital_file_url`, `custom_options`, `bundle_items`, `service_details`.

The existing `products.product_type` CHECK constraint supports `simple|variable|bundle|digital`. Merchant-facing kinds map onto that constraint: digital/service/subscription become `digital`, bundle becomes `bundle`, variable becomes `variable`, custom/physical become `simple`. Digital kinds get unlimited stock, zero weight, no courier and no shipment row. Internal `system-*` attributes are hidden from the public Attributes admin and protected from accidental edit/delete.

On the storefront, custom fields render on the product page, are validated in `app.js`, sent to `/api/cart/add`, converted into a deterministic active `product_variants` row, and snapshotted into `order_items.options`. Bundle contents, kind, image and digital URL travel with the order line. Product duplication now also copies images, attribute values, variants and inventory.

## 3. Very easy checkout + Bangladesh division / district / thana

- Default country is Bangladesh for new installs and checkout (`checkout_default_country=BD`).
- Checkout shows a cascading **Division → District → Upazila/Thana** selector. Selecting the area auto-fills City and Division/District, and shipping rates/totals update without a page reload.
- No account is required; submitted values are preserved on validation errors; logged-in customers can save the address.
- All three IDs and their Bengali/English labels are validated server-side, stored in `orders.shipping_address`, and exported to CSV.
- Physical BD orders require the complete area selection. Digital-only orders skip the address, courier, shipping charge and shipment row entirely.
- `ShippingService` can target BD divisions/districts in `regions` and upazilas/thanas in `cities`; `admin/shipping.php` documents this.
- The customer `/account/addresses` page uses the same cascade and stores labels in existing address columns.

### Admin management

`/admin/locations` (Catalogue → Bangladesh areas in admin navigation) lets an administrator:

- add, rename, activate/deactivate or delete a division, district, upazila or thana;
- choose whether an area is an **Upazila** or a **Thana**;
- restore the bundled reference dataset;
- see counts of divisions, districts, areas and active areas.

Changes are saved to `settings.bd_locations_json` and appear immediately through `GET /api/locations`. `GET /api/checkout/shipping-rates` validates the cascade and returns rates/totals. All mutations require admin permission, CSRF and audit logging.

### Bundled reference data and attribution

`config/bd_locations.default.json` contains **8 divisions, 64 districts and 495 upazilas** with Bengali and English names. Source: **Open Admin Data**, _Bangladesh Administrative Divisions_, <https://github.com/open-admin-data/bangladesh-administrative-divisions> / <https://openadmindata.org/bd/>, licensed **CC-BY-4.0**. The attribution is stored in the JSON and linked from the admin page. Admin-added thanas are not part of the reference dataset.

## 4. Sweep of related pages

Updated: checkout, cart, product page, order confirmation, customer order details, address book, admin orders list/detail, admin product form, admin products API/CSV import, admin shipping hints, admin settings, seed defaults, admin Attributes, shipping service, cart service, checkout service, order repository, shared helpers, API routes, page router, admin navigation, tests and JavaScript assets.

## 5. Local verification

- `bash tests/run.sh`: **192 PHP** syntax, **5 JS** syntax, **15/0** bug-fix regressions, **32/0** feature regressions, **650** route/handler checks, **40** Vercel-readiness checks, **73/0** HTTP smoke checks.
- `tests/validate_release_features.py`: checks the bundled 8/64/495 dataset, attribution, routes, settings-backed storage, cascading checkout, admin media/CSV, SSRF guards, all product kinds, custom fields, digital-only checkout, private URLs and BD defaults.
- A local runtime script verified **35/35** checks: public hierarchy API, rate API, invalid area rejection, admin division/district/thana CRUD + reset, product form, custom fields → cart → BD checkout → admin image/CSV/download, digital-only checkout, account address book, missing-area rejection and private digital upload.
- Headless Chromium **8/8** checks: add-to-cart, BD cascade auto-fill, live rate response, shipping method selection, admin order media, exact custom selections, and zero console/page errors. Screenshots: `/home/user/checkout_bd_local.png`, `/home/user/admin_order_media_local.png`.

These are local-source results. Live GitHub/Vercel promotion, live Supabase Storage signed URLs, real payment webhooks and full device QA remain outside this local verification.
