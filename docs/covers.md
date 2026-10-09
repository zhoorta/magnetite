# Covers from the web

Albums without a cover (nothing embedded, no `cover.jpg` in the folder) can be looked up online.

- **Who:** the owner of the album only (`AlbumPolicy::update`, 404 for others). Library page: "Find cover" on an album without one (the tile and the album's ⋯ menu; the all-albums "Find missing covers" button was removed from the page, the `FindCovers` job and `POST /covers/find` stay for the CLI and API). CLI: `php artisan magnetite:covers [username] [--retry] [--queue]`.
- **Sources, in order:** MusicBrainz release-group search, then the Cover Art Archive (`front-500`); then the iTunes Search API (artwork rewritten to 600px). A result is used only when the normalised title matches exactly (case, accents, punctuation and a trailing "(Deluxe)" note ignored) and the artist matches (one name contains the other; compilations match any artist).
- **What leaves the server:** the artist and the album title, nothing else. Set `MAGNETITE_COVERS_USER_AGENT` to `Magnetite/1.0 (you@example.com)`, as MusicBrainz asks.
- **Safety:** only `musicbrainz.org`, `coverartarchive.org`, `archive.org`, `itunes.apple.com` and `mzstatic.com` over https, redirects included (checked on every hop); 8 MB cap; bytes are decoded and re-encoded to a 500px JPEG by `CoverStore`, never stored as received. The music folder is not touched.
- **Bookkeeping:** `albums.cover_source` (`musicbrainz`, `itunes`; null for covers from the user's files) and `albums.cover_checked_at`. Albums already searched without a match are skipped until `--retry`. A scan that finds a cover in the user's own files replaces a downloaded one.
- One album per second in the job and the command (MusicBrainz limit).
- **Wrong match? Find another:** an album whose cover came from the web has "Find another cover" in its ⋯ menu. It walks the matches in order (up to ten MusicBrainz release groups, then up to ten iTunes results, same title and artist rules), skips those already tried (`albums.cover_ref` is the current one, `albums.cover_tried` the list) and any that gives the same picture, and replaces the cover with the next. With no other match the cover stays and the list starts over. A cover from the user's own files is never replaced (422).
- **Cover addresses carry `?v=` (the start of the file name)**, so a replaced cover is fetched again although covers are cached for a year.
