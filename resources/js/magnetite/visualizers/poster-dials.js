import { toDb } from './meter.js';
import { W, H, begin, drawColumns, drawName, follow, metaColumns, palette, posterText, showText } from './poster.js';

const st = { loud: 0, peak: 0, corr: 0, dr: 0 };

/**
 * Four circles on a checkerboard, each filled along a diagonal like a gauge: loudness (short-term LUFS), true peak, stereo correlation and
 * the track's dynamic range (measured, so only for your own tracks). The values are printed in the corners.
 */
export default {
    id: 'poster-dials',
    needs: ['lufs', 'stereo'],
    label: 'Poster: Dials',
    params: {
        min: -40,
        max: -5,
        response: 0.4,
        text: 1,
        theme: 1,
        paper: '#dfe0d6',
        ink: '#050505',
        a: '#e8372c',
    },

    draw(g, { lufs, stereo }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const look = palette(p, colors, ['paper', 'ink', 'a']);
        const s = begin(g, w, h, look.paper);

        const loud = Number.isFinite(lufs.s) ? lufs.s : Number.isFinite(lufs.m) ? lufs.m : -Infinity;
        const peakDb = toDb(Math.max(lufs.tpL ?? 0, lufs.tpR ?? 0));
        const drValue = track?.truth?.dr;
        st.loud = follow(st.loud, Number.isFinite(loud) ? (loud - p.min) / (p.max - p.min) : 0, dt, p.response);
        st.peak = Math.max((peakDb + 40) / 40, st.peak - 0.25 * dt);
        st.corr = follow(st.corr, (Math.max(-1, Math.min(1, stereo.corr)) + 1) / 2, dt, p.response);
        st.dr = follow(st.dr, typeof drValue === 'number' ? (drValue - 4) / 16 : 0, dt, 0.6);

        let top = 0;
        if (showText(p, s)) {
            const say = posterText(track);
            drawColumns(g, metaColumns(say), 40, { font: colors.font, color: look.ink });
            drawName(g, say.name, 28, 152, { font: colors.font, maxW: W - 56, size: 84, color: look.ink });
            drawName(g, say.title, 28, 196, { font: colors.font, maxW: W - 56, size: 24, color: look.ink });
            drawColumns(g, [[say.album].filter(Boolean)], 220, { font: colors.font, color: look.ink, size: 14, xs: [28] });
            top = 250;
        }

        const cw = W / 2;
        const ch = (H - top) / 2;
        const r = Math.min(cw, ch) * 0.43;
        const items = [
            ['loudness', st.loud, Number.isFinite(loud) ? `${loud.toFixed(1)} LUFS` : '–'],
            ['true peak', st.peak, Number.isFinite(peakDb) && peakDb > -90 ? `${peakDb.toFixed(1)} dB` : '–'],
            ['correlation', st.corr, `${stereo.corr >= 0 ? '+' : ''}${stereo.corr.toFixed(2)}`],
            ['dynamic range', st.dr, typeof drValue === 'number' ? `DR${drValue}` : '–'],
        ];
        items.forEach(([label, value, text], i) => {
            const x0 = (i % 2) * cw;
            const y0 = top + Math.floor(i / 2) * ch;
            const dark = ((i % 2) + Math.floor(i / 2)) % 2 === 0;
            const cx = x0 + cw / 2;
            const cy = y0 + ch / 2;
            g.fillStyle = dark ? look.ink : look.a;
            g.fillRect(x0, y0, cw, ch);

            g.save();
            g.beginPath();
            g.arc(cx, cy, r, 0, 7);
            g.clip();
            g.fillStyle = dark ? look.a : look.ink;
            g.fillRect(cx - r, cy - r, 2 * r, 2 * r);
            g.translate(cx, cy);
            g.rotate(-Math.PI / 4);
            g.fillStyle = look.paper;
            const edge = (1 - 2 * Math.max(0, Math.min(1, value))) * r;
            g.fillRect(-r, edge, 2 * r, r - edge);
            g.restore();

            g.font = `600 13px ${colors.font}`;
            g.fillStyle = look.paper;
            g.textAlign = 'left';
            g.fillText(label, x0 + 14, y0 + 24);
            g.fillText(text, x0 + 14, y0 + ch - 14);
        });
        g.restore();
    },
};
