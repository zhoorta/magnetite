// K-system meter (Bob Katz): the RMS level on a scale whose zero is a fixed distance under full scale, K-20 (-20 dBFS), K-14
// or K-12. The body is the RMS with a 300 ms integration, the tick is the peak with a hold. RMS is read the AES17 way, so a
// full-scale sine reads 0 dBFS (+3 dB over the plain RMS). Green up to 0, yellow 0 to +4 K, red above.

import { Meter } from './meter.js';
import { stateOf } from './poster.js';

const SQRT2 = Math.SQRT2; // +3.01 dB: the AES17 reading of an RMS value
const FLOOR = 40; // dB shown below the K zero
const ZONES = [[-FLOOR, 0, 'ok'], [0, 4, 'accent2'], [4, 99, 'hot']]; // 'ok' and 'hot' are params, 'accent2' the theme's

export default {
    id: 'k-meter',
    needs: ['levels'],
    label: 'K-meter',
    params: {
        scale: 20, // 20, 14 or 12: the K zero sits this many dB under full scale
        ok: '#7ec17e',
        hot: '#ff4d3d',
    },

    draw(g, { levels }, { w, h, colors, params, dt }) {
        const p = { ...this.params, ...params };
        const st = stateOf(g, () => ({ l: new Meter(), r: new Meter() }));
        const cfg = { min: -FLOOR - p.scale, max: 0, attack: 0.3, release: 0.3, hold: 1.5, fall: 0.25, clipDb: -0.1 };
        st.l.update({ rms: levels.l.rms * SQRT2, peak: levels.l.peak }, dt, cfg);
        st.r.update({ rms: levels.r.rms * SQRT2, peak: levels.r.peak }, dt, cfg);

        const kOf = (m) => cfg.min + m.value * (cfg.max - cfg.min) + p.scale; // the smoothed RMS in K units
        const peakOf = (m) => cfg.min + m.peak * (cfg.max - cfg.min) + p.scale;
        const zoneColor = { ok: p.ok, hot: p.hot, accent2: colors.accent2 };

        const margin = Math.min(w, h) * 0.06;
        const label = Math.max(9, Math.min(h * 0.045, w * 0.045));
        const top = margin + label * 1.6;
        const bottom = h - margin - label * 1.8;
        const bw = Math.min(w * 0.14, (bottom - top) * 0.18);
        const gapB = bw * 0.6;
        const scaleW = label * 2.6;
        const readout = w >= h * 1.15;
        const textW = readout ? label * 8 : 0;
        const total = scaleW + bw * 2 + gapB + (readout ? bw + textW : 0);
        const x0 = (w - total) / 2 + scaleW;
        const total_k = FLOOR + p.scale;
        const y = (k) => bottom - ((bottom - top) * (Math.max(-FLOOR, Math.min(p.scale, k)) + FLOOR)) / total_k;

        g.textBaseline = 'middle';
        g.textAlign = 'left';
        g.fillStyle = colors.muted;
        g.font = `${Math.round(label * 1.1)}px ${colors.font}`;
        g.fillText(`K-${p.scale}`, margin, margin + label * 0.7);

        // scale
        g.textAlign = 'right';
        g.font = `${Math.round(label)}px ${colors.font}`;
        for (let k = -FLOOR; k <= p.scale; k += 2) {
            const major = k % 10 === 0 || k === 4;
            g.strokeStyle = k === 0 ? colors.text : colors.muted;
            g.globalAlpha = major ? 0.9 : 0.4;
            g.lineWidth = k === 0 ? 2 : 1;
            g.beginPath();
            g.moveTo(x0 - label * 0.5, y(k));
            g.lineTo(x0 - label * (major ? 0.05 : 0.25), y(k));
            g.stroke();
            if (major) {
                g.globalAlpha = 1;
                g.fillStyle = k === 0 ? colors.text : colors.muted;
                g.fillText(k > 0 ? `+${k}` : String(k), x0 - label * 0.7, y(k));
            }
        }
        g.globalAlpha = 1;

        [['L', st.l], ['R', st.r]].forEach(([name, m], i) => {
            const x = x0 + i * (bw + gapB);
            g.fillStyle = colors.muted;
            g.globalAlpha = 0.2;
            g.fillRect(x, top, bw, bottom - top);
            g.globalAlpha = 1;
            const k = kOf(m);
            for (const [a, b, c] of ZONES) {
                if (k <= a) continue;
                g.fillStyle = zoneColor[c] ?? c;
                g.fillRect(x, y(Math.min(b, k)), bw, y(a) - y(Math.min(b, k)));
            }
            g.fillStyle = colors.text;
            g.fillRect(x - bw * 0.12, y(peakOf(m)) - 1.5, bw * 1.24, 3);
            g.textAlign = 'center';
            g.fillStyle = colors.muted;
            g.font = `${Math.round(label)}px ${colors.font}`;
            g.fillText(name, x + bw / 2, bottom + label * 1.1);
        });

        if (readout) {
            const x = x0 + bw * 2 + gapB + bw;
            const big = Math.max(14, label * 2.2);
            const mid = (top + bottom) / 2;
            g.textAlign = 'left';
            [['RMS', Math.max(kOf(st.l), kOf(st.r))], ['PEAK', Math.max(peakOf(st.l), peakOf(st.r))]].forEach(([name, v], i) => {
                const yy = mid - big * 0.9 + i * big * 2;
                g.fillStyle = colors.muted;
                g.font = `${Math.round(label)}px ${colors.font}`;
                g.fillText(name, x, yy - big * 0.8);
                g.fillStyle = v > 4 && name === 'RMS' ? p.hot : colors.text;
                g.font = `${Math.round(big)}px ${colors.font}`;
                g.fillText(v > -FLOOR + 0.05 ? `${v > 0 ? '+' : v < 0 ? '−' : ''}${Math.abs(v).toFixed(1)}` : '−−', x, yy);
            });
        }
    },
};
