# AllStore — Vercel Container Runtime / generic OCI deployment.
# Official Vercel KB: root Dockerfile + FrankenPHP + Caddy listening on $PORT.
# Pinned, patched FrankenPHP 1.14 / PHP 8.4 Debian image for reproducible builds.
FROM dunglas/frankenphp:1.14.0-php8.4-trixie

WORKDIR /app

# Production PHP settings and the exact extensions used by the application.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && install-php-extensions pdo_pgsql pgsql pdo_sqlite sqlite3 curl mbstring intl gd zip opcache \
 && { \
      echo 'expose_php=Off'; \
      echo 'display_errors=Off'; \
      echo 'display_startup_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/proc/self/fd/2'; \
      echo 'session.cookie_httponly=1'; \
      echo 'session.cookie_samesite=Lax'; \
      echo 'upload_max_filesize=6M'; \
      echo 'post_max_size=8M'; \
      echo 'memory_limit=256M'; \
      echo 'opcache.enable=1'; \
    } > "$PHP_INI_DIR/conf.d/zz-allstore.ini"

COPY . /app
COPY Caddyfile /etc/caddy/Caddyfile

ENV APP_ENV=production \
    APP_DEBUG=false \
    PORT=80

EXPOSE 80
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
