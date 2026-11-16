# Docker

One image (`Dockerfile`) and one container (`compose.yaml`): FrankenPHP (Caddy with PHP) serves the web app and a queue worker runs beside it for library scans. SQLite lives in a volume, so there is no database container. This is the shortest way to run Magnetite; the demo server itself runs without Docker (`docs/bare-metal.md`).

## Quick start

    git clone <the repository> magnetite && cd magnetite
    docker compose up -d --build   # then open the address: /setup creates the first user (administrator)
    # or from the shell: MAGNETITE_USER=jose docker compose up -d --build
    docker compose logs app | grep Password        # the password of the first user, printed once
    # open http://localhost:8080 and sign in

The first start makes everything it needs: the application key (kept in the `data` volume as `app.key`), the SQLite file, the tables, the first user (an administrator) and the queue worker. Set `MAGNETITE_PASSWORD` as well to choose the password yourself, and `MAGNETITE_PORT` to use another port than 8080.

Music: upload zips on the Upload page (the default `music` volume is writable), or put files into `users/jose/` of the music volume or folder and press "Scan now" on the Upload page. For a folder that already holds music: `MAGNETITE_MUSIC_DIR=/path/to/music docker compose up -d`; it must be writable by uid 33 (`chown -R 33:33 /path/to/music`) for uploads, and the start-up log warns when it is not. Each user has a folder `users/{username}` in it; an old flat library is moved there with `magnetite:claim` (`docs/multi-user.md`).

More users: `docker compose exec app php artisan magnetite:user ana` (asks for a password; `--generate` makes one).

The app listens on `127.0.0.1:8080` (plain HTTP). For anything beyond your own machine put a reverse proxy with TLS in front, set `APP_URL` and `SESSION_SECURE_COOKIE=true`, in a file named `.env.docker` (copy `.env.docker.example`).

## What the start-up script does

`docker/entrypoint.sh`, for the web server only: uses `APP_KEY` when it is set, otherwise reads or makes `/data/app.key`; defaults `APP_URL` to `http://localhost:8080`; creates the SQLite file; runs `migrate --force`; creates the user named in `MAGNETITE_USER` when there is none of that name (with `--admin`, and a random password printed once when `MAGNETITE_PASSWORD` is empty); caches config, routes and views with `optimize`; warns when `/music` is not writable; then starts the queue worker in a loop (it is started again if it stops) and runs the server. Other commands (`docker compose exec app php artisan ...`) skip all of that.

To run the queue as its own container instead, set `MAGNETITE_WORKER=0` on the web service and add a second service from the same image with `command: ["php", "artisan", "queue:work", "--tries=1", "--timeout=0", "--sleep=3", "--max-time=3600"]`, the same volumes and `depends_on` the web service being healthy. It needs the same `APP_KEY` (or the `data` volume, which holds `app.key`).

## What is where

| Path in the container | What | Persistence |
|---|---|---|
| `/data` | SQLite database (users, library, sessions, cache, queue) and `app.key` | named volume `data` |
| `/app/storage` | covers, ALAC conversions, framework files | named volume `storage` |
| `/music` | the library, one folder per user (`users/{username}`); each user's uploads are unpacked into their own folder | named volume `music`, or a folder (`MAGNETITE_MUSIC_DIR`) |

The processes run as `www-data` (uid 33), not root. Back up `data` (the database and the key) and the music; `storage` can be rebuilt (covers by a scan, conversions on first play).

## Inside the image

PHP 8.4 with `gd`, `zip`, `intl`, `opcache`, `pcntl`; ffmpeg and ffprobe (ALAC to FLAC, `magnetite:analyze`); `fpcalc` (album fingerprints, used only when `MAGNETITE_ACOUSTID_KEY` is set); Node 22 (`bin/measure.mjs`). Size is about 1.7 GB, mostly the base image and ffmpeg. Logs go to stderr (`docker compose logs`).

## A library in Ferrite

Nothing in the image changes: the container only needs outbound HTTPS to Ferrite. What to know on a running deployment:

- **Update:** `git pull && docker compose up -d --build`. The container migrates on start.
- **Point a user at Ferrite** (the token is asked for, so it stays out of the shell history): `docker compose exec -u www-data app php artisan magnetite:storage jose --ferrite=https://files.example.com`. It prints the Ferrite folder, access and expiry, or why it failed. Then `docker compose exec -u www-data app php artisan magnetite:scan jose --queue`. Go back with `--local`.
- **The token is stored encrypted with the application key.** Keep the key as it is (`/data/app.key` is in the `data` volume, so back that up), or the stored tokens cannot be read and `magnetite:storage` has to be run again.
- **The worker matters more with Ferrite:** scans and the prefetch of the next track run there. Without it a track still plays (through the proxy) but the next one is not fetched ahead.
- **Cache:** `/app/storage/app/private/ferrite-cache` in the `storage` volume, capped by `MAGNETITE_CACHE_MAX_GB` (default 10; set it to what the disk can spare). Clear it from the Storage page.
- **Streaming:** `/stream` passes Ferrite's bytes through the container with Range, so a reverse proxy in front must not buffer whole responses (nginx: `proxy_buffering off` for `/stream/`, or at least do not cache), or the first byte waits for the whole file.
- A library on Ferrite no longer needs `/music` for that user; users on the local store still do.

## Limits

- `MAGNETITE_UPLOAD_MAX_MB` (default 2048) sets PHP's `upload_max_filesize` as well as the app's own limit; `post_max_size` is unlimited and the number of files is capped at 20.
- Caddy caps request bodies at 16 GB for `/upload` (`MAGNETITE_UPLOAD_BODY_MAX`) and 4 MB for everything else, because PHP reads a whole upload to disk before Laravel can check the session. The config was checked in the running container; that the cut-off actually fires was not demonstrated (a 6 MB body to `/login` still ended in a 419, the CSRF answer, not a 413).
- A proxy in front has its own limits (nginx `client_max_body_size`, Cloudflare 100 MB on the free plan).
- `TRUSTED_PROXIES` (default: the private address ranges) says whose `X-Forwarded-*` headers Laravel believes, which gives the real client address to the sign-in throttle and `https` URLs. Verified with a forwarded `https` host. If the container's port is reachable directly from the internet, narrow it to the proxy's address.
- SQLite runs in WAL mode with a 5 s busy timeout (`DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`) because the web server and the queue worker write to the same file. Keep `/data` on a local volume, not NFS.

## Updating

    git pull && docker compose up -d --build

Migrations run on start, and the key, the database and the music stay in their volumes. To run a command: `docker compose exec -u www-data app php artisan ...`.

## CI and registry

`.github/workflows/ci.yml`: every push and pull request runs Pint, Pest (with ffmpeg installed, so the analysis tests run) and the Node tests on PHP 8.4. If they pass, the image is built: pull requests only build `linux/amd64` to prove it works; pushes to `main` and `v*` tags publish `linux/amd64` and `linux/arm64` to `ghcr.io/<owner>/<repo>` (tags `latest`, the short commit hash and, for tags, the version). It uses the built-in `GITHUB_TOKEN`, so no secrets are needed; the package is private until you change its visibility. To deploy from the registry, replace `build: .` in `compose.yaml` with the image name and run `docker compose pull && docker compose up -d`.

Not run yet: the workflow has never run on GitHub (only its YAML was parsed and its test steps run locally), and the arm64 build under emulation is untested and slow.

## Not done

No health check for the queue worker inside the container (the loop starts it again when it stops, and `--max-time=3600` ends it every hour on purpose).
