# Ferrite as library storage (design, not built yet)

The music files live in a Ferrite folder instead of on the Magnetite server's disk. Magnetite reads and adds files through Ferrite's API (Ferrite's `docs/api.md`) with one folder-scoped token. Local folders stay the default and keep working.

## What changes in the code

A `LibraryStore` interface, chosen by `MAGNETITE_STORAGE` (`local` or `ferrite`):

- `list()`: `path, size, hash, mtime` of every file. `LocalStore` walks the folder (today's scanner), `FerriteStore` pages `GET /files`.
- `open($path, $range)`: a stream with status and headers, used by `/stream`.
- `fetch($path)`: a local file path for tools that need one (getID3, ffmpeg, `bin/measure.mjs`, `TranscodeCache`). Local returns the real path; Ferrite downloads into the cache first.
- `put($localFile, $path)`: add a file, never overwrite. Used by `LibraryUploader`.

`tracks` gets `store_key` (Ferrite file id) and `store_hash` (Ferrite's sha256). `pcm_sha1` and the analysis stay what they are.

**Scan.** List, diff against the table by path and `store_hash`: unchanged files are not touched, new or changed ones are fetched, read with getID3 and stored, missing ones are removed exactly as the local scan does. Embedded covers and `cover.jpg` files are cut at scan time into `covers/{sha1}.jpg`, so the browser never needs Ferrite for a cover. The listing is paged, so one scan makes `ceil(files/1000)` requests plus one download per new file.

**Stream.** `/stream/{track}` stays a proxy behind the sign-in check: it forwards `Range` and `If-Range`, copies `200/206`, `Content-Range`, `Content-Length`, `ETag` and `Accept-Ranges`, and sets `Content-Type` from Magnetite's own allowlist, `nosniff` and `inline`. Nothing Ferrite says about types is trusted, and the URL and token never reach the browser. Redirects are not followed.

**Cache.** `storage/app/private/ferrite-cache/{sha256}.{ext}`, size-capped (`MAGNETITE_CACHE_MAX_GB`, default 10), least recently used removed first. Filled by analysis, ALAC conversion and prefetch (below), and optionally "keep offline" later; plain playback does not fill it (seeking works through the proxy). A cache hit is checked against `store_hash`, and `/stream` serves a hit from the local file with the same Range handling, so a cached track starts and seeks as fast as a local library.

**Prefetch.** The proxy adds a hop to the first byte (see the latency notes in the plan), which is most noticeable at a track change. So the player asks for the next track ahead of time:

- The `player` store knows the queue. When the current track passes 70% or has 45 seconds left (whichever comes first), it calls `POST /prefetch/{track}` for the next one. Only one track ahead; the call is sent once per track, and not at all for a paused or empty queue.
- The server queues `PrefetchTrack` (the same queue worker as scans) and answers 202 at once. The job takes a cache lock per `store_hash`, so two requests, or a prefetch and an analysis, never download the same file twice; it skips a file already cached, and the local store does nothing.
- The download goes to `{sha256}.part` and is renamed when complete, so `/stream` never serves half a file. If the next track is requested before the job finishes, it simply streams through the proxy as usual.
- The track playing now and the one prefetched are pinned against eviction. A prefetch of a file larger than the cache cap is skipped.
- The audio element and the Web Audio graph are untouched (`@persist('player')` stays as is); prefetch only changes where `/stream` reads from. It does not make playback gapless by itself.
- Needs a queue worker, like scans. Without one the call queues and nothing happens, which is harmless.

**Upload.** `/upload` still takes the zip. `LibraryUploader` unpacks it into a temporary directory with every rule it has today (types, traversal, symlinks, unpacked size), then `put()`s each file with `on_conflict=fail`. A `409` with the same hash counts as "already there", with another hash as "a different file is in the way" (reported, not replaced). Then a scan is queued. One difference: Magnetite's token cannot delete, so a failure halfway leaves the files that already arrived (today it rolls back). Sending the same zip again finishes the job, because existing files are skipped. If the token is read-only, the upload page says so instead of showing the form.

**If Ferrite is down.** The library page works from the database; playing a track that is not in the cache answers 502 with a message in the player; scans fail with the reason and keep the old data; uploads fail before anything is unpacked (a `GET /root` first).

## What the user sets up

### In Ferrite (Settings > API tokens)

1. Make a folder, for example `Music`, and put the existing library in it (or leave it empty).
2. Create token: name `Magnetite`, folder `Music`, access **Read and write** (or Read only if Magnetite should never add files), expiry as you like.
3. Copy the token and the API address. It is shown once.

### In Magnetite

Version 1 is configuration, no screen needed to set it up:

    MAGNETITE_STORAGE=ferrite
    MAGNETITE_FERRITE_URL=https://files.example.com
    MAGNETITE_FERRITE_TOKEN=...
    MAGNETITE_CACHE_MAX_GB=10

    php artisan magnetite:storage:test    # GET /root: folder name, access, expiry, quota
    php artisan magnetite:scan --queue

The token is a secret that can add files to someone's drive, so it stays in `.env` (or a Docker secret), not in the database. The `Dockerfile` needs nothing new (outbound HTTPS only); the cache directory goes in the data volume.

A **Storage** page (signed-in users, read-only) shows what is configured and whether it works:

- Source: Local folder or Ferrite, address, folder name, access (read or read and write), token expiry and a warning when it is near.
- **Test connection** (calls `GET /root`) and the free quota on the Ferrite side.
- Last scan, number of files, cache size and used, **Clear cache**.
- Errors in plain words: 401 "the token was revoked or has expired", 404 "the folder was deleted or the token cannot see it", unreachable, quota full.

Editing the address and token from that page is a possible step 2. It needs an admin notion first (Magnetite has no roles; any user may upload, but not everyone should be able to repoint the library) and the token encrypted with `APP_KEY`.

## Decisions to make

- Stream through the proxy only, or also let a trusted deployment redirect the browser to a short-lived Ferrite URL (saves bandwidth through Magnetite; Ferrite has no signed URLs yet, so: proxy).
- Migration: a command `magnetite:storage:push` that uploads an existing local library to Ferrite and switches the paths, or "put the files into the Ferrite folder by hand and rescan". The second needs nothing new because paths are relative to the root.
- An extension allowlist on the token in Ferrite (audio and images only) as a second wall behind `LibraryUploader`.
- Ferrite-side encryption at rest: nothing for Magnetite to do; it just reads plaintext through the API.

## Status (2026-10-09)

Multi-user (`docs/multi-user.md`) comes first and changes this design: the store is resolved per user (`$user->store()`), and the token is stored encrypted in the database instead of `.env`. Read that before building anything here.

Designed in conversation, nothing built or committed. Decided: proxy streaming (no redirect), token in `.env` for v1, prefetch of the next track, no delete ability for the token, API is generic (not Magnetite-specific). Still open: the four decisions above, plus whether Magnetite and Ferrite run on the same host or network (if yes, latency is a non-issue; if not, measure first).

Cheaper alternative to remember: mount the Ferrite storage (rclone or sshfs of the Storage Box) and point `MAGNETITE_MUSIC_PATH` at it. No code, but no Ferrite folders, tokens or quota, and Ferrite's random blob keys make it useless for Ferrite-managed files.

Recommended staging, stop when it stops paying off: (1) `LibraryStore` + `LocalStore` refactor, useful anyway; (2) Ferrite read-only token, `/root`, `/files`, content with Range; (3) `FerriteStore` reading, then measure first-byte and seek time; (4) only then uploads and the Storage page.

## Built 2026-10-09: read-only `FerriteStore` (step 2)

- Per user: `users.store` (encrypted JSON `{driver, url, token}`, hidden from serialisation); `php artisan magnetite:storage {username} --ferrite=URL [--token=]` sets it (token asked for when left out), `--local` switches back, no option checks the connection (`GET /root`). `tracks` has `store_key` (Ferrite file id) and `store_hash`.
- `App\Services\FerriteStore`: lists every page of `/files` (cached for the run), `fetch()` downloads into `storage/app/private/ferrite-cache/{sha256}.{ext}` via a `.part` file, checks the checksum, and prunes the least recently used files over `MAGNETITE_CACHE_MAX_GB`. A scan caches every file it reads, so a first scan fills the cache up to that cap. Redirects are never followed; 429 waits for `Retry-After`; errors say what happened (revoked, folder gone, unreachable).
- Scan: a file is unchanged when its hash and Ferrite id match (folders still use size and mtime). A failed listing keeps the old tracks and says why (`LibraryStore::unavailableReason()`).
- `/stream`: a track that is not cached goes through `FerriteStore::proxy()` (Range, If-Range, If-None-Match forwarded; type, `nosniff`, `inline` are Magnetite's own); a cached one is served from disk; ALAC goes through the cache and the converter. Ferrite down and not cached: 502 with the reason.
- Measured with both apps on one machine (real Ferrite on MySQL, `php artisan serve`): first byte 85 ms cold, 21 ms for a Range request; fixture files only (4 KB), so this says nothing about a remote Ferrite yet. Still to do: measure against the real server with a full-size album.
- Not built: uploads and `put()`, prefetch, the Storage page, `magnetite:claim` and the zip upload for Ferrite users. Ferrite's reads are limited to 120/min per token: a first scan of a big library waits on the limit (the store sleeps for `Retry-After`), and playback Range requests count too, so Ferrite may need a higher limit for content.

## Build order

Step 1 is done (2026-10-09). `App\Contracts\LibraryStore` has `isAvailable()`, `files()` (yields `StoredFile`: path, size, mtime), `fetch($path)` (a local file path or null) and `folderCover($path)` (bytes of `cover.jpg` and friends). `App\Services\LocalStore` implements it over `TrackLibrary`; `User::store()` is the one place that will choose the driver. `LibraryScanner`, `StreamController` and `TrackAnalyzer` use it. `open($path, $range)` and `put()` are not in the interface yet; add them with the Ferrite driver and the uploader (steps 2 and 4). Still local-only: `LibraryUploader`, `magnetite:claim`, the music-folder check in `magnetite:scan`.

1. `LibraryStore` with `LocalStore` extracted from today's code, no behaviour change, all existing tests green.
2. `FerriteStore` read-only against a fake (`Http::fake`) and then against a real Ferrite: list, diff scan, proxy with Range, cache, ALAC and analysis through `fetch()`.
   - Measure first byte and seek time through the proxy against the real Ferrite and Storage Box, with and without a cache hit, before deciding how much more to optimise.
   - Prefetch: `POST /prefetch/{track}`, `PrefetchTrack`, cache hit serving in `/stream`, the `player` store trigger.
3. `magnetite:storage:test`, the Storage page, error messages.
4. Writing: `put()`, `LibraryUploader` through the store, partial-failure reporting.
5. Tests: Range and `If-Range` pass-through, a 401 mid-scan, a changed hash replaces the track, a removed file removes it, token never in logs or responses, no redirect following, cache eviction (pinned files kept), prefetch (queued once, no double download, `.part` never served, skipped when already cached or too big, a cache hit answers Range), the zip rules unchanged.
