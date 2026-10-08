# File truth

Decided 2026-10-08, built 2026-10-09. The point: tell the author, from the audio itself, whether a file is what it claims to be and how well it was made. Nothing here uses tags or file names.

`php artisan magnetite:analyze` (separate from `magnetite:scan`, which stays fast) decodes every track once and stores the measurements on the track. `--force` redoes everything, `--limit=N` stops after N. A track is measured again when its file changes (the scanner clears the old result) or when `TrackAnalyzer::VERSION` is raised. Needs ffmpeg, ffprobe and Node (`MAGNETITE_FFMPEG`, `MAGNETITE_FFPROBE`, `MAGNETITE_NODE`, or found on PATH); the command says which one is missing. The music folder is only read.

## How it runs

`TrackAnalyzer` starts `node bin/measure.mjs --ffmpeg ... --ffprobe ... <file>` with an argument array (no shell, and only for a path that `TrackLibrary::resolve` accepted). The script runs ffprobe for the stream facts, then ffmpeg decoding to raw 32-bit PCM on a pipe, and feeds the chunks to `TrackAnalysis` (`resources/js/magnetite/analysis/`), which prints one JSON document. Sources with more than two channels are downmixed to stereo by ffmpeg; mono stays mono. Speed: about 80 times real time for a stereo 44.1 kHz track (a 6 minute track in about 4 s), so a library of 10 000 tracks is an overnight job, once.

Node is used instead of PHP because the loop runs over every sample (13 million per track): the same `MeterDsp` as the live meters does the loudness and true-peak maths, so the player and the report cannot disagree.

## What is measured

Stored in `tracks.analysis` (JSON), with `dr` and `pcm_sha1` also as columns.

| Measurement | How | Used for |
|---|---|---|
| Integrated loudness (LUFS) and loudness range (LU) | BS.1770-4 K-weighting with both gates, EBU Tech 3342 range | information |
| True peak (dBTP) and sample peak | 4x oversampling, 193-tap Kaiser sinc | headroom, inter-sample overs |
| Dynamic range (DR) | Pleasurize Music Foundation method: per channel, 3 s blocks, RMS of the loudest 20% (RMS counted +3 dB) against the second highest block peak, channels averaged, rounded; album = rounded mean of its tracks. A partial last block counts if at least half a block | the DR badge |
| Clipping | runs of exactly equal samples at the ceiling: 3 or more at full scale (>= 0.9999), 6 or more between 0.97 and full scale | clipped peaks |
| Average spectrum | 4096-point Hann FFT of the mono mix, mean power per 500 Hz band | the cut-off, the content ceiling, the small graph |
| Cut-off | the highest steep drop (30 dB over about 500 Hz, from 8 kHz up) after which the spectrum stays low | lossy source |
| Content ceiling | upper edge of the highest band above -95 dB (a full-scale sine reads 0 dB) | upsampled hi-res |
| Effective bit depth | the bits actually used in the decoded 32-bit samples (trailing zero bits) against the declared depth | padded bits |
| Digital-silence gaps | 20 ms to 5 s of exact zeros between sound before and after | dropouts in a bad rip |
| DC offset, dual mono, inverted channels | mean of each channel, `L-R` and `L+R` peaks | bad conversions |
| Decode errors, missing seconds | ffmpeg's error output, decoded length against the header | truncated rips and downloads |
| PCM hash | SHA-1 of the decoded 32-bit PCM | exact duplicates |

## What it says about a file

`App\Support\FileTruth` turns the measurements into findings; all thresholds are constants there, so changing one needs no new analysis. Levels: `problem`, `warn`, `note`.

- **problem** likely lossy source (a lossless file with a cut-off at or below 20.5 kHz), likely upsampled (above 48 kHz with content ending at or below 22.5 kHz), padded bit depth, clipped (100 or more flat tops), decode errors, cut short, channels out of phase
- **warn** heavily compressed (DR 5 or less), clipping (10 to 99), peaks 1 dB or more over 0 dBTP, DC offset of 1% or more, exact duplicate
- **note** peaks over 0 dBTP, silent gaps, dual mono, another copy of the same recording (same artist and title, length within 2 s, different audio)

The library page shows a DR badge per track and album, the problem and warning labels on the row, a "only files with problems" filter, and a detail panel per track with the numbers, the average spectrum (the cut-off is marked) and every finding in words.

## Checked, and what is not

- Loudness: all 36 tracks of the author's library against ffmpeg's `ebur128` filter: integrated within 0.1 LU (ffmpeg rounds to 0.1), loudness range within 0.3 LU, true peak within 0.16 dB (mean +0.005; ffmpeg uses a different, shorter filter). The first version of the true-peak filter (49 taps, Blackman window) read up to 0.5 dB low on loud masters, so it was replaced.
- DC offset against ffmpeg's `astats`: the same to four digits.
- Synthetic signals in `npm run test:js` (31 tests): known DR, second-peak rule, clipping, bit depth, cut-off at 16 kHz found and full-band content not, content ceiling of real and upsampled 96 kHz, silence gaps, dual mono and inverted, LRA of two levels, chunking independence.
- End to end in Pest (`tests/Feature/AnalyzeTest.php`, skipped without the tools): a sine scores DR0, and a 128 kbit/s MP3 turned into FLAC (twice) is flagged as a lossy source and as a duplicate, while the MP3 itself is not accused.
- By hand on files made from one of the author's tracks with ffmpeg: a 128 kbit/s MP3 decoded to FLAC cuts off at 17 kHz (44 dB drop) and is caught; the original is clean; 16-bit audio stored as 24-bit reads 16 of 24; a CD track resampled to 96 kHz has content to 21.5 kHz and is caught, while the author's real 96 kHz files have content to about 28 kHz.
- **Not validated:** the DR numbers against the official DR meter or the DR database (the algorithm follows the published description, but no reference output was available; compare a few albums before trusting the last digit). The lossy-source and upsampling findings are heuristics.
- **Known blind spots:** a lossy file encoded at a high bitrate has no cliff to find (MP3 V0 and AAC at 256 kbit/s decoded to FLAC were not caught); a CD master that was low-passed at 20 kHz on purpose looks like a 320 kbit/s MP3, which is why the text says "likely" and shows the frequency; digital silence between sections of electronic music is reported as a note, not damage; a very quiet, dark recording can have no content above -95 dB and read as upsampled at 96 kHz.

## Later

- Edition compare: two masters of one album side by side, loudness-matched.
- Hole detection for lossy files (missing blocks in the spectrogram that a high bitrate leaves), and a full-track spectrogram picture drawn from the decoded file.
- Chromaprint fingerprints to find the same recording across formats and masters.
- Run the analysis from the library page or after a scan, as a queued job.
