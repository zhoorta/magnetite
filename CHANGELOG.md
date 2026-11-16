# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/). Versions follow [semver](https://semver.org/) from the first tagged release; the server, the terminal client and the apps are versioned separately until then (Android 0.4.x, terminal client 0.1.0).

## [Unreleased]

### Added
- The docked player has a compact mode on phones (no meters, small cover); the choice is saved per browser.
- Media Session: lock screen, headset and media-key controls with the track, cover and position.
- One-command Docker install (`docker run`), a first-run page (`/setup`) and a CI smoke test of the image.
- Terminal client, Android (phone, tablet, TV) and iOS clients.
- Dense, keyboard-first library with search, a truth view, an edit table and an online lookup.
- File truth: loudness, true peak, dynamic range, clipping, lossy-source and upsampling findings per file.
- Multi-user libraries with sharing, playlists, Ferrite storage, and a read-only demo library.
- Themes as JSON, with the theme studio and 16 bundled open fonts.
- The terminal client shows the library's folders as a tree, remembers which are open, makes, renames (`r`), moves and deletes (`d`) folders, moves albums into them (`x` marks, `v` chooses), shows folders in the search and has a flat list (`z`).

### Changed
- The whole repository is licensed AGPL-3.0-or-later.
