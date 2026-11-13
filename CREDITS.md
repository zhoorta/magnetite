# Credits

## Demo music on the start page

Short excerpts (about 75 seconds, with a fade) of free music, cut by `bin/prepare-demo.sh`. The audio is not in the repository; the list the page reads is `resources/demo/tracks.json`. Every track is released under [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/) by its author, which allows copying, changing and sharing it without asking or crediting. We credit the authors anyway. The licence of each track was read from its source page (archive.org's `licenseurl` field, OpenGameArt's "License(s)" field) on 2026-11-13.

| Track | Author | Source |
|---|---|---|
| Aria (Goldberg Variations) | Kimiko Ishizaka | [The Open Goldberg Variations](https://archive.org/details/The_Open_Goldberg_Variations-11823) |
| Variatio 16 (Ouverture) | Kimiko Ishizaka | [The Open Goldberg Variations](https://archive.org/details/The_Open_Goldberg_Variations-11823) |
| The Budding of Consciousness | Yoiyami | [OpenGameArt](https://opengameart.org/content/the-budding-of-consciousness-%E2%80%93-cc0-ambient-minimalist-theme-yoiyami-blue-series-%E2%80%93-no4) |
| The Way It Is | Zane Little | [OpenGameArt](https://opengameart.org/content/the-way-it-is) |
| Achilles | Zane Little | [OpenGameArt](https://opengameart.org/content/achilles) |
| Xenon Phoenix | continuum.mid and Bloo (from the EP "Ignoble") | [OpenGameArt](https://opengameart.org/content/xenon-phoenix) |

The cover shown while they play (`public/demo-cover.png`, drawn from `resources/demo/cover.svg`, which is `public/logo.svg` with another subtitle) is Magnetite's own and under the project licence.

The Goldberg recordings come from the archive's 96 kbps Vorbis copies, so the file-truth measurements call them a lossy source.

Check a track's licence again before adding it: some pages on these sites carry a different licence than their pack, or mix tracks under several.

## Fonts

Bundled for the theme studio, all under the [SIL Open Font License 1.1](https://openfontlicense.org/), taken from the `@fontsource` packages (latin subset, which covers Portuguese and the other western languages): Inter, Space Grotesk, IBM Plex Sans, Outfit, Quicksand, Righteous, Fraunces, Lora, JetBrains Mono, IBM Plex Mono, Courier Prime, Share Tech Mono, VT323, Silkscreen, Press Start 2P and Pixelify Sans. They are dev dependencies bundled by Vite (`resources/css/fonts.css`); each package carries its own licence text and the original authors are named in the font files.
