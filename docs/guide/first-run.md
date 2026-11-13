# First run

## Sign in

Open the address of the server and choose **Sign in** in the top bar. Users are made on the command line (`php artisan magnetite:user NAME`); there is no registration page. A password has at least 8 characters, and sign-in is blocked for a while after 5 wrong tries.

Without an account, **Library** shows a read-only demo library when the server has demo music; otherwise it shows the sign-in page.

## Add music

There are two ways.

**Upload zips.** Open **Upload** in the top bar, or press `U` in the library, or drop zip files anywhere on the library page. Several zips can be sent at once, and the upload goes on while other pages are opened.

- Only audio (`mp3`, `m4a`, `flac`, `ogg`, `opus`, `wav`) and cover images (`jpg`, `jpeg`, `png`) are kept. Everything else is left out.
- Existing files are never overwritten. A different file with the same name is skipped and counted as a conflict.
- Keep one folder per album inside the zip. Loose files go into a folder named after the zip.
- A zip with an unsafe path inside it (`..`, an absolute path) is refused as a whole.

After an upload the library is scanned in the background and the new albums appear.

**Copy files.** Put the files in the user's folder (`users/{username}` inside the music folder), then press **Scan now** on the Upload page, or run:

    php artisan magnetite:scan yourname

With Docker: `docker compose exec -u www-data app php artisan magnetite:scan yourname`.

## How the library is read

A scan reads the tags of each file: title, artist, album, album artist, track number, year, record label, and the embedded cover. A `cover.jpg` or `folder.jpg` in the album's folder is used when no cover is embedded. A later scan only reads new and changed files.

Files without an album tag are listed under "Without album tag". [The library](library.md) explains how to name them.

Magnetite does not change the music files. It adds files on upload and removes a file only when its owner deletes the album or track in the library.

## Analyse the files

The analysis is separate from the scan, because it decodes every file. It measures dynamic range, loudness, true peak, clipping and the spectrum:

    php artisan magnetite:analyze

In the library, `A` analyses the selected album, folder or track. It needs ffmpeg, ffprobe and Node (all three are in the Docker image). A stereo track is measured at about 80 times its playing speed, so a 6-minute track takes about 4 seconds.

[File analysis](file-analysis.md) explains the results.

## The queue worker

Scans and analyses started from the web page run in a background worker. Docker starts one inside the container, and `composer dev` starts one too. On another kind of install, `php artisan queue:work` must be kept running. When a scan stays at "Scan queued", the worker is not running: see [Troubleshooting](troubleshooting.md).

Next: [The library](library.md).
