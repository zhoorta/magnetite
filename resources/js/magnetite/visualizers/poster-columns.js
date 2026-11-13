import { W, H, begin, drawColumns, drawName, follow, level01, palette, posterText, showText } from './poster.js';

const st = { l: 0, r: 0 };

/**
 * Two sets of tall columns, left and right channel, and in each a circle cut by a diagonal stripe that rides up its column with the level.
 * With text it has a boxed header like a paperback: the artist, then the title, album and format in ruled cells.
 */
export default {
    id: 'poster-columns',
    needs: ['levels'],
    label: 'Poster: Columns',
    params: {
        min: -50,
        max: -6,
        response: 0.25,
        text: 1,
        theme: 1,
        paper: '#fff6d6',
        ink: '#0a0a0a',
        a: '#f99a0a',
    },

    draw(g, { levels }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const look = palette(p, colors, ['paper', 'ink', 'a']);
        const s = begin(g, w, h, look.paper);

        st.l = follow(st.l, level01(levels.l.rms, p.min, p.max), dt, p.response);
        st.r = follow(st.r, level01(levels.r.rms, p.min, p.max), dt, p.response);

        let top = 0;
        if (showText(p, s)) {
            const say = posterText(track);
            g.strokeStyle = look.ink;
            g.lineWidth = 2;
            g.strokeRect(14, 14, W - 28, 196);
            g.beginPath();
            g.moveTo(14, 112);
            g.lineTo(W - 14, 112);
            g.moveTo(W * 0.5, 112);
            g.lineTo(W * 0.5, 210);
            g.moveTo(W * 0.82, 112);
            g.lineTo(W * 0.82, 210);
            g.stroke();
            drawName(g, say.name, 30, 84, { font: colors.font, maxW: W - 60, size: 56, color: look.ink, weight: 800 });
            drawColumns(g, [[say.title.toUpperCase()], [say.album.toUpperCase()], [say.fmt.toUpperCase(), say.dr.toUpperCase()].filter(Boolean)], 146, { font: colors.font, color: look.ink, size: 13, xs: [30, W * 0.5 + 14, W * 0.82 + 12] });
            top = 224;
        }

        const ah = H - top - (top ? 14 : 0);
        const half = W / 2;
        const r = half * 0.3;
        g.fillStyle = look.a;
        g.fillRect(0, top, half, ah);
        g.fillStyle = look.ink;
        g.fillRect(half / 3, top, half / 3, ah);
        g.fillRect(half, top, half, ah);
        g.fillStyle = look.paper;
        g.fillRect(half + half / 3, top, half / 3, ah);

        [[half / 2, st.l, look.ink, look.paper], [half * 1.5, st.r, look.a, look.ink]].forEach(([cx, level, base, stripe]) => {
            const cy = top + ah - r - 12 - level * (ah - 2 * r - 24);
            g.save();
            g.beginPath();
            g.arc(cx, cy, r, 0, 7);
            g.clip();
            g.fillStyle = base;
            g.fillRect(cx - r, cy - r, 2 * r, 2 * r);
            g.translate(cx, cy);
            g.rotate(Math.PI / 4);
            g.fillStyle = stripe;
            g.fillRect(-r * 1.5, -r * 0.28, r * 3, r * 0.56);
            g.restore();
        });
        g.restore();
    },
};
