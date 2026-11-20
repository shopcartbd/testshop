# Deployment guide

## Current deployment status

The repository has a root `Dockerfile` and `Caddyfile` for a PHP 8.4 FrankenPHP container. Vercel's Container Runtime is documented as beta. The project's current Vercel linkage, environment values, image build, production URL and deployment status are unknown from this environment. A push to the connected `main` branch may start a Vercel deployment; check Vercel's Deployments page and deployment logs to verify it. Git push success alone is not deployment success.

See [docs/VERCEL_RUNTIME_NOTES.md](VERCEL_RUNTIME_NOTES.md) for the reviewed official documentation and its source links. This single root-Dockerfile setup is documented without a `vercel.json`.

## Before connecting production

1. Validate migrations 001 then 002 and the optional seed on a separate Supabase staging project.
2. Create `allstore_app` as a non-superuser; grant required schema/table/sequence access and apply the four PHP runtime RLS policies in [the setup guide](SUPABASE_VERCEL_SETUP.md). Do not use the anon key as a database login or expose the runtime password.
3. In Vercel, set the required server-side variables in [docs/ENVIRONMENT_VARIABLES.md](ENVIRONMENT_VARIABLES.md). Set `SESSION_DRIVER=database`; ensure a direct or Supabase Session pooler connection supports the session advisory-lock behavior and can serve the expected connection volume.
4. Confirm Vercel builds the root Dockerfile, runtime PHP extensions/dependencies are available, it binds to the supplied `PORT`, and `GET /health` returns 2xx without connecting to the database.
5. Run `php scripts/bootstrap-admin.php` from a trusted environment pointed at the staging DB; verify owner login and least-privilege roles.
6. Set real shipping prices/regions and publish accurate delivery/return/privacy terms before launch.
7. Test customer/admin flows with non-production data. Configure/test SMTP or Storage only if needed. Check HTTPS, static images, cache behavior, logs and errors.
8. Only after staging acceptance, repeat on production using the store's backup/change process.

## External services and limits

Required: a Supabase PostgreSQL project and server-side connection credentials. Optional: authenticated SMTP and Supabase Storage. Online payments, payment webhooks, actual courier APIs, Vercel deployment credentials/project access and production monitoring are not configured. COD is the only checkout payment choice. No build, live migration, Vercel deployment or production route check has been verified here.
