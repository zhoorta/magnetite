# Poster meters

Meters drawn like printed posters and book covers: flat shapes in a few colours, hard edges, slow movement, and the track's artist, title, album and format printed on them. Twelve designs of the mockup, the Tunnel, the Circuit, the Terrain, the Airport and the Pylon are built; Warp (stripes around an eye) and Columns were removed on 2026-11-13 and are kept on the `poster-warp` and `poster-columns` branches (`resources/js/magnetite/visualizers/poster-*.js`).

Mockup of the designs, on a fake signal: `docs/mockups/poster-meters.html`.

## The built ones

| Meter | What moves | Reads |
|---|---|---|
| Lens | Two discs, one per channel; the overlap is the stereo correlation, each disc grows with its level | levels, stereo |
| Circuit | A textbook cover: title and artist in rounded lowercase, flush right, over a one-colour logic schematic. Seven straps fill cell by cell with seven ranges (a tick holds the peak); a row's amplifier lights when any range reaches it, its gate when two do; two wide gates fill with L and R; domes flash on onsets and four count the strobe (bass onsets); boxes hold time played, length and format | bands, levels, progress |
| Lenses | One line per band group swells into a lens with its energy, and drifts | bands |
| Arches | Six nested arches, low to high; height is energy | bands |
| Fan | Twelve rays from a corner; length is energy | bands |
| Overcome | One hairline per third-octave band; two circles ride the L and R peaks | bands, levels |
| Pillars | Seven rounded pillars and a corner circle that swells with the lowest range | bands |
| Facets | A triangle grid that scrolls like a spectrogram (only while playing) | bands |
| Dials | Four circles filled along a diagonal like gauges: short-term LUFS, true peak, correlation, DR (DR only for your own, measured tracks) | lufs, stereo |
| Terrain | A vector workstation screen, two colours of line on a dark ground, where every panel is a reading. A wireframe city on a grid is the spectrum (eight columns are eight ranges; the front row follows the sound calmly, each row behind more slowly, so it does not jump); around it a spectrum strip, a ruler with the time played, L and R level, a clip lamp, the stereo width, a small goniometer, four ranges with their level, four folders that fill with momentary, short-term and integrated loudness and true peak, a window that prints the numbers, time played and left; the title is the logo | bands, levels, lufs, stereo, progress |
| Rings | A planet and nine curved stripes behind and in front of it; stripe thickness is band energy, the planet swells with the level | bands |
| Halftone | A halftone dot grid that is a scrolling spectrogram (dot size is energy), light and dark blocks, and two flat blocks that grow with L and R | bands, levels |
| Heads | Sixteen heads, one per range, each swelling with energy; the red one is the loudest range (with hysteresis, so it does not flicker) | bands |
| Slides | Four rows of half-discs, low to high; a dome and a bowl slide towards each other with the energy and overlap | bands |
| Tunnel | Line only, one colour: ruled boxes with the track's text, a small spectrum, a globe that turns with the level and the time played; seven circles hang from one top edge and the gap between two is a frequency range (more energy, wider gap) | bands, progress |
| Airport | An FAA airport diagram (US government chart, public domain) with the airport's names taken out of the picture and the track printed in their place. A plane rolls down a runway as the track plays, a different one for each track (the diagonal, the two long ones, the short one, chosen from the track's name); the centreline lights behind it. The long runways fill with L and R (lights from the south end), the short one with the peak, the diagonal one with the loudness; 30 bars east of the right runway are the bands, a dot at each held peak; the terminal flashes and the tower pings on bass onsets, twelve gate lights follow band groups; the pavement data become the levels in dB | bands, levels, progress |
| Pylon | A black-and-white information poster: heavy title over 44 concentric rings (the spectrum, bass in the middle, thin, calm and slow, with a faint ripple on a bass onset); a lattice pylon whose three crossarms are three tiers (highs on top, bass at the bottom: the arm, its insulators and its wires light and tremble with the tier, sparks from the lowest on onsets); three bolts (L, loudness, R) filling from the bottom; a dot field that is a spectrogram of the last three seconds; three stars (spectrum in polar form, held peaks, onsets); a ring in the corner for the time played; small print and a paragraph made from the track and the levels | bands, levels, progress |

## Themes

Themes carry no meter settings, so every poster uses its defaults (`theme` level 1) on every theme.

## How they work

- `poster.js` holds what they share: a poster is drawn in its own 600 x 800 space, scaled to fit the cell and centred on the paper colour (`begin`, which returns `{ scale, text }`), so a wide cell in the dock shows a smaller poster with margins. `groupBands` / `Bars` turn the 31 third-octave bands into N groups with rise and fall; `palette` gives the colours; `posterText` and `metaColumns` say what to print; `drawName` and `drawColumns` fit text. Pure parts are tested in `tests/js/poster.test.mjs`.
- Every poster has the same settings plus its own colours: `text` (0 off, 1 on; text is also left out when the cell is too small, under 30% scale), `theme` (0 = the poster's own colours; 1, the default = paper, ink and the dark panel follow the theme, so the poster sits on the theme's background while its oranges, reds and greens stay; 2 = every colour follows the theme: paper = background, ink = text, a and b = the two accents), `range` (how many dB below the track's own peak still show; 40 for the band shapes, 30 for the level shapes, and a gentle curve keeps ordinary levels in the middle so only peaks reach the top), and rise and fall. The scale follows the track (`Reference` in `poster.js`: it follows the loudest band or channel quickly up and 2 dB a second down), so a quiet recording and a loud master move alike; Dials is the exception, it shows absolute LUFS and true peak. A colour set to null follows the theme too.
- Text comes from the playing track (`artist`, `title`, `album`, `year`, `number`, `duration`, `codec` and the `truth` for bit depth, rate and DR; only the owner's own tracks have `truth`). With no artist the title becomes the name. Fonts: the theme's meter font (`colors.font`); Circuit sets its title and artist in the bundled Righteous (asked for through `loadFont`), because the rounded lettering is the design, and its small labels in the meter font.
- Overlaps (Lens, Slides) darken on a light paper and lighten (`screen`) on a dark one (Slides decides per row). Text and shapes that sit on a poster's own colour pick what reads best (`readableOn`, `darkest` in `poster.js`): the Facets text on its orange, the Lenses name on its panel.
- Terrain has two settings of its own: `city` (seconds the front row of the city takes to follow, 1.2; each row behind takes 1.9 times longer) and `glow` (0.35, 0 is off). The glow is the same drawing made once more at half size on a layer of its own and laid over the poster blurred and added (`lighter`); it is skipped on a light paper, in a cell too small for text (so in the thumbnails) and where the canvas has no `filter` (older Safari). On a light paper its highlights take the theme's text colour when the amber would not read.
- Checked on the Terminal, Late-night FM and Paper & ink themes (2026-11-13); other themes are not checked one by one.
- The module `label` is "Poster: Name". The meter menu in the player bar lists the other meters first, then "Posters" as a group that opens in place (`visualizers/menu.js`, tested; a group is open when one of its meters is on, and shows how many are on; Posters starts open). A new group is one line in `GROUPS` there.

- Airport is the one poster with a picture in it: `visualizers/airport-chart.png` is the chart's ink as a mask (alpha = how black a pixel was, 1044 x 1601, 96 KB, made from the FAA PDF with the names and data blocks painted out before the mask was cut, so no name is left in the file). The meter draws it in the theme's text colour on the theme's background (a dark theme lights the aprons instead of darkening them), and puts its own overlays on top in the picture's pixel space (700 x 1073, 130 dpi of the page). The picture is fetched once when the module loads and shared by every canvas; until it has arrived (or if it cannot load) the poster draws only its overlays and text. The overlays, the text and the colour come from the meter, not the picture, so a new theme needs nothing. The runway is chosen from the artist, title and number by `routeFor` (tested, `tests/js/poster-airport.test.mjs`, which also draws the poster on a stand-in canvas). Not ported to the Android and terminal clients.

## Thumbnails

The meter menu shows the posters as a grid of pictures with the name under each. A poster's picture is drawn once per theme by `visualizers/thumb.js` on a canvas of its own, with its default settings, text off and a canned demo signal (`demo-sample.js`); `loadThumbs()` in the player store makes them one by one when the menu is first opened (about 150 ms for all sixteen) and again after a theme change. This is why the poster meters keep their state per canvas context (`stateOf`), so drawing a picture never disturbs the live meter.

## Ideas not done

- Reel (a tape reel cropped by the bottom right corner, windows swelling with the levels, tape ring following progress) and Record (vinyl grooves as the spectrum, a cream sheen that swings with the stereo balance) exist only as mockups: `docs/mockups/poster-reel.html`, `docs/mockups/poster-record.html`. Tunnel was built from `docs/mockups/poster-tunnel.html`.
- Halftone is the heaviest to draw (about 1,200 dots a frame, batched into two paths); not measured on a phone yet.
- More bundled themes with poster palettes (red and black, teal and brown); names should not be brands.
- Thumbnails for the other meters (they keep state at module level, so they would need the same per-canvas change).
- Colour check of the posters' own palettes against the theme's contrast rules.

## Posters for other software (idea, 2026-11-15, nothing built)

Brainstorm on offering the posters outside Magnetite. A poster is a self-contained canvas 2D module that draws in its own 600 x 800 space and needs only the band spectrum, levels, the track's text and the theme's colours, so the web modules are portable.

- **JS package:** the poster modules, `poster.js`, the colour handling and a small engine (analyser to bands and levels) as a standalone library, for any web-based player or Electron app. Roughly `createPoster('terrain', canvas, { colors })` and `poster.draw(sample, track)`.
- **Standalone poster page:** audio from the browser, a loopback device or a WebSocket feed, shown full screen: an OBS browser source, a Raspberry Pi or a dashboard. MPD, Plex or Spotify give no audio samples, so they would need a small desktop wrapper (Tauri or Electron) that captures the system audio.
- **Hosts with a plugin community** (from memory, check the current APIs first): Webamp with Butterchurn (JS and WebGL, Milkdrop presets) and Jellyfin (its web client can load custom JS) are the cheapest, since the posters are canvas JS. foobar2000 (components, scripting panels), Kodi (C++ visualization add-ons, large reach), VLC and MusicBee need a native port each; Winamp and projectM are shader based. The Android (Kotlin) and terminal (Rust) clients already show what a port costs: each must be kept in step with the web version (the Android `arc` sweep bug made three posters differ).
- **To decide first:** a permissive licence (MIT or ISC) for an embeddable package, since the project is AGPL; keep the rule of no copied artwork or brand names; start with a small Webamp or Jellyfin demo to see whether anyone wants them before any native work.

The references were album, book and gig posters seen on the web; the code draws its own shapes, and no artwork, band name or typeface is copied. Keep it that way when adding more (and avoid brand names such as Soundcraft in labels and themes).
