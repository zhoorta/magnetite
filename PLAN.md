# Magnetite plan

A web player where the look is the product: themes are one JSON bundle (palette, radius, font, default visualizer + params), visualizers are pluggable canvas modules, and playback never stops while you navigate.

## Positioning (decided 2026-10-08, revisit)

Library servers are a solved problem (Navidrome, Koel, Funkwhale). Feishin and Koel already ship palette themes and visualizers, so the gap is: themes as full bundles in a free shareable file, a polished fullscreen now-playing mode, and a PHP/Livewire option. Pitch the look, not the library.

Decided 2026-10-08: stay standalone, with its own thin library (scanner, covers, ALAC conversion). An OpenSubsonic client was considered and dropped for now (Feishin already covers it, and the scanner work would be thrown away); it could return later as an alternative track source behind the same player. Extracting the theme engine as a package stays an option.

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
- [x] Theme flash on full reload removed (2026-10-08): an inline head script applies the saved theme before first paint; measured per frame, only the saved colour is shown now (it used to show the default for a frame). `wire:navigate` never flashed
- [x] Name check (2026-10-08): usable, with caveats. Packagist: no `magnetite` vendor or package. npm: `magnetite` is a 0.0.0 placeholder from 2022, `magnetite-player` is free. GitHub: the `magnetite` user is taken, and `scryst/magnetite` is a one-star MIT macOS music player in Swift created 2026-09-24 (same name, same category). Domains: .com, .app (bought 2026-09-01) and .dev taken, .io registered; .fm, .music, .audio, .pt had no registration found (RDAP is unreliable for some ccTLDs, confirm at a registrar)
- [x] Level meters (2026-10-08): `vu-needle` (analog dials with a red zone, clip lamp, 300 ms ballistics) and `vu-leds` (segmented bars with peak hold) visualizers, per-channel L/R RMS and peak from a channel splitter and one analyser per channel (the heard signal is untouched), idle state drawn before the first play, themes pick their meter, new "Vintage VU" theme. Verified with exact test tones: left-only tone leaves the right meter silent, levels within about 0.3 dB of the expected -9.0 dBFS RMS / -6.0 peak (MP3 test files)
- [x] MilkDrop (Butterchurn) tried and removed (2026-10-08): it worked (lazy-loaded WebGL visualizer, per-theme presets, overlay) but the author did not like it, so the adapter, the packages and the WebGL canvas machinery are gone. Not planned again; if a WebGL visualizer is ever wanted it needs a second canvas because a canvas keeps one context type
- [x] Audio unlock (2026-10-08): playback no longer waits on `AudioContext.resume()`; any click or key press unlocks a suspended context, and the player shows "Sound is blocked by the browser" while it plays suspended. Written after a "can't hear sound" report that I could not reproduce in headless Chrome (it was fixed on the author's Safari after this change, so a suspended context is the likely cause, though the exact trigger was not confirmed): the Web Audio routing was traced in headless Chrome (source to analyser to speakers intact, also with MilkDrop; element not muted, volume 1, context running). The blocked state itself could not be tested because headless Chrome does not enforce the policy
- [x] Magic eye meter (2026-10-08): `magic-eye` visualizer, one 6E5/EM84-style tube per channel (glass, phosphor target that brightens with level, shadow wedge that closes as the level rises, cathode cap), glow colour from the theme's second accent, new "Tube radio" theme. Checked with the exact-level test tones: left-only leaves the right eye wide open and dim, stereo closes both equally, readable in the small dock
- [x] More VU looks (2026-10-08): `vu-receiver` (amber backlit dial pair with metal bezel, screws and glass glare, "Amber receiver" theme) and `vu-wide` (long shallow cassette-deck style meters that stack on tall canvases and sit side by side in the dock; Terminal theme now uses it in green with a red zone). The dial drawing is shared in `dial.js` with `vu-needle`, whose look is unchanged. Checked with the left-only test tone in full screen and in the dock
- [ ] More VU looks not built: twin needles on one dial, 270 degree round gauge, VFD bar graph, vertical channel strips, studio black face, reel-to-reel cream and chrome; needle overshoot and bounce; 0 VU calibration choice

## Next
- [ ] More meters, in the order the author liked them (restrained, instrument-like ones; no busy visualizers): goniometer plus phase correlation (needs the L/R sample buffers exposed from the engine), third-octave analyser with peak hold, LUFS loudness (K-weighting and gating needed to be accurate), PPM, crest factor, nixie or 7-segment dB readout, BPM display
- [ ] Add a volume control (there is none)
- [ ] Test in Safari, the author's own browser, not just headless Chrome: audio unlock, the meters, ALAC played natively (the ffmpeg conversion is not needed there)

- [ ] Optional: `magnetite:scan --transcode` to pre-convert ALAC; cap or document the cache size (about the size of the ALAC files, 37 MB for the 4 tracks tried)
- [ ] Trademark search for "Magnetite" (EUIPO TMview, Portuguese INPI), and decide whether to buy a domain (.fm, .music, .audio and .pt showed no registration; .com, .app and .dev are taken)
- [ ] Tune visualizers further with varied music (radial and scope looked good; bars are better after the tilt)
- [ ] Pest tests for theme JSON validation (stream, cover, scanner and library page are covered, 14 tests)
- [ ] Theme editor with live preview, export and import
- [ ] Fullscreen now-playing polish, playlists, keyboard shortcuts
- [ ] Flux UI for library, queue and settings screens
- [ ] Licence (AGPL is consistent with the other projects), README, demo GIF

## Known risks

- Mobile Safari: strict `AudioContext` and background playback rules.
- Albums without an album-artist tag are grouped by title within their folder; a compilation split across folders becomes several albums.
- The scan runs synchronously from the command; move it to a queued job when the library page offers a rescan button.
- Gapless playback and crossfade need a second element; skipped for v1.
