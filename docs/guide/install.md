# Install

There are two ways to run Magnetite: in one Docker container, or directly with PHP and Node. Docker is the shorter one.

## With Docker

Needs Docker with Compose. The image is built from the source, so the first start takes a few minutes.

    git clone <repository address> magnetite
    cd magnetite
    MAGNETITE_USER=yourname docker compose up -d --build
    docker compose logs app | grep Password

The last command prints the password of the first user. It is printed once. Then open `http://localhost:8080` and sign in.

The first start creates everything it needs: the application key, the SQLite database, the first user and the queue worker that scans the library. Nothing has to be configured.

| Variable | What it does | Default |
|---|---|---|
| `MAGNETITE_USER` | Name of the first user, created on the first start | none |
| `MAGNETITE_PASSWORD` | Password of the first user | generated and printed once |
| `MAGNETITE_PORT` | Port on the host | 8080 |
| `MAGNETITE_MUSIC_DIR` | A folder on the host that holds the music | a Docker volume |

### Music that is already on the disk

    MAGNETITE_MUSIC_DIR=/path/to/music MAGNETITE_USER=yourname docker compose up -d --build

Each user has a folder `users/{username}` inside the music folder, so the files of `yourname` go in `/path/to/music/users/yourname/`. For uploads to work the folder must be writable by uid 33 (`chown -R 33:33 /path/to/music`); the start-up log says so when it is not.

### Where the data is kept

| In the container | What | Kept in |
|---|---|---|
| `/data` | The database and the application key | volume `data` |
| `/app/storage` | Covers and converted files | volume `storage` |
| `/music` | The library, one folder per user | volume `music`, or `MAGNETITE_MUSIC_DIR` |

Back up `data` and the music. `storage` can be rebuilt: covers by a scan, conversions on the next play.

### Reaching it from other machines

The container listens on `127.0.0.1` over plain HTTP. To use it from anywhere else, put a reverse proxy with TLS in front (nginx, Caddy, Traefik), copy `.env.docker.example` to `.env.docker` and set:

    APP_URL=https://music.example.com
    SESSION_SECURE_COOKIE=true

Two things to check on the proxy: its limit on the size of a request body (nginx `client_max_body_size`) must allow the zips to be uploaded, and it should not buffer the responses of `/stream/`.

### More users

    docker compose exec app php artisan magnetite:user ana

It asks for a password; `--generate` makes one and prints it. There is no registration page.

### Updating

    git pull
    docker compose up -d --build

The database is migrated on start. The key, the database and the music stay in their volumes.

## Without Docker

Needs PHP 8.3 or newer with Composer, and Node. ffmpeg and ffprobe are needed to play ALAC in every browser and for the file analysis.

    git clone <repository address> magnetite
    cd magnetite
    composer setup
    php artisan magnetite:user yourname
    mkdir -p storage/app/music/users/yourname
    php artisan magnetite:scan yourname
    composer dev

`composer setup` installs the dependencies, writes `.env`, creates the SQLite database and builds the assets. `magnetite:user` asks for a password. `composer dev` starts the web server, the queue worker and Vite; open the address it prints, usually `http://127.0.0.1:8000`.

The music goes in `storage/app/music/users/yourname`, or in another place set with `MAGNETITE_MUSIC_PATH` in `.env` (the `users/yourname` part stays).

For a server that stays up, run it as any Laravel application: a web server with PHP-FPM, `npm run build` for the assets, and a process manager (systemd, Supervisor) that keeps `php artisan queue:work` running. The queue worker is required: scans started from the web page wait for it.

Next: [First run](first-run.md).
