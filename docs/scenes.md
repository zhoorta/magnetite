# Scenes: pictures as visualizers

Status (2026-11-11): prototype committed; upload, storage and a first settings page are built, uncommitted, and not yet tried in a browser.

**Built since:** `scenes` table (`user_id`, `name`, `file`, `settings`, `hotspots` unused so far), `SceneStore` (decodes and redraws any JPEG/PNG/WebP/GIF as a JPEG, longest side 1600, named by sha1, in `storage/app/private/scenes`), `SceneController` (upload, image route only for the owner, `PATCH` with a whitelist of keys and ranges, delete; 30 scenes per person), the page `/scenes` (`scenes-page.js`: upload by choosing or dropping, cards, name, sliders with "auto" per setting, glow colour, fit, "Show in player", delete). The player gets the person's scenes in `window.magnetite.scenes`; each becomes the meter `scene-{id}`. Precedence: defaults, picture suggestion, the scene's `settings`, the theme's look. The local test scenes are no longer registered: upload those pictures instead.

## How it works

`visualizers/scene.js` (`createScene(id, label, defaults)`, one instance per picture) and `scene-auto.js` (pure, tested by Node):

- The picture is analysed once on load, on a 128 px grid: the brightest ~14% of the pixels become blobs (4-connected, 3x3 majority filter). Blobs that fill the picture (frames, backgrounds) are dropped.
- The biggest, brightest blob is the **kick** layer; up to 8 more are **band** layers, ordered left to right (bass to treble). One big shape is cut into strips; with too few blobs, one band per hue (red low, violet high). A bright picture (mean luma > 0.6) is inverted: its dark shapes carry the music and glow in `tint`.
- Each layer is the picture's own pixels inside the blob, added on top of it (`lighter`), with an alpha from the band's level (`bands`, third-octave) or the kick (`beat.js`, bass onset). Whole-picture `bloom` follows the loudness.
- `suggest()` gives per-picture starting values (bloom 0 for bright pictures, calmer bands when there are many shapes, `glowColor: 'picture'` when the picture has colour). Order of precedence: defaults, suggestion, the user's own params.
- `dominantHue()` finds the colour of the bright, saturated parts (`glowColor: 'picture'`).

## Params

`image`, `fit` (contain: whole picture, blurred backdrop; cover: crop), `focus`, `bloom`, `kickGain`, `bandGain`, `glowColor` (`'picture'`, `'accent'`, hex, null), `glowGain`, `dim`, `saturation`, `attack`, `release`, `kickThreshold`, `kickCooldown`, `min`, `max`, `vignette` (0), `drift` (0), `zoom` (0), `tint`. Vignette, drift and zoom are off: they were distracting (the kick zoom especially).

## Decisions

- Subtle on purpose: the first version (strong bloom, kick zoom) looked like a thunderstorm.
- Settings are per picture, only what the user changed is stored, "reset to auto" per setting.
- Pictures are private to their owner; shared bundles would carry the settings, never the image.

## Next

1. Real music in the player for tuning (done by ear so far, only fake audio in screenshots).
2. Upload (per user, like covers: validate, re-encode, downscale to ~1600 px, signed route), `scenes` table (`user_id`, `file`, `settings` JSON, `hotspots` JSON), Scenes page.
3. Settings panel with live preview; hotspot editor for pictures where the auto blobs miss the subject (tornado, skull, vintage photo).
4. Android; the terminal client cannot show pictures.
