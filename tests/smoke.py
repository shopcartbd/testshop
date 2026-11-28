#!/usr/bin/env python3
"""AllStore end-to-end HTTP smoke test against a running local server.

Start the app first, for example:
  php -S 0.0.0.0:8080 router.php
  python3 tests/smoke.py
Optional environment: BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD.

The script uses only the Python standard library. It exercises public pages,
API routes, CSRF, registration/login, cart, checkout idempotency, account
flows, admin RBAC, security headers and source-file exposure. It mutates the
local SQLite database; do NOT run it against production.
"""
from __future__ import annotations

import http.cookiejar
import json
import os
import re
import sqlite3
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = os.environ.get("BASE_URL", "http://127.0.0.1:8080").rstrip("/")
ADMIN_EMAIL = os.environ.get("ADMIN_EMAIL", "local-admin@allstore.test")
ADMIN_PASSWORD = os.environ.get("ADMIN_PASSWORD", "")
if not ADMIN_PASSWORD:
    candidate = Path("/home/user/allstore-local-admin.txt")
    if candidate.is_file():
        m = re.search(r"^Password:\s*(.+)$", candidate.read_text(encoding="utf-8"), re.M)
        if m:
            ADMIN_PASSWORD = m.group(1).strip()

CSRF_HEADER = "X-CSRF-TOKEN"
# Give each local run a stable, unique client IP. Without this, repeated local
# test runs share the fixed-window rate-limit bucket and can fail even when the
# application is healthy. This header is only used by the local smoke client.
SMOKE_CLIENT_IP = "198.51.100." + str((uuid.uuid4().int % 253) + 1)
csrf = ""


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
no_redirect = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(jar), NoRedirect()
)


class Resp:
    def __init__(self, status: int, headers, body: bytes):
        self.status = status
        self.headers = headers
        self.body = body
        self.json_data = None
        ctype = (headers.get("Content-Type") or "").lower()
        if "json" in ctype:
            try:
                self.json_data = json.loads(body.decode("utf-8"))
            except Exception:
                self.json_data = None

    @property
    def text(self) -> str:
        return self.body.decode("utf-8", errors="replace")


class Suite:
    def __init__(self):
        self.checks = 0
        self.failures: list[str] = []

    def check(self, name: str, condition: bool, detail: str = ""):
        self.checks += 1
        if condition:
            print("PASS  " + name)
        else:
            msg = name + (" — " + detail if detail else "")
            self.failures.append(msg)
            print("FAIL  " + msg)

    def finish(self):
        print(f"\nSmoke checks: {self.checks}, failures: {len(self.failures)}")
        if self.failures:
            print("Failures:\n - " + "\n - ".join(self.failures))
            return 1
        print("Smoke test passed.")
        return 0


suite = Suite()


def call(method: str, path: str, body=None, *, csrf_mode: str = "default",
         csrf_override: str | None = None, allow_redirect: bool = True,
         accept: str = "application/json", extra_headers: dict | None = None,
         form_body: dict | None = None) -> Resp:
    global csrf
    headers = {"Accept": accept, "X-Forwarded-For": SMOKE_CLIENT_IP}
    if csrf_mode != "none":
        token = csrf_override if csrf_override is not None else csrf
        if token:
            headers[CSRF_HEADER] = token
    data = None
    if form_body is not None:
        data = urllib.parse.urlencode(form_body).encode("utf-8")
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    elif body is not None:
        data = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    if extra_headers:
        headers.update(extra_headers)
    req = urllib.request.Request(BASE + path, data=data, headers=headers, method=method)
    client = opener if allow_redirect else no_redirect
    try:
        raw = client.open(req, timeout=20)
        with raw:
            res = Resp(raw.status, raw.headers, raw.read())
    except urllib.error.HTTPError as e:
        res = Resp(e.code, e.headers, e.read())
    if isinstance(res.json_data, dict) and res.json_data.get("csrf_token"):
        csrf = str(res.json_data["csrf_token"])
    return res


def expect(name: str, res: Resp, allowed, condition=None, detail: str = ""):
    ok = res.status in allowed
    if condition is not None:
        ok = ok and bool(condition)
    extra = detail or f"status={res.status}, body={res.text[:180]!r}"
    suite.check(name, ok, extra)


def api_ok(res: Resp) -> bool:
    return isinstance(res.json_data, dict) and res.json_data.get("success") is True


def extract_csrf(html: str) -> str:
    m = re.search(r'data-csrf="([^"]+)"', html)
    return m.group(1) if m else ""


def scalar(sql: str, default: str = "") -> str:
    try:
        conn = sqlite3.connect(ROOT / "storage/allstore.sqlite")
        row = conn.execute(sql).fetchone()
        conn.close()
        return str(row[0]) if row and row[0] is not None else default
    except Exception:
        return default


def routes_from(php_file: str) -> list[tuple[str, str]]:
    text = (ROOT / php_file).read_text(encoding="utf-8")
    return re.findall(r"'([^']+)'\s*=>\s*'([^']+\.php)'", text)


def fatal_markers(text: str) -> bool:
    lowered = text.lower()
    return any(x in lowered for x in [
        "fatal error", "uncaught", "warning:", "notice:", "sqlstate",
        "call to undefined", "something went wrong. our team has been notified",
        "stack trace",
    ])


def sweep(label: str, paths: list[str], *, api: bool = False):
    bad = []
    for path in paths:
        try:
            res = call("GET", path, csrf_mode="none",
                       accept="application/json" if api else "text/html")
        except Exception as e:
            bad.append(f"{path}: {type(e).__name__}: {e}")
            continue
        if res.status >= 500 or fatal_markers(res.text):
            bad.append(f"{path}: HTTP {res.status} {res.text[:100]!r}")
        if api and "json" in (res.headers.get("Content-Type") or "").lower() and res.json_data is None:
            bad.append(f"{path}: invalid JSON")
    suite.check(label, not bad, f"{len(paths)} paths; " + ("; ".join(bad[:5]) if bad else "no 5xx or PHP errors"))


# --- Local fixture values -------------------------------------------------
product = scalar("SELECT id FROM products WHERE status='active' AND stock>0 ORDER BY created_at LIMIT 1", "sample-product")
product_slug = scalar("SELECT slug FROM products WHERE status='active' ORDER BY created_at LIMIT 1", "sample-product")
category_slug = scalar("SELECT slug FROM categories WHERE is_active=1 AND parent_id IS NULL ORDER BY sort_order LIMIT 1", "sample-category")
subcategory_slug = scalar("SELECT slug FROM categories WHERE is_active=1 AND parent_id IS NOT NULL ORDER BY sort_order LIMIT 1", category_slug)
brand_slug = scalar("SELECT slug FROM brands WHERE is_active=1 ORDER BY name LIMIT 1", "sample-brand")
blog_slug = scalar("SELECT slug FROM blog_posts WHERE status='published' ORDER BY created_at DESC LIMIT 1", "sample-post")
order_number = scalar("SELECT order_number FROM orders ORDER BY created_at DESC LIMIT 1", "AS-TEST-ORDER")
user_id = scalar("SELECT p.id FROM profiles p JOIN roles r ON r.id=p.role_id WHERE r.slug='super_admin' ORDER BY p.created_at LIMIT 1", product)
customer_id = scalar("SELECT p.id FROM profiles p JOIN roles r ON r.id=p.role_id WHERE r.slug='customer' ORDER BY p.created_at LIMIT 1", user_id)
ticket_id = scalar("SELECT id FROM support_tickets ORDER BY created_at DESC LIMIT 1", product)

page_routes = [p for p, _ in routes_from("src/Core/Router.php")]
api_routes = [p for p, _ in routes_from("src/Core/ApiRoutes.php")]

print(f"Base URL: {BASE}")
print(f"Routes discovered: {len(page_routes)} pages, {len(api_routes)} API patterns")

# --- Public shell, headers, assets, protected sources --------------------
home = call("GET", "/", csrf_mode="none", accept="text/html")
expect("Homepage renders", home, (200,), "AllStore" in home.text)
csrf = extract_csrf(home.text)
suite.check("CSRF token is present", len(csrf) >= 32, f"length={len(csrf)}")
for header in ["Content-Security-Policy", "X-Content-Type-Options", "Referrer-Policy", "X-Frame-Options"]:
    suite.check("Security header " + header, bool(home.headers.get(header)))

for asset in ["/assets/js/app.js", "/assets/css/app.css", "/assets/img/favicon.svg", "/assets/img/hero-marketplace.jpg", "/assets/fonts/noto-sans-bengali-400.ttf"]:
    res = call("GET", asset, csrf_mode="none", accept="*/*")
    expect("Static asset " + asset, res, (200,), len(res.body) > 100)

for secret in ["/.env", "/Dockerfile", "/Dockerfile.vercel", "/vercel.json",
               "/database/migrations/001_schema.sql", "/src/Core/Database.php",
               "/api/handlers/_common.php", "/includes/bootstrap.php",
               "/storage/allstore.sqlite", "/tests/smoke.py", "/docs/README.md"]:
    res = call("GET", secret, csrf_mode="none", accept="*/*", allow_redirect=False)
    expect("Blocked source " + secret, res, (403, 404), "Not found" in res.text or res.status in (403, 404))

# --- Public API, health and method/CSRF guards ----------------------------
health = call("GET", "/api/health", csrf_mode="none")
expect("Health endpoint", health, (200, 503), api_ok(health) and isinstance(health.json_data.get("data"), dict))
products = call("GET", "/api/products?per_page=6&in_stock=1", csrf_mode="none")
expect("Product listing API", products, (200,), api_ok(products) and len(products.json_data.get("data", {}).get("items", [])) > 0)
product_detail = call("GET", "/api/products/" + product, csrf_mode="none")
expect("Product detail API", product_detail, (200, 404), isinstance(product_detail.json_data, dict))
suggest = call("GET", "/api/search/suggest?q=head", csrf_mode="none")
expect("Search suggest API", suggest, (200,), api_ok(suggest))
cart0 = call("GET", "/api/cart", csrf_mode="none")
expect("Empty cart API", cart0, (200,), api_ok(cart0))

# Account pages must redirect guests before running any account DB query.
for guest_path in ("/account/wishlist", "/account/reviews"):
    guest_page = call("GET", guest_path, csrf_mode="none", allow_redirect=False, accept="text/html")
    expect("Guest " + guest_path + " redirects to login", guest_page, (301, 302),
           "/login?next=" in (guest_page.headers.get("Location") or ""))

bad_csrf = call("POST", "/api/cart/add", {"product_id": product, "quantity": 1}, csrf_mode="none")
expect("Missing CSRF rejected", bad_csrf, (419,), isinstance(bad_csrf.json_data, dict) and bad_csrf.json_data.get("success") is False)
bad_method = call("GET", "/api/cart/add", csrf_mode="none")
expect("Wrong method rejected", bad_method, (405,))
webhook = call("POST", "/api/webhooks/stripe", {"any": "payload"}, csrf_mode="none")
expect("Unconfigured Stripe webhook is safely rejected", webhook, (400, 401, 403, 404, 405, 503),
       isinstance(webhook.json_data, dict))

# Sweep every declared API pattern with a safe GET (POST-only routes return 405).
def fill_api(pattern: str) -> str:
    value = order_number if pattern.startswith("orders/") else product
    return "/api/" + pattern.replace("{id}", value)

sweep("All API patterns respond without server errors", [fill_api(p) for p in api_routes], api=True)

# --- Cart, coupon, checkout and idempotency -------------------------------
added = call("POST", "/api/cart/add", {"product_id": product, "quantity": 1})
expect("Add to cart", added, (200,), api_ok(added))
cart1 = call("GET", "/api/cart", csrf_mode="none")
items = (cart1.json_data or {}).get("data", {}).get("cart", {}).get("items", [])
suite.check("Cart contains an item", isinstance(items, list) and len(items) > 0)
item_id = str(items[0].get("id", "")) if items else ""
coupon = call("POST", "/api/cart/coupon", {"code": "WELCOME10"})
expect("Coupon endpoint", coupon, (200, 422), isinstance(coupon.json_data, dict))
call("POST", "/api/cart/coupon", {"remove": True})
updated = call("POST", "/api/cart/update", {"item_id": item_id, "quantity": 2})
expect("Update cart quantity", updated, (200, 422), isinstance(updated.json_data, dict))
removed = call("POST", "/api/cart/remove", {"item_id": item_id})
expect("Remove cart item", removed, (200, 422), isinstance(removed.json_data, dict))
readded = call("POST", "/api/cart/add", {"product_id": product, "quantity": 1})
expect("Re-add item for checkout", readded, (200,), api_ok(readded))

checkout = call("GET", "/checkout", csrf_mode="none", accept="text/html")
expect("Checkout page renders", checkout, (200,), "Checkout" in checkout.text)
idem_match = re.search(r'name="idem"\s+value="([^"]+)"', checkout.text)
idem = idem_match.group(1) if idem_match else ""
suite.check("Checkout idempotency key is present", len(idem) >= 32)
customer_email = "smoke-" + uuid.uuid4().hex[:12] + "@example.test"
order_payload = {
    "idem": idem,
    "full_name": "Smoke Customer", "email": customer_email,
    "phone": "+1 555 010 2030", "line1": "120 Test Market Street",
    "city": "Bangkok", "region": "Bangkok", "postal_code": "10110",
    "country": "US", "payment_method": "cod", "shipping_method": "standard",
    "customer_note": "Local smoke test order.",
}
placed = call("POST", "/api/orders/create", order_payload)
expect("Place order", placed, (201,), api_ok(placed) and bool((placed.json_data or {}).get("data", {}).get("order_number")))
created_number = str((placed.json_data or {}).get("data", {}).get("order_number", order_number))
replayed = call("POST", "/api/orders/create", order_payload)
expect("Duplicate checkout returns same order", replayed, (200,),
       api_ok(replayed) and (replayed.json_data or {}).get("data", {}).get("duplicate") is True)

# The real HTML checkout form uses POST + a hidden idempotency key (not the JSON API).
call("POST", "/api/cart/add", {"product_id": product, "quantity": 1})
checkout_form = call("GET", "/checkout", csrf_mode="none", accept="text/html")
idem_match2 = re.search(r'name="idem"\s+value="([^"]+)"', checkout_form.text)
form_order = dict(order_payload)
form_order.update({"idem": idem_match2.group(1) if idem_match2 else "", "csrf_token": csrf})
form_submit = call("POST", "/checkout", form_body=form_order, csrf_mode="none",
                   allow_redirect=False, accept="text/html")
location = form_submit.headers.get("Location") or ""
expect("HTML checkout form submits", form_submit, (301, 302), "/order-confirmation/" in location)
m = re.search(r"/order-confirmation/([^?\s]+)", location)
if m:
    created_number = urllib.parse.unquote(m.group(1))

confirmation = call("GET", "/order-confirmation/" + urllib.parse.quote(created_number), csrf_mode="none", accept="text/html")
expect("Order confirmation renders", confirmation, (200,), "order" in confirmation.text.lower())

# --- Public support endpoints --------------------------------------------
newsletter = call("POST", "/api/newsletter", {"email": "news-" + uuid.uuid4().hex[:10] + "@example.test"})
expect("Newsletter subscription", newsletter, (200,), api_ok(newsletter))
contact = call("POST", "/api/contact", {"name": "Smoke Tester", "email": customer_email,
                                       "subject": "Smoke test message", "message": "This is a local functional test message."})
expect("Contact message", contact, (200, 422, 429), isinstance(contact.json_data, dict))

# --- Customer account lifecycle ------------------------------------------
password1 = "CustomerPass2026"
password2 = "CustomerPass2027"
registered = call("POST", "/api/auth/register", {"full_name": "Smoke Customer", "email": customer_email,
                                                 "password": password1, "phone": "+1 555 010 2030"})
expect("Customer registration", registered, (200, 201), api_ok(registered))
account = call("GET", "/account", csrf_mode="none", accept="text/html")
expect("Account overview renders", account, (200,), "My Account" in account.text or "Overview" in account.text)
orders = call("GET", "/api/orders", csrf_mode="none")
expect("Orders API requires a valid session", orders, (200,), api_ok(orders))
profile = call("POST", "/api/account/profile", {"full_name": "Smoke Customer Updated", "phone": "+1 555 010 9999", "newsletter_opt_in": 1})
expect("Profile update", profile, (200,), api_ok(profile))
wish_add = call("POST", "/api/wishlist/add", {"product_id": product})
expect("Wishlist add", wish_add, (200,), api_ok(wish_add))
wishlist = call("GET", "/api/wishlist", csrf_mode="none")
wish_items = (wishlist.json_data or {}).get("data", {}).get("items", [])
suite.check("Wishlist contains an item", isinstance(wish_items, list) and len(wish_items) > 0)
if wish_items:
    wish_remove = call("POST", "/api/wishlist/remove", {"item_id": str(wish_items[0].get("id", ""))})
    expect("Wishlist remove", wish_remove, (200,), api_ok(wish_remove))
reviews = call("POST", "/api/reviews/create", {"product_id": product, "rating": 5,
                                              "title": "Smoke review", "body": "A reliable product used in this local smoke test."})
expect("Review submission", reviews, (201, 409, 422), isinstance(reviews.json_data, dict))
ticket = call("POST", "/api/tickets/create", {"subject": "Smoke support ticket",
                                              "message": "Please confirm that this support ticket can be created.",
                                              "category": "technical", "priority": "normal"})
expect("Support ticket creation", ticket, (201,), api_ok(ticket) and bool((ticket.json_data or {}).get("data", {}).get("ticket", {}).get("ticket_number")))
ticket_number_value = str((ticket.json_data or {}).get("data", {}).get("ticket", {}).get("ticket_number", "")) if isinstance(ticket.json_data, dict) else ""
if ticket_number_value:
    try:
        conn = sqlite3.connect(ROOT / "storage/allstore.sqlite")
        row = conn.execute("SELECT id FROM support_tickets WHERE ticket_number=?", (ticket_number_value,)).fetchone()
        conn.close()
        if row: ticket_id = str(row[0])
    except Exception:
        pass
notifications = call("POST", "/api/notifications/read", {})
expect("Mark notifications read", notifications, (200,), api_ok(notifications))
old_csrf = csrf
password_change = call("POST", "/api/account/password", {"current_password": password1, "new_password": password2})
expect("Password change", password_change, (200,), api_ok(password_change))
suite.check("Password change rotates CSRF token", csrf != old_csrf)
stale_csrf = call("POST", "/api/notifications/read", {}, csrf_override=old_csrf)
expect("Stale CSRF rejected after password change", stale_csrf, (419,))
forgot = call("POST", "/api/auth/forgot-password", {"email": customer_email})
expect("Password reset request", forgot, (200,), api_ok(forgot) and isinstance((forgot.json_data or {}).get("data"), dict))
logout = call("POST", "/api/auth/logout", {})
expect("Logout", logout, (200,), api_ok(logout))
home2 = call("GET", "/", csrf_mode="none", accept="text/html")
csrf = extract_csrf(home2.text)
login = call("POST", "/api/auth/login", {"email": customer_email, "password": password2})
expect("Login with changed password", login, (200,), api_ok(login))
profile_form = call("POST", "/account/profile", form_body={
    "csrf_token": csrf, "full_name": "Smoke Customer Form", "phone": "+1 555 010 7777",
    "newsletter_opt_in": "1"}, csrf_mode="none", allow_redirect=False, accept="text/html")
expect("HTML profile form redirects after save", profile_form, (301, 302))
profile_page = call("GET", "/account/profile", csrf_mode="none", accept="text/html")
expect("Profile success flash renders", profile_page, (200,), "Profile updated." in profile_page.text)
logout2 = call("POST", "/api/auth/logout", {})
expect("Second logout", logout2, (200,), api_ok(logout2))
home3 = call("GET", "/", csrf_mode="none", accept="text/html")
csrf = extract_csrf(home3.text)

# --- Admin access control and admin API ----------------------------------
admin_page = call("GET", "/admin", csrf_mode="none", accept="text/html", allow_redirect=False)
expect("Admin page blocks guests", admin_page, (301, 302, 401, 403))
admin_login_page = call("GET", "/admin/login", csrf_mode="none", accept="text/html")
expect("Admin login page renders for guests", admin_login_page, (200,),
       "Admin sign in" in admin_login_page.text and not fatal_markers(admin_login_page.text))
admin_api_guest = call("GET", "/api/admin/dashboard", csrf_mode="none")
expect("Admin API blocks guests", admin_api_guest, (401,), isinstance(admin_api_guest.json_data, dict) and admin_api_guest.json_data.get("success") is False)

if not ADMIN_PASSWORD:
    suite.check("Administrator credentials available", False, "Set ADMIN_PASSWORD or create /home/user/allstore-local-admin.txt")
else:
    admin_login = call("POST", "/api/auth/login", {"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD})
    expect("Administrator login", admin_login, (200,), api_ok(admin_login))
    dashboard = call("GET", "/api/admin/dashboard", csrf_mode="none")
    expect("Admin dashboard API", dashboard, (200,), api_ok(dashboard))
    admin_settings = call("GET", "/api/admin/settings", csrf_mode="none")
    expect("Admin settings API", admin_settings, (200,), api_ok(admin_settings))

    def fill_page(route: str) -> str:
        if "{slug}" in route:
            if route.startswith("/category/"): value = category_slug
            elif route.startswith("/subcategory/"): value = subcategory_slug
            elif route.startswith("/brand/"): value = brand_slug
            elif route.startswith("/blog/"): value = blog_slug
            else: value = product_slug
            route = route.replace("{slug}", value)
        if "{number}" in route:
            route = route.replace("{number}", created_number or order_number)
        if "{id}" in route:
            value = customer_id if route.startswith("/admin/customers/") else ticket_id if route.startswith("/account/tickets/") else product
            route = route.replace("{id}", value)
        return route

    skip = {"/logout", "/admin/logout", "/500"}
    sweep("All page routes render without server errors", [fill_page(p) for p in page_routes if p not in skip])
    sweep("Admin API routes respond without server errors",
          ["/api/" + p.replace("{id}", product) for p in api_routes if p.startswith("admin/") or p == "admin/health"], api=True)

sys.exit(suite.finish())
