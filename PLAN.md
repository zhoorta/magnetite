# Magnetite plan

A web player where the look is the product: themes are one JSON bundle (palette, radius, font, default visualizer + params), visualizers are pluggable canvas modules, and playback never stops while you navigate.

## Positioning (decided 2026-10-08, revisit)

Library servers are a solved problem (Navidrome, Koel, Funkwhale). Feishin and Koel already ship palette themes and visualizers, so the gap is: themes as full bundles in a free shareable file, a polished fullscreen now-playing mode, and a PHP/Livewire option. Pitch the look, not the library.

Open choice: keep the thin built-in library (current prototype) or become an OpenSubsonic client for the user's Navidrome (audio must be proxied or served with CORS, or the analyser returns zeros). Alternative path: extract the theme engine as a package first.

## Status

- [x] Prototype: persistent `@persist` player, AudioEngine, Range streaming endpoint
- [x] Visualizers: bars, radial, scope (own registry contract)
- [x] Themes: 3 JSON themes applied as CSS variables, saved in localStorage
- [x] Verified in headless Chrome: playback and analyser survive `wire:navigate`, seek works
- [x] Fix: re-apply theme after `wire:navigate`
- [x] Real music (2026-10-08): library path is configurable (`MAGNETITE_MUSIC_PATH`) and scanned recursively; stream endpoint checks the resolved path stays inside it
- [x] Fixes from real tracks: opaque fullscreen overlay, treble tilt on the bars, error state for undecodable files

- [x] Real library (2026-10-08): `albums` and `tracks` tables, getID3 scanner (`php artisan magnetite:scan [--force]`, skips unchanged files by size and mtime, drops deleted ones), tags, codec, duration, embedded or folder cover art stored once as a 500px JPEG on the private disk, streaming and cover by id, Pest suite with committed fixtures
- [x] ALAC is now detected at scan time (getID3 leaves it empty, so the QuickTime sample description is read directly) and flagged in the library
- [x] ALAC plays everywhere (2026-10-08): the stream endpoint serves ALAC as lossless FLAC, converted once by ffmpeg on first play and cached in `storage/app/private/transcodes` (named by path, size and mtime; pruned on scan). First play of a track takes about a second, later plays and seeks are instant. Without ffmpeg ALAC is served as is and flagged in the library (22 tests)

## Next

- [ ] Optional: `magnetite:scan --transcode` to pre-convert ALAC; cap or document the cache size (about the size of the ALAC files, 37 MB for the 4 tracks tried)
- [ ] Check name availability (Packagist, GitHub, npm, domains)
- [ ] Tune visualizers further with varied music (radial and scope looked good; bars are better after the tilt)
- [ ] Remove the theme flash on full reload (inline head script)
- [ ] Decide positioning (thin library vs Subsonic client vs theme package)
- [ ] Pest tests for theme JSON validation (stream, cover, scanner and library page are covered, 14 tests)
- [ ] Butterchurn adapter (lazy-loaded) behind the same visualizer contract
- [ ] Theme editor with live preview, export and import
- [ ] Fullscreen now-playing polish, playlists, keyboard shortcuts
- [ ] Flux UI for library, queue and settings screens
- [ ] Licence (AGPL is consistent with the other projects), README, demo GIF

## Known risks

- Mobile Safari: strict `AudioContext` and background playback rules.
- Butterchurn needs WebGL 2 and is heavy; lazy-load.
- Albums without an album-artist tag are grouped by title within their folder; a compilation split across folders becomes several albums.
- The scan runs synchronously from the command; move it to a queued job when the library page offers a rescan button.
- Gapless playback and crossfade need a second element; skipped for v1.
