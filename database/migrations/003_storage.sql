-- =====================================================================
-- AllStore :: Supabase Storage buckets + policies
-- Public bucket for catalogue/marketing imagery, private bucket for tickets.
-- =====================================================================
INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES ('product-images', 'product-images', true, 5242880,
        ARRAY['image/jpeg','image/png','image/webp','image/gif'])
ON CONFLICT (id) DO UPDATE SET public = true, file_size_limit = 5242880;

INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES ('private-documents', 'private-documents', false, 5242880,
        ARRAY['image/jpeg','image/png','image/webp','application/pdf'])
ON CONFLICT (id) DO UPDATE SET public = false, file_size_limit = 5242880;

DROP POLICY IF EXISTS "public read product images" ON storage.objects;
CREATE POLICY "public read product images" ON storage.objects FOR SELECT
  USING (bucket_id = 'product-images');

-- Uploads are performed by the PHP backend using the server-side secret key,
-- which bypasses RLS. No client-side INSERT/UPDATE/DELETE policy is granted.
DROP POLICY IF EXISTS "deny client writes product images" ON storage.objects;
CREATE POLICY "deny client writes product images" ON storage.objects FOR INSERT
  WITH CHECK (false);

DROP POLICY IF EXISTS "private docs admin only" ON storage.objects;
CREATE POLICY "private docs admin only" ON storage.objects FOR SELECT
  USING (bucket_id = 'private-documents' AND public.allstore_is_admin());
