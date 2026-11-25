-- =====================================================================
-- AllStore :: Row Level Security (PostgreSQL / Supabase ONLY)
-- The PHP application connects with PDO and therefore BYPASSES these policies.
-- Authorization is enforced in PHP (Auth::can / ownership checks). These
-- policies protect the Supabase Data API (anon/publishable key) and any future
-- client-side access. No browser ever receives a secret/service_role key.
-- Run AFTER 001_schema.sql in the Supabase SQL editor.
-- =====================================================================
ALTER TABLE profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE carts ENABLE ROW LEVEL SECURITY;
ALTER TABLE cart_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE wishlists ENABLE ROW LEVEL SECURITY;
ALTER TABLE wishlist_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE addresses ENABLE ROW LEVEL SECURITY;
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE order_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE order_status_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE payments ENABLE ROW LEVEL SECURITY;
ALTER TABLE shipments ENABLE ROW LEVEL SECURITY;
ALTER TABLE return_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE refunds ENABLE ROW LEVEL SECURITY;
ALTER TABLE reviews ENABLE ROW LEVEL SECURITY;
ALTER TABLE product_questions ENABLE ROW LEVEL SECURITY;
ALTER TABLE support_tickets ENABLE ROW LEVEL SECURITY;
ALTER TABLE ticket_messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE recently_viewed ENABLE ROW LEVEL SECURITY;

-- Public catalogue is read-only for anonymous/authenticated Data API clients.
ALTER TABLE products ENABLE ROW LEVEL SECURITY;
ALTER TABLE categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE brands ENABLE ROW LEVEL SECURITY;
ALTER TABLE attributes ENABLE ROW LEVEL SECURITY;
ALTER TABLE attribute_values ENABLE ROW LEVEL SECURITY;
ALTER TABLE category_attributes ENABLE ROW LEVEL SECURITY;
ALTER TABLE product_images ENABLE ROW LEVEL SECURITY;
ALTER TABLE product_variants ENABLE ROW LEVEL SECURITY;
ALTER TABLE product_attribute_values ENABLE ROW LEVEL SECURITY;
ALTER TABLE banners ENABLE ROW LEVEL SECURITY;
ALTER TABLE homepage_sections ENABLE ROW LEVEL SECURITY;
ALTER TABLE flash_sales ENABLE ROW LEVEL SECURITY;
ALTER TABLE flash_sale_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE pages ENABLE ROW LEVEL SECURITY;
ALTER TABLE blogs ENABLE ROW LEVEL SECURITY;
ALTER TABLE faqs ENABLE ROW LEVEL SECURITY;

-- Everything else: RLS enabled with NO permissive policies => deny by default.
ALTER TABLE roles ENABLE ROW LEVEL SECURITY;
ALTER TABLE permissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE role_permissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE password_resets ENABLE ROW LEVEL SECURITY;
ALTER TABLE rate_limits ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE inventory ENABLE ROW LEVEL SECURITY;
ALTER TABLE stock_movements ENABLE ROW LEVEL SECURITY;
ALTER TABLE warehouses ENABLE ROW LEVEL SECURITY;
ALTER TABLE coupons ENABLE ROW LEVEL SECURITY;
ALTER TABLE coupon_usage ENABLE ROW LEVEL SECURITY;
ALTER TABLE shipping_zones ENABLE ROW LEVEL SECURITY;
ALTER TABLE shipping_rates ENABLE ROW LEVEL SECURITY;
ALTER TABLE couriers ENABLE ROW LEVEL SECURITY;
ALTER TABLE transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE promotions ENABLE ROW LEVEL SECURITY;
ALTER TABLE contact_messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE newsletter_subscribers ENABLE ROW LEVEL SECURITY;
ALTER TABLE search_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE popular_searches ENABLE ROW LEVEL SECURITY;

-- Helper: current Supabase auth user id (NULL for anonymous requests).
CREATE OR REPLACE FUNCTION public.allstore_uid() RETURNS text
LANGUAGE sql STABLE AS $$ SELECT COALESCE(NULLIF(current_setting('request.jwt.claim.sub', true), ''), '') $$;

-- Helper: true when the caller is an AllStore administrator. Role is read from
-- the server-side profiles table, NEVER from a client-supplied claim.
CREATE OR REPLACE FUNCTION public.allstore_is_admin() RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT EXISTS (
    SELECT 1 FROM profiles p JOIN roles r ON r.id = p.role_id
    WHERE p.id = public.allstore_uid() AND r.slug IN ('super_admin','admin')
      AND p.status = 'active' AND p.deleted_at IS NULL
  )
$$;

DROP POLICY IF EXISTS "public read active products" ON products;
CREATE POLICY "public read active products" ON products FOR SELECT
  USING (status = 'active' AND deleted_at IS NULL);
DROP POLICY IF EXISTS "public read categories" ON categories;
CREATE POLICY "public read categories" ON categories FOR SELECT USING (is_active = 1 AND deleted_at IS NULL);
DROP POLICY IF EXISTS "public read brands" ON brands;
CREATE POLICY "public read brands" ON brands FOR SELECT USING (is_active = 1);
DROP POLICY IF EXISTS "public read attributes" ON attributes;
CREATE POLICY "public read attributes" ON attributes FOR SELECT USING (true);
DROP POLICY IF EXISTS "public read attribute values" ON attribute_values;
CREATE POLICY "public read attribute values" ON attribute_values FOR SELECT USING (true);
DROP POLICY IF EXISTS "public read category attributes" ON category_attributes;
CREATE POLICY "public read category attributes" ON category_attributes FOR SELECT USING (true);
DROP POLICY IF EXISTS "public read product images" ON product_images;
CREATE POLICY "public read product images" ON product_images FOR SELECT USING (true);
DROP POLICY IF EXISTS "public read variants" ON product_variants;
CREATE POLICY "public read variants" ON product_variants FOR SELECT USING (is_active = 1);
DROP POLICY IF EXISTS "public read pav" ON product_attribute_values;
CREATE POLICY "public read pav" ON product_attribute_values FOR SELECT USING (true);
DROP POLICY IF EXISTS "public read banners" ON banners;
CREATE POLICY "public read banners" ON banners FOR SELECT USING (is_active = 1);
DROP POLICY IF EXISTS "public read sections" ON homepage_sections;
CREATE POLICY "public read sections" ON homepage_sections FOR SELECT USING (is_enabled = 1);
DROP POLICY IF EXISTS "public read flash sales" ON flash_sales;
CREATE POLICY "public read flash sales" ON flash_sales FOR SELECT USING (is_active = 1);
DROP POLICY IF EXISTS "public read flash sale items" ON flash_sale_items;
CREATE POLICY "public read flash sale items" ON flash_sale_items FOR SELECT USING (true);
DROP POLICY IF EXISTS "public read pages" ON pages;
CREATE POLICY "public read pages" ON pages FOR SELECT USING (is_published = 1);
DROP POLICY IF EXISTS "public read blogs" ON blogs;
CREATE POLICY "public read blogs" ON blogs FOR SELECT USING (is_published = 1);
DROP POLICY IF EXISTS "public read faqs" ON faqs;
CREATE POLICY "public read faqs" ON faqs FOR SELECT USING (is_published = 1);

DROP POLICY IF EXISTS "own profile read" ON profiles;
CREATE POLICY "own profile read" ON profiles FOR SELECT
  USING (id = public.allstore_uid() OR public.allstore_is_admin());
DROP POLICY IF EXISTS "own profile update" ON profiles;
CREATE POLICY "own profile update" ON profiles FOR UPDATE
  USING (id = public.allstore_uid()) WITH CHECK (id = public.allstore_uid());

DROP POLICY IF EXISTS "own orders" ON orders;
CREATE POLICY "own orders" ON orders FOR SELECT
  USING (user_id = public.allstore_uid() OR public.allstore_is_admin());
DROP POLICY IF EXISTS "own order items" ON order_items;
CREATE POLICY "own order items" ON order_items FOR SELECT
  USING (EXISTS (SELECT 1 FROM orders o WHERE o.id = order_id AND (o.user_id = public.allstore_uid() OR public.allstore_is_admin())));
DROP POLICY IF EXISTS "own order history" ON order_status_history;
CREATE POLICY "own order history" ON order_status_history FOR SELECT
  USING (EXISTS (SELECT 1 FROM orders o WHERE o.id = order_id AND (o.user_id = public.allstore_uid() OR public.allstore_is_admin())));
DROP POLICY IF EXISTS "own addresses" ON addresses;
CREATE POLICY "own addresses" ON addresses FOR ALL
  USING (user_id = public.allstore_uid()) WITH CHECK (user_id = public.allstore_uid());
DROP POLICY IF EXISTS "own wishlist" ON wishlists;
CREATE POLICY "own wishlist" ON wishlists FOR ALL
  USING (user_id = public.allstore_uid()) WITH CHECK (user_id = public.allstore_uid());
DROP POLICY IF EXISTS "own wishlist items" ON wishlist_items;
CREATE POLICY "own wishlist items" ON wishlist_items FOR ALL
  USING (EXISTS (SELECT 1 FROM wishlists w WHERE w.id = wishlist_id AND w.user_id = public.allstore_uid()))
  WITH CHECK (EXISTS (SELECT 1 FROM wishlists w WHERE w.id = wishlist_id AND w.user_id = public.allstore_uid()));
DROP POLICY IF EXISTS "own cart" ON carts;
CREATE POLICY "own cart" ON carts FOR ALL
  USING (user_id = public.allstore_uid()) WITH CHECK (user_id = public.allstore_uid());
DROP POLICY IF EXISTS "own cart items" ON cart_items;
CREATE POLICY "own cart items" ON cart_items FOR ALL
  USING (EXISTS (SELECT 1 FROM carts c WHERE c.id = cart_id AND c.user_id = public.allstore_uid()))
  WITH CHECK (EXISTS (SELECT 1 FROM carts c WHERE c.id = cart_id AND c.user_id = public.allstore_uid()));
DROP POLICY IF EXISTS "own returns" ON return_requests;
CREATE POLICY "own returns" ON return_requests FOR SELECT
  USING (user_id = public.allstore_uid() OR public.allstore_is_admin());
DROP POLICY IF EXISTS "own refunds" ON refunds;
CREATE POLICY "own refunds" ON refunds FOR SELECT
  USING (EXISTS (SELECT 1 FROM orders o WHERE o.id = order_id AND o.user_id = public.allstore_uid()) OR public.allstore_is_admin());
DROP POLICY IF EXISTS "own tickets" ON support_tickets;
CREATE POLICY "own tickets" ON support_tickets FOR SELECT
  USING (user_id = public.allstore_uid() OR public.allstore_is_admin());
DROP POLICY IF EXISTS "own ticket messages" ON ticket_messages;
CREATE POLICY "own ticket messages" ON ticket_messages FOR SELECT
  USING (EXISTS (SELECT 1 FROM support_tickets t WHERE t.id = ticket_id AND (t.user_id = public.allstore_uid() OR public.allstore_is_admin())));
DROP POLICY IF EXISTS "own notifications" ON notifications;
CREATE POLICY "own notifications" ON notifications FOR SELECT
  USING (user_id = public.allstore_uid() OR (admin_only = 1 AND public.allstore_is_admin()));
DROP POLICY IF EXISTS "own reviews read" ON reviews;
CREATE POLICY "own reviews read" ON reviews FOR SELECT
  USING (status = 'approved' OR user_id = public.allstore_uid() OR public.allstore_is_admin());
DROP POLICY IF EXISTS "own questions" ON product_questions;
CREATE POLICY "own questions" ON product_questions FOR SELECT
  USING (status = 'answered' OR user_id = public.allstore_uid() OR public.allstore_is_admin());
