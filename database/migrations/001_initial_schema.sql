-- Initial marketplace schema for PostgreSQL / Supabase.
-- UUID defaults use gen_random_uuid() (built into supported PostgreSQL versions).
-- This migration assumes Supabase's auth schema and auth.users already exist.
-- Direct PDO/server connections do NOT inherit PostgREST RLS behavior. RLS is
-- defense in depth for clients using Supabase's authenticated API; application
-- authorization, ownership checks, validation, and transaction rules MUST still
-- be enforced by the PHP application for all direct database access.
-- No administrator account, password, or secret is created here.

BEGIN;

CREATE SCHEMA IF NOT EXISTS app;

CREATE OR REPLACE FUNCTION app.set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$$;

-- User identity and authorization catalog. Role grants are intentionally not
-- exposed through broad end-user RLS policies; grant and revoke through trusted
-- server-side authorization workflows only.
CREATE TABLE app.profiles (
  id uuid PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
  display_name text CHECK (display_name IS NULL OR length(btrim(display_name)) BETWEEN 1 AND 120),
  phone text CHECK (phone IS NULL OR length(phone) <= 32),
  avatar_url text CHECK (avatar_url IS NULL OR length(avatar_url) <= 2048),
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app.roles (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL UNIQUE CHECK (name = lower(btrim(name)) AND length(name) BETWEEN 2 AND 64),
  description text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app.role_permissions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  role_id uuid NOT NULL REFERENCES app.roles(id) ON DELETE CASCADE,
  permission_key text NOT NULL CHECK (permission_key = lower(btrim(permission_key)) AND length(permission_key) BETWEEN 2 AND 128),
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (role_id, permission_key)
);

CREATE TABLE app.profile_roles (
  profile_id uuid NOT NULL REFERENCES app.profiles(id) ON DELETE CASCADE,
  role_id uuid NOT NULL REFERENCES app.roles(id) ON DELETE RESTRICT,
  granted_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (profile_id, role_id)
);

-- Catalog.
CREATE TABLE app.categories (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  parent_id uuid REFERENCES app.categories(id) ON DELETE RESTRICT,
  name text NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 160),
  slug text NOT NULL UNIQUE CHECK (slug = lower(btrim(slug)) AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  description text,
  image_url text CHECK (image_url IS NULL OR length(image_url) <= 2048),
  is_active boolean NOT NULL DEFAULT true,
  sort_order integer NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (parent_id IS NULL OR parent_id <> id)
);
CREATE INDEX categories_parent_idx ON app.categories(parent_id);
CREATE INDEX categories_active_sort_idx ON app.categories(is_active, sort_order);

CREATE TABLE app.brands (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL UNIQUE CHECK (length(btrim(name)) BETWEEN 1 AND 160),
  slug text NOT NULL UNIQUE CHECK (slug = lower(btrim(slug)) AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  description text,
  logo_url text CHECK (logo_url IS NULL OR length(logo_url) <= 2048),
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app.products (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  category_id uuid REFERENCES app.categories(id) ON DELETE SET NULL,
  brand_id uuid REFERENCES app.brands(id) ON DELETE SET NULL,
  name text NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 240),
  slug text NOT NULL UNIQUE CHECK (slug = lower(btrim(slug)) AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  short_description text CHECK (short_description IS NULL OR length(short_description) <= 1000),
  description text,
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','archived')),
  currency char(3) NOT NULL DEFAULT 'BDT' CHECK (currency ~ '^[A-Z]{3}$'),
  base_price numeric(12,2) NOT NULL CHECK (base_price >= 0),
  compare_at_price numeric(12,2) CHECK (compare_at_price IS NULL OR compare_at_price >= base_price),
  sku text UNIQUE CHECK (sku IS NULL OR (length(btrim(sku)) BETWEEN 1 AND 80)),
  stock_quantity integer NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
  is_featured boolean NOT NULL DEFAULT false,
  published_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX products_category_idx ON app.products(category_id);
CREATE INDEX products_brand_idx ON app.products(brand_id);
CREATE INDEX products_status_published_idx ON app.products(status, published_at DESC);
CREATE INDEX products_featured_idx ON app.products(is_featured) WHERE is_featured;

CREATE TABLE app.product_variants (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id uuid NOT NULL REFERENCES app.products(id) ON DELETE CASCADE,
  sku text UNIQUE CHECK (sku IS NULL OR length(btrim(sku)) BETWEEN 1 AND 80),
  name text NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 160),
  attributes jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(attributes) = 'object'),
  price numeric(12,2) CHECK (price IS NULL OR price >= 0),
  stock_quantity integer NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (product_id, name)
);
CREATE INDEX product_variants_product_idx ON app.product_variants(product_id);

CREATE TABLE app.product_images (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id uuid NOT NULL REFERENCES app.products(id) ON DELETE CASCADE,
  variant_id uuid REFERENCES app.product_variants(id) ON DELETE CASCADE,
  image_url text NOT NULL CHECK (length(btrim(image_url)) BETWEEN 1 AND 2048),
  alt_text text CHECK (alt_text IS NULL OR length(alt_text) <= 300),
  sort_order integer NOT NULL DEFAULT 0,
  is_primary boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX product_images_product_sort_idx ON app.product_images(product_id, sort_order);
CREATE UNIQUE INDEX product_images_one_primary_idx ON app.product_images(product_id) WHERE is_primary;

-- Customer-owned data. Monetary amounts are snapshotted in order_items so
-- catalog price changes do not rewrite historical purchases.
CREATE TABLE app.addresses (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL REFERENCES app.profiles(id) ON DELETE CASCADE,
  recipient_name text NOT NULL CHECK (length(btrim(recipient_name)) BETWEEN 1 AND 160),
  phone text CHECK (phone IS NULL OR length(phone) <= 32),
  line1 text NOT NULL CHECK (length(btrim(line1)) BETWEEN 1 AND 240),
  line2 text CHECK (line2 IS NULL OR length(line2) <= 240),
  locality text NOT NULL CHECK (length(btrim(locality)) BETWEEN 1 AND 120),
  region text CHECK (region IS NULL OR length(region) <= 120),
  postal_code text CHECK (postal_code IS NULL OR length(postal_code) <= 32),
  country_code char(2) NOT NULL CHECK (country_code ~ '^[A-Z]{2}$'),
  is_default boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX addresses_profile_idx ON app.addresses(profile_id);
CREATE UNIQUE INDEX addresses_one_default_idx ON app.addresses(profile_id) WHERE is_default;

CREATE TABLE app.carts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL UNIQUE REFERENCES app.profiles(id) ON DELETE CASCADE,
  currency char(3) NOT NULL DEFAULT 'BDT' CHECK (currency ~ '^[A-Z]{3}$'),
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','converted','abandoned')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app.cart_items (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  cart_id uuid NOT NULL REFERENCES app.carts(id) ON DELETE CASCADE,
  product_id uuid NOT NULL REFERENCES app.products(id) ON DELETE RESTRICT,
  variant_id uuid REFERENCES app.product_variants(id) ON DELETE RESTRICT,
  quantity integer NOT NULL CHECK (quantity BETWEEN 1 AND 10000),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (cart_id, product_id, variant_id)
);
CREATE INDEX cart_items_cart_idx ON app.cart_items(cart_id);
CREATE INDEX cart_items_product_idx ON app.cart_items(product_id);

CREATE TABLE app.coupons (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  code text NOT NULL UNIQUE CHECK (code = upper(btrim(code)) AND length(code) BETWEEN 3 AND 64),
  description text,
  discount_type text NOT NULL CHECK (discount_type IN ('percent','fixed')),
  discount_value numeric(12,2) NOT NULL CHECK (discount_value > 0),
  currency char(3) CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
  minimum_order_amount numeric(12,2) NOT NULL DEFAULT 0 CHECK (minimum_order_amount >= 0),
  max_redemptions integer CHECK (max_redemptions IS NULL OR max_redemptions > 0),
  max_redemptions_per_user integer CHECK (max_redemptions_per_user IS NULL OR max_redemptions_per_user > 0),
  starts_at timestamptz,
  ends_at timestamptz,
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK ((discount_type = 'percent' AND discount_value <= 100 AND currency IS NULL) OR discount_type = 'fixed'),
  CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
);

CREATE TABLE app.orders (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_number text NOT NULL UNIQUE CHECK (length(btrim(order_number)) BETWEEN 1 AND 64),
  profile_id uuid NOT NULL REFERENCES app.profiles(id) ON DELETE RESTRICT,
  shipping_address_id uuid REFERENCES app.addresses(id) ON DELETE SET NULL,
  billing_address_id uuid REFERENCES app.addresses(id) ON DELETE SET NULL,
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','confirmed','processing','shipped','delivered','cancelled','refunded')),
  currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  subtotal numeric(12,2) NOT NULL CHECK (subtotal >= 0),
  discount_total numeric(12,2) NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
  shipping_total numeric(12,2) NOT NULL DEFAULT 0 CHECK (shipping_total >= 0),
  tax_total numeric(12,2) NOT NULL DEFAULT 0 CHECK (tax_total >= 0),
  grand_total numeric(12,2) NOT NULL CHECK (grand_total >= 0),
  customer_note text,
  placed_at timestamptz NOT NULL DEFAULT now(),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (discount_total <= subtotal),
  CHECK (grand_total = subtotal - discount_total + shipping_total + tax_total)
);
CREATE INDEX orders_profile_created_idx ON app.orders(profile_id, created_at DESC);
CREATE INDEX orders_status_created_idx ON app.orders(status, created_at DESC);

CREATE TABLE app.order_items (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES app.orders(id) ON DELETE CASCADE,
  product_id uuid REFERENCES app.products(id) ON DELETE SET NULL,
  variant_id uuid REFERENCES app.product_variants(id) ON DELETE SET NULL,
  product_name text NOT NULL CHECK (length(btrim(product_name)) BETWEEN 1 AND 240),
  variant_name text,
  sku text,
  quantity integer NOT NULL CHECK (quantity BETWEEN 1 AND 10000),
  unit_price numeric(12,2) NOT NULL CHECK (unit_price >= 0),
  line_total numeric(12,2) NOT NULL CHECK (line_total >= 0),
  created_at timestamptz NOT NULL DEFAULT now(),
  CHECK (line_total = unit_price * quantity)
);
CREATE INDEX order_items_order_idx ON app.order_items(order_id);
CREATE INDEX order_items_product_idx ON app.order_items(product_id);

CREATE TABLE app.order_status_history (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES app.orders(id) ON DELETE CASCADE,
  status text NOT NULL CHECK (status IN ('pending','confirmed','processing','shipped','delivered','cancelled','refunded')),
  note text,
  changed_by uuid REFERENCES app.profiles(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX order_status_history_order_time_idx ON app.order_status_history(order_id, created_at DESC);

CREATE TABLE app.coupon_usage (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  coupon_id uuid NOT NULL REFERENCES app.coupons(id) ON DELETE RESTRICT,
  profile_id uuid NOT NULL REFERENCES app.profiles(id) ON DELETE RESTRICT,
  order_id uuid NOT NULL UNIQUE REFERENCES app.orders(id) ON DELETE CASCADE,
  discount_amount numeric(12,2) NOT NULL CHECK (discount_amount >= 0),
  used_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (coupon_id, profile_id, order_id)
);
CREATE INDEX coupon_usage_coupon_idx ON app.coupon_usage(coupon_id);
CREATE INDEX coupon_usage_profile_idx ON app.coupon_usage(profile_id);

CREATE TABLE app.reviews (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id uuid NOT NULL REFERENCES app.products(id) ON DELETE CASCADE,
  profile_id uuid NOT NULL REFERENCES app.profiles(id) ON DELETE CASCADE,
  order_item_id uuid REFERENCES app.order_items(id) ON DELETE SET NULL,
  rating smallint NOT NULL CHECK (rating BETWEEN 1 AND 5),
  title text CHECK (title IS NULL OR length(title) <= 160),
  body text CHECK (body IS NULL OR length(body) <= 10000),
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','published','rejected')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (product_id, profile_id)
);
CREATE INDEX reviews_product_status_time_idx ON app.reviews(product_id, status, created_at DESC);
CREATE INDEX reviews_profile_idx ON app.reviews(profile_id);

-- Publicly renderable content. Publishing and edits are trusted-server duties.
CREATE TABLE app.homepage_sections (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  section_key text NOT NULL UNIQUE CHECK (section_key = lower(btrim(section_key)) AND section_key ~ '^[a-z0-9_-]{1,80}$'),
  title text NOT NULL CHECK (length(btrim(title)) BETWEEN 1 AND 160),
  content jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(content) = 'object'),
  sort_order integer NOT NULL DEFAULT 0,
  is_active boolean NOT NULL DEFAULT true,
  starts_at timestamptz,
  ends_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
);
CREATE INDEX homepage_sections_active_sort_idx ON app.homepage_sections(is_active, sort_order);

CREATE TABLE app.banners (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  title text NOT NULL CHECK (length(btrim(title)) BETWEEN 1 AND 200),
  image_url text NOT NULL CHECK (length(btrim(image_url)) BETWEEN 1 AND 2048),
  alt_text text CHECK (alt_text IS NULL OR length(alt_text) <= 300),
  target_url text CHECK (target_url IS NULL OR length(target_url) <= 2048),
  placement text NOT NULL DEFAULT 'home' CHECK (length(btrim(placement)) BETWEEN 1 AND 80),
  sort_order integer NOT NULL DEFAULT 0,
  is_active boolean NOT NULL DEFAULT true,
  starts_at timestamptz,
  ends_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
);
CREATE INDEX banners_placement_active_sort_idx ON app.banners(placement, is_active, sort_order);

-- Append-oriented operational log. Restrict access to trusted server/admin roles.
CREATE TABLE app.audit_logs (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_profile_id uuid REFERENCES app.profiles(id) ON DELETE SET NULL,
  action text NOT NULL CHECK (length(btrim(action)) BETWEEN 1 AND 160),
  entity_type text NOT NULL CHECK (length(btrim(entity_type)) BETWEEN 1 AND 120),
  entity_id uuid,
  request_id text CHECK (request_id IS NULL OR length(request_id) <= 128),
  details jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(details) = 'object'),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_logs_entity_time_idx ON app.audit_logs(entity_type, entity_id, created_at DESC);
CREATE INDEX audit_logs_actor_time_idx ON app.audit_logs(actor_profile_id, created_at DESC);

-- Maintain update timestamps consistently for mutable records.
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['profiles','roles','categories','brands','products','product_variants','addresses','carts','cart_items','coupons','orders','reviews','homepage_sections','banners'] LOOP
    EXECUTE format('CREATE TRIGGER %I BEFORE UPDATE ON app.%I FOR EACH ROW EXECUTE FUNCTION app.set_updated_at()', t || '_set_updated_at', t);
  END LOOP;
END;
$$;

-- RLS for user-private/profile-associated records. Policies are deliberately
-- limited to the authenticated owner identity. No public catalog/admin-write
-- policies are added: exposure and privileged mutation should be granted by the
-- application/service role according to deployment needs.
ALTER TABLE app.profiles ENABLE ROW LEVEL SECURITY;
CREATE POLICY profiles_self_select ON app.profiles FOR SELECT TO authenticated USING (id = (SELECT auth.uid()));
CREATE POLICY profiles_self_update ON app.profiles FOR UPDATE TO authenticated USING (id = (SELECT auth.uid())) WITH CHECK (id = (SELECT auth.uid()));

ALTER TABLE app.addresses ENABLE ROW LEVEL SECURITY;
CREATE POLICY addresses_owner_select ON app.addresses FOR SELECT TO authenticated USING (profile_id = (SELECT auth.uid()));
CREATE POLICY addresses_owner_insert ON app.addresses FOR INSERT TO authenticated WITH CHECK (profile_id = (SELECT auth.uid()));
CREATE POLICY addresses_owner_update ON app.addresses FOR UPDATE TO authenticated USING (profile_id = (SELECT auth.uid())) WITH CHECK (profile_id = (SELECT auth.uid()));
CREATE POLICY addresses_owner_delete ON app.addresses FOR DELETE TO authenticated USING (profile_id = (SELECT auth.uid()));

ALTER TABLE app.carts ENABLE ROW LEVEL SECURITY;
CREATE POLICY carts_owner_all ON app.carts FOR ALL TO authenticated USING (profile_id = (SELECT auth.uid())) WITH CHECK (profile_id = (SELECT auth.uid()));

ALTER TABLE app.cart_items ENABLE ROW LEVEL SECURITY;
CREATE POLICY cart_items_owner_all ON app.cart_items FOR ALL TO authenticated
  USING (EXISTS (SELECT 1 FROM app.carts c WHERE c.id = cart_id AND c.profile_id = (SELECT auth.uid())))
  WITH CHECK (EXISTS (SELECT 1 FROM app.carts c WHERE c.id = cart_id AND c.profile_id = (SELECT auth.uid())));

ALTER TABLE app.orders ENABLE ROW LEVEL SECURITY;
CREATE POLICY orders_owner_select ON app.orders FOR SELECT TO authenticated USING (profile_id = (SELECT auth.uid()));

ALTER TABLE app.order_items ENABLE ROW LEVEL SECURITY;
CREATE POLICY order_items_owner_select ON app.order_items FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM app.orders o WHERE o.id = order_id AND o.profile_id = (SELECT auth.uid())));
ALTER TABLE app.order_status_history ENABLE ROW LEVEL SECURITY;
CREATE POLICY order_history_owner_select ON app.order_status_history FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM app.orders o WHERE o.id = order_id AND o.profile_id = (SELECT auth.uid())));

ALTER TABLE app.coupon_usage ENABLE ROW LEVEL SECURITY;
CREATE POLICY coupon_usage_owner_select ON app.coupon_usage FOR SELECT TO authenticated USING (profile_id = (SELECT auth.uid()));

ALTER TABLE app.reviews ENABLE ROW LEVEL SECURITY;
CREATE POLICY reviews_owner_select ON app.reviews FOR SELECT TO authenticated USING (profile_id = (SELECT auth.uid()));
CREATE POLICY reviews_owner_insert ON app.reviews FOR INSERT TO authenticated WITH CHECK (profile_id = (SELECT auth.uid()));
CREATE POLICY reviews_owner_update ON app.reviews FOR UPDATE TO authenticated USING (profile_id = (SELECT auth.uid())) WITH CHECK (profile_id = (SELECT auth.uid()));
CREATE POLICY reviews_owner_delete ON app.reviews FOR DELETE TO authenticated USING (profile_id = (SELECT auth.uid()));

COMMIT;
