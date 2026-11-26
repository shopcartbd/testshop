#!/usr/bin/env python3
"""Static regression checks for admin order media, generic products and BD checkout."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
failures: list[str] = []
checks = 0


def check(name: str, condition: bool, detail: str = "") -> None:
    global checks
    checks += 1
    if not condition:
        failures.append(f"{name}{': ' + detail if detail else ''}")
    print(("PASS " if condition else "FAIL ") + name)


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def all_text(paths: list[str]) -> str:
    return "\n".join(read(p) for p in paths)


bd = json.loads(read("config/bd_locations.default.json"))
division_count = len(bd["divisions"])
district_count = sum(len(d["districts"]) for d in bd["divisions"])
area_count = sum(len(x["areas"]) for d in bd["divisions"] for x in d["districts"])
check(
    "Bundled Bangladesh hierarchy has 8 divisions, 64 districts and 495 upazilas",
    division_count == 8 and district_count == 64 and area_count == 495,
    f"got {division_count}/{district_count}/{area_count}",
)
check("Bundled Bangladesh data includes CC-BY attribution", "CC-BY-4.0" in bd.get("_attribution", "") + bd.get("_license", ""))

router = read("src/Core/Router.php")
api_routes = read("src/Core/ApiRoutes.php")
admin_nav = read("includes/admin-layout.php")
check("Admin locations page is routed", "'/admin/locations' => 'admin/locations.php'" in router)
check("Locations and checkout rates API endpoints are mapped", "'locations' => 'locations.php'" in api_routes and "'checkout/shipping-rates' => 'checkout-rates.php'" in api_routes)
check("Admin navigation exposes Bangladesh areas", "['Bangladesh areas','/admin/locations'" in admin_nav)
check("Locations and checkout-rates handlers exist", (ROOT / "api/handlers/locations.php").is_file() and (ROOT / "api/handlers/checkout-rates.php").is_file())

location_service = read("src/Services/LocationService.php")
check("Admin-managed location service has CRUD helpers", all(s in location_service for s in ["publicData()", "save(array", "reset()", "resolve(", "newId("]))
check("Location data uses existing settings table, not a new migration", "bd_locations_json" in location_service and "Helpers::setSetting" in location_service)

checkout = read("pages/checkout.php") + read("pages/checkout-simple-bd.php")
checkout_js = read("assets/js/checkout.js")
bd_js = read("assets/js/bd-locations.js")
addresses = read("pages/account/addresses.php")
check("Checkout renders cascading BD selectors", all(s in checkout for s in ["bdLocationFields", 'id="division_id"', 'id="district_id"', 'id="area_id"']))
check("Reusable BD cascade preserves early selections on cold starts", "/api/locations" in bd_js and "bd-location-change" in bd_js and "bd-locations.js" in checkout and "captureSelection" in bd_js and "selected.district || district.value" in bd_js)
check("Checkout loads live shipping rates", "/api/checkout/shipping-rates" in checkout_js and "data-checkout-total" in checkout_js and "bd-location-change" in checkout_js and "el.addEventListener('change', refreshRates)" in checkout_js and "refreshRates();" in checkout_js)
check("Checkout rate requests are deduplicated and partial-safe", "lastKey" in checkout_js and "const seq = ++requestSeq" in checkout_js and "isBd && partial" in checkout_js and "key === lastKey" in checkout_js)
check("Checkout keeps form values and offers saved addresses", "checkout_old" in checkout and "save_address" in checkout)
check("Checkout default is Bangladesh and structured address is supported", "LocationService::defaultCountry()" in checkout and "division_id" in checkout)
check("Customer address book uses the same BD cascade", all(s in addresses for s in ["LocationService::resolve", "bdLocationFields", "bd-locations.js", 'id="division_id"', "LocationService::defaultCountry()"]))

order_admin = read("admin/order-details.php")
orders_admin = read("admin/orders.php")
check("Admin order detail has image view/download and item CSV", all(s in order_admin for s in ["View image", "Download image", "download_item", "export_items", "OrderMediaService::downloadOrderItem"]))
check("Admin order detail shows exact ordered options and bundle", "OrderRepository::itemDetails" in order_admin and "bundle_items" in order_admin and "product_kind" in order_admin)
check("Admin orders list shows a product thumbnail", "first_item_image" in orders_admin)
check("Admin order CSV exports structured BD address", all(s in read("src/Models/OrderRepository.php") for s in ["'division','district','area'", "resolved_image_url", "itemsCsv"]))

media = read("src/Services/OrderMediaService.php")
check("Order image download is SSRF-guarded and size-limited", all(s in media for s in ["gethostbynamel", "FILTER_FLAG_NO_PRIV_RANGE", "CURLOPT_FOLLOWLOCATION => false", "MAX_BYTES"]))
check("Order image download always emits a labelled fallback", "image/svg+xml" in media and "Original product image is unavailable" in media)

product_form = read("admin/product-form.php")
product_page = read("pages/product.php")
app_js = read("assets/js/app.js")
check("Admin product form supports all product kinds and custom fields", all(s in product_form for s in ["product_kind", "custom_options", "digital_file_url", "bundle_items", "service_details", "name=\"spec[", 'name="digital_file"', "UploadService::store($digitalFile, true, true)"]))
check("Storefront renders custom order fields and bundle/service details", "data-custom-option" in product_page and "bundle_items" in product_page and "service_details" in product_page)
check("Add-to-cart sends custom order fields", "data-custom-option" in app_js and "custom_options: customOptions" in app_js)

catalog = read("src/Models/CatalogRepository.php")
cart_service = read("src/Services/CartService.php")
checkout_service = read("src/Services/CheckoutService.php")
check("Generic product metadata uses existing attribute tables", all(s in catalog for s in ["SYSTEM_META", "productMeta(", "validateCustomOptions(", "saveProductSpecs("]))
check("Custom products become deterministic cart variants", all(s in cart_service for s in ["customVariant(", "CatalogRepository::customOptions", "requires_shipping", "needsShipping()"]))
check("Checkout validates BD selection and skips shipping for digital-only orders", all(s in checkout_service for s in ["LocationService::resolve", "digital_only", "save_address", "orderStockVariantId", "Select your division, district and upazila/thana for delivery."]))
check("Shipping rates honour division/district/upazila and digital-only carts", all(s in read("src/Services/ShippingService.php") for s in ["digitalRate()", "$location['division']", "$location['area']", "normList("]))

customer_order = read("pages/account/order-details.php")
confirmation = read("pages/order-confirmation.php")
check("Customer order views show item images, options and authorised digital downloads", all(s in customer_order for s in ["OrderRepository::itemDetails", "canShowDigitalDownloads", "Download digital item"]) and "OrderRepository::itemDetails" in confirmation)

helpers = read("src/Core/Helpers.php")
check("Shared helpers safely format media, options and stock variants", all(s in helpers for s in ["safeMediaUrl(", "orderItemDetails(", "orderStockVariantId(", "canShowDigitalDownloads(", "supabase-private:"]))
check("Private digital files resolve to signed URLs in order views", "UploadService::url" in read("src/Models/OrderRepository.php") and "UploadService::url" in media)

settings = read("admin/settings.php")
seed = read("database/seed.php")
check("Store defaults and seed target Bangladesh", "checkout_default_country" in settings and "checkout_default_country'=>'BD'" in seed and "'default_country'=>'BD'" in seed)
check("Seed includes Bangladesh delivery-zone defaults", "delivery_zone_config_json" in seed and "DeliveryZoneService::defaultConfig()" in seed)
check("Storefront currency defaults to BDT", "APP_CURRENCY', 'BDT'" in read("src/Core/Config.php") and "APP_CURRENCY=BDT" in read(".env.example"))
check("Storefront chrome avoids delivery-charge fluff", "New arrivals every week" in read("includes/header.php") and "Bangladesh delivery from" not in read("includes/header.php").replace("'Bangladesh delivery from'","") and "DeliveryZoneService::publicHeadline()" not in read("includes/home-sections.php") and "Complimentary shipping on orders over $75" not in read("database/seed.php"))
check("Bengali font is bundled locally with OFL attribution", "Noto Sans Bengali" in read("assets/css/tokens.css") and "unicode-range: U+0980-09FF" in read("assets/css/tokens.css") and "Noto Sans Bengali" in read("docs/FONT_LICENSES.md") and all((ROOT / f"assets/fonts/noto-sans-bengali-{w}.ttf").is_file() for w in (400, 600, 700)) and (ROOT / "assets/fonts/OFL.txt").is_file())

# UI / delivery-charge release, 2026-11-25.
delivery_service = read("src/Services/DeliveryZoneService.php")
router = read("src/Core/Router.php")
admin_nav = read("includes/admin-layout.php")
admin_css = read("assets/css/admin.css")
check("Admin delivery-charge service uses settings and three BD zones", all(s in delivery_service for s in ["delivery_zone_config_json", "dhaka_city", "dhaka_sub_area", "outside_dhaka", "rateForLocation(", "classify("]))
check("Dhaka city rates are scoped to Dhaka division", "$isDhakaDivision = self::matchesAny($divisionFields" in delivery_service and "Kushtia Mirpur" in delivery_service and "'Dhaka City'" in delivery_service and "'Dhaka District'" not in delivery_service)
check("Simple BD checkout is admin-configurable and server-validated", "simple_bd_checkout" in delivery_service and "simpleCheckoutEnabled()" in delivery_service and "simple_bd_checkout" in read("admin/delivery-charges.php") and "data-simple-bd" in read("pages/checkout-simple-bd.php") and "simpleBd" in read("src/Services/CheckoutService.php") and "findDistrictAny" in read("assets/js/bd-locations.js"))
check("Shipping service applies server-side BD zone rates", "DeliveryZoneService::rateForLocation" in read("src/Services/ShippingService.php") and "DeliveryZoneService::enabled()" in read("src/Services/ShippingService.php"))
check("Admin delivery-charges and setup-guide pages are routed", "'/admin/delivery-charges'" in router and "'/admin/setup-guide'" in router and (ROOT / "admin/delivery-charges.php").is_file() and (ROOT / "admin/setup-guide.php").is_file())
check("Admin navigation is grouped with Delivery and Setup guide", all(s in admin_nav for s in ["'Delivery' =>", "Delivery charges", "Setup guide", "'System' =>"]) and "groupNotes" not in admin_nav and "linkNotes" not in admin_nav and "nav-new" not in admin_nav)
check("Admin shipping address has a structured card and copy button", all(s in order_admin for s in ["address-card", "address-copy-btn", "data-copy-address", "address-copy-preview", "DeliveryZoneService::classify"]))
check("Admin copy handler and address styles exist", "data-copy-address" in read("assets/js/admin.js") and "address-card" in admin_css and "copy'" in read("includes/icons.php"))
check("Checkout has professional styling and auto-zone notice", all(s in checkout for s in ["checkout.css", "checkout-hero", "checkout-summary", 'id="checkoutDeliveryZone"', "delivery-zone-note"]) and "zone" in read("api/handlers/checkout-rates.php") and "showZone" in checkout_js)
order_views = [read("pages/cart.php"), read("pages/order-confirmation.php"), read("pages/account/order-details.php"), order_admin]
check("Zero discount is not shown as a negative amount", "discount_cents'] ?? 0) > 0 ? '−' : ''" in read("api/handlers/_common.php") and "discount_cents'] > 0 ? '−' : ''" in checkout and all("> 0 ? '−' : ''" in v for v in order_views) and "−'.Helpers::money($totals['discount_cents'])" not in checkout)
check("Product view has professional styling and delivery summary", all(s in product_page for s in ["product.css", "pdp-shell", "pdp-delivery-card", "DeliveryZoneService::publicSummary"]) and (ROOT / "assets/css/product.css").is_file() and (ROOT / "assets/css/checkout.css").is_file())

# No exact token literal may enter this release either.
token_re = re.compile(rb"(?:ghp_[A-Za-z0-9]{20,}|vcp_[A-Za-z0-9]{20,})")
token_hits = []
for path in ROOT.rglob("*"):
    if not path.is_file() or path.stat().st_size > 5_000_000:
        continue
    if any(part in {".git", "node_modules", ".venv", "__pycache__"} for part in path.parts):
        continue
    try:
        data = path.read_bytes()
    except OSError:
        continue
    if token_re.search(data):
        token_hits.append(str(path.relative_to(ROOT)))
check("No exact GitHub/Vercel token literal in source tree", not token_hits, ", ".join(token_hits[:3]))

print(f"Feature release static checks: {checks}, failures: {len(failures)}")
if failures:
    print("Failures:\n - " + "\n - ".join(failures), file=sys.stderr)
    sys.exit(1)
print("Feature release static checks passed.")
