# Identifying untagged albums (built 2026-10-09)

Albums uploaded without tags end up with a folder name as title, no artist, and often no track numbers. The idea: suggest what they are, let the owner confirm.

## Levels, cheapest first

1. **Path parsing (offline).** `Artist - Album (2004)/03 - Title.flac`, `CD1/`, `[FLAC]` and similar noise. Gives a guess for artist, album, year, track number and title. No network.
2. **Shape match against MusicBrainz.** Search release by the guess (or by track titles when there is no guess), then compare with each candidate: track count and per-track duration (within about 2 s). The score is the share of tracks that match. Durations are already in `tracks`. Same rate limit and user agent as `CoverFinder` (`docs/covers.md`).
3. **Fingerprints (optional).** `fpcalc` (Chromaprint) per track, AcoustID lookup, vote across the album for one release. Needs `fpcalc` in the Docker image and one request per track. Only for what levels 1 and 2 could not decide.

What leaves the server: guessed names, track counts and durations at level 2; fingerprints and durations at level 3. Never audio.

## As built

- `App\Support\PathGuess` reads folder and file names (brackets and years, `Artist - Album`, `2004 - Album`, `Album` inside an `Artist` folder, `03 - `, `03. `, `1-03 `, a shared `Artist - ` prefix in the file names; `CD1`/`Disc 2` folders are grouped under their parent with the disc number). `App\Support\ReleaseMatch` compares file lengths with release track lengths in playing order (2 s tolerance, score = matches over the longer list).
- `App\Services\AlbumIdentifier` groups the user's tracks without an album by folder (loose files in the library root are skipped), asks MusicBrainz for releases of the guessed album (nearest track count first, within 2 tracks), fetches up to five of them with recordings, and keeps the best three that fit at least half the tracks, plus the path guess as the last candidate. A release title or number is used only for a track whose length matched; the others keep what the file names said. MusicBrainz down: the path guess alone.
- `album_suggestions` holds the result per folder until it is accepted (deleted) or dismissed (`dismissed_at`; looked at again only with `--retry`). `IdentifyAlbums` job and `IdentifyStatus` as for covers; `php artisan magnetite:identify [username] [--retry] [--queue]`; `GET/POST /identify`, `POST /identify/{id}/accept` (`candidate` index), `DELETE /identify/{id}`, all owner only (404).
- Library page: "Suggest albums" in the ⋯ menu next to the "Without album tag" heading, and under "Without album tag" one box per folder with the candidates, a track-by-track list (file → proposed title, "length fits / differs") and "Use this" / "Dismiss".
- Accepting creates (or reuses) the album, and sets album, title, artist (only where it differs from the album's), track and disc number on the tracks that are still the owner's and still without an album. It sets `tracks.identified`, and the scanner then refreshes only the technical fields of those tracks, so a rescan does not undo the choice. The files are never touched. This replaced the override table of the first design: the choice is written to the track rows themselves, and `identified` protects it.
- Search order for a folder, each step only if the one before found no release that fits 80% of the tracks: (1) the guessed album name, (2) the titles of up to three tracks as recordings (a release must come up for at least two of them; titles like `Track 01` or `07` are skipped), (3) fingerprints. Every release found is verified by track count and length, whatever found it. The candidate says how it was found.
- Fingerprints (`App\Services\Fingerprinter`): `fpcalc` (Chromaprint, `MAGNETITE_FPCALC` or PATH; `libchromaprint-tools` in the Docker image) on up to six tracks spread over the folder, one AcoustID lookup each (`MAGNETITE_ACOUSTID_KEY`, a free application key; matches under 0.8 are ignored), the releases voted for by most tracks go on to the length check. Without `fpcalc` or the key the step is skipped. What leaves the server: a fingerprint and the length of each sampled track. The file is passed to `fpcalc` as a process argument, never through a shell.
- Accepting also takes a cover from the folder (`cover.jpg`, `folder.jpg`, ...) when the album has none; otherwise use "Find cover".
- Not built: albums that have an album tag but wrong tags, files in the library root. `fpcalc` and the AcoustID lookup were not run against the real tool and service (the Pest tests fake them); the Docker image was not rebuilt with the new package.

## Suggestions, not writes

- The music folder stays untouched (`LibraryUploader` is the only writer).
- Confirmed answers live in the database (see "As built"); a scan must not undo them.
- A suggestion carries its source, score and MusicBrainz release id, and is kept until accepted or dismissed. Nothing is applied automatically, however high the score; 12/12 tracks matching is shown as such.
- Owner only (`AlbumPolicy::update`, 404 for others), like covers. After a confirm, `magnetite:covers` can find the cover with the correct names.

## Surface

`php artisan magnetite:identify [username] [--queue]`, a queue job with per-user status like `FindCovers`, a badge "Untagged" on the album header with a "Suggest" button and a confirm/dismiss panel listing track-by-track matches.

## Open questions

- Compilations: the shape match works, but the album artist of a various-artists release is MusicBrainz's "Various Artists".
