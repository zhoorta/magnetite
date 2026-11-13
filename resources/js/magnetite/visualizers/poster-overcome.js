import { W, H, Bars, begin, fitSize, follow, level01, palette, posterText, showText } from './poster.js';

const LINES = 31;
const bars = new Bars(LINES);
const st = { t: 0, l: 0, r: 0 };

/**
 * One hairline per third-octave band, as tall as its energy. Two circles (left channel in one colour, right in the other) ride the
 * channels' peaks and clear a halo in the lines around them. The name sits in a pill on the lines.
 */
export default {
    id: 'poster-overcome',
    needs: ['bands', 'levels'],
    label: 'Poster: Overcome',
    params: {
        min: -75,
        max: -25,
        attack: 0.05,
        release: 0.4,
        fall: 0.35,
        text: 1,
        theme: 1,
        paper: '#e8cdb0',
        ink: '#4a2a12',
        a: '#1f63b0',
        b: '#ee2a24',
    },

    draw(g, { bands, levels }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const look = palette(p, colors, ['paper', 'ink', 'a', 'b']);
        const s = begin(g, w, h, look.paper);

        st.t += dt;
        const e = bars.update(bands, dt, p);
        st.l = Math.max(level01(levels.l.peak, -40, -3), st.l - p.fall * dt);
        st.r = Math.max(level01(levels.r.peak, -40, -3), st.r - p.fall * dt);

        const x0 = W * 0.1;
        const x1 = W * 0.89;
        const y0 = H * 0.087;
        const y1 = H * 0.915;
        const step = (x1 - x0) / (LINES - 1);
        g.fillStyle = look.ink;
        for (let i = 0; i < LINES; i++) {
            const top = y0 + (1 - (0.7 + 0.3 * e[i])) * (y1 - y0);
            g.fillRect(x0 + i * step - 2, top, 4, y1 - top);
        }

        const r = W * 0.075;
        [[look.a, 0.2 + 0.06 * Math.sin(st.t * 0.2), st.l], [look.b, 0.78 + 0.06 * Math.sin(st.t * 0.17 + 2), st.r]].forEach(([c, at, level]) => {
            const x = x0 + (x1 - x0) * at;
            const y = y1 - r - 14 - level * (y1 - y0 - 2 * r - 28);
            g.fillStyle = look.paper;
            g.beginPath();
            g.arc(x, y, r + 9, 0, 7);
            g.fill();
            g.fillStyle = c;
            g.beginPath();
            g.arc(x, y, r, 0, 7);
            g.fill();
        });

        if (showText(p, s)) {
            const say = posterText(track);
            const size = fitSize(g, say.name, 600, colors.font, (x1 - x0) * 0.6, 40, 16);
            const width = g.measureText(say.name).width;
            const y = H * 0.72;
            g.fillStyle = look.paper;
            g.beginPath();
            g.roundRect(x0 + 10, y - size - 6, width + 28, size + 24, (size + 24) / 2);
            g.fill();
            g.fillStyle = look.ink;
            g.textBaseline = 'alphabetic';
            g.fillText(say.name, x0 + 24, y);

            const mark = [say.no, say.dur].filter(Boolean).join('/');
            if (mark) {
                g.font = `500 28px ${colors.font}`;
                g.textAlign = 'right';
                g.fillText(`/${mark}`, W - 54, H - 36);
                g.textAlign = 'left';
            }
        }
        g.restore();
    },
};
