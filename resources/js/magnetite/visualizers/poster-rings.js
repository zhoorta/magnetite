import { W, H, stateOf, Bars, begin, drawName, drawColumns, palette, posterText, readableOn } from './poster.js';

const RINGS = 9;

const make = () => ({ bars: new Bars(RINGS), st: { lvl: 0 } });

/**
 * A planet on a dark sky with curved stripes passing behind and in front of it, like an astronomy poster. Each stripe is a frequency range and
 * gets thicker with its energy; the planet swells a little with the overall level.
 */
export default {
    id: 'poster-rings',
    needs: ['bands'],
    label: 'Poster: Rings',
    params: {
        range: 40,
        attack: 0.06,
        release: 0.5,
        text: 1,
        theme: 1,
        paper: '#f0e6c8',
        dark: '#14283d',
        a: '#f08a12',
        c: '#f3e8c0',
        ink: '#14283d',
    },

    draw(g, { bands }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const { bars, st } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'dark', 'a', 'c', 'ink']);
        const view = begin(g, w, h, look.paper, { p });

        const e = bars.update(bands, dt, p);
        st.lvl = e.reduce((sum, v) => sum + v, 0) / RINGS;

        const m = 14;
        const px = m;
        const py = m;
        const pw = W - 2 * m;
        const ph = H - 2 * m;
        g.save();
        g.beginPath();
        g.rect(px, py, pw, ph);
        g.clip();
        g.fillStyle = look.dark;
        g.fillRect(px, py, pw, ph);

        const stripe = readableOn(look.dark, [look.c, look.ink]);
        const cx = W * 0.1;
        const cy = H * 0.72;
        const radius = W * 0.46 * (0.96 + 0.08 * st.lvl);
        const ring = (i, front) => {
            const rx = radius * 1.3 + i * 27;
            g.save();
            g.translate(cx, cy);
            g.rotate(-0.4);
            g.strokeStyle = stripe;
            g.lineWidth = 2 + e[i] * 11;
            g.beginPath();
            g.ellipse(0, 0, rx, rx * 0.3, 0, front ? 0 : Math.PI, front ? Math.PI : 2 * Math.PI);
            g.stroke();
            g.restore();
        };
        for (let i = 0; i < RINGS; i++) ring(i, false);
        g.fillStyle = look.a;
        g.beginPath();
        g.arc(cx, cy, radius, 0, 7);
        g.fill();
        for (let i = 0; i < RINGS; i++) ring(i, true);
        g.restore();

        if (view.text) {
            const say = posterText(track);
            const on = readableOn(look.dark, [look.c, look.ink, look.paper]);
            drawName(g, say.name, px + 26, py + 66, { font: colors.font, maxW: pw - 60, size: 40, color: on });
            drawColumns(g, [[[say.title, say.album, say.year].filter(Boolean).join(' · ')]], py + 90, { font: colors.font, color: on, size: 13, xs: [px + 26] });
        }
        g.restore();
    },
};
