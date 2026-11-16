#!/bin/sh
# Starts the image the way the README says (one `docker run`, empty volumes), waits until it is healthy, signs in over HTTP,
# restarts it and signs in again (the application key and the user must survive). CI runs this so the quick start cannot rot.
#
#   bin/smoke-docker.sh [image]        # default: magnetite:smoke (build it first: docker build -t magnetite:smoke .)
set -eu

image="${1:-magnetite:smoke}"
port="${SMOKE_PORT:-18080}"
name="magnetite-smoke-$$"
base="http://127.0.0.1:$port"
jar="$(mktemp)"
pass="smoke-$(date +%s)-pass"

cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker volume rm "$name-data" "$name-music" >/dev/null 2>&1 || true
    rm -f "$jar"
}
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; docker logs --tail 40 "$name" >&2 || true; exit 1; }

wait_healthy() {
    i=0
    while [ "$i" -lt 60 ]; do
        [ "$(docker inspect -f '{{.State.Health.Status}}' "$name")" = healthy ] && return 0
        i=$((i + 1)); sleep 3
    done
    fail "the container did not become healthy in 3 minutes"
}

code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

sign_in() {
    : > "$jar"
    token="$(curl -s -c "$jar" "$base/login" | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -1)"
    [ -n "$token" ] || fail "no CSRF token on /login"
    status="$(curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' --data-urlencode "_token=$token" --data-urlencode "username=me" --data-urlencode "password=$pass" "$base/login")"
    [ "$status" = 302 ] || fail "sign-in answered $status, expected a redirect"
    [ "$(code -b "$jar" "$base/library")" = 200 ] || fail "/library is not open after signing in"
}

docker run -d --name "$name" -p "127.0.0.1:$port:8080" -v "$name-data:/data" -v "$name-music:/music" \
    -e MAGNETITE_USER=me -e "MAGNETITE_PASSWORD=$pass" "$image" >/dev/null

wait_healthy
[ "$(code "$base/up")" = 200 ] || fail "/up is not 200"
[ "$(code "$base/login")" = 200 ] || fail "/login is not 200"
[ "$(code "$base/setup")" = 404 ] || fail "/setup should be closed once a user exists"
[ "$(code "$base/library/tracks/1/spectrum")" != 200 ] || fail "a private route answered a visitor"
sign_in
echo "ok: first start"

docker restart "$name" >/dev/null
wait_healthy
sign_in
echo "ok: after a restart (key and user kept)"
