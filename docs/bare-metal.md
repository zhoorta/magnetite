# Bare-metal install (Ubuntu, no Docker)

How the demo server runs (since 2026-10-09): nginx + PHP-FPM 8.5 + MySQL + a systemd queue worker, code from GitHub with a deploy key. Libraries live in Ferrite (`files.example.com`, same host, Germany), so there is no music folder on this server. The Docker image (`docs/docker.md`) still works; this is the alternative for a shared server with other sites.

Layout: Linux user `magnetite` (home `/home/magnetite`), code in `/var/www/magnetite/app` (git checkout, nginx root `app/public`), PHP temp files in `/var/www/magnetite/tmp`, cache of Ferrite files in `app/storage/app/private/ferrite-cache` (`MAGNETITE_CACHE_MAX_GB`).

## 1. Packages

    sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
    sudo apt install -y nginx mysql-server git unzip curl ffmpeg libchromaprint-tools \
      php8.5-fpm php8.5-cli php8.5-mysql php8.5-gd php8.5-zip php8.5-intl php8.5-mbstring php8.5-xml php8.5-curl php8.5-opcache
    curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
    curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs

`ffmpeg` (ALAC, analysis), Node (`bin/measure.mjs`) and `fpcalc` must be on the PATH of the `magnetite` user.

## 2. User and database

    sudo adduser --system --group --home /home/magnetite --shell /bin/bash magnetite
    sudo usermod -aG magnetite www-data            # nginx reads public/ through the group
    sudo mkdir -p /var/www/magnetite/app /var/www/magnetite/tmp
    sudo chown -R magnetite:magnetite /var/www/magnetite

    DBPASS=$(openssl rand -base64 24 | tr -d '/+=') && sudo mysql -e "
    CREATE DATABASE IF NOT EXISTS magnetite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'magnetite'@'localhost' IDENTIFIED BY '${DBPASS}';
    GRANT ALL PRIVILEGES ON magnetite.* TO 'magnetite'@'localhost';
    FLUSH PRIVILEGES;" && echo "DB_PASSWORD=${DBPASS}"

## 3. Code (GitHub deploy key)

As `magnetite` (`sudo -iu magnetite`):

    ssh-keygen -t ed25519 -C "magnetite deploy" -f ~/.ssh/magnetite_deploy -N ""
    cat ~/.ssh/magnetite_deploy.pub                 # GitHub: repo > Settings > Deploy keys, read-only

`~/.ssh/config` (the `Host` must be the name used in the clone URL; a deploy key fits one repo only):

    Host github.com
        HostName github.com
        User git
        IdentityFile ~/.ssh/magnetite_deploy
        IdentitiesOnly yes

    chmod 600 ~/.ssh/config && ssh-keyscan github.com >> ~/.ssh/known_hosts
    ssh -T git@github.com
    cd /var/www/magnetite && git clone git@github.com:zhoorta/magnetite.git app && cd app
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build
    cp .env.example .env && php artisan key:generate

`.env`:

    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://music.example.com
    LOG_LEVEL=warning
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=magnetite
    DB_USERNAME=magnetite
    DB_PASSWORD=...
    QUEUE_CONNECTION=database
    SESSION_DRIVER=database
    CACHE_STORE=database
    MAGNETITE_CACHE_MAX_GB=10

No `MAGNETITE_MUSIC_PATH` when every library is in Ferrite. Then:

    chmod -R ug+rwX storage bootstrap/cache
    php artisan migrate --force && php artisan optimize

## 4. PHP-FPM pool

A separate pool (own socket), so other sites on the same FPM are untouched: do not disable `www.conf`, and do not put these limits in `conf.d` (they would apply to every pool). `/etc/php/8.5/fpm/pool.d/magnetite.conf`:

    [magnetite]
    user = magnetite
    group = magnetite
    listen = /run/php/php8.5-fpm-magnetite.sock
    listen.owner = www-data
    listen.group = www-data
    listen.mode = 0660
    pm = dynamic
    pm.max_children = 10
    pm.start_servers = 2
    pm.min_spare_servers = 1
    pm.max_spare_servers = 4
    request_terminate_timeout = 600s
    env[HOME] = /var/www/magnetite
    php_admin_value[upload_tmp_dir] = /var/www/magnetite/tmp
    php_admin_value[sys_temp_dir] = /var/www/magnetite/tmp
    php_admin_value[upload_max_filesize] = 2048M
    php_admin_value[post_max_size] = 0
    php_admin_value[max_file_uploads] = 20
    php_admin_value[memory_limit] = 512M
    php_admin_value[max_execution_time] = 600
    php_admin_flag[expose_php] = off

    sudo php-fpm8.5 -t && sudo systemctl reload php8.5-fpm

`opcache.validate_timestamps` is system-wide, so it is left alone; `php artisan optimize` and the FPM reload on deploy do the job.

## 5. nginx

`/etc/nginx/sites-available/magnetite`, linked into `sites-enabled` (remove the old Docker proxy site for the same `server_name`; keep `default` if other sites use it):

    server {
        listen 80;
        listen [::]:80;
        server_name music.example.com;
        root /var/www/magnetite/app/public;
        index index.php;
        charset utf-8;
        server_tokens off;
        client_max_body_size 4m;
        add_header X-Content-Type-Options nosniff always;
        add_header Referrer-Policy same-origin always;

        # Zip uploads: large body, straight to the front controller (the fastcgi-php.conf snippet would 404 here)
        location = /upload {
            client_max_body_size 16g;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
            fastcgi_param SCRIPT_NAME /index.php;
            fastcgi_pass unix:/run/php/php8.5-fpm-magnetite.sock;
            fastcgi_read_timeout 600s;
        }
        # The same for the API upload (`magnetite upload` in the terminal client, the mobile apps): without it nginx answers 413 above 4m
        location = /api/v1/uploads {
            client_max_body_size 16g;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
            fastcgi_param SCRIPT_NAME /index.php;
            fastcgi_pass unix:/run/php/php8.5-fpm-magnetite.sock;
            fastcgi_read_timeout 600s;
        }
        location / { try_files $uri $uri/ /index.php?$query_string; }
        location ~ ^/index\.php(/|$) {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
            fastcgi_pass unix:/run/php/php8.5-fpm-magnetite.sock;
            fastcgi_buffering off;            # /stream goes straight to the browser
            fastcgi_read_timeout 600s;
            fastcgi_hide_header X-Powered-By;
        }
        location ~ \.php$ { return 404; }
        location ~ /\.(?!well-known) { deny all; }
        location /build/ {
            expires 1y;
            add_header Cache-Control "public, immutable";
            add_header X-Content-Type-Options nosniff always;
        }
    }

    sudo nginx -t && sudo systemctl reload nginx
    sudo certbot --nginx -d music.example.com
    curl -I https://music.example.com/up

A proxy or CDN in front needs the same body limits (Cloudflare's free plan caps a request at 100 MB).

## 6. Queue worker

Required: scans, prefetch, analysis and Ferrite uploads all run on the queue. `/etc/systemd/system/magnetite-queue.service`:

    [Unit]
    Description=Magnetite queue worker
    After=network.target mysql.service
    Wants=mysql.service

    [Service]
    User=magnetite
    Group=magnetite
    WorkingDirectory=/var/www/magnetite/app
    ExecStart=/usr/bin/php8.5 artisan queue:work --sleep=3 --tries=1 --max-time=3600
    Restart=always
    RestartSec=5
    TimeoutStopSec=300

    [Install]
    WantedBy=multi-user.target

    sudo systemctl daemon-reload && sudo systemctl enable --now magnetite-queue
    journalctl -u magnetite-queue -f

`--max-time=3600` restarts the worker hourly (systemd starts it again); `queue:restart` after a deploy does the same on demand. One worker is what the scans expect (one user at a time). There is no scheduler: nothing is scheduled.

## 7. Users and Ferrite

A token per user in Ferrite (Settings > API tokens), scoped to that user's folder, read and write plus the trash access if they upload and delete. As `magnetite`, in `app`:

    php artisan magnetite:user jose                  # asks for the password; --admin for an administrator
    php artisan magnetite:storage jose --ferrite=https://files.example.com     # asks for the token
    php artisan magnetite:storage jose --test
    php artisan magnetite:scan jose --queue

The address is the Ferrite root, without `/api/v1`. Users are separate libraries; only the Shared page crosses them.

## 8. Deploy an update

As `magnetite`:

    cd /var/www/magnetite/app
    git pull --ff-only
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build
    php artisan migrate --force
    php artisan optimize
    php artisan queue:restart

The Android APK is not in git (`/public/downloads/*.apk` is ignored). After a release copy it by hand: `scp public/downloads/magnetite-X.Y.Z.apk server:/var/www/magnetite/app/public/downloads/`, and set `MAGNETITE_APK_VERSION=X.Y.Z` in `.env` (default in `config/magnetite.php`), then `php artisan optimize`. The start page looks for the file by that name and shows its size and SHA-256; without the file the download is hidden.

The start page's demo music is not in git either (6 FLAC excerpts, about 41 MB, credits in `CREDITS.md`). Make it with `bin/prepare-demo.sh` (needs curl, ffmpeg, jq; it downloads the CC0 originals) and copy `storage/app/demo/` to the same place on the server, or set `MAGNETITE_DEMO_PATH`. Without the files the panel is hidden and `/library` asks a visitor to sign in; with them a visitor gets the demo library (read only). The measurements it shows are in git (`resources/demo/analysis.json`, rewritten by `php artisan magnetite:demo-analyze` when the excerpts change); `/demo/{id}` streams only the ids in `resources/demo/tracks.json`.

Then as a sudoer: `sudo systemctl reload php8.5-fpm`. (`/etc/sudoers.d/magnetite` can allow just `magnetite ALL=(root) NOPASSWD: /bin/systemctl reload php8.5-fpm`.)

## 9. Backup

Ferrite holds the music. Here, back up MySQL and `.env` (the Ferrite tokens in `users.store` are encrypted with `APP_KEY`; without the key they must be entered again). `/etc/cron.daily/magnetite-backup` (executable, runs as root):

    #!/bin/sh
    set -e
    d=/var/backups/magnetite
    mkdir -p "$d"
    mysqldump --single-transaction magnetite | gzip > "$d/db-$(date +%F).sql.gz"
    cp /var/www/magnetite/app/.env "$d/env-$(date +%F)"
    chmod 600 "$d"/*
    find "$d" -type f -mtime +14 -delete

Copy that folder off the server too.

## Moving from Docker

SQLite does not convert to MySQL by a dump. The Docker instance was started over: create the users again, point them at Ferrite, scan. Playlists, shares and analysis of the old database are not carried over.

## Remote test (planned)

Magnetite and Ferrite run on one host in Germany, so the proxy hop costs almost nothing there. The open measurement from `docs/ferrite-storage.md` is a Magnetite on a US server against `files.example.com`: first byte and seek time of `/stream` for an uncached track at full size, the effect of prefetch at track changes, scan time for a real album, and the 429 limits (Ferrite's 120 reads a minute may need raising). Repeat the checklist above on that server, with `MAGNETITE_CACHE_MAX_GB` sized to its disk, and write the numbers into `docs/ferrite-storage.md`.
