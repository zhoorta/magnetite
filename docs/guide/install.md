# Install

There are two ways to run Magnetite: in one Docker container, or directly with PHP and Node. Docker is the shorter one.

## With Docker

Needs Docker. The image is published for amd64 and arm64:

    docker run -d --name magnetite -p 8080:8080 \
      -v magnetite-data:/data -v magnetite-music:/music \
      ghcr.io/zhoorta/magnetite

The two named volumes keep the database and the music when the container is replaced. To use a folder that already has music, put its path in place of `magnetite-music` (see below).

To build it from the source with Compose instead (the first start takes a few minutes):

    git clone https://github.com/zhoorta/magnetite.git
    cd magnetite
    docker compose up -d --build

Then open `http://localhost:8080`. While there is no user, you land on a set-up page where you choose a username and a password; that first user is the administrator.

The first start creates everything it needs: the application key, the SQLite database and the queue worker that scans the library. Nothing has to be configured.

Until that form is finished, anyone who can reach the address can fill it in and become the administrator. On a server that is already public, do it at once, or protect it with `MAGNETITE_SETUP_TOKEN` (below), or create the user from the shell with `MAGNETITE_USER`. The page is gone (404) as soon as a user exists.

| Variable | What it does | Default |
|---|---|---|
| `MAGNETITE_USER` | Name of the first user, created on the first start from the shell instead of the set-up page | none |
| `MAGNETITE_PASSWORD` | Password of that user | generated and printed once in `docker compose logs app` |
| `MAGNETITE_PUBLIC_SITE` | `true` turns on the start page, the demo library and the documentation for visitors who are not signed in; otherwise they go straight to the sign-in page | `false` |
| `MAGNETITE_DEFAULT_QUOTA_GB` | Disk limit in GB for every new user except administrators; changed per user on the Users page | none |
| `MAGNETITE_SETUP_TOKEN` | When set, the set-up page asks for it too | none |
| `MAGNETITE_PORT` | Port on the host | 8080 |
| `MAGNETITE_MUSIC_DIR` | A folder on the host that holds the music | a Docker volume |

### Music that is already on the disk

    MAGNETITE_MUSIC_DIR=/path/to/music MAGNETITE_USER=yourname docker compose up -d --build

Each user has a folder `users/{username}` inside the music folder, so the files of `yourname` go in `/path/to/music/users/yourname/`. For uploads to work the folder must be writable by uid 33 (`chown -R 33:33 /path/to/music`); the start-up log says so when it is not.

### Volumes

| In the container | What | Kept in |
|---|---|---|
| `/data` | The database and the application key | volume `data` |
| `/app/storage` | Covers and converted files | volume `storage` |
| `/music` | The library, one folder per user | volume `music`, or `MAGNETITE_MUSIC_DIR` |

Back up `data` and the music. `storage` can be rebuilt: covers by a scan, conversions on the next play.

### Reaching it from other machines

The container speaks plain HTTP. With Compose it is published on `127.0.0.1` only; with `docker run -p 8080:8080` it is published on every interface, so use `-p 127.0.0.1:8080:8080` on a server. To use it from anywhere else, put a reverse proxy with TLS in front (Caddy, nginx, Traefik) and set the public address:

    APP_URL=https://music.example.com
    SESSION_SECURE_COOKIE=true

With Compose, copy `.env.docker.example` to `.env.docker` and write them there; with `docker run`, pass them as `-e`.

Before the server is reachable, create the first user (`MAGNETITE_USER` and `MAGNETITE_PASSWORD`), because until a user exists anyone who finds the address can use the set-up page and become the administrator.

A complete example on a VPS, with the container bound to localhost:

    docker run -d --name magnetite --restart unless-stopped \
      -p 127.0.0.1:8080:8080 \
      -v magnetite-data:/data -v magnetite-music:/music \
      -e MAGNETITE_USER=yourname -e MAGNETITE_PASSWORD='a-long-password' \
      -e APP_URL=https://music.example.com -e SESSION_SECURE_COOKIE=true \
      ghcr.io/zhoorta/magnetite

Point a DNS record for the name at the server, install Caddy (`apt install caddy`) and put this in `/etc/caddy/Caddyfile`:

    music.example.com {
        reverse_proxy 127.0.0.1:8080
    }

Then `systemctl reload caddy`. Caddy gets and renews the certificate by itself, has no upload limit by default and does not buffer streams. Allow only ports 22, 80 and 443 in the firewall; 8080 stays closed.

With another proxy, two things to check: its limit on the size of a request body (nginx `client_max_body_size`) must allow the zips to be uploaded, and it should not buffer the responses of `/stream/` (nginx `proxy_buffering off`).

### More users

    docker compose exec app php artisan magnetite:user ana

It asks for a password; `--generate` makes one and prints it. The easier way: an administrator opens **Users** in the top bar, makes an invitation and sends the link. It works once, for 1, 7 or 30 days, and the person chooses their own username and password. There is no open registration page.

### Administrators and deleting users

On **Users** an administrator can make another user an administrator (or take it away) and delete a user by typing their name. Deleting removes the account, its library entries, playlists and shares; the music files stay on the disk, in `users/NAME` inside the music folder. You cannot change or delete your own account there, so there is always an administrator.

### Passwords

Everyone can change their own on the **Password** page, in the user menu (the person icon) in the top bar; that signs their other devices out, apps included. A forgotten password: an administrator opens **Users**, presses **Reset password** next to the name and sends the one-time link (valid 24 hours); the person chooses a new password and every device is signed out. The administrator never sees it. An administrator who is locked out uses the shell: `php artisan magnetite:user NAME --reset`.

### Updating

    git pull
    docker compose up -d --build

With the published image: `docker pull ghcr.io/zhoorta/magnetite`, then stop, remove and start the container again with the same `docker run` command. The volumes keep everything. Commands written here as `docker compose exec app …` are `docker exec magnetite …` for a container started with `docker run`.

The database is migrated on start. The key, the database and the music stay in their volumes.

## Without Docker

Needs PHP 8.3 or newer with Composer, and Node. ffmpeg and ffprobe are needed to play ALAC in every browser and for the file analysis. `fpcalc` (on Debian and Ubuntu the package `libchromaprint-tools`) is optional: with it and an AcoustID key, untagged albums can be identified by audio fingerprint (see [Configuration](configuration.md)).

    git clone https://github.com/zhoorta/magnetite.git
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
