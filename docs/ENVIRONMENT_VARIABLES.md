# Environment variables

Set these values on the server only. In Vercel, add them under the project's Environment Variables settings and select the correct environments. For local Docker, copy `.env.example` to an ignored `.env` file.

`APP_NAME` controls the visible brand name and page title; default: `AllStore`. `APP_ENV` distinguishes production/local configuration; the application must run with debug output disabled. `APP_CURRENCY` is the display currency; default: `BDT`. `APP_URL` is the canonical public site URL. `LOG_LEVEL` controls server log verbosity.

For PostgreSQL, provide either `SUPABASE_DB_URL` in PostgreSQL URI form (including `sslmode=require`) or all of `SUPABASE_DB_HOST`, `SUPABASE_DB_PORT`, `SUPABASE_DB_NAME`, `SUPABASE_DB_USER`, `SUPABASE_DB_PASSWORD`, and `SUPABASE_DB_SSLMODE`. Use a narrowly privileged dedicated database login. The runtime connection is server-side and uses TLS. Do not put the database password, service-role key, or database URL in HTML, JavaScript, public GitHub files, screenshots, or logs.

No Supabase project URL, public/secret API key, payment key, mail account, courier key, or admin bootstrap credentials are currently required by the storefront code. Supabase Storage, auth providers, payments, mail, and shipping are not connected in this iteration; do not invent those values. A `.env` file must never be committed.