#!/bin/sh
# Prepares the container, then runs the command. Only the web server and the queue worker get the full set-up, so
# `docker compose run --rm app php artisan ...` stays quick and works before a key exists (key:generate --show).
set -eu

mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

case "$*" in
    frankenphp*|*queue:work*)
        : "${APP_KEY:?APP_KEY is not set. Generate one with: docker compose run --rm app php artisan key:generate --show}"

        db="${DB_DATABASE:-/data/database.sqlite}"
        [ -f "$db" ] || touch "$db"

        # Only the web container migrates; the worker waits for it to be healthy.
        case "$*" in frankenphp*) php artisan migrate --force --no-interaction ;; esac

        php artisan optimize
        ;;
esac

exec "$@"
