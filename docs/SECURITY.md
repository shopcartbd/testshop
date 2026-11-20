# Security notes

## Implemented in the source (not a penetration-test result)

- PHP-managed passwords use PHP password hashing; login/reset have rate limiting. Registration creates only customer roles. Privileged bootstrap is CLI-only and refuses a second owner/admin.
- Session cookies are HTTP-only, `SameSite=Lax`, strict-mode and secure when the request is HTTPS. Vercel persistence uses PostgreSQL sessions; the handler takes per-session advisory locks across requests to reduce concurrent session/cart overwrites.
- Mutating HTML/API forms validate CSRF tokens. Admin routes enforce admin/owner roles; account services scope addresses, wishlist, orders, returns and verified reviews to the authenticated account.
- Database reads/writes use PDO parameters for user data. Checkout locks stock and calculates price, discount, shipping, tax and totals server-side in a transaction. Order keys are replay-protected; cancel/state-transition operations update stock/order history transactionally.
- Upload code validates image MIME/type/size/dimensions and generates unique Storage object names. HTML output is escaped by the shared helpers; response security headers include CSP nonce, frame denial and no-store for the application.
- Logs record generic error classes/messages rather than credential values. `.gitignore` excludes environment files, logs, backups, dumps and local tooling folders.

These controls are not a substitute for code audit, staged validation, penetration testing or correct production configuration. Functional and security tests have not been run against Supabase/Vercel.

## Critical deployment configuration

Keep the PostgreSQL password, SMTP password, Storage service-role key and any private access token server-side. Use the dedicated non-superuser runtime role and the four explicit RLS policies described in `docs/SUPABASE_VERCEL_SETUP.md`; do not grant `BYPASSRLS`. Because direct PDO does not carry a Supabase Auth JWT, PHP services—not RLS `auth.uid()`—are responsible for browser-user ownership/role checks. Never expose `app` schema or the application database credential to client code/PostgREST.

The app requires HTTPS in production, an exact `APP_URL`, a stable connection/session mode, backups, alerting and rotation procedures. Session advisory locks require a direct or session-affinity PostgreSQL connection; do not move to transaction pooling without an alternative lock design and concurrency testing.

## Known gaps and not-verified items

No formal SQL injection/XSS/CSRF/IDOR/session-fixation/rate-limit/upload/path-traversal/security review has been executed. In particular, no runtime policy, reset email, Storage bucket policy, secrets scan across Git history, HTTPS/header observation or database-authorization boundary has been verified live. One-time owner creation must be done only from a trusted shell. Rotate any credential ever committed or exposed; deleting it from the latest commit is not sufficient.
