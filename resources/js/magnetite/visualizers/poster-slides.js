import { W, H, Bars, begin, drawColumns, drawName, overlap, palette, posterText, showText } from './poster.js';

const ROWS = 4;
const bars = new Bars(ROWS);

/**
 * Four rows of half-discs, low frequencies at the top. In each row a dome and a bowl slide towards each other as that range gets louder,
 * and where they overlap the colours add up. The rows alternate direction, so the shapes form a slow S.
 */
export default {
    id: 'poster-slides',
    needs: ['bands'],
    label: 'Poster: Slides',
    params: {
        min: -75,
        max: -25,
        attack: 0.08,
        release: 0.6,
        text: 1,
        theme: 1,
        paper: '#efe9dc',
        ink: '#111111',
        a: '#e8581f',
        b: '#e3262e',
        c: '#f5a800',
    },

    draw(g, { bands }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const look = palette(p, colors, ['paper', 'ink', 'a', 'b', 'c']);
        const s = begin(g, w, h, look.paper);

        const e = bars.update(bands, dt, p);
        const txt = showText(p, s);
        const r = W * 0.25;
        const y0 = txt ? 56 : (H - ROWS * r) / 2;
        const span = W / 2 - r;

        for (let k = 0; k < ROWS; k++) {
            const y = y0 + k * r;
            g.fillStyle = k % 2 ? look.paper : look.c;
            g.fillRect(0, y, W, r);
            const slide = e[k] * span;
            const dome = k % 2 === 0 ? r + slide : W - r - slide;
            const bowl = k % 2 === 0 ? W - r - slide : r + slide;
            g.save();
            g.beginPath();
            g.rect(0, y, W, r);
            g.clip();
            g.globalCompositeOperation = overlap(k % 2 ? look.paper : look.c);
            g.fillStyle = look.a;
            g.beginPath();
            g.arc(dome, y + r, r, Math.PI, 0);
            g.fill();
            g.fillStyle = look.b;
            g.beginPath();
            g.arc(bowl, y, r, 0, Math.PI);
            g.fill();
            g.restore();
        }

        if (txt) {
            const say = posterText(track);
            const below = y0 + ROWS * r;
            drawName(g, say.year, W - 28, 36, { font: colors.font, maxW: 120, size: 16, color: look.ink, weight: 600, align: 'right' });
            drawName(g, say.name, 28, below + 64, { font: colors.font, maxW: W - 56, size: 46, color: look.ink, weight: 800, caps: true, spacing: 3 });
            drawColumns(g, [[[say.title, say.album].filter(Boolean).join(' · ')]], below + 94, { font: colors.font, color: look.ink, size: 14, xs: [28] });
        }
        g.restore();
    },
};
