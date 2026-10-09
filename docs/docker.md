# Docker image

One image (`Dockerfile`), two containers (`compose.yaml`): `app` (FrankenPHP, which is Caddy with PHP) and `worker` (`queue:work`, runs the library scans). SQLite lives in a volume, so there is no database container.

## Deploy

    cp .env.docker.example .env.docker
    docker compose run --rm app php artisan key:generate --show    # paste into APP_KEY, set APP_URL
    mkdir music && chown -R 33:33 music                              # or MAGNETITE_MUSIC_DIR=/path/to/music
    docker compose up -d --build
    docker compose exec app php artisan magnetite:user jose

The app listens on `127.0.0.1:8080` (plain HTTP, `MAGNETITE_PORT` to change). Put a reverse proxy with TLS in front; the container does not do HTTPS. Behind HTTPS keep `SESSION_SECURE_COOKIE=true`.

What the start-up script (`docker/entrypoint.sh`) does: refuses to start without `APP_KEY`, creates the SQLite file, runs `migrate --force` (web container only; the worker waits until it is healthy), then `artisan optimize` (config, route, view and event caches). Other commands (`docker compose exec app php artisan ...`) skip all that.

## What is where

| Path in the container | What | Persistence |
|---|---|---|
| `/data` | SQLite database (users, library, sessions, cache, queue) | named volume `data` |
| `/app/storage` | covers, ALAC conversions, framework files | named volume `storage` |
| `/music` | the library; uploads are unpacked here | bind mount (`MAGNETITE_MUSIC_DIR`, default `./music`) |

The processes run as `www-data` (uid 33), not root. The music folder has to be writable by that uid or uploads fail with "not writable". Back up `data` and the music folder; `storage` can be rebuilt (covers by a scan, conversions on first play).

## Inside the image

PHP 8.4 with `gd`, `zip`, `intl`, `opcache`, `pcntl`; ffmpeg and ffprobe (ALAC to FLAC, `magnetite:analyze`); Node 22 (`bin/measure.mjs`). Size is about 1.7 GB, mostly the base image and ffmpeg. Logs go to stderr (`docker compose logs`).

## Limits

- `MAGNETITE_UPLOAD_MAX_MB` (default 2048) sets PHP's `upload_max_filesize` as well as the app's own limit; `post_max_size` is unlimited and the number of files is capped at 20.
- Caddy caps request bodies at 16 GB for `/upload` (`MAGNETITE_UPLOAD_BODY_MAX`) and 4 MB for everything else, because PHP reads a whole upload to disk before Laravel can check the session. The config was checked in the running container; that the cut-off actually fires was not demonstrated (a 6 MB body to `/login` still ended in a 419, the CSRF answer, not a 413).
- A proxy in front has its own limits (nginx `client_max_body_size`, Cloudflare 100 MB on the free plan).
- `TRUSTED_PROXIES` (default: the private address ranges) says whose `X-Forwarded-*` headers Laravel believes, which gives the real client address to the sign-in throttle and `https` URLs. Verified with a forwarded `https` host. If the container's port is reachable directly from the internet, narrow it to the proxy's address.
- SQLite runs in WAL mode with a 5 s busy timeout (`DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`) because the web and worker containers write to the same file. Keep `/data` on a local volume, not NFS.

## Updating

    git pull && docker compose up -d --build

Migrations run on start. To run a command: `docker compose exec -u www-data app php artisan ...` (add `-it` is automatic with Compose).

## CI and registry

`.github/workflows/ci.yml`: every push and pull request runs Pint, Pest (with ffmpeg installed, so the analysis tests run) and the Node tests on PHP 8.4. If they pass, the image is built: pull requests only build `linux/amd64` to prove it works; pushes to `main` and `v*` tags publish `linux/amd64` and `linux/arm64` to `ghcr.io/<owner>/<repo>` (tags `latest`, the short commit hash and, for tags, the version). It uses the built-in `GITHUB_TOKEN`, so no secrets are needed; the package is private until you change its visibility. To deploy from the registry, replace `build: .` in `compose.yaml` with the image name and run `docker compose pull && docker compose up -d`.

Not run yet: the workflow has never run on GitHub (only its YAML was parsed and its test steps run locally), and the arm64 build under emulation is untested and slow.

## Not done

No health check for the worker (it is plain `queue:work` with `--max-time=3600`, restarted by Docker).
