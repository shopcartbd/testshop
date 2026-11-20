-- Operational marketplace additions. Apply after 001_initial_schema.sql.
-- Authentication is PHP-managed; this schema is server-only and must not be exposed through PostgREST.
BEGIN;

-- PHP-managed customer/admin credentials. The first privileged account is created only by
-- scripts/bootstrap-admin.php; public registration is always assigned role=customer.
CREATE TABLE app.accounts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  email text NOT NULL CHECK (email = lower(btrim(email)) AND length(email) BETWEEN 3 AND 320),
  password_hash text NOT NULL CHECK (length(password_hash) BETWEEN 50 AND 255),
  display_name text NOT NULL CHECK (length(btrim(display_name)) BETWEEN 1 AND 120),
  phone text CHECK (phone IS NULL OR length(phone) <= 32),
  role text NOT NULL DEFAULT 'customer' CHECK (role IN ('customer','admin','owner')),
  status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','disabled','locked')),
  email_verified_at timestamptz,
  last_login_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX accounts_email_lower_uq ON app.accounts(lower(email));
CREATE INDEX accounts_role_status_idx ON app.accounts(role,status,created_at DESC);

CREATE TABLE app.account_addresses (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  account_id uuid NOT NULL REFERENCES app.accounts(id) ON DELETE CASCADE,
  recipient_name text NOT NULL CHECK (length(btrim(recipient_name)) BETWEEN 1 AND 160),
  phone text NOT NULL CHECK (length(btrim(phone)) BETWEEN 5 AND 32),
  line1 text NOT NULL CHECK (length(btrim(line1)) BETWEEN 1 AND 240),
  line2 text CHECK (line2 IS NULL OR length(line2) <= 240),
  locality text NOT NULL CHECK (length(btrim(locality)) BETWEEN 1 AND 120),
  region text CHECK (region IS NULL OR length(region) <= 120),
  postal_code text CHECK (postal_code IS NULL OR length(postal_code) <= 32),
  country_code char(2) NOT NULL DEFAULT 'BD' CHECK (country_code ~ '^[A-Z]{2}$'),
  is_default boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX account_addresses_owner_idx ON app.account_addresses(account_id);
CREATE UNIQUE INDEX account_addresses_one_default_uq ON app.account_addresses(account_id) WHERE is_default;

-- Shared PHP sessions work across Vercel container instances when SESSION_DRIVER=database.
CREATE TABLE app.sessions (
  id text PRIMARY KEY CHECK (length(id) BETWEEN 16 AND 256),
  account_id uuid REFERENCES app.accounts(id) ON DELETE CASCADE,
  payload text NOT NULL CHECK (length(payload) <= 131072),
  expires_at timestamptz NOT NULL,
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX sessions_expiry_idx ON app.sessions(expires_at);

CREATE TABLE app.rate_limits (
  bucket_key char(64) PRIMARY KEY CHECK (bucket_key ~ '^[a-f0-9]{64}$'),
  attempts integer NOT NULL CHECK (attempts >= 0),
  window_started_at timestamptz NOT NULL,
  blocked_until timestamptz,
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX rate_limits_expiry_idx ON app.rate_limits(updated_at);

CREATE TABLE app.password_reset_tokens (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  account_id uuid NOT NULL REFERENCES app.accounts(id) ON DELETE CASCADE,
  token_hash char(64) NOT NULL UNIQUE CHECK (token_hash ~ '^[a-f0-9]{64}$'),
  expires_at timestamptz NOT NULL,
  used_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX password_resets_account_expiry_idx ON app.password_reset_tokens(account_id,expires_at DESC);

-- Fully dynamic attribute definitions and product values.
CREATE TABLE app.attributes (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL UNIQUE CHECK (length(btrim(name)) BETWEEN 1 AND 120),
  slug text NOT NULL UNIQUE CHECK (slug = lower(btrim(slug)) AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  data_type text NOT NULL CHECK (data_type IN ('text','number','boolean','dropdown','multi_select','color','measurement')),
  options jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(options) = 'array'),
  unit text CHECK (unit IS NULL OR length(unit) <= 32),
  is_filterable boolean NOT NULL DEFAULT false,
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE app.category_attributes (
  category_id uuid NOT NULL REFERENCES app.categories(id) ON DELETE CASCADE,
  attribute_id uuid NOT NULL REFERENCES app.attributes(id) ON DELETE CASCADE,
  is_required boolean NOT NULL DEFAULT false,
  sort_order integer NOT NULL DEFAULT 0,
  PRIMARY KEY(category_id,attribute_id)
);
CREATE TABLE app.product_attribute_values (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id uuid NOT NULL REFERENCES app.products(id) ON DELETE CASCADE,
  variant_id uuid REFERENCES app.product_variants(id) ON DELETE CASCADE,
  attribute_id uuid NOT NULL REFERENCES app.attributes(id) ON DELETE CASCADE,
  value jsonb NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE(product_id,variant_id,attribute_id)
);
CREATE INDEX product_attribute_filter_idx ON app.product_attribute_values(attribute_id,product_id);

ALTER TABLE app.categories ADD COLUMN icon_name text;
ALTER TABLE app.categories ADD COLUMN banner_url text CHECK (banner_url IS NULL OR length(banner_url) <= 2048);
ALTER TABLE app.categories ADD COLUMN seo_title text CHECK (seo_title IS NULL OR length(seo_title) <= 200);
ALTER TABLE app.categories ADD COLUMN seo_description text CHECK (seo_description IS NULL OR length(seo_description) <= 500);
ALTER TABLE app.brands ADD COLUMN seo_title text CHECK (seo_title IS NULL OR length(seo_title) <= 200);
ALTER TABLE app.brands ADD COLUMN seo_description text CHECK (seo_description IS NULL OR length(seo_description) <= 500);
ALTER TABLE app.products ADD COLUMN barcode text;
ALTER TABLE app.products ADD COLUMN product_type text NOT NULL DEFAULT 'simple' CHECK (product_type IN ('simple','variable','bundle','digital'));
ALTER TABLE app.products ADD COLUMN cost_price numeric(12,2) CHECK (cost_price IS NULL OR cost_price >= 0);
ALTER TABLE app.products ADD COLUMN tax_rate numeric(5,2) NOT NULL DEFAULT 0 CHECK (tax_rate BETWEEN 0 AND 100);
ALTER TABLE app.products ADD COLUMN low_stock_threshold integer NOT NULL DEFAULT 5 CHECK (low_stock_threshold >= 0);
ALTER TABLE app.products ADD COLUMN weight_grams integer CHECK (weight_grams IS NULL OR weight_grams >= 0);
ALTER TABLE app.products ADD COLUMN dimensions jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(dimensions) = 'object');
ALTER TABLE app.products ADD COLUMN warranty text;
ALTER TABLE app.products ADD COLUMN return_eligible boolean NOT NULL DEFAULT true;
ALTER TABLE app.products ADD COLUMN is_best_seller boolean NOT NULL DEFAULT false;
ALTER TABLE app.products ADD COLUMN is_new_arrival boolean NOT NULL DEFAULT false;
ALTER TABLE app.products ADD COLUMN seo_title text CHECK (seo_title IS NULL OR length(seo_title) <= 200);
ALTER TABLE app.products ADD COLUMN seo_description text CHECK (seo_description IS NULL OR length(seo_description) <= 500);
ALTER TABLE app.products ADD COLUMN deleted_at timestamptz;
ALTER TABLE app.products DROP CONSTRAINT IF EXISTS products_status_check;
ALTER TABLE app.products ADD CONSTRAINT products_status_check CHECK (status IN ('draft','active','inactive','archived'));
CREATE UNIQUE INDEX products_barcode_uq ON app.products(barcode) WHERE barcode IS NOT NULL;
CREATE INDEX products_best_seller_idx ON app.products(created_at DESC) WHERE is_best_seller AND status='active' AND deleted_at IS NULL;
CREATE INDEX products_new_arrival_idx ON app.products(created_at DESC) WHERE is_new_arrival AND status='active' AND deleted_at IS NULL;
ALTER TABLE app.product_variants ADD COLUMN barcode text;
ALTER TABLE app.product_variants ADD COLUMN image_url text;
ALTER TABLE app.product_variants ADD COLUMN deleted_at timestamptz;

-- Inventory changes are append-only and audited. Product stock remains the storefront quantity.
CREATE TABLE app.warehouses (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL UNIQUE CHECK (length(btrim(name)) BETWEEN 1 AND 120),
  code text NOT NULL UNIQUE CHECK (code = upper(btrim(code)) AND length(code) BETWEEN 2 AND 32),
  address jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(address)='object'),
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE app.inventory_levels (
  warehouse_id uuid NOT NULL REFERENCES app.warehouses(id) ON DELETE RESTRICT,
  product_id uuid NOT NULL REFERENCES app.products(id) ON DELETE RESTRICT,
  on_hand integer NOT NULL DEFAULT 0 CHECK (on_hand >= 0),
  reserved integer NOT NULL DEFAULT 0 CHECK (reserved >= 0 AND reserved <= on_hand),
  damaged integer NOT NULL DEFAULT 0 CHECK (damaged >= 0 AND damaged <= on_hand),
  updated_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY(warehouse_id,product_id)
);
CREATE TABLE app.stock_movements (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id uuid NOT NULL REFERENCES app.products(id) ON DELETE RESTRICT,
  warehouse_id uuid REFERENCES app.warehouses(id) ON DELETE SET NULL,
  movement_type text NOT NULL CHECK (movement_type IN ('stock_in','stock_out','adjustment','reserve','release','damage','order','return')),
  quantity_delta integer NOT NULL CHECK (quantity_delta <> 0),
  quantity_after integer NOT NULL CHECK (quantity_after >= 0),
  reason text NOT NULL CHECK (length(btrim(reason)) BETWEEN 1 AND 500),
  order_id uuid REFERENCES app.orders(id) ON DELETE SET NULL,
  actor_account_id uuid REFERENCES app.accounts(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX stock_movements_product_time_idx ON app.stock_movements(product_id,created_at DESC);

CREATE TABLE app.wishlists (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  account_id uuid NOT NULL REFERENCES app.accounts(id) ON DELETE CASCADE,
  name text NOT NULL DEFAULT 'Favorites' CHECK (length(btrim(name)) BETWEEN 1 AND 120),
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE(account_id,name)
);
CREATE TABLE app.wishlist_items (
  wishlist_id uuid NOT NULL REFERENCES app.wishlists(id) ON DELETE CASCADE,
  product_id uuid NOT NULL REFERENCES app.products(id) ON DELETE CASCADE,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY(wishlist_id,product_id)
);

CREATE TABLE app.marketplace_reviews (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id uuid NOT NULL REFERENCES app.products(id) ON DELETE CASCADE,
  account_id uuid NOT NULL REFERENCES app.accounts(id) ON DELETE CASCADE,
  order_item_id uuid NOT NULL REFERENCES app.order_items(id) ON DELETE RESTRICT,
  rating smallint NOT NULL CHECK (rating BETWEEN 1 AND 5),
  title text CHECK (title IS NULL OR length(title) <= 160),
  body text CHECK (body IS NULL OR length(body) <= 10000),
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','published','rejected')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE(product_id,account_id)
);
CREATE INDEX marketplace_reviews_public_idx ON app.marketplace_reviews(product_id,created_at DESC) WHERE status='published';

-- Shipping, rates and tax are server-owned configuration; customer-provided amounts are ignored.
CREATE TABLE app.shipping_zones (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL UNIQUE CHECK (length(btrim(name)) BETWEEN 1 AND 120),
  country_codes text[] NOT NULL DEFAULT ARRAY['BD']::text[],
  regions text[] NOT NULL DEFAULT ARRAY[]::text[],
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE app.shipping_methods (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  zone_id uuid REFERENCES app.shipping_zones(id) ON DELETE RESTRICT,
  name text NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 120),
  method_code text NOT NULL UNIQUE CHECK (method_code ~ '^[a-z0-9_-]{2,48}$'),
  base_fee numeric(12,2) NOT NULL DEFAULT 0 CHECK (base_fee >= 0),
  free_shipping_threshold numeric(12,2) CHECK (free_shipping_threshold IS NULL OR free_shipping_threshold >= 0),
  per_kg_fee numeric(12,2) NOT NULL DEFAULT 0 CHECK (per_kg_fee >= 0),
  estimated_days_min smallint NOT NULL DEFAULT 2 CHECK (estimated_days_min >= 0),
  estimated_days_max smallint NOT NULL DEFAULT 5 CHECK (estimated_days_max >= estimated_days_min),
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE app.tax_rules (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL UNIQUE,
  country_code char(2) NOT NULL DEFAULT 'BD',
  region text,
  rate_percent numeric(5,2) NOT NULL CHECK (rate_percent BETWEEN 0 AND 100),
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE app.orders ALTER COLUMN profile_id DROP NOT NULL;
ALTER TABLE app.orders ADD COLUMN customer_account_id uuid REFERENCES app.accounts(id) ON DELETE SET NULL;
ALTER TABLE app.orders ADD COLUMN customer_email text;
ALTER TABLE app.orders ADD COLUMN customer_phone text;
ALTER TABLE app.orders ADD COLUMN customer_name text;
ALTER TABLE app.orders ADD COLUMN shipping_address_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(shipping_address_snapshot)='object');
ALTER TABLE app.orders ADD COLUMN shipping_method_id uuid REFERENCES app.shipping_methods(id) ON DELETE SET NULL;
ALTER TABLE app.orders ADD COLUMN shipping_method_name text;
ALTER TABLE app.orders ADD COLUMN payment_method text NOT NULL DEFAULT 'cod' CHECK (payment_method IN ('cod','stripe'));
ALTER TABLE app.orders ADD COLUMN payment_status text NOT NULL DEFAULT 'pending' CHECK (payment_status IN ('pending','authorized','paid','failed','refunded','partially_refunded'));
ALTER TABLE app.orders ADD COLUMN coupon_code text;
ALTER TABLE app.orders ADD COLUMN idempotency_key char(64);
ALTER TABLE app.orders ADD COLUMN public_token_hash char(64);
ALTER TABLE app.orders ADD COLUMN weight_grams integer NOT NULL DEFAULT 0 CHECK (weight_grams >= 0);
ALTER TABLE app.orders ADD CONSTRAINT orders_buyer_check CHECK (profile_id IS NOT NULL OR customer_account_id IS NOT NULL OR (customer_email IS NOT NULL AND customer_name IS NOT NULL));
ALTER TABLE app.orders DROP CONSTRAINT IF EXISTS orders_status_check;
ALTER TABLE app.orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','confirmed','processing','packed','shipped','delivered','cancelled','return_requested','returned','refunded'));
CREATE UNIQUE INDEX orders_idempotency_uq ON app.orders(idempotency_key) WHERE idempotency_key IS NOT NULL;
CREATE INDEX orders_account_created_idx ON app.orders(customer_account_id,created_at DESC);
CREATE INDEX orders_email_created_idx ON app.orders(lower(customer_email),created_at DESC);
ALTER TABLE app.order_status_history ADD COLUMN actor_account_id uuid REFERENCES app.accounts(id) ON DELETE SET NULL;
ALTER TABLE app.order_status_history DROP CONSTRAINT IF EXISTS order_status_history_status_check;
ALTER TABLE app.order_status_history ADD CONSTRAINT order_status_history_status_check CHECK (status IN ('pending','confirmed','processing','packed','shipped','delivered','cancelled','return_requested','returned','refunded'));

ALTER TABLE app.coupons DROP CONSTRAINT IF EXISTS coupons_discount_type_check;
ALTER TABLE app.coupons ADD CONSTRAINT coupons_discount_type_check CHECK (discount_type IN ('percent','fixed','free_shipping'));
ALTER TABLE app.coupons ADD COLUMN maximum_discount numeric(12,2) CHECK (maximum_discount IS NULL OR maximum_discount >= 0);
ALTER TABLE app.coupons ADD COLUMN applies_to_categories jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(applies_to_categories)='array');
ALTER TABLE app.coupons ADD COLUMN applies_to_products jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(applies_to_products)='array');
ALTER TABLE app.coupons ADD COLUMN first_order_only boolean NOT NULL DEFAULT false;
ALTER TABLE app.coupon_usage ALTER COLUMN profile_id DROP NOT NULL;
ALTER TABLE app.coupon_usage ADD COLUMN customer_account_id uuid REFERENCES app.accounts(id) ON DELETE RESTRICT;
ALTER TABLE app.coupon_usage ADD COLUMN guest_email text;
ALTER TABLE app.coupon_usage ADD CONSTRAINT coupon_usage_buyer_check CHECK (profile_id IS NOT NULL OR customer_account_id IS NOT NULL OR guest_email IS NOT NULL);
CREATE UNIQUE INDEX coupon_usage_guest_uq ON app.coupon_usage(coupon_id,lower(guest_email),order_id) WHERE guest_email IS NOT NULL;

CREATE TABLE app.payments (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES app.orders(id) ON DELETE CASCADE,
  provider text NOT NULL CHECK (provider IN ('cod','stripe','manual')),
  provider_reference text UNIQUE,
  amount numeric(12,2) NOT NULL CHECK (amount >= 0),
  currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  status text NOT NULL CHECK (status IN ('pending','processing','succeeded','failed','refunded')),
  metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata)='object'),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX payments_order_time_idx ON app.payments(order_id,created_at DESC);
CREATE TABLE app.payment_events (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  provider text NOT NULL,
  event_id text NOT NULL,
  payload_hash char(64) NOT NULL,
  processed_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE(provider,event_id)
);

CREATE TABLE app.shipments (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL UNIQUE REFERENCES app.orders(id) ON DELETE CASCADE,
  carrier text,
  tracking_number text,
  tracking_url text,
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','packed','shipped','in_transit','delivered','failed','returned')),
  shipped_at timestamptz,
  delivered_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE app.return_requests (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES app.orders(id) ON DELETE RESTRICT,
  order_item_id uuid REFERENCES app.order_items(id) ON DELETE RESTRICT,
  customer_account_id uuid REFERENCES app.accounts(id) ON DELETE SET NULL,
  guest_email text,
  reason text NOT NULL CHECK (length(btrim(reason)) BETWEEN 3 AND 2000),
  request_type text NOT NULL DEFAULT 'return' CHECK (request_type IN ('return','exchange','refund')),
  status text NOT NULL DEFAULT 'requested' CHECK (status IN ('requested','approved','rejected','received','completed','cancelled')),
  customer_note text,
  admin_note text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX return_requests_status_time_idx ON app.return_requests(status,created_at DESC);
CREATE TABLE app.refunds (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES app.orders(id) ON DELETE RESTRICT,
  return_request_id uuid REFERENCES app.return_requests(id) ON DELETE SET NULL,
  amount numeric(12,2) NOT NULL CHECK (amount > 0),
  currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
  status text NOT NULL DEFAULT 'requested' CHECK (status IN ('requested','approved','processing','completed','rejected')),
  provider_reference text,
  completed_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE app.notifications (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  account_id uuid REFERENCES app.accounts(id) ON DELETE CASCADE,
  email text,
  channel text NOT NULL CHECK (channel IN ('in_app','email','sms','push')),
  event_type text NOT NULL CHECK (length(btrim(event_type)) BETWEEN 2 AND 80),
  subject text NOT NULL CHECK (length(btrim(subject)) BETWEEN 1 AND 200),
  body text NOT NULL CHECK (length(body) <= 10000),
  status text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','sent','failed','read')),
  sent_at timestamptz,
  read_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notifications_account_time_idx ON app.notifications(account_id,created_at DESC);
CREATE TABLE app.support_tickets (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  ticket_number text NOT NULL UNIQUE,
  account_id uuid REFERENCES app.accounts(id) ON DELETE SET NULL,
  guest_email text,
  subject text NOT NULL CHECK (length(btrim(subject)) BETWEEN 3 AND 200),
  category text NOT NULL DEFAULT 'general',
  priority text NOT NULL DEFAULT 'normal' CHECK (priority IN ('low','normal','high','urgent')),
  status text NOT NULL DEFAULT 'open' CHECK (status IN ('open','in_progress','waiting_customer','resolved','closed')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX support_tickets_status_time_idx ON app.support_tickets(status,priority,created_at DESC);
CREATE TABLE app.ticket_messages (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  ticket_id uuid NOT NULL REFERENCES app.support_tickets(id) ON DELETE CASCADE,
  sender_account_id uuid REFERENCES app.accounts(id) ON DELETE SET NULL,
  message text NOT NULL CHECK (length(btrim(message)) BETWEEN 1 AND 10000),
  is_internal boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ticket_messages_ticket_time_idx ON app.ticket_messages(ticket_id,created_at);

CREATE TABLE app.pages (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  title text NOT NULL CHECK (length(btrim(title)) BETWEEN 1 AND 200),
  slug text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  content text NOT NULL DEFAULT '',
  meta_title text,
  meta_description text,
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
  published_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE app.blog_posts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  title text NOT NULL CHECK (length(btrim(title)) BETWEEN 1 AND 240),
  slug text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  excerpt text,
  content text NOT NULL DEFAULT '',
  cover_image_url text,
  author_account_id uuid REFERENCES app.accounts(id) ON DELETE SET NULL,
  meta_title text,
  meta_description text,
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
  published_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX blog_posts_published_idx ON app.blog_posts(published_at DESC) WHERE status='published';
CREATE TABLE app.settings (
  setting_key text PRIMARY KEY CHECK (setting_key ~ '^[a-z0-9_.-]{1,100}$'),
  setting_value jsonb NOT NULL DEFAULT 'null'::jsonb,
  is_secret boolean NOT NULL DEFAULT false,
  updated_by uuid REFERENCES app.accounts(id) ON DELETE SET NULL,
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE app.search_events (
  id bigserial PRIMARY KEY,
  query_hash char(64) NOT NULL,
  query_length smallint NOT NULL CHECK (query_length BETWEEN 1 AND 100),
  result_count integer NOT NULL DEFAULT 0 CHECK (result_count >= 0),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX search_events_time_idx ON app.search_events(created_at DESC);
CREATE TABLE app.recently_viewed (
  account_id uuid NOT NULL REFERENCES app.accounts(id) ON DELETE CASCADE,
  product_id uuid NOT NULL REFERENCES app.products(id) ON DELETE CASCADE,
  viewed_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY(account_id,product_id)
);
CREATE INDEX recently_viewed_time_idx ON app.recently_viewed(account_id,viewed_at DESC);
CREATE TABLE app.newsletter_subscribers (
  email text PRIMARY KEY CHECK (email=lower(btrim(email)) AND length(email) BETWEEN 3 AND 320),
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','active','unsubscribed')),
  consented_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);

-- Server-only data: no anon/authenticated grants or PostgREST policies are created here.
-- All protected access must pass through PHP authorization and owner-scoped parameterized queries.
REVOKE ALL ON TABLE app.accounts,app.account_addresses,app.sessions,app.rate_limits,app.password_reset_tokens,
  app.attributes,app.category_attributes,app.product_attribute_values,app.warehouses,app.inventory_levels,
  app.stock_movements,app.wishlists,app.wishlist_items,app.marketplace_reviews,app.payments,app.payment_events,app.shipments,
  app.return_requests,app.refunds,app.notifications,app.support_tickets,app.ticket_messages,app.pages,
  app.blog_posts,app.settings,app.search_events,app.recently_viewed,app.newsletter_subscribers
  FROM PUBLIC,anon,authenticated;

COMMIT;

-- Attribute PHP-managed admin activity separately from the legacy Supabase Auth profile model.
BEGIN;
ALTER TABLE app.audit_logs ADD COLUMN actor_account_id uuid REFERENCES app.accounts(id) ON DELETE SET NULL;
CREATE INDEX audit_logs_actor_account_time_idx ON app.audit_logs(actor_account_id,created_at DESC);

-- The original migration has both column-level and table-level discount constraints;
-- replace them explicitly so free_shipping can be configured without a positive fake discount.
ALTER TABLE app.coupons DROP CONSTRAINT IF EXISTS coupons_discount_value_check;
ALTER TABLE app.coupons DROP CONSTRAINT IF EXISTS coupons_check;
ALTER TABLE app.coupons ADD CONSTRAINT coupons_discount_value_valid_check CHECK (
  (discount_type='percent' AND discount_value>0 AND discount_value<=100 AND currency IS NULL)
  OR (discount_type='fixed' AND discount_value>0)
  OR (discount_type='free_shipping' AND discount_value=0)
);
COMMIT;
