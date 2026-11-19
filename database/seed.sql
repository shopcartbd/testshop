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
