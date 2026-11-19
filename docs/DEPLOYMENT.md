# Deployment

## Runtime choice

Vercel's official Functions runtime documentation lists Node.js, Bun, Python, Rust, Go, Ruby, Wasm, Edge, and Container, but not a native PHP Function runtime. The supported path for this PHP app is a root Dockerfile using the Vercel Container runtime. Vercel's current PHP-on-Vercel guide demonstrates PHP 8.4 with FrankenPHP and Docker. This repository includes `Dockerfile`, `Caddyfile`, and a `/health` route.

Official references: [Vercel runtime catalog](https://vercel.com/docs/functions/runtimes) and [Deploy PHP on Vercel with Docker](https://vercel.com/kb/guide/deploy-php-on-vercel-with-docker).

## Connect and configure

1. Push the repository to GitHub, then open the Vercel dashboard and select **New Project**.
2. Import `shopcartbd/testshop`. The project root is the repository root; the root `Dockerfile` should be selected by Vercel's Container preset.
3. Add the variables described in `ENVIRONMENT_VARIABLES.md` to server environments. Configure the Supabase database host, password, and least-privilege login only in Vercel's server-side environment settings.
4. Deploy a preview first. Verify `/health`, the home page, `/shop`, and the static logo/CSS paths. Confirm the application can connect to the intended database before changing the production branch or custom domain.
5. Once preview verification passes, use Vercel's production deployment controls. The production URL and deployment status must be copied from Vercel; they must not be guessed.

Vercel's Git integration can create preview deployments on pushes and deploy the configured production branch. Actual automatic deployment is contingent on connecting the repository to the user's Vercel account. This task has no authenticated Vercel CLI/project, so no live deployment has been made or verified.

## Container notes

The container listens on `PORT` (default 80) using FrankenPHP/Caddy. Vercel handles public HTTPS. The filesystem is treated as ephemeral; durable catalog and order data belongs in PostgreSQL/Supabase Storage, not container disk. Private pages/API responses should never be shared-cacheable. The current storefront does not yet provide authenticated customer/admin pages, but future private responses must use `Cache-Control: private, no-store`.
