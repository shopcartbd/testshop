# Security notes

Implemented in the current storefront source: PDO prepared statements with emulated prepares disabled; server-side database credentials; TLS-required PostgreSQL connection configuration; HTML escaping for rendered untrusted data; category/search allowlisting and length limits; CSRF token validation for cart writes; secure/HttpOnly/SameSite session cookies; CSP, frame, referrer, permissions, and content-type headers; stock-based quantity bounds when reading product data; and non-sensitive generic errors in the UI/logs.

The SQL schema enables Supabase RLS policies for user-owned records. The web application currently uses a server-side PDO connection. Direct database access does not automatically inherit PostgREST RLS, so RLS must not be used as a substitute for PHP authorization. A least-privilege database role remains mandatory.

Not yet implemented: customer/admin authentication and role checks; account ownership checks; login/search/checkout rate limits; full checkout idempotency and transaction-safe stock updates; secure file uploads; Storage policies; admin bootstrap; payment-provider webhooks; password reset/email; security testing; deployment log review. Do not expose this site as a production transaction system until these controls and a real checkout have been built and audited.

The PHP container should run with production PHP settings and must not display stack traces or secrets. Never commit `.env` or a credential. Rotate any credential that is accidentally published; deleting a leaked value from the latest commit does not revoke it.