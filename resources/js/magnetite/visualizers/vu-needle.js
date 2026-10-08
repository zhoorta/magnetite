import { drawDial } from './dial';
import { stereoMeters } from './meter';

const ASPECT = 1.55; // dial face width / height
const meters = stereoMeters();

export default {
    id: 'vu-needle',
    label: 'VU needles',
    params: {
        min: -30, // dBFS at the left end of the scale
        max: 3, // dBFS at the right end
        response: 0.3, // seconds for the needle to settle, like a real VU meter
        redFrom: -6, // dBFS where the red zone starts
        face: null, // dial face colour; defaults to the theme surface
        ink: null, // ticks, labels and needle; defaults to the theme text
        zone: null, // red zone and clip lamp; defaults to the theme accent
    },

    draw(g, { levels }, { w, h, colors, params, dt }) {
        const p = { ...this.params, ...params };
        const look = { ...p, face: p.face ?? colors.surface, ink: p.ink ?? colors.text, zone: p.zone ?? colors.accent };
        const cfg = { min: p.min, max: p.max, attack: p.response, release: p.response, hold: 1, fall: 0.3 };

        meters.l.update(levels.l, dt, cfg);
        meters.r.update(levels.r, dt, cfg);

        // Dials keep a fixed shape, so a tall fullscreen canvas gets bigger dials, not taller faces.
        const gap = Math.min(w, h) * 0.04;
        const boxW = Math.min((w - gap * 3) / 2, (h - gap * 2) * ASPECT);
        const boxH = boxW / ASPECT;
        const x0 = (w - (boxW * 2 + gap)) / 2;
        const y0 = (h - boxH) / 2;

        [['L', meters.l], ['R', meters.r]].forEach(([name, m], i) => {
            drawDial(g, x0 + i * (boxW + gap), y0, boxW, boxH, name, m, look, colors);
        });
    },
};
