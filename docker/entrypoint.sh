#!/bin/sh
# Prepares the container, then runs the command. Only the web server and a separate queue worker get the full
# set-up, so `docker compose run --rm app php artisan ...` stays quick.
#
# First start, with nothing configured: makes an application key (kept in the data volume), creates the SQLite
# file, migrates, creates the first user from MAGNETITE_USER (and MAGNETITE_PASSWORD, or a random one that is
# printed once) when that is set, otherwise the first visitor creates it at /setup and starts a queue worker next to the web server.
set -eu

mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

db="${DB_DATABASE:-/data/database.sqlite}"
keyfile="$(dirname "$db")/app.key"

case "$*" in
    frankenphp*|*queue:work*)
        # The key: given in APP_KEY, or made on the first start and kept next to the database.
        if [ -z "${APP_KEY:-}" ]; then
            if [ ! -s "$keyfile" ]; then
                case "$*" in
                    frankenphp*)
                        (umask 077 && php artisan key:generate --show > "$keyfile")
                        echo "Created an application key in $keyfile (keep it with the database: sessions and tokens depend on it)."
                        ;;
                    *) echo "No APP_KEY, and no $keyfile yet: start the web container first." >&2; exit 1 ;;
                esac
            fi
            APP_KEY="$(cat "$keyfile")"
            export APP_KEY
        fi
        : "${APP_URL:=http://localhost:8080}"
        export APP_URL

        [ -f "$db" ] || touch "$db"

        # Only the web container migrates and creates the first user; a separate worker waits for it to be healthy.
        case "$*" in
            frankenphp*)
                php artisan migrate --force --no-interaction

                if [ -n "${MAGNETITE_USER:-}" ]; then
                    if [ -n "${MAGNETITE_PASSWORD:-}" ]; then
                        php artisan magnetite:user "$MAGNETITE_USER" --password="$MAGNETITE_PASSWORD" --admin --if-missing
                    else
                        php artisan magnetite:user "$MAGNETITE_USER" --generate --admin --if-missing
                    fi
                else
                    # No MAGNETITE_USER: the first user is created in the browser, at /setup.
                    php artisan magnetite:setup-hint
                fi
                ;;
        esac

        php artisan optimize

        # The music folder has to be writable for uploads: say so now, not when the first zip fails.
        [ -w "${MAGNETITE_MUSIC_PATH:-/music}" ] || echo "WARNING: ${MAGNETITE_MUSIC_PATH:-/music} is not writable by this user (uid $(id -u)): uploads will fail. Fix: chown -R $(id -u):$(id -g) /path/to/your/music on the host." >&2

        case "$*" in
            frankenphp*)
                # One container is enough for most: the queue (library scans, the prefetch of the next track) runs beside
                # the web server and is started again if it stops. Set MAGNETITE_WORKER=0 to run it as a separate container.
                if [ "${MAGNETITE_WORKER:-1}" = "1" ]; then
                    (while true; do
                        php artisan queue:work --tries=1 --timeout=0 --sleep=3 --max-time=3600 || true
                        sleep 2
                    done) &
                fi
                ;;
        esac
        ;;
esac

exec "$@"
