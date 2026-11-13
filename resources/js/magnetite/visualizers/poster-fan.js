import { W, H, stateOf, Bars, begin, drawColumns, drawName, palette, posterText } from './poster.js';

const RAYS = 12;

const make = () => ({ bars: new Bars(RAYS) });

/**
 * Twelve rays fanning out from the top right corner in alternating colours, one per frequency range, as long as its energy. The shapes stay
 * clean; the name, title and album are printed at the bottom left.
 */
export default {
    id: 'poster-fan',
    needs: ['bands'],
    label: 'Poster: Fan',
    params: {
        range: 40,
        attack: 0.05,
        release: 0.4,
        text: 1,
        theme: 1,
        paper: '#efe6d2',
        ink: '#111111',
        a: '#12a84a',
    },

    draw(g, { bands }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const { bars } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink', 'a']);
        const view = begin(g, w, h, look.paper, { p });

        const e = bars.update(bands, dt, p);
        const ox = W * 0.86;
        const oy = H * 0.075;
        const from = (185 * Math.PI) / 180;
        const to = (72 * Math.PI) / 180;
        const turn = (from - to) / RAYS;
        const hub = 26;
        const at = (r, a) => [ox + r * Math.cos(a), oy + r * Math.sin(a)];

        for (let i = 0; i < RAYS; i++) {
            const a0 = from - i * turn;
            const a1 = a0 - turn;
            const length = H * (0.3 + 0.6 * e[i]);
            g.fillStyle = i % 2 === 0 ? look.ink : look.a;
            g.beginPath();
            g.moveTo(...at(hub, a0));
            g.lineTo(...at(length, a0));
            g.lineTo(...at(length, a1));
            g.lineTo(...at(hub, a1));
            g.closePath();
            g.fill();
        }
        g.fillStyle = look.paper;
        g.beginPath();
        g.arc(ox, oy, hub * 0.85, 0, 7);
        g.fill();

        if (view.text) {
            const say = posterText(track);
            drawName(g, say.name, 28, H - 96, { font: colors.font, maxW: W * 0.7, size: 40, color: look.ink, weight: 800 });
            drawName(g, say.title, 28, H - 66, { font: colors.font, maxW: W * 0.7, size: 20, color: look.a });
            drawColumns(g, [[[say.album, say.year].filter(Boolean).join(' · '), [say.fmt, say.dur].filter(Boolean).join(' · ')].filter(Boolean)], H - 42, { font: colors.font, color: look.ink, size: 13, xs: [28] });
        }
        g.restore();
    },
};
