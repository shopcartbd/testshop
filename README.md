# AllStore

A premium, responsive PHP storefront foundation for a configurable all-category marketplace. AllStore is a placeholder brand name and can be configured with `APP_NAME`.

## Stack and Vercel compatibility

The application uses PHP 8.4, PostgreSQL through PDO, and Supabase PostgreSQL as an external database. Vercel does not list PHP as an official Functions runtime. Its current documented PHP route is a Docker container using FrankenPHP; this repository includes a root `Dockerfile` and `Caddyfile` for that deployment model. Vercel account setup, project linking, environment variables, and Supabase credentials are still required before a live deployment can be verified.

## Local setup

Requirements: Docker (recommended) or PHP 8.4 with PDO PostgreSQL enabled, plus Composer 2 if dependencies are added.

1. Copy `.env.example` to `.env` and set the database connection values locally.
2. Apply `database/migrations/001_initial_schema.sql` to a Supabase project using its SQL editor or a restricted database role.
3. Start the container with `docker build -t allstore . && docker run --rm -p 8080:80 --env-file .env allstore`.
4. Open `http://localhost:8080`.

Never commit `.env`. Do not use a Supabase service-role key in browser code. Use a least-privilege PostgreSQL login for the PHP server and enforce authorization in PHP; direct PDO connections do not automatically apply PostgREST RLS policies.

## Vercel deployment

Connect/import `shopcartbd/testshop` from the Vercel dashboard after the GitHub changes are pushed. The root Dockerfile should select the Container runtime; use PHP 8.4/FrankenPHP and set the environment variables from `.env.example` in Vercel's server-side environment settings. Vercel can automatically create preview deployments on pushes and a production deployment from the selected production branch. This repository is not yet linked to a Vercel project, so no production URL is claimed.

See [`docs/ENVIRONMENT_VARIABLES.md`](docs/ENVIRONMENT_VARIABLES.md) and [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) before connecting the database.

## Current scope

The storefront source is being built in phases. Only features that are present and tested should be treated as implemented. Payment processing, transactional email, shipping integrations, full account recovery, advanced admin/CMS, uploads, and analytics require additional implementation and provider credentials; they must not be represented as active until completed. See `TODO.md` for the outstanding work.