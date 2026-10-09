# Covers from the web

Albums without a cover (nothing embedded, no `cover.jpg` in the folder) can be looked up online.

- **Who:** the owner of the album only (`AlbumPolicy::update`, 404 for others). Library page: "Find cover" on an album without one, and "Find missing covers" for all of the user's albums (queue job `FindCovers`, status per user in `CoverStatus`). CLI: `php artisan magnetite:covers [username] [--retry] [--queue]`.
- **Sources, in order:** MusicBrainz release-group search, then the Cover Art Archive (`front-500`); then the iTunes Search API (artwork rewritten to 600px). A result is used only when the normalised title matches exactly (case, accents, punctuation and a trailing "(Deluxe)" note ignored) and the artist matches (one name contains the other; compilations match any artist).
- **What leaves the server:** the artist and the album title, nothing else. Set `MAGNETITE_COVERS_USER_AGENT` to `Magnetite/1.0 (you@example.com)`, as MusicBrainz asks.
- **Safety:** only `musicbrainz.org`, `coverartarchive.org`, `archive.org`, `itunes.apple.com` and `mzstatic.com` over https, redirects included (checked on every hop); 8 MB cap; bytes are decoded and re-encoded to a 500px JPEG by `CoverStore`, never stored as received. The music folder is not touched.
- **Bookkeeping:** `albums.cover_source` (`musicbrainz`, `itunes`; null for covers from the user's files) and `albums.cover_checked_at`. Albums already searched without a match are skipped until `--retry`. A scan that finds a cover in the user's own files replaces a downloaded one.
- One album per second in the job and the command (MusicBrainz limit).
