import { W, H, Bars, begin, drawColumns, drawName, palette, posterText, readableOn, showText } from './poster.js';

const GROUPS = 9;
const bars = new Bars(GROUPS);
const st = { t: 0 };

/**
 * A dark panel with a red sun and fine lines across it, one per band group. Each line swells into a cream lens where its band has energy, and
 * the lenses drift slowly sideways. The quietest of the poster meters.
 */
export default {
    id: 'poster-lenses',
    needs: ['bands'],
    label: 'Poster: Lenses',
    params: {
        min: -75,
        max: -25,
        attack: 0.06,
        release: 0.45,
        text: 1,
        theme: 1,
        paper: '#dcd2b2',
        dark: '#141f26',
        a: '#e2412a',
        c: '#e8dfc6',
        ink: '#141f26',
    },

    draw(g, { bands }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const look = palette(p, colors, ['paper', 'dark', 'a', 'c', 'ink']);
        const s = begin(g, w, h, look.paper);

        st.t += dt;
        const e = bars.update(bands, dt, p);
        const txt = showText(p, s);

        const px = 28;
        const py = 28;
        const pw = W - 56;
        const ph = H - 28 - (txt ? 76 : 28);
        g.save();
        g.beginPath();
        g.rect(px, py, pw, ph);
        g.clip();
        g.fillStyle = look.dark;
        g.fillRect(px, py, pw, ph);
        const radius = pw * 0.78;
        g.fillStyle = look.a;
        g.beginPath();
        g.arc(W / 2, py + ph * 0.42 + radius, radius, 0, 7);
        g.fill();

        const first = py + ph * 0.6;
        const last = py + ph - 18;
        const gap = (last - first) / GROUPS;
        for (let j = 0; j < GROUPS; j++) {
            const y = first + (j + 0.5) * gap;
            g.strokeStyle = look.c;
            g.lineWidth = 1.5;
            g.globalAlpha = 0.85;
            g.beginPath();
            g.moveTo(px, y);
            g.lineTo(px + pw, y);
            g.stroke();
            g.globalAlpha = 1;

            const lx = px + pw * (0.5 + 0.36 * Math.sin(st.t * 0.13 * (1 + j * 0.17) + j * 1.9));
            const half = pw * (0.07 + 0.17 * e[j]);
            const lift = gap * (0.15 + e[j] * 1.5);
            for (const grow of [0, 5]) {
                g.beginPath();
                for (let k = 0; k <= 40; k++) {
                    const u = k / 20 - 1;
                    const x = lx + u * (half + grow);
                    const yy = y - (lift + grow) * (1 - u * u) ** 2;
                    k ? g.lineTo(x, yy) : g.moveTo(x, yy);
                }
                for (let k = 40; k >= 0; k--) {
                    const u = k / 20 - 1;
                    g.lineTo(lx + u * (half + grow), y + (lift + grow) * (1 - u * u) ** 2);
                }
                g.closePath();
                if (grow) {
                    g.lineWidth = 1.2;
                    g.stroke();
                } else {
                    g.fillStyle = look.c;
                    g.fill();
                }
            }
        }
        g.restore();

        if (txt) {
            const say = posterText(track);
            drawName(g, say.name, px + 16, py + 54, { font: colors.font, maxW: pw * 0.6, size: 34, color: readableOn(look.dark, [look.c, look.ink]), weight: 600 });
            drawColumns(g, [[[say.title, say.album, say.year, say.fmt].filter(Boolean).join(' · ')]], H - 34, { font: colors.font, color: look.ink, size: 12, xs: [28] });
        }
        g.restore();
    },
};
