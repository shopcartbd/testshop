#!/usr/bin/env python3
"""Static regression checks for the 2026-11-26 cart/account bug-fix release."""
from __future__ import annotations

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


account = read("includes/account-layout.php")
check(
    "Guest account guard runs before account_header()",
    "Auth::requireLogin();" in account
    and account.index("Auth::requireLogin();") < account.index("function account_header("),
)

reviews = read("src/Models/CustomerRepository.php")
check(
    "Eligible reviews use portable ROW_NUMBER()",
    "ROW_NUMBER() OVER" in reviews and "SELECT DISTINCT oi.product_id" not in reviews,
)

shop = read("assets/js/shop.js")
check(
    "Product variant id comes from data-variant-id",
    "btn.dataset.variantId" in shop
    and "btn.dataset.variant ||" not in shop
    and ".pdp-actions [data-add-to-cart]" in shop
    and "firstAvailableVariant.click()" in shop,
)

app = read("assets/js/app.js")
check(
    "Buy now goes to checkout and add-to-cart is optimistic",
    "window.location.href = '/checkout'" in app
    and "adjustCartCount(qty)" in app
    and "csrfRetried" in app
    and "timeout: 30000" in app
    and "progressToast.remove()" in app,
)

cart = read("pages/cart.php")
check(
    "Coupon removal is a real POST form",
    'name="remove" value="1"' in cart
    and '<form method="post" data-ajax-form="/api/cart/coupon"' in cart
    and '<a href="#" data-ajax-form="/api/cart/coupon"' not in cart,
)

footer = read("includes/footer.php")
newsletter = footer.split('<form class="newsletter-form"', 1)[-1].split("</form>", 1)[0]
check("Newsletter error box is inside its form", "data-form-error" in newsletter)

css = read("assets/css/app.css")
check("Newsletter form wraps its error row", "flex-wrap: wrap" in css and ".newsletter-form .field-error" in css)

product = read("pages/product.php")
check(
    "Buy now button is explicitly marked",
    'data-buy-now="1"' in product and "data-go-to-cart" not in product,
)

security = read("src/Core/Security.php")
check("Rate limiter uses one atomic UPSERT", "RETURNING hits" in security and "SELECT hits, window_start FROM rate_limits" not in security)

cart_service = read("src/Services/CartService.php")
check("Cart and line items are cached per request", "$itemsCache" in cart_service and "$cartCache" in cart_service)

session = read("src/Core/Session.php")
check("Session pruning is sampled, not per request", "random_int(1, 200)" in session)

helpers = read("src/Core/Helpers.php")
check("Settings are loaded once per request", "$settingsCache" in helpers)

shipping = read("src/Services/ShippingService.php")
check("Shipping rates are cached per request", "$ratesCache" in shipping)

checkout = read("src/Services/CheckoutService.php")
check("Checkout invalidates cart cache", "CartService::invalidate();" in checkout)

# Never allow a real classic GitHub PAT or Vercel token into the source tree.
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

print(f"Bug-fix static checks: {checks}, failures: {len(failures)}")
if failures:
    print("Failures:\n - " + "\n - ".join(failures), file=sys.stderr)
    sys.exit(1)
print("Bug-fix static checks passed.")
