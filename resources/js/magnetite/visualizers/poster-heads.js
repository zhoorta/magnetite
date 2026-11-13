import { W, H, stateOf, Bars, begin, drawColumns, drawName, palette, posterText } from './poster.js';

const HEADS = 16;

/** One head in profile, facing right, in a 100 x 110 box with its corner at (x, y), scaled by `scale`. */
function head(g, x, y, scale) {
    g.save();
    g.translate(x, y);
    g.scale(scale, scale);
    g.beginPath();
    g.moveTo(10, 110);
    g.lineTo(12, 78);
    g.bezierCurveTo(-4, 62, 0, 18, 40, 6);
    g.bezierCurveTo(72, -4, 98, 26, 92, 52);
    g.lineTo(100, 66);
    g.lineTo(92, 72);
    g.lineTo(95, 82);
    g.lineTo(86, 90);
    g.lineTo(88, 100);
    g.lineTo(66, 104);
    g.lineTo(66, 110);
    g.closePath();
    g.fill();
    g.restore();
}

const make = () => ({ bars: new Bars(HEADS), st: { hot: 0 } });

/**
 * Sixteen heads in a grid, one per frequency range; each swells a little with its energy, and the one in the accent colour is the loudest
 * range right now (it only changes when another is clearly louder, so it does not flicker). It has the look of a paperback cover.
 */
export default {
    id: 'poster-heads',
    needs: ['bands'],
    label: 'Poster: Heads',
    params: {
        range: 40,
        attack: 0.08,
        release: 0.5,
        text: 1,
        theme: 1,
        paper: '#f3dcbc',
        ink: '#1a1a1a',
        a: '#f04a24',
        b: '#1d6f8a',
    },

    draw(g, { bands }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const { bars, st } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink', 'a', 'b']);
        const view = begin(g, w, h, look.paper, { p });

        const e = bars.update(bands, dt, p);
        let loudest = 0;
        for (let i = 1; i < HEADS; i++) if (e[i] > e[loudest]) loudest = i;
        if (e[loudest] > e[st.hot] + 0.08) st.hot = loudest;

        let top = 24;
        if (view.text) {
            const say = posterText(track);
            drawName(g, say.name, 28, 70, { font: colors.font, maxW: W - 56, size: 56, color: look.ink, weight: 800 });
            drawName(g, say.title, 28, 112, { font: colors.font, maxW: W - 56, size: 30, color: look.a, weight: 600 });
            drawColumns(g, [[[say.album, say.year].filter(Boolean).join(' · ')]], 150, { font: colors.font, color: look.ink, size: 16, xs: [28] });
            top = 200;
        }

        const cw = W / 4;
        const ch = (H - top - 16) / 4;
        const scale = Math.min((cw * 0.78) / 100, (ch * 0.95) / 110);
        for (let i = 0; i < HEADS; i++) {
            const grow = scale * (0.88 + 0.12 * Math.min(1, e[i] * 1.6));
            const cx = (i % 4) * cw + cw / 2;
            const cy = top + Math.floor(i / 4) * ch + ch / 2;
            g.fillStyle = i === st.hot ? look.a : look.b;
            head(g, cx - 50 * grow, cy - 55 * grow, grow);
        }
        g.restore();
    },
};
