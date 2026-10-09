# syntax=docker/dockerfile:1

# 1. PHP dependencies (no dev packages). The platform check is skipped here because the final image has the extensions.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY app app
COPY database database
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts

# 2. Front-end build. resources/js imports Livewire from vendor/, so the PHP dependencies come along.
FROM node:22-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY --from=vendor /app/vendor vendor
COPY vite.config.js ./
COPY resources resources
COPY public public
RUN npm run build

# 3. The image that runs: FrankenPHP (Caddy + PHP), ffmpeg for ALAC and file analysis, Node for bin/measure.mjs.
FROM dunglas/frankenphp:1-php8.4 AS app
RUN install-php-extensions gd zip intl opcache pcntl \
    && apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*
COPY --from=node:22-slim /usr/local/bin/node /usr/local/bin/node

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-magnetite.ini"
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor vendor
COPY --from=assets --chown=www-data:www-data /app/public/build public/build

# Folders that hold state. Named volumes copy this ownership on first use, so the unprivileged user can write to them.
RUN mkdir -p /data /music storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /data /music storage bootstrap/cache \
    && su -s /bin/sh www-data -c "php artisan package:discover --ansi"

ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    MAGNETITE_MUSIC_PATH=/music \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:8080 \
    XDG_CONFIG_HOME=/tmp/caddy-config \
    XDG_DATA_HOME=/tmp/caddy-data

USER www-data
EXPOSE 8080
VOLUME ["/data", "/music"]

HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === false ? 1 : 0);'

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
