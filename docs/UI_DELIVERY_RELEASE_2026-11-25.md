# UI polish & Bangladesh delivery-charge release

**Status:** implemented and locally verified; deployment status is recorded in `/home/user/UI_DELIVERY_DEPLOY_RESULT.md` and `/home/user/FEATURE_RELEASE_REPORT_BN.md`.

This follow-up makes the admin panel, checkout and product view clearer and more professional, and adds merchant-configurable Bangladesh delivery charges that are calculated automatically from the customer's area. It still uses **no new database tables or user-run SQL**.

## 1. Admin delivery charges (ঢাকা / সাব-এরিয়া / ঢাকার বাহিরে)

New page `/admin/delivery-charges` lets an administrator set:

| Zone | Default | Purpose |
|---|---:|---|
| ঢাকা সিটি / Dhaka city | ৳60 | Dhaka city thanas added by the admin |
| ঢাকা সাব-এরিয়া / Dhaka sub-area | ৳90 | Savar, Keraniganj, Gazipur, Narayanganj and nearby Dhaka-division districts |
| ঢাকার বাহিরে / Outside Dhaka | ৳120 | Everything else in Bangladesh |
| Free delivery over | ৳0 (off) | Optional threshold applied after discount |

- Configuration is stored as one JSON document in `settings.delivery_zone_config_json` via `DeliveryZoneService`.
- Admin-editable match lists control classification. Optional “force outside” overrides win first; city/sub-area names are scoped to Dhaka division so a same-named upazila elsewhere (e.g. Kushtia Mirpur) is never charged as Dhaka city. City thanas win before sub-area hints, all other Dhaka-division areas default to sub-area, and every other division is outside Dhaka.
- `ShippingService::rates()` calls `DeliveryZoneService::rateForLocation()` for `BD` checkout, so the price is always recomputed on the server. The browser cannot choose the charge.
- `GET /api/checkout/shipping-rates` now returns `zone.key`, `zone.label`, `zone.cost_label`, `zone.note` and `zone.free`; `checkout.js` shows a prominent delivery-zone notice and updates totals without a page reload.
- Before an area is chosen, checkout shows “Select delivery area” with a zero placeholder rate. Once division → district → upazila/thana is complete, the exact zone rate appears.
- Digital-only orders and non-BD countries still use their existing logic.

## 2. Admin shipping address — beautiful + copy button

`/admin/orders/{number}` now shows a structured address card instead of a plain `<br>` list:

- labelled rows for full name, phone, street, apartment/suite, division, district, upazila/thana, area type, city, region, postal code, country and email;
- a **Copy address** button (`assets/js/admin.js`) with clipboard fallback and visible “Copied” feedback;
- a courier-friendly monospace preview containing the order number, customer, phone, email, address lines, delivery zone, applied charge and customer note;
- a zone badge plus “applied delivery charge” and “current zone rate”.

## 3. Checkout form — organised and professional

`assets/css/checkout.css` adds:

- a soft branded page hero with trust chips;
- numbered step cards with subtitles;
- larger, focused form controls and clearer hints;
- a dedicated Bangladesh delivery-area card with an auto-charge notice;
- a sticky order summary with product thumbnails, option details and a prominent Place order button;
- responsive single-column layout and no horizontal overflow at 390 px.

All original field names, CSRF, idempotency, server-side validation and script IDs are preserved.

## 4. Product view — professional

`assets/css/product.css` adds:

- a framed product shell, sticky gallery, rounded thumbnails and a “Quality checked” badge;
- clearer brand/category kicker, larger price, stock card, pill variant buttons and custom-order field styling;
- a Bangladesh delivery card showing “From ৳60” (or the configured minimum) and explaining automatic checkout calculation;
- nicer trust cards, tabs, specification table and review cards;
- responsive gallery/actions with no horizontal overflow at 390 px.

## 5. Admin panel organisation

- New `/admin/setup-guide` gives six clear steps: areas → charges → products → orders → advanced shipping → settings.
- Admin navigation is regrouped as **Overview · Catalogue · Sales · Content · Delivery · System**, with group notes, link tooltips, a Setup guide link, a `NEW` badge for Delivery charges, and subtitles in the top bar.
- Dashboard, Settings, Shipping and Bangladesh areas pages now link directly to the delivery setup screens.
- Shipping zones explicitly explains that Bangladesh checkout uses the dedicated delivery-charge page.
- Default storefront currency is now **BDT / ৳** (`APP_CURRENCY=BDT` in `.env.example` and `Config`), and the announcement/nav/homepage delivery promise is generated from the current zone configuration instead of the old fixed “$75” placeholder. Admin-edited announcement text is preserved. Local Noto Sans Bengali static font files (SIL OFL 1.1) are bundled under `assets/fonts` with full Bengali shaping support, so labels and the Taka sign render without an external font request or missing-glyph boxes; see `docs/FONT_LICENSES.md`.

## 6. Bug sweep and fixes

- Added missing `copy`, `clipboard-check`, `trending`, `banknote` and `map` inline SVG icons.
- Added `extra_css` support to both the storefront header and admin layout so page-specific CSS loads cleanly.
- Hardened `bd-locations.js` against an early division selection and `checkout.js` against a cached `/api/locations` event firing before its listener attaches; both were verified in the previous live release and covered here by static checks.
- Fixed the checkout/cart “−৳0.00” zero-discount display in both the initial HTML and live API totals.
- Scoped Dhaka-city matching to Dhaka division and removed generic “Dhaka” city terms, preventing Kushtia’s Mirpur upazila from being misclassified as Dhaka city.
- Verified every new page route with the HTTP smoke suite; no unexpected 500 responses.

## 7. Local verification

- `bash tests/run.sh`: **195 PHP**, **5 JS**, bug-fix static `15/0`, feature static `46/0`, **653** route/handler checks, **41** Vercel checks, **74/0** HTTP smoke checks.
- `tests/validate_release_features.py` now also checks the delivery-zone service, settings-backed storage, routing, nav grouping, copy button, address styles, checkout zone notice, product styling, BDT default, dynamic Bangladesh delivery headline, local Bengali font, zero-discount formatting and Dhaka-division-scoped classification.
- UI/delivery runtime: **22/22** checks — admin charge save/reset, three zone classifications (city ৳70, sub-area ৳100, outside ৳150 during test), same-named-area regression, free-over threshold, copy address data, product/checkout styling, local Bengali font, new admin pages and no 5xx.
- UI/delivery Chromium: **13/13** checks — desktop product, Bengali font load, checkout cascade + zone notice, zero-discount formatting, mobile 390×844 overflow, admin delivery charges, setup guide, copy button feedback, **0 console/page errors**. Screenshots are in `/home/user/*_local.png`.

Live Supabase signed URLs, Stripe/webhook, SMTP, full cross-browser/accessibility/load QA remain outside this local verification.
