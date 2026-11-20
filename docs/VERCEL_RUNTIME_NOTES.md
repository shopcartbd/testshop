# Vercel PHP container runtime notes

Reviewed on 2026-11-19 while preparing the current deployment. Vercel's Container Runtime is documented as beta and builds a root `Dockerfile`/`Containerfile` into an OCI image. A root Dockerfile is sufficient for a single-service app with no other framework detected; Vercel selects the Container preset and routes traffic to the image. The default HTTP port is 80 and can be overridden with project `PORT`. Its official PHP guide uses FrankenPHP/PHP 8.4, Caddy `php_server`, Composer lockfiles, a health route, and server-side environment variables. It explicitly says a `vercel.json` is not required for that single-root-Dockerfile case. No local Docker daemon is available here, so the image cannot be built locally; GitHub push only triggers Vercel if the linked Vercel project has its Git integration active.

Sources:

- https://vercel.com/docs/functions/runtimes/container
- https://vercel.com/kb/guide/deploy-php-on-vercel-with-docker
- https://vercel.com/docs/services/config-reference
