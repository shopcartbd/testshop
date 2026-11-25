-- =====================================================================
-- AllStore :: least-privilege database role for the PHP runtime
-- Run once as the Supabase postgres owner. Never use the postgres superuser
-- in the application. PDO connections run as allstore_app.
-- =====================================================================
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'allstore_app') THEN
    CREATE ROLE allstore_app LOGIN PASSWORD 'REPLACE_WITH_A_STRONG_UNIQUE_PASSWORD';
  END IF;
END $$;

GRANT USAGE ON SCHEMA public TO allstore_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO allstore_app;
-- No TRUNCATE, no DDL, no superuser, no BYPASSRLS.
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO allstore_app;
REVOKE ALL ON TABLE profiles FROM allstore_app;
GRANT SELECT, INSERT, UPDATE ON TABLE profiles TO allstore_app;
REVOKE ALL ON TABLE audit_logs FROM allstore_app;
GRANT SELECT, INSERT ON TABLE audit_logs TO allstore_app;
REVOKE ALL ON TABLE settings FROM allstore_app;
-- Seeding inserts the initial settings rows through the runtime role.
GRANT SELECT, INSERT, UPDATE ON TABLE settings TO allstore_app;
-- Public catalogue can be modified by admins through PHP only; the app role
-- still needs DML for the admin panel, which is guarded by Auth::can().
-- Verification queries:
--   SELECT has_table_privilege('allstore_app','orders','INSERT');
--   SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname='allstore_app';
