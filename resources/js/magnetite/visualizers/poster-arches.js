import { W, H, stateOf, Bars, begin, drawColumns, drawName, palette, posterText } from './poster.js';

const ARCHES = 6;

const make = () => ({ bars: new Bars(ARCHES) });

/**
 * Six nested arches in two colours, from the low frequencies on the left to the high ones on the right; the height of each is the energy of
 * its range. With text it has the look of a book cover: the artist, the title in the accent colour, the album and year.
 */
export default {
    id: 'poster-arches',
    needs: ['bands'],
    label: 'Poster: Arches',
    params: {
        range: 40,
        attack: 0.05,
        release: 0.4,
        text: 1,
        theme: 1,
        paper: '#f1ece0',
        ink: '#111111',
        a: '#f4601a',
    },

    draw(g, { bands }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const { bars } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink', 'a']);
        const view = begin(g, w, h, look.paper, { p });

        const e = bars.update(bands, dt, p);
        let top = 30;
        if (view.text) {
            const say = posterText(track);
            drawName(g, say.name, 28, 82, { font: colors.font, maxW: W - 56, size: 56, color: look.ink });
            drawName(g, say.title, 28, 124, { font: colors.font, maxW: W - 56, size: 28, color: look.a, weight: 500 });
            drawColumns(g, [[[say.album, say.year].filter(Boolean).join(' · ')]], 154, { font: colors.font, color: look.ink, size: 15, xs: [28] });
            top = 190;
        }

        const ax = 30;
        const aw = (W - 60) / ARCHES;
        const base = H - 30;
        const tall = base - top;
        const gap = aw * 0.03;
        g.save();
        g.beginPath();
        g.rect(0, top - 2, W, tall + 4);
        g.clip();
        for (let i = 0; i < ARCHES; i++) {
            const height = tall * (0.22 + 0.78 * e[i]);
            const x = ax + i * aw + gap / 2;
            const width = aw - gap;
            const step = width * 0.085;
            [look.ink, look.a, look.ink, look.a, look.ink, look.paper].forEach((c, k) => {
                const xk = x + k * step;
                const wk = width - 2 * k * step;
                const yk = base - height + k * step;
                const r = wk / 2;
                g.fillStyle = c;
                g.beginPath();
                g.moveTo(xk, base + 4);
                g.lineTo(xk, yk + r);
                g.arc(xk + r, yk + r, r, Math.PI, 0);
                g.lineTo(xk + wk, base + 4);
                g.closePath();
                g.fill();
            });
        }
        g.restore();
        g.restore();
    },
};
