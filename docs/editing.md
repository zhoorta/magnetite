# Editing details and the record label

The owner can correct what the library shows: **Edit details…** in the ⋯ menu of an album or a track.

- **Album:** title, album artist, release year, record label. **Track:** title, artist (empty: the album's), track number.
- **Database only.** The files and their tags are never written (`LibraryUploader` and `LibraryRemover` stay the only writers). Everything the page shows comes from the database, so the change is immediate.
- **Rescans keep the edits.** Saving marks the tracks concerned `identified` (the same flag as an accepted album suggestion, see `identify-albums.md`): the scanner then updates only the technical fields (size, duration, codec) and does not read titles, artists or albums from the tags again. Consequence: a new file added later to an edited album is read from its own tags, so it can create the old album next to the edited one; edit that one too.
- **Rules** (`LibraryEditController`): owner only (404 for others, `AlbumPolicy::update` / `TrackPolicy::update`); the title is required; year 1000–2100; blanks become null; an album cannot take the title and artist of another album of the same user (422), because the scanner tells albums apart by that pair.
- **Record label:** `albums.label`. The scanner fills it from the tags (`publisher`, `label` or `organization`) when an album has none; the owner can type or clear it. It shows next to the artist and year on the library and shared pages, and in the API (`label` on an album).
- Routes: `PATCH /library/albums/{album}`, `PATCH /library/tracks/{track}`.

## Find details online (release date, record label, track titles)

**Find details online…** in an album's ⋯ menu looks the album up on MusicBrainz by its title and artist (the owner's, after Edit details) and offers the release date, the record label and the real track titles. It covers the case "the album has its real name but the tracks are Track 1, 2, 3…" as well as an album with no date or label.

- `AlbumIdentifier::titlesFor($album)`: the tracks are put in playing order (disc, number, then file name in natural order); releases with the album's title (and artist), track count within 2, are fetched (up to five, `inc=recordings+artist-credits+labels`, one request a second) and held against the files' lengths with `ReleaseMatch` (2 s tolerance); up to three that fit at least half the tracks are offered, best first. Only the album title and artist leave the server. Editions differ in date and label, so these come from the release that fits the files, never from a bare title match.
- The dialog shows, for the chosen release, three tick boxes: **Release date** (current → proposed), **Record label** (current → proposed) and **Track titles** (how many would be renamed), with the per-track list (now → proposed). Boxes are ticked for what would change; nothing is applied until "Use selected". A track is renamed only when its length fits ("length differs" keeps its title), so a wrong edition cannot scramble the order.
- The candidates wait in the cache for 30 minutes (`POST /library/albums/{id}/titles/find`, which also returns the album's current date and label); `POST /library/albums/{id}/titles` with `{candidate: index, parts: [titles|date|label]}` applies by index, so the browser never sends values back to be trusted. An expired search answers 422, MusicBrainz down 502. Owner only (404 for others).
- Applying `titles` sets title, track and disc number and a track artist (only where it differs from the album's) on the tracks whose length matched, and marks all the album's tracks `identified`. `date` sets `release_date` and the year; `label` sets the label. A ticked value replaces the old one. The album's own title and artist and the files are never changed.
- `albums.release_date` holds the date as far as it is known (`2001`, `2001-03`, `2001-03-12`, validated by `Album::DATE_PATTERN`); `year` follows it. Pages show `Album::released` (the date, else the year). The edit form has one "Release date" field; the API sends both `year` and `release_date`.
- Accepting an album suggestion for untagged folders (`identify-albums.md`) also takes the release's date and label.

## Folders

The owner can sort albums into folders and subfolders ("Jazz" › "Bebop"), by hand; nothing is automatic.

- Tables: `folders` (`user_id`, `parent_id`, `name`) and `albums.folder_id` (null: at the top). A folder is only a view of the library: no file moves, and a rescan keeps the arrangement (it matches albums, not folders).
- An album is in one folder at most. Folders go up to `Folder::MAX_DEPTH` (6) levels deep; a folder cannot be moved into itself or its own subfolders.
- `/library`: "New folder" in the header; each folder has a ⋯ menu (New subfolder, Rename, Move to, Delete folder) and each album has "Move to folder…". Open folders are remembered in this browser (`localStorage`, `magnetite.folder.{id}`).
- Deleting a folder deletes no music: its albums and subfolders move up one level. Folders are private (`FolderPolicy`, 404 for others) and are not shown on `/shared`, which stays a flat list.
- Routes: `POST /library/folders`, `PATCH|DELETE /library/folders/{folder}`, `PUT /library/albums/{album}/folder`.

## Split albums (2026-11-10)

An album could be cut in two when one file had an album-artist tag (`band` / TPE2) and its neighbours did not: with the tag the scanner identified the album by artist and title, without it by title and folder, so one track became a second album of the same name (Quicksilver Messenger Service's *Just For Love*: seven tracks, and *The Hat* alone).

- **Scanner (`LibraryScanner::albumFor`).** Albums are compared by `albums.title_key`, the title as `App\Support\AlbumKey` cleans it (case, repeated and surrounding spaces, curly quotes and dashes, Unicode composition; kept by a `saving` hook, backfilled by the migration). With an album-artist tag the album is the one with that artist; without one it is the album of the folder. New: a tagged file takes into its album an untagged album of the same title that has a file in the same folder (and fills in the artist), and an untagged file joins a tagged album of that title with a file in its folder, whichever was scanned first. Two different album artists with one title in one folder stay two albums. Files at the top of the library (folder `.`), where unrelated albums share a folder, keep the strict identity. A forced rescan (`magnetite:scan --force`) puts an existing split together, except tracks marked `identified`.
- **Merging.** `AlbumMerger::merge($from, $into)` moves the tracks, marks every track of the result `identified` (the owner confirmed the album as a whole), carries over what the kept album lacks (artist, year, date, label, folder, cover) and the share of the emptied one, and deletes it. Only database rows change. `POST /library/albums/{album}/merge` and `POST /api/v1/albums/{album}/merge` with `{into}` (needs the write ability); both albums must be the caller's (404 otherwise), the same album twice is 422. There is no button in the web library yet.
- **Doctor.** `php artisan magnetite:albums:doctor [username] [--fix] [--all]` lists albums with one title that look split (`AlbumDoctor`): the same folder, or the same shown artist, never two different album artists. `high` (same folder, or same artist with track numbers that do not clash) is merged by `--fix`; `medium` (numbers that clash: two discs or two editions) only with `--all`. The fuller album is kept (the lower id on a tie). Without `--fix` nothing is changed. Not built: a suggestion box in the library page, the terminal client's view of it, cleaning a trailing `[FLAC]` or `(Remastered)` off titles before comparing.

