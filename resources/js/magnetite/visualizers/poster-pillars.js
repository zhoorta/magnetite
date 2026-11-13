import { W, H, stateOf, Bars, begin, drawColumns, drawName, palette, posterText } from './poster.js';

const PILLARS = 7;

const make = () => ({ bars: new Bars(PILLARS) });

/**
 * A square sleeve of seven rounded pillars in two oranges, rising with the energy of their frequency ranges, and a circle in the corner
 * that swells with the lowest range. With text the artist and details are printed below the square.
 */
export default {
    id: 'poster-pillars',
    needs: ['bands'],
    label: 'Poster: Pillars',
    params: {
        range: 40,
        attack: 0.04,
        release: 0.35,
        text: 1,
        theme: 1,
        paper: '#f3e6c6',
        ink: '#1a1a1a',
        a: '#f26a14',
        b: '#f58a2a',
    },

    draw(g, { bands }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const { bars } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink', 'a', 'b']);
        const view = begin(g, w, h, look.paper, { p });

        const e = bars.update(bands, dt, p);
        const txt = view.text;
        const size = txt ? W - 56 : Math.min(W - 56, H - 56);
        const px = (W - size) / 2;
        const py = txt ? 28 : (H - size) / 2;
        const gap = 5;
        const width = (size - gap * (PILLARS - 1)) / PILLARS;

        g.save();
        g.beginPath();
        g.roundRect(px, py, size, size, 36);
        g.clip();
        for (let i = 0; i < PILLARS; i++) {
            const height = size * (0.35 + 0.65 * e[i]);
            const x = px + i * (width + gap);
            const top = py + size - height;
            const r = width / 2;
            g.fillStyle = i % 2 ? look.b : look.a;
            g.beginPath();
            g.moveTo(x, py + size + 2);
            g.lineTo(x, top + r);
            g.arc(x + r, top + r, r, Math.PI, 0);
            g.lineTo(x + width, py + size + 2);
            g.closePath();
            g.fill();
        }
        const cr = size * 0.12 * (1 + 0.3 * e[0]);
        const cx = px + size - cr - 26;
        const cy = py + cr + 26;
        g.fillStyle = look.paper;
        g.beginPath();
        g.arc(cx, cy, cr + 7, 0, 7);
        g.fill();
        g.fillStyle = look.a;
        g.beginPath();
        g.arc(cx, cy, cr, 0, 7);
        g.fill();
        g.restore();

        if (txt) {
            const say = posterText(track);
            drawName(g, say.name, 28, py + size + 76, { font: colors.font, maxW: W - 56, size: 52, color: look.ink, weight: 800, caps: true, spacing: 2 });
            drawColumns(g, [[[say.title, say.album].filter(Boolean).join(' · '), [say.year, say.fmt, say.dur].filter(Boolean).join(' · ')].filter(Boolean)], py + size + 108, { font: colors.font, color: look.ink, size: 15, xs: [28] });
        }
        g.restore();
    },
};
