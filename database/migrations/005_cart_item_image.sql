-- 005: remember the gallery image the shopper actually picked for each cart line.
-- Safe to run more than once; existing rows keep '' and fall back to the variant /
-- primary product image.
ALTER TABLE cart_items ADD COLUMN IF NOT EXISTS image_url TEXT NOT NULL DEFAULT '';
