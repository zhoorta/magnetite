# Meters and posters

The player shows up to four meters at once, next to the track or in full screen. Which meters show is kept per account, so the web app and the other clients show the same list.

## Choosing meters

The **Meters** button in the player opens a menu with a picture of each meter; a click adds or removes it. With the keyboard:

| Key | What it does |
|---|---|
| `m` `M` | Next or previous meter on the active panel |
| `+` `-` | Add or remove a panel (0 to 4) |
| `[` `]` | Choose which panel `m` changes (`Tab` in full screen) |
| `c` | Take the meter colours from the cover of the playing album |
| `F` | Full screen; the controls hide after 3 seconds without a move |
| `Shift+H` | Keep the controls showing in full screen |

The meters use the colours of the theme in use.

## The meters

| Meter | What it shows |
|---|---|
| Wide meters | The level of the left and right channel on two long, shallow needle scales |
| VU needles | The same levels on two needle dials |
| LED meters | The same levels as bars of segments |
| Magic eyes | The same levels as two tuning-eye tubes |
| Scope | The waveform |
| Goniometer | The stereo image: left against right, with the phase correlation |
| Loudness (LUFS) | Momentary, short-term and integrated loudness, and the true peak |
| Third-octave | The spectrum in 31 bands |
| Spectrogram | The spectrum over time |

Loudness, true peak, correlation and the goniometer are computed from every sample, following ITU-R BS.1770 for loudness. The same code does the [file analysis](file-analysis.md), so the live reading and the stored numbers agree.

## Posters

Posters are meters drawn like printed posters: flat shapes in a few colours, with the artist, title and album of the playing track printed on them. They are in the same menu, in their own group, and count towards the four panels like any other meter.

Their shapes follow the music. Some examples:

- **Arches**, **Fan**, **Pillars**: each shape is a frequency range and grows with its energy.
- **Lens**: one disc per channel; they overlap more as the channels become more alike.
- **Halftone**, **Facets**: a grid that scrolls like a spectrogram.
- **Dials**: four gauges for short-term loudness, true peak, correlation and dynamic range.

A poster is upright, so in full screen two posters sit side by side.

Next: [File analysis](file-analysis.md).
