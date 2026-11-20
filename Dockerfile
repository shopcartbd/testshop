FROM dunglas/frankenphp:1-php8.4-alpine AS base
WORKDIR /app
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'upload_max_filesize=6M\npost_max_size=7M\n' > "$PHP_INI_DIR/conf.d/zzz-allstore.ini" \
    && install-php-extensions pdo_pgsql mbstring curl fileinfo

FROM base AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

FROM base AS runtime
COPY --from=dependencies /app/vendor ./vendor
COPY Caddyfile /etc/caddy/Caddyfile
COPY app ./app
COPY views ./views
COPY public ./public
ENV PORT=80
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
