# Poster meters

Meters drawn like printed posters and book covers: flat shapes in a few colours, hard edges, slow movement, and the track's artist, title, album and format printed on them. Eleven are built (`resources/js/magnetite/visualizers/poster-*.js`); three from the mockup are not.

Mockup with all fourteen, on a fake signal: `docs/mockups/poster-meters.html`.

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
| Slides | Four rows of half-discs, low to high; a dome and a bowl slide towards each other with the energy and overlap | bands |

Not built, drawn in the mockup: Rings (a planet with stripes, close to Lenses), Halftone (dots as a spectrogram, the heaviest to draw), Heads (the loudest band is the red head).

## Themes

The bundled themes **Night** (charcoal, teal and violet) and **Cream & orange** (light) carry `theme: 2` for every poster meter, so on them the posters use only the theme's colours; on the other themes they use level 1. Android and iOS have their own theme lists and do not have these two.

## How they work

- `poster.js` holds what they share: a poster is drawn in its own 600 x 800 space, scaled to fit the cell and centred on the paper colour (`begin`), so a landscape cell in the dock shows a smaller poster with margins. `groupBands` / `Bars` turn the 31 third-octave bands into N groups with rise and fall; `palette` gives the colours; `posterText` and `metaColumns` say what to print; `drawName` and `drawColumns` fit text. Pure parts are tested in `tests/js/poster.test.mjs`.
- Every poster has the same settings plus its own colours (`meter-schemas.js`): `text` (0 off, 1 on; text is also left out when the cell is too small, under 30% scale), `theme` (0 = the poster's own colours; 1, the default = paper, ink and the dark panel follow the theme, so the poster sits on the theme's background while its oranges, reds and greens stay; 2 = every colour follows the theme: paper = background, ink = text, a and b = the two accents), the dB range, and rise and fall. A colour set to null follows the theme too. A theme may carry any of these like the other meters' settings.
- Text comes from the playing track (`artist`, `title`, `album`, `year`, `number`, `duration`, `codec` and the `truth` for bit depth, rate and DR; only the owner's own tracks have `truth`). With no artist the title becomes the name. Fonts: the theme's meter font (`colors.font`).
- Overlaps (Lens, Slides) darken on a light paper and lighten (`screen`) on a dark one (Slides decides per row). Text and shapes that sit on a poster's own colour pick what reads best (`readableOn`, `darkest` in `poster.js`): the Facets text on its orange, the Warp pupil, the Lenses name on its panel.
- Checked on the Terminal, Late-night FM and Paper & ink themes (2026-11-13); other themes are not checked one by one.
- The module `label` is "Poster: Name". The meter menu in the player bar lists the other meters first, then "Posters" and "Scenes" as groups that open in place (`visualizers/menu.js`, tested; a group is open when one of its meters is on, and shows how many are on). A new group is one line in `GROUPS` there.

## Ideas not done

- A landscape composition for the dock (text on the left, shapes on the right) instead of a small centred poster; today the dock shows shapes only, because the text is too small.
- More bundled themes with poster palettes (red and black, teal and brown); names should not be brands.
- Thumbnails in the meter menu.
- Colour check of the posters' own palettes against the theme's contrast rules.

The references were album, book and gig posters seen on the web; the code draws its own shapes, and no artwork, band name or typeface is copied. Keep it that way when adding more (and avoid brand names such as Soundcraft in labels and themes).
