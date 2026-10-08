import { drawDial } from './dial';
import { stereoMeters } from './meter';

const ASPECT = 1.4;
const meters = stereoMeters();

/** A hi-fi receiver's backlit dial pair: amber light from behind, black needle, metal bezel and glass glare. */
export default {
    id: 'vu-receiver',
    label: 'VU receiver',
    params: {
        min: -30,
        max: 3,
        response: 0.3,
        redFrom: -6,
        backlight: null, // lamp colour behind the dial; defaults to the theme's second accent
        ink: '#1a0f05', // markings and needle: dark, printed on the lit face
        zone: '#d62d1f',
        bezel: true,
        glare: true,
    },

    draw(g, { levels }, { w, h, colors, params, dt }) {
        const p = { ...this.params, ...params };
        const look = { ...p, backlight: p.backlight ?? colors.accent2, face: colors.surface };
        const cfg = { min: p.min, max: p.max, attack: p.response, release: p.response, hold: 1, fall: 0.3 };

        meters.l.update(levels.l, dt, cfg);
        meters.r.update(levels.r, dt, cfg);

        // Leave room around each face for the bezel and its screws.
        const gap = Math.min(w, h) * 0.09;
        const boxW = Math.min((w - gap * 3) / 2, (h - gap * 2) * ASPECT);
        const boxH = boxW / ASPECT;
        const x0 = (w - (boxW * 2 + gap)) / 2;
        const y0 = (h - boxH) / 2;

        [['L', meters.l], ['R', meters.r]].forEach(([name, m], i) => {
            drawDial(g, x0 + i * (boxW + gap), y0, boxW, boxH, name, m, look, colors);
        });
    },
};
