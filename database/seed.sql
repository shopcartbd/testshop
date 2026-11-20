-- Optional starter categories for the AllStore storefront; no hardcoded products are seeded.
-- Replace the image paths with Supabase Storage URLs when product/category media is configured.
INSERT INTO app.categories (name, slug, description, image_url, is_active, sort_order)
VALUES
  ('Electronics', 'electronics', 'Everyday technology and accessories.', '/assets/category-tech.jpg', true, 10),
  ('Home & Living', 'home-living', 'Considered essentials for your home.', '/assets/category-home.jpg', true, 20)
ON CONFLICT (slug) DO UPDATE SET
  description = EXCLUDED.description,
  image_url = EXCLUDED.image_url,
  is_active = EXCLUDED.is_active,
  sort_order = EXCLUDED.sort_order;

-- Example only: update this rate to your actual delivery pricing before production use.
INSERT INTO app.shipping_zones(name,country_codes,regions,is_active)
VALUES ('Bangladesh standard','{BD}','{}',true)
ON CONFLICT (name) DO UPDATE SET country_codes=EXCLUDED.country_codes,regions=EXCLUDED.regions,is_active=EXCLUDED.is_active;
INSERT INTO app.shipping_methods(zone_id,name,method_code,base_fee,per_kg_fee,estimated_days_min,estimated_days_max,is_active)
SELECT id,'Standard delivery (manual courier)','bd_standard',80.00,0.00,2,5,true
FROM app.shipping_zones WHERE name='Bangladesh standard'
ON CONFLICT (method_code) DO UPDATE SET zone_id=EXCLUDED.zone_id,base_fee=EXCLUDED.base_fee,per_kg_fee=EXCLUDED.per_kg_fee,estimated_days_min=EXCLUDED.estimated_days_min,estimated_days_max=EXCLUDED.estimated_days_max,is_active=EXCLUDED.is_active;
