# Poster meters

Meters drawn like printed posters and book covers: flat shapes in a few colours, hard edges, slow movement, and the track's artist, title, album and format printed on them. All fourteen designs of the mockup are built (`resources/js/magnetite/visualizers/poster-*.js`).

Mockup of the designs, on a fake signal: `docs/mockups/poster-meters.html`.

## The built ones

| Meter | What moves | Reads |
|---|---|---|
| Lens | Two discs, one per channel; the overlap is the stereo correlation, each disc grows with its level | levels, stereo |
| Warp | Stripes part around an eye; the opening follows the level | levels |
| Lenses | One line per band group swells into a lens with its energy, and drifts | bands |
| Arches | Six nested arches, low to high; height is energy | bands |
| Fan | Twelve rays from a corner; length is energy | bands |
| Overcome | One hairline per third-octave band; two circles ride the L and R peaks | bands, levels |
| Pillars | Seven rounded pillars and a corner circle that swells with the lowest range | bands |
| Facets | A triangle grid that scrolls like a spectrogram (only while playing) | bands |
| Dials | Four circles filled along a diagonal like gauges: short-term LUFS, true peak, correlation, DR (DR only for your own, measured tracks) | lufs, stereo |
| Columns | Two sets of columns, L and R; a circle cut by a diagonal stripe rides each with its level | levels |
| Rings | A planet and nine curved stripes behind and in front of it; stripe thickness is band energy, the planet swells with the level | bands |
| Halftone | A halftone dot grid that is a scrolling spectrogram (dot size is energy), light and dark blocks, and two flat blocks that grow with L and R | bands, levels |
| Heads | Sixteen heads, one per range, each swelling with energy; the red one is the loudest range (with hysteresis, so it does not flicker) | bands |
| Slides | Four rows of half-discs, low to high; a dome and a bowl slide towards each other with the energy and overlap | bands |

## Themes

Themes carry no meter settings, so every poster uses its defaults (`theme` level 1) on every theme.

## How they work

- `poster.js` holds what they share: a poster is drawn in its own 600 x 800 space, scaled to fit the cell and centred on the paper colour (`begin`, which returns `{ scale, text }`), so a wide cell in the dock shows a smaller poster with margins. `groupBands` / `Bars` turn the 31 third-octave bands into N groups with rise and fall; `palette` gives the colours; `posterText` and `metaColumns` say what to print; `drawName` and `drawColumns` fit text. Pure parts are tested in `tests/js/poster.test.mjs`.
- Every poster has the same settings plus its own colours: `text` (0 off, 1 on; text is also left out when the cell is too small, under 30% scale), `theme` (0 = the poster's own colours; 1, the default = paper, ink and the dark panel follow the theme, so the poster sits on the theme's background while its oranges, reds and greens stay; 2 = every colour follows the theme: paper = background, ink = text, a and b = the two accents), `range` (how many dB below the track's own peak still show; 40 for the band shapes, 30 for the level shapes, and a gentle curve keeps ordinary levels in the middle so only peaks reach the top), and rise and fall. The scale follows the track (`Reference` in `poster.js`: it follows the loudest band or channel quickly up and 2 dB a second down), so a quiet recording and a loud master move alike; Dials is the exception, it shows absolute LUFS and true peak. A colour set to null follows the theme too.
- Text comes from the playing track (`artist`, `title`, `album`, `year`, `number`, `duration`, `codec` and the `truth` for bit depth, rate and DR; only the owner's own tracks have `truth`). With no artist the title becomes the name. Fonts: the theme's meter font (`colors.font`).
- Overlaps (Lens, Slides) darken on a light paper and lighten (`screen`) on a dark one (Slides decides per row). Text and shapes that sit on a poster's own colour pick what reads best (`readableOn`, `darkest` in `poster.js`): the Facets text on its orange, the Warp pupil, the Lenses name on its panel.
- Checked on the Terminal, Late-night FM and Paper & ink themes (2026-11-13); other themes are not checked one by one.
- The module `label` is "Poster: Name". The meter menu in the player bar lists the other meters first, then "Posters" as a group that opens in place (`visualizers/menu.js`, tested; a group is open when one of its meters is on, and shows how many are on; Posters starts open). A new group is one line in `GROUPS` there.

## Thumbnails

The meter menu shows the posters as a grid of pictures with the name under each. A poster's picture is drawn once per theme by `visualizers/thumb.js` on a canvas of its own, with its default settings, text off and a canned demo signal (`demo-sample.js`); `loadThumbs()` in the player store makes them one by one when the menu is first opened (about 150 ms for all fourteen) and again after a theme change. This is why the poster meters keep their state per canvas context (`stateOf`), so drawing a picture never disturbs the live meter.

## Ideas not done

- Halftone is the heaviest to draw (about 1,200 dots a frame, batched into two paths); not measured on a phone yet.
- More bundled themes with poster palettes (red and black, teal and brown); names should not be brands.
- Thumbnails for the other meters (they keep state at module level, so they would need the same per-canvas change).
- Colour check of the posters' own palettes against the theme's contrast rules.

The references were album, book and gig posters seen on the web; the code draws its own shapes, and no artwork, band name or typeface is copied. Keep it that way when adding more (and avoid brand names such as Soundcraft in labels and themes).
