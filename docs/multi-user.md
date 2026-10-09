# Multi-user: own libraries and a shared area (built 2026-10-09, steps 1 to 4)

For you and a few friends. Everyone has their own library; the shared area holds **references** to things in someone's library, never copies. Local disk is the default and the only thing this needs; Ferrite storage (`docs/ferrite-storage.md`) is an option on top. Users are created with `php artisan magnetite:user`; no screens for user management yet.

## Model

- `users` get `is_admin` (bool) and, later, `store` (JSON, null = local disk; see Ferrite below).
- `albums` and `tracks` get `user_id` (the owner). Unique keys that are global today (`tracks.path`, the album key used by `firstOrCreate`) become per user, because two friends can own the same album or even the same relative path.
- New table `shared_items`: `id`, `user_id` (who shared it), `album_id` nullable, `track_id` nullable (exactly one set), `created_at`, unique per item. Foreign keys cascade, so deleting the album or track deletes the reference.
- No `libraries` table: a library is simply "the tracks of one user". Add one only if a second library per user or a group-owned library is ever wanted.

**Sharing an album shares the album, not a snapshot.** Tracks the owner adds later appear in the shared area, tracks they remove disappear. Sharing a single track is the same with `track_id`. The scanner already upserts by path, so ids stay stable across rescans (`LibraryScanner` uses `updateOrCreate` and only deletes albums with no tracks left), and references survive a rescan. Check that the album key includes `user_id` after the change.

## What a user can see

One rule, in a `TrackPolicy` and a matching query scope `Track::visibleTo($user)`:

> own tracks, plus tracks of an album in `shared_items`, plus tracks in `shared_items`.

This rule decides who may *use* a track: `/stream/{track}`, `/cover/{album}` and the prefetch call. Listings are separate: `/library` queries only `user_id = me`, `/shared` queries only `shared_items`. An invisible track answers 404, not 403, so ids reveal nothing.

Today any signed-in user can stream any id, and `/library` lists everything; this is the biggest change and gets the most tests (user A cannot stream, see the cover of, or find via duplicates, user B's unshared track).

- **Two separate places, never mixed.** `/library` lists only your own tracks and albums; shared items from others never appear in it. `/shared` is the shared music area: one listing of everything in `shared_items` from all users (yours included, marked as yours), each with the name of who shares it, playable from there. Sharing and unsharing are buttons on your own albums and tracks in `/library` (and an Unshare button on yours in `/shared`). Plain viewers get Play and Add to queue only.
- **Queue and player:** the queue can hold tracks from both places; playing a shared track goes through `/stream` and the same visibility check.
- **File truth:** findings and duplicate detection run over your own library only, on `/library`. `/shared` shows no findings, so a hash can never reveal that someone else has a file privately.
- **Covers:** `covers/{sha1}.jpg` is content-addressed and may be shared between users, so access is decided at the route by album visibility, never by the file name.
- **Delete rights:** only the owner can unshare or remove; a friend who sees a shared album can play it but cannot change it. Friends cannot put files into your folder.

## Disk layout and upload

- Local: `MAGNETITE_MUSIC_PATH/users/{username}/...`. Each user's scanner, uploader and analysis run on their own folder only. Usernames are already restricted to lowercase letters, digits, `.`, `_`, `-` (no separators), so a username is safe as a folder name; the uploader still resolves with `realpath` and checks the result stays inside that user's folder.
- `LibraryUploader::extract()` takes the user and writes into their folder, with every current rule unchanged (types, traversal, symlinks, no overwrite, unpacked size, rollback). An optional per-user quota (`users.quota_mb`, default none) is checked against the size of their folder before unpacking.
- `ScanLibrary::request()` takes a user: a scan is one user's folder, status per user in `ScanStatus`, and coalescing is per user. `magnetite:scan` without arguments scans everyone, `magnetite:scan jose` one user.
- `magnetite:analyze` and `TrackAnalyzer` work as today per track; the transcode cache key is the file hash, so it is shared by users with identical files, which is fine because access is checked at `/stream`.

## Moving to it

The existing library lives at the root of `MAGNETITE_MUSIC_PATH` and its tracks have no owner. One command does the move:

    php artisan magnetite:claim jose      # moves the root's contents into users/jose and sets user_id on every row

It renames on the same filesystem (no copy), refuses if `users/jose` already has files, and is idempotent. Until it is run, nothing works for any user, so the migration that adds the columns also makes the first user created the owner of unclaimed rows if there is exactly one user. Admin: `magnetite:user jose --admin`; only admins see future admin tools (none yet).

## Ferrite on top (later)

With `users.store` a user can point their library at a Ferrite folder with their own token, while others stay on local disk. `LibraryStore` is resolved per user (`$user->store()`), which is why this design comes first. The token is then stored in the database encrypted with `APP_KEY`, the one thing `docs/ferrite-storage.md` had kept to `.env`. A reference to a track on Ferrite streams through the owner's token; the viewer never sees it, and a revoked or expired token only breaks that owner's tracks.

## Not doing

Public links, registration, comments, a shared folder others can write to (references only), roles beyond admin, or per-track permissions finer than "shared with everyone who has an account".

## Build order

1. Migration and models: `user_id`, `is_admin`, `shared_items`, per-user unique keys; `magnetite:user --admin`; `magnetite:claim`. Existing tests adapted, all green.
2. `Track::visibleTo()` and `TrackPolicy`, applied to library, stream, cover, prefetch (when it exists) and file truth; tests that each route hides other users' unshared items (404) and shows shared ones.
3. Per-user folders in scanner, uploader, `ScanStatus` and `magnetite:scan`, quota.
4. Share and unshare buttons, the `/shared` page (separate from `/library`, which lists only your own).
5. Then `LibraryStore` per user and Ferrite, as in `docs/ferrite-storage.md`.

## As built (2026-10-09)

- `tracks.path` is relative to the owner's folder, unique per `(user_id, path)`. `TrackLibrary::resolve($path, $owner)` and `TrackLibrary::userPath()` are the only ways to reach a file; usernames must start with a letter or digit (`.` and `..` are refused, `magnetite:user` and `TrackLibrary::validUsername`).
- Rows with no owner (from before) are visible to nobody until `magnetite:claim`; the migration gives them to the only user when there is exactly one.
- `Track::visibleTo()` / `Album::visibleTo()` and `TrackPolicy` / `AlbumPolicy` say the same thing (tests check both). `/stream` and `/cover` answer 404 when it fails. Covers are `private` cached.
- A single shared track also opens its album's cover, because the player shows it.
- `ShareController`: `POST` and `DELETE /share/{albums|tracks}/{id}`, owner only (404 otherwise). `/shared` shows no findings and no Unshare on others' items.
- Scan status, the scan job and its overlap lock are per user. The uploader creates `users/{username}` on the first upload and checks `users.quota_mb` (null = no limit; no command sets it yet, use tinker).
- Not done: the Share buttons and the unshare animation were not tried in a browser (Pest renders the pages), no admin tools, quota has no UI.
