# File analysis

Magnetite can measure every file from the audio itself. Tags and file names are not used. The results show whether a file is what it claims to be, and how it was mastered.

## Running it

    php artisan magnetite:analyze

This measures the tracks that are new or changed. `--force` measures everything again and `--limit=N` stops after N tracks. In the library, `A` analyses the selected album, folder or track.

It needs ffmpeg, ffprobe and Node. They are in the Docker image; on another install they are found on the `PATH` or set in [Configuration](configuration.md). The music files are only read.

## What is measured

| Measurement | Notes |
|---|---|
| Dynamic range (DR) | The difference between the loud parts and the peaks, as one number per track; an album's DR is the mean of its tracks |
| Integrated loudness (LUFS) and loudness range (LU) | ITU-R BS.1770 and EBU R128 |
| True peak (dBTP) and sample peak (dBFS) | True peak is measured with 4 times oversampling |
| Clipped peaks | Runs of equal samples at the ceiling |
| Average spectrum | Where the content ends, and whether it ends in a steep drop |
| Bit depth in use | The bits that are used, against the depth the file declares |
| Silent gaps, DC offset, dual mono, inverted channels | Signs of a bad rip or conversion |
| Decode errors, missing audio | A file shorter than its header says |
| Audio hash | Finds exact duplicates, whatever the tags say |

## Findings

A finding is a conclusion drawn from the measurements. There are three levels.

**Problem**

- **Likely lossy source**: a lossless file whose spectrum drops steeply at or below 20.5 kHz, as an MP3 or AAC encoder leaves it.
- **Likely upsampled**: sampled above 48 kHz with nothing above 22.5 kHz.
- **Padded bit depth**: stored as 24-bit with only 16 bits in use, for example.
- **Clipped**: 100 or more flat-topped peaks.
- **Decode errors**, **Cut short**, **Channels out of phase**.

**Warning**

- **Very compressed**: DR 5 or less.
- **Clipped**: 10 to 99 flat-topped peaks.
- **Peaks over 0 dBTP** by 1 dB or more.
- **DC offset** of 1 % or more.
- **Exact duplicate** of another file in the library.

**Note**

- Peaks over 0 dBTP by less than 1 dB, silent gaps, dual mono, and another copy of the same recording (same artist and title, about the same length, different audio).

## Where the results show

- A **DR** value on each track and album, coloured by how compressed it is.
- A `!` on rows with findings.
- `l` on a track opens its measurements: the numbers, the average spectrum with the cut-off marked, and each finding in words.
- `:truth` opens the **Truth view**: every file in one list, worst first. `s` changes the order.
- `o` opens the **Findings view**: only the files with a finding.
- The player bar shows the format, DR and loudness of the playing track.

`I` chooses how much of this the lists show.

## Limits

The lossy-source and upsampling findings are estimates, which is why they say "likely".

- A lossy file encoded at a high bitrate has no steep drop to find, so it is not flagged.
- A master that was low-passed at 20 kHz on purpose looks like a lossy source.
- A very quiet, dark recording at 96 kHz can read as upsampled.
- Digital silence between sections of electronic music is reported as a note; it is not damage.
- The DR numbers follow the published method but were not compared with the official DR meter.

Next: [Themes](themes.md).
