# Terminal client

Status (2026-11-09): started in `tui/` (Rust). Same `/api/v1` as the mobile apps; no server changes. Builds, the decoder is tested on the fixtures (`cargo test` in `tui/`); not yet tried against a running server or through the speakers.

## Why Rust and native audio
The point is real meters (spectrum, VU, correlation, later LUFS and true peak), which need the samples. An mpv backend would be less code but gives no access to them. So the client decodes itself: `symphonia` (FLAC, ALAC, MP3, AAC, Ogg, WAV) into `cpal`, with a tap of the last 4096 frames that the UI thread turns into meters (`rustfft`).

## Use
```
cd tui && cargo run --release -- login https://magnetite.stackcare.pt   # shows a code, approve it at /device
cargo run --release                                                      # the player
```
The token is kept in `~/.config/magnetite/tui.json` (mode 600). Keys: `j/k` move, `enter` open an album or play from a track, `space` pause, `n`/`p` next and previous, `,`/`.` seek ∓10 s, `tab` choose the active meter panel (its title is marked ▸), `m`/`M` next/previous meter for it (never one already shown), `+`/`-` add or remove a panel (0 to 4, 2 x 2; `-` removes the last one too and `m` or `+` brings one back), `i` file-truth table (below), `f` full screen (esc closes), `t`/`T` next/previous theme, `h` back, `q` quit.

## Meters
Same contract as the web visualizers: each draws only into the cell it is given (`src/panels.rs`), and the ids are the web's. Drawn: `vu-wide` (the web's look: two long shallow windows, 4.2 : 1, a 28° scale arc with the pivot far below so the needle sweeps sideways, red zone from -6, ticks every 2 dB, labels, clip lamp; stacked or side by side by whichever is bigger, centred; the window is the theme's `surface`), `vu-leds` (level bars with peak hold), `third-octave` (31 ISO bands, 3 dB/octave tilt), `spectrogram` (half blocks, about 10 columns a second), `scope` (braille, rising-edge trigger, auto gain), `goniometer` (braille, mid/side), `loudness` (live M, S, I, LRA and true peak from the `ebur128` crate fed with every sample played, plus a minute of momentary history), `vu-needle` (the web's `dial.js`: two faces 1.55 : 1 side by side and centred, 96° scale of -30..+3 dBFS with the pivot near the bottom, red from -6, ticks every 2 dB, labels, "dB", L / R, clip lamp, needle with tail and hub, 0.3 s VU ballistics, braille), `magic-eye` (two tubes whose shadow wedge closes with the level, 78° open at silence, same ballistics as the web) and `spectrum` (24 log bars, terminal-only). All the web's meters are drawn now. Which meters show: this client's own list in `tui.json` (`tab`, `m`, `+`, `-`), else the account's list from `/me`, else spectrum and wide meters. Changing it here does not touch the account setting.

## Full screen
`f` is the web's full screen: the page background, the meters filling the terminal with no borders, laid out by aspect ratio like `layout.js` (a wide terminal puts two side by side, a tall one stacks them; 2 x 2 beats 3 + 1; the same rule is used in the small dock), then the title, artist (accent) and album, the progress line and a hint with the active meter. Playback keys, `tab`/`m`/`+`/`-` and `t` keep working; browsing keys are off. `esc` or `f` returns.

## File truth table
`i` replaces the two lists with every analysed track of your library in one table: marker (✗ problem, ! warn, · note), track, album, format (codec, bits/kHz), DR, LUFS, true peak and the finding labels, with the selected track's numbers and every finding explained underneath. `s` cycles the order (worst findings first, lowest DR, loudest, highest true peak, library order), `o` keeps only tracks with findings, `j`/`k`, `PgUp`/`PgDn`, `g`/`G` move, `enter` plays from there and **the list shown becomes the queue**, so `n`/`p` walk through, say, all the clipped files; `i` or `esc` goes back. The API has no list of tracks with their truth, so the first `i` reads every album (`/albums/{id}`, four threads; 74 albums took 8 s) and the title shows `loading n/N` meanwhile; the table is kept until you quit. Planned (in `PLAN.md`): one server endpoint that lists every analysed track with its truth, so this takes a single request. Only your own analysed tracks appear. Duplicates are found across the whole library by the server, so they show here too.

## Cover
The album cover is fetched in the background from `/cover/{album}` with the token (path and query only, so a proxy's scheme or host does not matter), decoded with the `image` crate (JPEG and PNG), scaled to 256 px at most and drawn with half blocks, a square as tall as the meters area, left of the meters in the player and in full screen, only when the area is at least 70 columns wide and the cover has loaded. Terminal image protocols (kitty, iTerm2, sixel) are not used yet, so it is a mosaic of about 14 x 7 to 40 x 20 pixels in the dock and bigger in full screen.

## Resume
The track and position are saved in `tui.json` (`last`) when a track starts, every 5 s while playing and on quit. The next start opens that album with the track selected, shows its cover and says paused; `space` starts it from the saved position (`,` `.` move that position first, `enter` plays something else). Nothing plays by itself. The saved album is the one the queue came from (not the one open in the list). If the album or track is gone or no longer shared with you, it starts as before.

## No meters
With every meter removed the player shows the cover on the left (a square, up to a third of the width) and beside it the track (title, artist), the album (title, year, track n of N, album DR), the format (codec, effective bits / sample rate, "stored as" when padded), the file truth (DR and its class, LUFS, LRA, true and sample peak, clipped runs, content and cut-off frequency) and every finding with its explanation (problem in red, warn in accent2, note muted); the full screen is the cover big in the middle with the same information centred below it. Tracks that are not yours or not analysed say so. The empty list is saved in `tui.json`, so it stays that way after a restart (a client that never chose anything still starts from the account's list).

## Themes
The six built-in themes are read from `resources/js/magnetite/themes.json` at build time (one source with the web player). Your own go in `~/.config/magnetite/themes/*.json` (a theme object or a list; same bundle as the web, only `colors` is used; a theme with a built-in's id replaces it). `magnetite theme` lists them, `magnetite theme ID` chooses, `t` cycles in the player; the choice is saved in `tui.json`. Mapping: `bg` page, `surface` panes, `text`, `muted` borders and secondary text, `accent` focus, selection, spectrum bottom and VU, `accent2` spectrum top, VU above -12 dB and the playhead (red above -3 dB). Radius, font and the visualizer look of a theme do not apply. Without `COLORTERM=truecolor` (Terminal.app) colours fall back to the nearest of the 256-colour palette, so use iTerm2, Ghostty, kitty or WezTerm for the exact look.

## Rules kept
- Only the API is used; tracks without `stream_url` (not shared with the viewer) are greyed out and skipped.
- The file-truth line (codec, bits/rate, DR, findings) and the LUFS / LRA / true peak readouts come from `TrackResource.truth` (owner's tracks only); the live meters are computed locally.

## Known limits (v0)
- Playback streams in 256 KB Range chunks with 3 chunks of read-ahead (`src/stream.rs`), so it starts after the first chunk and an MP4 with its index at the end costs one more chunk. The next track is opened in the background. Chunks stay in memory for the track's life. Needs `Range` support from `/stream` (it has it). On a slow link (2026-11-09 from home: about 20 KB/s to magnetite.stackcare.pt, for static files too, while other hosts gave 3 MB/s) playback starts after about 23 s and then stalls: a 19 MB ALAC needs far more.
- Seek is `,`/`.` (±10 s, coarse); no seek bar click. No queue view, no search, no playlists or shared tab yet.
- Decoding is symphonia 0.6.1 (FLAC, ALAC, MP3, AAC, Vorbis, WAV) plus Opus through `symphonia-adapter-opus-rs` (pure Rust, no `cmake` or `libopus`), registered in `codecs()` in `src/audio.rs`. Checked against ffmpeg on real tracks from the library (2026-11-09): Opus 75 dB SNR, Vorbis 139 dB, ALAC 133 dB, all the same length; the ignored test `matches_ffmpeg_on_a_real_file` does it for any file. Seeks are exact: the reader lands at or before the target and the frames before it are dropped (Opus: up to 6.5 ms late, its pre-skip). M4A has no gapless trimming in symphonia, so an ALAC file starts with about 48 ms of encoder-delay silence that ffmpeg would cut.
- Resampling is linear (fine for meters, not audiophile); output must be f32 (macOS default).
- Multichannel is reduced to the first two channels.
- The loudness meter is live (`ebur128`); the LUFS in the info line is the stored analysis of the whole file.

## Ideas
`magnetite now` / `next` for tmux status bars, headless mode, offline sync, MPRIS.
