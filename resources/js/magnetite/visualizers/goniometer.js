// Stereo vectorscope (mid up, side across) with a phase-correlation strip. The trace keeps a short
// phosphor-like afterglow on a canvas of its own, because the player canvas is cleared every frame.

import { stateOf } from './poster.js';

const makeState = () => ({ c: null, g: null, size: 0 }); // per canvas, see spectrogram.js
const CORR_TICKS = [-1, 0, 1];

export default {
    id: 'goniometer',
    needs: ['stereo'],
    label: 'Goniometer',
    params: {
        glow: 0.09, // seconds the trace takes to fade (time constant)
        warn: '#ff4d3d', // correlation bar when the channels fight each other
    },

    draw(g, { stereo }, { w, h, colors, params, dt }) {
        const state = stateOf(g, makeState);
        const p = { ...this.params, ...params };
        const margin = Math.min(w, h) * 0.06;
        const font = Math.max(10, Math.min(h * 0.045, w * 0.03));
        const barH = Math.max(6, font * 0.7);
        const stripH = barH + font * 2.4;
        const size = Math.floor(Math.min(w - margin * 2, h - stripH - margin * 2));
        if (size < 20) return;

        const cx = w / 2;
        const top = (h - size - stripH) / 2;
        const cy = top + size / 2;
        const R = size / 2;

        // trace, on the persistent canvas
        if (!state.c) state.c = document.createElement('canvas');
        if (state.size !== size) {
            state.c.width = state.c.height = size;
            state.g = state.c.getContext('2d');
            state.size = size;
        }
        const t = state.g;
        t.globalCompositeOperation = 'destination-out';
        t.fillStyle = `rgba(0,0,0,${1 - Math.exp(-dt / p.glow)})`;
        t.fillRect(0, 0, size, size);
        t.globalCompositeOperation = 'source-over';

        const k = R * 0.7; // a full-scale mono signal just reaches the circle
        const s = stereo.gonio;
        t.beginPath();
        for (let i = 0; i < s.length; i += 2) {
            const x = R + ((s[i + 1] - s[i]) / Math.SQRT2) * k;
            const y = R - ((s[i] + s[i + 1]) / Math.SQRT2) * k;
            i === 0 ? t.moveTo(x, y) : t.lineTo(x, y);
        }
        t.strokeStyle = colors.accent;
        t.lineWidth = Math.max(1, size / 260);
        t.globalAlpha = 0.55;
        t.stroke();
        t.globalAlpha = 1;

        // graticule
        g.strokeStyle = colors.muted;
        g.globalAlpha = 0.4;
        g.lineWidth = 1;
        g.beginPath();
        g.arc(cx, cy, R, 0, Math.PI * 2);
        g.moveTo(cx, cy - R);
        g.lineTo(cx, cy + R);
        g.moveTo(cx - R, cy);
        g.lineTo(cx + R, cy);
        g.moveTo(cx - R * 0.7071, cy - R * 0.7071);
        g.lineTo(cx + R * 0.7071, cy + R * 0.7071);
        g.moveTo(cx + R * 0.7071, cy - R * 0.7071);
        g.lineTo(cx - R * 0.7071, cy + R * 0.7071);
        g.stroke();
        g.globalAlpha = 1;

        g.fillStyle = colors.muted;
        g.font = `${Math.round(font)}px ${colors.font}`;
        g.textBaseline = 'middle';
        g.textAlign = 'center';
        g.fillText('M', cx, cy - R - font * 0.7);
        g.fillText('L', cx - R * 0.7071 - font * 0.9, cy - R * 0.7071 - font * 0.3);
        g.fillText('R', cx + R * 0.7071 + font * 0.9, cy - R * 0.7071 - font * 0.3);

        g.drawImage(state.c, cx - R, cy - R);

        // correlation strip
        const barW = Math.min(w - margin * 2, size * 1.5);
        const x0 = cx - barW / 2;
        const y0 = cy + R + font * 0.9;
        const at = (v) => x0 + ((v + 1) / 2) * barW;
        const c = Math.max(-1, Math.min(1, stereo.corr));

        g.fillStyle = colors.muted;
        g.globalAlpha = 0.25;
        g.fillRect(x0, y0, barW, barH);
        g.globalAlpha = 1;
        g.fillStyle = c < 0 ? p.warn : colors.accent;
        g.fillRect(Math.min(at(0), at(c)), y0, Math.abs(at(c) - at(0)), barH);

        g.fillStyle = colors.muted;
        g.font = `${Math.round(font * 0.9)}px ${colors.font}`;
        for (const v of CORR_TICKS) {
            g.textAlign = v < 0 ? 'left' : v > 0 ? 'right' : 'center';
            g.fillText(v > 0 ? '+1' : String(v), at(v), y0 + barH + font * 0.9);
        }
        g.textAlign = 'center';
        g.fillStyle = colors.text;
        g.font = `${Math.round(font)}px ${colors.font}`;
        g.fillText(`correlation ${c >= 0 ? '+' : '−'}${Math.abs(c).toFixed(2)}`, cx, y0 + barH + font * 2.1);
    },
};
