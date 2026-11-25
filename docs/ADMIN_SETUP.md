# Administrator setup

AllStore deliberately ships **no default administrator password**. Seed data creates roles and permissions but no admin account. The first `super_admin` must be created once from a secure shell with the CLI bootstrap script.

## One-time bootstrap

Run after migrations and seed, with the application able to reach the target database:

```bash
ADMIN_BOOTSTRAP_EMAIL='owner@example.com' \
ADMIN_BOOTSTRAP_PASSWORD='use-a-long-unique-secret' \
ADMIN_BOOTSTRAP_NAME='Store Owner' \
php scripts/bootstrap_admin.php
```

Requirements and behaviour:

- `ADMIN_BOOTSTRAP_EMAIL` must be a valid email.
- `ADMIN_BOOTSTRAP_PASSWORD` must be at least 12 characters; generate a unique value and store it in a password manager.
- The script is CLI-only (`PHP_SAPI === 'cli'`), so there is no web route to trigger it.
- It refuses to run if `settings.bootstrap_completed` is `1` or if a `super_admin`/`admin` already exists.
- On success it creates an active `super_admin`, sets `bootstrap_completed=1`, and writes a critical audit entry.
- Unset the bootstrap environment variables immediately after use. The web application never reads them.

For a production Supabase database, run this from a trusted machine with production `DATABASE_URL`/`SUPABASE_DB_*` environment variables. Do not rely on a Vercel function's ephemeral filesystem or expose the bootstrap variables to browser code.

## Arena local preview

For this workspace only, the script was run once against the local SQLite database with a generated password. The credentials are outside the project at:

```text
/home/user/allstore-local-admin.txt
```

Use `/admin/login` or `POST /api/auth/login` with those credentials to explore the live preview. The file and local SQLite database are excluded from `/home/user/allstore.zip`; a fresh installation must run the bootstrap command above.

## Roles and permissions

The seed creates `super_admin`, `admin`, `staff`, and `customer` roles plus permission definitions. `super_admin` is granted every permission mapping; `staff` receives a limited operational subset; `customer` has no admin permissions. Admin pages enforce RBAC at the page/helper level and admin API handlers call `api_require_admin()` with a specific permission. `admin_header()` also enforces administrator access as defence in depth.

Additional admin users, role assignments, and permission changes are managed from `/admin/admin-users`, `/admin/roles`, and `/admin/permissions` by an authorised administrator. Activity is recorded in `/admin/activity-logs`.

## Security notes

- Password hashes use PHP `password_hash()`/`password_verify()`; no reversible password storage.
- Login failures are generic and rate-limited by IP/email-domain buckets.
- Sessions are DB-backed; login and privilege changes rotate the session identifier and CSRF token.
- Changing a password invalidates other active sessions; password reset invalidates all sessions for that user.
- Admin pages and mutations are no-cache, CSRF-protected, permission-checked and audit-logged.
- For production, use HTTPS, a unique `APP_KEY`, and the least-privilege DB role from `database/migrations/004_least_privilege.sql` after replacing its placeholder password.
