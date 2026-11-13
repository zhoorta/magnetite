# Troubleshooting

## A scan stays at "Scan queued"

No queue worker is running. Scans, analyses and look-ups started from the web page wait for it.

- **Docker**: the worker runs inside the container and is restarted when it stops. Check `docker compose logs app`.
- **`composer dev`**: it starts a worker; check that it is still running.
- **A server without Docker**: `php artisan queue:work` must be kept running by systemd or Supervisor.

A scan can always be run directly: `php artisan magnetite:scan yourname`.

## New files do not appear

Files copied into the folder by hand are only seen by a scan: press **Scan now** on the Upload page. Check that they are in the user's own folder, `users/{username}` inside the music folder, and that they are one of `mp3`, `m4a`, `flac`, `ogg`, `opus`, `wav`.

## No sound

- **"Sound is blocked by the browser"**: browsers do not let a page start sound by itself. Click anywhere on the page.
- **After a reload the track is paused**: this is intended. The queue and the position come back, paused; press `Space`.
- **The meters do not move**: they follow the audio, so they are still while it is paused.

## An ALAC file does not play

A track marked `ALAC` cannot be decoded by the browser, and ffmpeg is not available to convert it. Install ffmpeg or set `MAGNETITE_FFMPEG` (see [Configuration](configuration.md)). Safari plays ALAC without it.

## An upload fails

- **Too large (413), or it stops partway**: a limit below Magnetite's own was reached. Without Docker, PHP's `upload_max_filesize` and `post_max_size` must be at least `MAGNETITE_UPLOAD_MAX_MB`. A reverse proxy has its own limit (nginx `client_max_body_size`), and so does a CDN (Cloudflare's free plan stops at 100 MB per request).
- **The whole zip is refused**: it contains an unsafe path, or it unpacks to more than `MAGNETITE_UPLOAD_MAX_UNPACKED_MB`.
- **Files are "left out"**: they are not audio or cover images.
- **Files are "conflicts"**: a different file with the same name is already there. It is not replaced.
- **Nothing can be written**: the music folder must be writable by the user PHP runs as (uid 33 in Docker).

## The analysis does not run

`php artisan magnetite:analyze` says which of ffmpeg, ffprobe and Node is missing. Install it or set its path in [Configuration](configuration.md).

## One album shows as two

When some files of an album have an album-artist tag and others do not, a scan can split it. Mark the extra album with `x`, select the one to keep and run `:merge`, or run:

    php artisan magnetite:albums:doctor yourname
    php artisan magnetite:albums:doctor yourname --fix

The first lists the albums that look split; the second merges the ones that are certain.

## A forgotten password

    php artisan magnetite:user yourname --reset

## Where the logs are

- **Docker**: `docker compose logs app`.
- **Without Docker**: `storage/logs/laravel.log`.
