import { INFO_LEVELS } from './info';
import { coverPixels, paintMosaic } from './cover';
import { ease, fromHex, isLight, palette, toHex } from './palette';
import { send } from '../http';

/**
 * The user's interface choices (`users.ui`), shared by the library and the player bar: how much detail the lists and the
 * player show (`info`: 0 none, 1 DR, 2 DR and findings) and whether covers are pixelated (`pixel`: true, false, or null to
 * follow the theme). Saved with the account when someone is signed in; a guest keeps them until the page is reloaded.
 *
 * @param {{ info?: number, pixel?: ?boolean } | null} saved what the server sent
 * @param {function(): boolean} themePixel does the current theme ask for pixelated covers?
 * @param {boolean} signedIn
 */
export function uiStore(saved, themePixel, signedIn) {
    // Meter colours from the cover: what the cover offers, and the colours shown now, easing toward the target. Not reactive on purpose:
    // they change every frame.
    let coverUrl = null;
    let offered = null; // { accent, accent2 } of the cover at coverUrl, or null
    let shown = null; // { accent: [r, g, b], accent2: [r, g, b] } as drawn now

    return {
        info: Number.isInteger(saved?.info) ? Math.max(0, Math.min(INFO_LEVELS - 1, saved.info)) : 2,
        pixel: typeof saved?.pixel === 'boolean' ? saved.pixel : null,
        tint: saved?.tint === true, // meters take their colours from the playing cover (off until chosen)

        /** Pixelated now: the user's choice, else the theme's. */
        get pixelated() {
            return this.pixel ?? themePixel();
        },

        cycleInfo() {
            this.info = (this.info + INFO_LEVELS - 1) % INFO_LEVELS; // 2, 1, 0, 2 ...
            this.save({ info: this.info });
        },

        togglePixel() {
            this.pixel = !this.pixelated;
            this.save({ pixel: this.pixel });
        },

        toggleTint() {
            this.tint = !this.tint;
            this.save({ tint: this.tint });
        },

        /**
         * The colours the meters draw with this frame: the theme's, or with the tint on the cover's two vivid colours, eased in.
         * `colors` are the theme's (hex strings); the cover is `url` of the track that is playing.
         */
        shade(colors, url, dt) {
            if (url !== coverUrl) {
                coverUrl = url;
                offered = null;
                coverPixels(url).then((px) => {
                    if (coverUrl === url && px) offered = palette(px, isLight(colors.bg));
                }).catch(() => {});
            }
            const themeAccent = fromHex(colors.accent);
            const themeAccent2 = fromHex(colors.accent2);
            if (!themeAccent || !themeAccent2) return colors;
            const target = this.tint && offered ? offered : { accent: themeAccent, accent2: themeAccent2 };
            shown ??= { accent: themeAccent, accent2: themeAccent2 };
            const k = 1 - Math.exp(-dt * 4); // about a quarter second
            shown = { accent: ease(shown.accent, target.accent, k), accent2: ease(shown.accent2, target.accent2, k) };
            if (!this.tint && offered === null && Math.abs(shown.accent[0] - themeAccent[0]) < 1) return colors;

            return { ...colors, accent: toHex(shown.accent.map(Math.round)), accent2: toHex(shown.accent2.map(Math.round)) };
        },

        label() {
            return ['Info: off', 'Info: DR', 'Info: all'][this.info];
        },

        save(part) {
            if (signedIn) send('PUT', '/settings/ui', part).catch(() => {});
        },

        /** For `x-effect` on a canvas: show the cover there as a mosaic. */
        paint(canvas, url) {
            paintMosaic(canvas, url);
        },
    };
}
