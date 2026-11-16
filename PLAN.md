# Magnetite plan

A player where the look is the product: themes are one JSON bundle (palette, radius, font), meters are pluggable canvas modules that measure properly, and playback never stops while you navigate. Short status only; the dated log is `docs/history.md`, the detail is in the topic docs (`docs/README.md`).

## Positioning (decided 2026-10-08, revisit)

Library servers are a solved problem (Navidrome, Koel, Funkwhale). Feishin and Koel already ship palette themes and visualizers, so the gap is: themes as full bundles in a free shareable file, meters that are right (LUFS, true peak, correlation), file truth (what a file really is), a polished full-screen now-playing mode, and a PHP/Livewire option. Pitch the look, not the library.

Stay standalone with a thin library of our own (scanner, covers, ALAC conversion). An OpenSubsonic *client* was dropped (Feishin covers it); an OpenSubsonic *server* API is phase 5 of the open-source plan.

## Built

- [x] Persistent player (`@persist`), Web Audio graph, Range streaming with strict headers, ALAC served as FLAC (`docs/history.md`)
- [x] Library: getID3 scanner, covers, multi-user libraries with sharing (`docs/multi-user.md`), upload (`docs/auth-and-upload.md`), folders, editing (`docs/editing.md`), identify untagged albums (`docs/identify-albums.md`), covers from the web (`docs/covers.md`), delete
- [x] Playlists (private, shareable, drag-and-drop), keyboard shortcuts, resume after a reload
- [x] Meters: nine of them, up to four at once, sample-accurate LUFS, true peak, correlation and goniometer in an AudioWorklet (`docs/meters-and-file-truth.md`)
- [x] File truth: `magnetite:analyze`, dynamic range, findings, duplicates (`docs/file-truth.md`)
- [x] Themes as JSON bundles, theme studio with live meter preview, per-browser custom themes (simplified 2026-11-13: themes no longer carry meter settings)
- [x] Dense, keyboard-first library as the web UI: tree, tracks, command line, edit table, search, truth view (`docs/dense-library.md`)
- [x] Ferrite as library storage (`docs/ferrite-storage.md`)
- [x] Clients: Android phone, tablet and TV, released as 0.4.0 (`docs/android.md`); iOS in the simulator (`docs/ios.md`); terminal client in Rust (`docs/tui.md`)
- [x] Live since 2026-10-09 at magnetite.stackcare.pt, no Docker (`docs/bare-metal.md`); Docker image for others (`docs/docker.md`)

## Open

- [ ] **Open source and adoption** (`docs/open-source.md`): phase 0 history audit (the licence is AGPL-3.0-or-later everywhere, decided 2026-11-13), 1 one-container Docker install, 2 README, templates, releases, 3 client binaries, 4 shared Rust core, 5 Subsonic/OpenSubsonic server API (read-only first, `apiKey` auth), 6 one-file server only on evidence. Landing page and README refreshed 2026-11-13
- [x] Scenes (any picture as a meter) removed 2026-11-13: poster meters do the job with real measurements; the code is on the `scenes` branch
- [ ] Write edits back to the files' tags (`docs/editing.md`), opt-in with a preview
- [ ] Safari and a real phone for the dense library and the meters; iOS on a real device, drag and drop, CarPlay; Android: upload from the phone, keys in Search and the TV shell, a test on a real phone, tablet and box
- [x] Poster meters (2026-11-13): fourteen built (every design of the mockup), themes Night and Cream & orange, text printed from the track (`docs/posters.md`); Tunnel, Circuit and Terrain added (mockups `poster-tunnel.html`, `poster-circuit.html`, `poster-terrain.html`), Warp and Columns removed (branches `poster-warp`, `poster-columns`); the mockup `poster-glow.html` is not built; not yet seen on real music in the app
- [ ] More meters (PPM, crest factor, nixie readout, LRA, K-metering) and more VU looks
- [ ] Metadata sources beyond MusicBrainz and Cover Art Archive (optional), albums with a wrong tag, files in the library root
- [ ] Remote Ferrite latency test from a US server (`docs/bare-metal.md`)
- [ ] Backups with restic, targets managed in an admin page (design only)
- [ ] Name: trademark search (EUIPO, INPI) and a domain
- [x] Free music on the start page (2026-11-13): six CC0 excerpts, credits in `CREDITS.md` and `resources/demo/tracks.json`, audio not in git (`bin/prepare-demo.sh`, copied by hand, see `docs/bare-metal.md`)
- [x] Guide for users (2026-11-13): ten pages in `docs/guide`, shown at `/docs` (`App\Support\Guide`), linked from the top bar and an install section on the start page; no screenshots yet; the clone address is a placeholder until the repository is public
- [x] Demo library for visitors (2026-11-13): `/library` without an account shows the demo tracks in the library page, read only, with their measurements (`docs/dense-library.md`, "Demo library"); the top bar has Sign in
- [x] First run and user management (2026-11-16, `docs/multi-user.md`, `docs/guide/install.md`): `/setup` while there are no users (first visitor becomes admin, 404 afterwards, optional `MAGNETITE_SETUP_TOKEN`), `MAGNETITE_PUBLIC_SITE=false` (visitors go straight to sign-in), admin Users page (`/admin/users`: disk space and limit per user, total tracks only), per-user quota set there with optional `MAGNETITE_DEFAULT_QUOTA_GB` (checked against the scanned library size, not the disk), single-use invitation links (`/invite/{token}`, hash stored). Passwords without mail (2026-11-16): own password page, admin makes a one-time reset link (24 h), both revoke tokens and sessions. Not done: promote to admin, delete a user from the page, mail
- [ ] Fullscreen polish, add to queue / play next, Media Session, demo GIF
- [ ] Posters for other software (idea, 2026-11-15, brainstorm only, `docs/posters.md`): a standalone JS package of the poster modules, a standalone poster page (OBS browser source, Pi or dashboard), then a demo in a web host (Webamp/Butterchurn or Jellyfin); native ports (foobar2000, Kodi, VLC) only if there is demand
- [ ] Parked ideas: theme gallery with import by URL, cloud use as self-hosted with bring-your-own storage, Flux UI

## Known risks

- Mobile Safari: strict `AudioContext` and background playback rules.
- Albums without an album-artist tag are grouped by title within their folder; a compilation split across folders becomes several albums.
- Gapless playback and crossfade need a second element; skipped for now.
- With thousands of open album rows each key press in the dense library costs 60 to 90 ms; the tree would need windowing (`docs/dense-library.md`).
