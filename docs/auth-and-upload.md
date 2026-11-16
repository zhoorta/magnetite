# Sign-in and zip upload

## Who can do what

`/` (start page), `/themes` and `/login` are public. `/library`, `/stream/{track}`, `/cover/{album}` and `/upload` need a signed-in user; guests are sent to `/login` and back afterwards. There are no roles: any user may upload.

Users sign in with a username and password (the e-mail column is optional, Magnetite sends no mail). The first user is made on `/setup` (only while there are no users; optional `MAGNETITE_SETUP_TOKEN`), then there is no registration page:

    php artisan magnetite:user jose              # asks for the password twice
    php artisan magnetite:user jose --reset      # change the password of an existing user

Usernames are lowercase letters, digits, `.`, `_` and `-`; passwords at least 8 characters. Sign-in is throttled to 5 wrong tries per username and IP. Sessions use the database driver, lifetime `SESSION_LIFETIME` (120 min).

## Upload

`/upload` takes one or more `.zip` files (progress bar, XHR). Each is unpacked into `MAGNETITE_MUSIC_PATH` by `App\Services\LibraryUploader`, then a scan is queued if anything new was written (see Scan queue below), so the request ends as soon as the files are on disk.

Rules, all covered by `tests/Feature/UploadTest.php`:

- Only audio (the `TrackLibrary::TYPES` extensions) and cover images (`jpg`, `jpeg`, `png`) are extracted; everything else, hidden files and `__MACOSX` are counted as "left out".
- A path with `..`, a leading `/`, a drive letter, a control character or a segment over 255 bytes aborts the whole archive. Symlink entries are skipped. The destination directory is resolved with `realpath` and must stay inside the library, so a symlink already in the library cannot redirect a write.
- Existing files are never overwritten (counted as "already there").
- The unpacked size is counted while copying, because the sizes a zip declares can lie; going over `MAGNETITE_UPLOAD_MAX_UNPACKED_MB` stops the copy. A failure removes everything that archive wrote.
- A zip whose entries share one top folder keeps it; loose files or several top folders go into a folder named after the zip.
- Entries are written as `name.part` and renamed when complete, so the scanner never sees half a file.

## Scan queue

`App\Jobs\ScanLibrary` runs `LibraryScanner::scan()` on the queue (`QUEUE_CONNECTION=database`, so **a worker must be running**: `php artisan queue:work` on a server under Supervisor or systemd, `composer dev` locally). `App\Support\ScanStatus` keeps its state in the cache (idle, queued, running with files read, done with counts, failed with the message) and `GET /scan` returns it as JSON; the upload page polls it every 2 s and says when no worker has picked the job up after about 15 s.

- `ScanLibrary::request()` is the one entry point (upload, `POST /scan`, `php artisan magnetite:scan --queue`). If a scan is already waiting in the queue nothing is added, because it will see every file written so far; a scan that is already running may have passed the new files, so a second job is queued and `WithoutOverlapping` makes it wait for the first. A "waiting" flag older than an hour is ignored in case the job was lost (queue flushed).
- A failing scan fails once (`$maxExceptions = 1`) instead of retrying for hours; `retryUntil` only exists so the job can wait behind another scan. One unreadable file never fails a scan, the scanner counts it.
- `php artisan magnetite:scan` without `--queue` still scans right there, with the table of counts.
- The upload page also has "Scan the library now" for files copied into the folder by hand.
- Not done: scan progress as a percentage (the number of files to read is not known up front) and analysis (`magnetite:analyze`) after a scan.

## Server limits

The limits that matter are not Magnetite's: PHP `upload_max_filesize` and `post_max_size` (default 2M and 8M), the web server's body limit (nginx `client_max_body_size`) and any proxy or CDN limit (Cloudflare's free plan stops at 100 MB per request). Raise them to at least `MAGNETITE_UPLOAD_MAX_MB` (default 2048), or lower that value to match. The page reports a 413 or an incomplete upload with a hint. The music folder must be writable by the PHP user.

## Not done

Chunked or resumable uploads (a failed 1 GB upload starts again), deleting or moving uploaded albums from the app, per-user libraries.
