import { W, H, begin, drawColumns, drawName, follow, level01, metaColumns, overlap, palette, posterText, showText } from './poster.js';

const st = { l: 0, r: 0, corr: 0 };

/**
 * Two overlapping discs, one per channel, where the overlap darkens (or lightens, on a dark paper): its width is the stereo correlation (stacked is mono, apart is out of
 * phase) and each disc grows with its channel's level. The title and the track's details are printed above.
 */
export default {
    id: 'poster-lens',
    needs: ['levels', 'stereo'],
    label: 'Poster: Lens',
    params: {
        min: -50,
        max: -6,
        response: 0.25,
        text: 1,
        theme: 1,
        paper: '#f6efdc',
        ink: '#1a1a1a',
        a: '#fb6a1c',
        b: '#ee2e2e',
    },

    draw(g, { levels, stereo }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const look = palette(p, colors, ['paper', 'ink', 'a', 'b']);
        const s = begin(g, w, h, look.paper);

        st.l = follow(st.l, level01(levels.l.rms, p.min, p.max), dt, p.response);
        st.r = follow(st.r, level01(levels.r.rms, p.min, p.max), dt, p.response);
        st.corr = follow(st.corr, Math.max(-1, Math.min(1, stereo.corr)), dt, p.response);

        let top = 28;
        if (showText(p, s)) {
            const say = posterText(track);
            drawColumns(g, metaColumns(say), 40, { font: colors.font, color: look.ink });
            drawName(g, say.name, 28, 176, { font: colors.font, maxW: W - 56, size: 104, color: look.ink });
            drawName(g, say.title, 28, 222, { font: colors.font, maxW: W - 56, size: 24, color: look.ink, weight: 500 });
            top = 250;
        }

        const ax = 28;
        const aw = W - 56;
        const ah = H - top - 28;
        g.save();
        g.beginPath();
        g.rect(ax, top, aw, ah);
        g.clip();
        const cy = top + ah / 2;
        const base = aw * 0.26;
        const dx = base * (0.2 + ((1 - st.corr) / 2) * 1.5);
        g.globalCompositeOperation = overlap(look.paper);
        g.fillStyle = look.a;
        g.beginPath();
        g.arc(W / 2 - dx, cy, base * (0.55 + st.l * 0.7), 0, 7);
        g.fill();
        g.fillStyle = look.b;
        g.beginPath();
        g.arc(W / 2 + dx, cy, base * (0.55 + st.r * 0.7), 0, 7);
        g.fill();
        g.restore();

        g.strokeStyle = look.ink;
        g.lineWidth = 2;
        g.strokeRect(ax, top, aw, ah);
        g.font = `600 14px ${colors.font}`;
        g.fillStyle = look.ink;
        g.fillText('L', ax + 10, top + ah - 10);
        g.fillText('R', ax + aw - 22, top + ah - 10);
        g.fillText(`${st.corr >= 0 ? '+' : ''}${st.corr.toFixed(2)}`, W / 2 - 18, top + ah - 10);
        g.restore();
    },
};
