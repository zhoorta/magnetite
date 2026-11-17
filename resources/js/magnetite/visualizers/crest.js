// Crest factor: peak minus RMS in dB over the last few seconds, as a big number and a line of the last 30 s. A heavily
// limited master sits near 6 to 8 dB, an uncompressed recording at 14 or more. Both channels count (the larger peak over
// the larger RMS), so one loud side does not hide.

import { toDb } from './meter.js';
import { stateOf } from './poster.js';

const WINDOW = 3; // seconds of peak and energy the reading looks at
const POINT = 0.25; // seconds between the points of the line
const MAX_DB = 24;

const make = () => ({ frames: [], since: 0, line: [], value: NaN });

export default {
    id: 'crest',
    needs: ['levels'],
    label: 'Crest factor',
    params: {
        span: 30, // seconds the line shows
        ok: '#7ec17e',
    },

    draw(g, { levels, playing }, { w, h, colors, params, dt }) {
        const p = { ...this.params, ...params };
        const st = stateOf(g, make);
        const rms = Math.max(levels.l.rms, levels.r.rms);
        const peak = Math.max(levels.l.peak, levels.r.peak);

        if (playing !== false && rms > 1e-4) {
            st.frames.push({ dt, peak, e: rms * rms });
            let total = st.frames.reduce((s, f) => s + f.dt, 0);
            while (total > WINDOW && st.frames.length > 1) total -= st.frames.shift().dt;
            const pk = st.frames.reduce((m, f) => Math.max(m, f.peak), 0);
            const e = st.frames.reduce((s, f) => s + f.e * f.dt, 0) / total;
            st.value = toDb(pk) - toDb(Math.sqrt(e));
            st.since += dt;
            if (st.since >= POINT) {
                st.since = 0;
                st.line.push(st.value);
                const keep = Math.round(p.span / POINT);
                if (st.line.length > keep) st.line.splice(0, st.line.length - keep);
            }
        }

        const margin = Math.min(w, h) * 0.07;
        const label = Math.max(9, Math.min(h * 0.06, w * 0.04));
        const big = Math.max(18, Math.min(h * 0.3, w * 0.2));
        g.textBaseline = 'alphabetic';
        g.textAlign = 'left';
        g.fillStyle = colors.muted;
        g.font = `${Math.round(label)}px ${colors.font}`;
        g.fillText('CREST FACTOR', margin, margin + label);

        const shown = Number.isFinite(st.value);
        g.fillStyle = colors.accent2;
        g.font = `${Math.round(big)}px ${colors.font}`;
        g.fillText(shown ? st.value.toFixed(1) : '−−.−', margin, margin + label * 1.6 + big * 0.85);
        const numW = g.measureText(shown ? st.value.toFixed(1) : '−−.−').width;
        g.fillStyle = colors.muted;
        g.font = `${Math.round(label * 1.3)}px ${colors.font}`;
        g.fillText('dB', margin + numW + label * 0.5, margin + label * 1.6 + big * 0.85);

        // the line
        const lx0 = margin;
        const lx1 = w - margin;
        const ly1 = h - margin;
        const ly0 = Math.max(margin + label * 1.6 + big + label, h * 0.5);
        if (ly1 - ly0 < label * 2) return;
        const yOf = (v) => ly1 - ((ly1 - ly0) * Math.max(0, Math.min(MAX_DB, v))) / MAX_DB;
        g.font = `${Math.round(label * 0.9)}px ${colors.font}`;
        g.textAlign = 'right';
        g.lineWidth = 1;
        for (const v of [6, 12, 18]) {
            g.globalAlpha = 0.3;
            g.strokeStyle = colors.muted;
            g.beginPath();
            g.moveTo(lx0, yOf(v));
            g.lineTo(lx1, yOf(v));
            g.stroke();
            g.globalAlpha = 1;
            g.fillStyle = colors.muted;
            g.fillText(String(v), lx1, yOf(v) - label * 0.3);
        }
        if (st.line.length > 1) {
            const n = Math.round(p.span / POINT);
            g.strokeStyle = colors.accent;
            g.lineWidth = Math.max(1.5, h * 0.01);
            g.lineJoin = 'round';
            g.beginPath();
            st.line.forEach((v, i) => {
                const x = lx0 + ((lx1 - lx0) * (i + (n - st.line.length))) / (n - 1);
                if (i === 0) g.moveTo(x, yOf(v));
                else g.lineTo(x, yOf(v));
            });
            g.stroke();
        }
    },
};
