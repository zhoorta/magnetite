# Meters and file truth

Decided 2026-10-08. Magnetite's identity is instruments, not eye candy: meters that measure, and an honest look at what is in the files. Personal and non-commercial, so meters are our own designs (style names, no brand names) and standards-exact ballistics are optional. LUFS is the exception: the maths is short and public (ITU-R BS.1770 / EBU R128), so do it properly.

## Live meters

Cost per meter (compute / draw):

| Meter | Cost |
|---|---|
| Peak, RMS, VU, phase correlation | trivial |
| Goniometer | cheap, cap the point count (a few thousand) |
| LUFS momentary and short-term | cheap (K-weighting is two biquads, then a sliding mean) |
| True peak | cheap to moderate (4x oversampling) |
| Spectrum, third-octave | cheap, from one shared FFT |
| Spectrogram | moderate, mostly fill rate (scroll a canvas by 1 px) |

Ten meters at once is realistic on a recent laptop. The cost comes from separate analysers and loops, not from the meters.

Design:

1. One shared analysis stage: one FFT feeds spectrum, third-octave and spectrogram. Never one `AnalyserNode` per meter.
2. Meter contract like the visualizers: `id`, `label`, `needs` (`levels`, `lufs`, `freq`, `stereo`), default `params`, `draw(g, sample, {w, h, colors, params, dt})`. The host computes the union of the `needs` of visible meters.
3. One `requestAnimationFrame` loop and one sample object. Skip hidden meters and everything when `document.hidden`. 30 fps is enough for needles and bars, only the spectrogram gains from 60.
4. No per-frame allocation (reuse typed arrays), or garbage collection shows as meter hiccups.
5. Static chrome (scales, bezels, labels) drawn once to an offscreen canvas; only needles and bars update.
6. Start with `AnalyserNode` plus the existing channel splitter. Move the scalar meters (LUFS, ballistics) to an AudioWorklet only if frame-sampled values look wrong. `postMessage` is enough for the data size.
7. Profile the current loop before adding anything (done, see below).

## File truth (scan time)

Measured once by `magnetite:scan`, stored per track, never at playback:

- integrated LUFS and loudness range: ffmpeg `ebur128` filter
- true peak
- DR score (dynamic range, per track and per album)
- clipping count (samples at full scale)
- spectral cutoff estimate: a hard cutoff near 16 or 19 kHz in a FLAC means a lossy source; flags fake hi-res too

UI: a per-track panel, badges on albums and tracks, a "suspect files" filter. Later: edition compare (same album, different masters) with loudness-matched A/B so the comparison is fair.

## Metadata sources

MusicBrainz is the backbone (CC0, 1 request per second). Others are optional enrichment: Cover Art Archive (covers), AcoustID/Chromaprint (identify untagged files), LRCLIB (synced lyrics), ListenBrainz (history), Wikidata/Wikipedia (bios). Discogs and Last.fm limit caching and commercial use; keep them as optional extras.

## Cloud idea (parked)

Self-hosted with bring-your-own storage (S3, Backblaze B2, WebDAV, Hetzner Storage Box): Magnetite is the player and index, the user's files stay in their storage. No copies of audio on our servers and no public sharing, because hosted copies of users' music is the MP3.com / Amazon Cloud Player copyright territory. No paid tier.

## Baseline (2026-10-08)

Headless Chrome on the author's Mac, a lossless track playing, 3 s per visualizer, via the dev probe in `boot.js` (`window.__magnetitePerf = []` collects `{viz, sample, draw, dt}` in ms per frame):

| Visualizer | sample avg / p95 | draw avg / p95 |
|---|---|---|
| bars | 0.20 / 0.40 | 0.09 / 0.20 |
| radial | 0.21 / 0.40 | 0.11 / 0.20 |
| scope | 0.15 / 0.30 | 0.10 / 0.20 |
| vu-needle | 0.17 / 0.30 | 0.21 / 0.40 |
| vu-receiver | 0.14 / 0.30 | 0.25 / 0.50 |
| vu-wide | 0.18 / 0.30 | 0.21 / 0.40 |
| vu-leds | 0.18 / 0.30 | 0.15 / 0.30 |
| magic-eye | 0.18 / 0.30 | 0.18 / 0.30 |

All hold 60 fps. A frame costs 0.3 to 0.45 ms of CPU out of 16.7 ms. Caveats: timer resolution is 0.1 ms, and this measures JavaScript only (building the canvas commands), not GPU raster, so recheck with the Performance panel in Safari when several meters are on screen. `sample()` always runs three analysers and reads two float buffers, even for meters that do not use them; that is what the `needs` contract trims (done the same day: sample cost went to 0.03 to 0.15 ms, level meters 0.05 ms, 60 fps unchanged, meter values checked after the change).

## Multi-meter layout (2026-10-08)

`panels` in the player store is a list of `{id, params}` (max 4). The loop asks `layout(n, w, h)` for cells, then for each panel saves the context, clips to the cell, translates to its corner and calls `draw` with the cell's own `w` and `h`, so visualizers need no change (they must only use the `w`/`h` they are given, never the canvas size). The column count minimises the distance of the cell shape from 1.6:1, with a penalty for a ragged last row, so four meters become a 2x2 grid on a wide canvas. `needs` is recomputed only when the panels change.

Measured with four meters at once (full screen, headless Chrome, 60 fps): level meters only (`vu-needle`, `vu-receiver`, `magic-eye`, `vu-leds`) 0.06 ms sample + 0.66 ms draw (p95 1.1); `bars`, `radial`, `scope`, `vu-wide` 0.22 + 0.52 ms. A visualizer can only be in one panel at a time because its meter state is per module.

## Worklet meters (2026-10-08)

`worklet/meter-processor.js` holds the DSP (`MeterDsp`, plain class, tested in Node) and the processor that wraps it. Decisions and numbers:

- Loudness follows BS.1770-4: K-weighting (head shelf plus RLB high-pass, coefficients computed for the actual sample rate), channel weights 1 for L and R, 100 ms sub-blocks, momentary = last 4, short-term = last 30, integrated = 400 ms blocks every 100 ms, absolute gate -70 LUFS, relative gate -10 LU. Short-term shows nothing until 3 s have played. The integrated value and true-peak hold reset when a new track starts (`engine.play(url)` posts `reset`). Stereo only: a mono source feeds both channels, surround is not weighted.
- True peak is a 49-tap Blackman-windowed sinc, 4x, each phase normalised to unity DC gain; phase 0 lands exactly on the input samples. Annex 2 of BS.1770 gives its own 48-tap coefficients; this is the same idea, not the same numbers, so it may differ from a certified meter by a few hundredths of a dB.
- Correlation is `sum(LR) / sqrt(sum(LL) * sum(RR))` with a 0.3 s exponential average; the goniometer plots mid on the vertical axis and side across, scaled so a full-scale mono signal just reaches the circle, with the last 1024 samples (23 ms at 44.1 kHz) per message.
- The worklet runs always, off the main thread; the `needs` contract only matters for what the main thread computes. Its output is silence connected to the destination, so the heard signal is untouched.
- Verified: Node tests against analytic values (see `PLAN.md`) and headless Chrome on a real file (loud master: -9.3 LUFS, -0.4 dBTP, correlation 0.87). Not verified: Safari, or any comparison with a reference meter.
- Gotcha found on the way: `php artisan serve` handles one request at a time, so `addModule` called after the audio request had started stayed pending forever. The file is therefore fetched in the engine constructor and loaded from a Blob URL.
