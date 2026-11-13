import { toDb } from './meter.js';
import { W, H, stateOf, Reference, begin, darkest, drawName, follow, palette, posterText } from './poster.js';


const make = () => ({ ref: new Reference(), st: { lvl: 0, t: 0 } });

/**
 * Black stripes that part around an eye, like a printed optical poster: the opening follows the level, the red iris is cut by the stripes, and a
 * slow ripple runs through the rest. The artist and year are printed under it.
 */
export default {
    id: 'poster-warp',
    needs: ['levels'],
    label: 'Poster: Warp',
    params: {
        range: 30,
        response: 0.2,
        text: 1,
        theme: 1,
        paper: '#efe8d8',
        ink: '#0b0b0b',
        a: '#d92a1f',
    },

    draw(g, { levels }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const { st, ref } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink', 'a']);
        const view = begin(g, w, h, look.paper, { p });

        st.t += dt;
        const loudest = toDb(Math.max(levels.l.rms, levels.r.rms));
        ref.update(loudest, dt);
        st.lvl = follow(st.lvl, ref.norm(loudest, p.range), dt, p.response);

        const txt = view.text;
        const ax = 28;
        const ay = 24;
        const aw = W - 56;
        const ah = H - 24 - (txt ? 100 : 24);
        const stripes = 32;
        const pitch = ah / stripes;
        const cx = W / 2;
        const cy = ay + ah * 0.46;
        const hw = aw * 0.44;
        const open = 4 + st.lvl * 58;
        const iris = aw * 0.095;

        g.save();
        g.beginPath();
        g.rect(ax, ay, aw, ah);
        g.clip();
        g.fillStyle = look.a;
        g.beginPath();
        g.arc(cx, cy, iris, 0, 7);
        g.fill();
        g.fillStyle = darkest(look.ink, look.paper);
        g.beginPath();
        g.arc(cx, cy, iris * (0.55 - 0.2 * st.lvl), 0, 7);
        g.fill();

        g.fillStyle = look.ink;
        for (let i = 0; i < stripes; i++) {
            const y0 = ay + (i + 0.5) * pitch;
            const dy = y0 - cy;
            const sign = dy < 0 ? -1 : 1;
            const near = Math.exp(-Math.abs(dy) / (ah * 0.2));
            const half = pitch * 0.26;
            const edge = [];
            for (let x = ax; x <= ax + aw + 1; x += 8) {
                const u = (x - cx) / hw;
                const shape = Math.abs(u) < 1 ? (1 - u * u) ** 1.5 : 0;
                const ripple = Math.sin(x * 0.014 + i * 0.35 + st.t * 1.2) * 3.2 * (1 - near * 0.6);
                edge.push([x, y0 + sign * shape * open * near * 1.6 + ripple]);
            }
            g.beginPath();
            edge.forEach(([x, y], k) => (k ? g.lineTo(x, y - half) : g.moveTo(x, y - half)));
            for (let k = edge.length - 1; k >= 0; k--) g.lineTo(edge[k][0], edge[k][1] + half);
            g.closePath();
            g.fill();
        }
        g.restore();

        if (txt) {
            const say = posterText(track);
            const year = say.year;
            g.font = `800 40px ${colors.font}`;
            const yearW = year ? g.measureText(year).width + 40 : 0;
            drawName(g, say.name, 28, H - 52, { font: colors.font, maxW: W - 56 - yearW, size: 40, color: look.ink, weight: 800, caps: true, spacing: 4 });
            if (year) drawName(g, year, W - 28, H - 52, { font: colors.font, maxW: 160, size: 40, color: look.ink, weight: 800, spacing: 4, align: 'right' });
            drawName(g, [say.title, say.album].filter(Boolean).join(' · '), 28, H - 24, { font: colors.font, maxW: W - 56, size: 13, color: look.ink, weight: 500 });
        }
        g.restore();
    },
};
