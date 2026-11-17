// Loudness readout in LUFS (EBU R128) as nixie tubes: momentary, short-term and integrated, plus the true peak.
// The numbers come from the meter worklet; this only draws them. Each digit sits in its own dark cell and glows
// (a wide faint stroke under a bright fill: no shadowBlur, which is too slow to run 20 times a frame).

import { toDb } from './meter.js';

const ROWS = [
    ['M', 'MOMENTARY', 'LUFS'],
    ['S', 'SHORT-TERM', 'LUFS'],
    ['I', 'INTEGRATED', 'LUFS'],
    ['TP', 'TRUE PEAK', 'dBTP'],
];
const CELLS = 5; // -23.4

/** `−23.4` padded to the cells; a value that is not there yet shows dashes. */
export const nixieText = (v) => (Number.isFinite(v) ? `${v < 0 ? '−' : ' '}${Math.abs(v).toFixed(1)}`.padStart(CELLS, ' ') : ' −−.−'.slice(-CELLS));

export default {
    id: 'loudness',
    needs: ['lufs'],
    label: 'Loudness (LUFS)',
    params: {
        digit: '#ffa04a', // the glow of the tubes; a nixie is orange whatever the theme
        ceiling: -1, // dBTP, EBU R128 true-peak limit
        warn: '#ff4d3d',
    },

    draw(g, { lufs }, { w, h, colors, params }) {
        const p = { ...this.params, ...params };
        const margin = Math.min(w, h) * 0.06;
        const rowH = (h - margin * 2) / ROWS.length;
        const cellH = Math.min(rowH * 0.86, (w - margin * 2) / 9);
        const cellW = cellH * 0.78;
        const gap = cellW * 0.12;
        const tubes = CELLS * (cellW + gap) - gap;
        const label = Math.max(9, Math.min(cellH * 0.3, w * 0.03));
        const x0 = (w - (tubes + label * 4.6)) / 2 + label * 1.6; // room for the row tag at the left and the unit at the right

        const tp = Math.max(lufs.tpL > 0 ? toDb(lufs.tpL) : -Infinity, lufs.tpR > 0 ? toDb(lufs.tpR) : -Infinity);
        const values = [lufs.m, lufs.s, lufs.i, tp];

        ROWS.forEach(([tag, , unit], i) => {
            const y = margin + i * rowH + (rowH - cellH) / 2;
            const v = values[i];
            const hot = i === 3 && v > p.ceiling;
            const glow = hot ? p.warn : p.digit;

            g.textBaseline = 'middle';
            g.textAlign = 'right';
            g.fillStyle = colors.muted;
            g.font = `${Math.round(label)}px ${colors.font}`;
            g.fillText(tag, x0 - label * 0.6, y + cellH / 2);
            g.textAlign = 'left';
            g.fillText(unit, x0 + tubes + label * 0.6, y + cellH / 2);

            const text = nixieText(v);
            g.font = `${Math.round(cellH * 0.74)}px ${colors.font}`;
            g.textAlign = 'center';
            g.lineJoin = 'round';
            for (let k = 0; k < CELLS; k++) {
                const x = x0 + k * (cellW + gap);
                g.globalAlpha = 1;
                g.fillStyle = colors.bg;
                g.beginPath();
                g.roundRect(x, y, cellW, cellH, cellW * 0.16);
                g.fill();
                g.strokeStyle = colors.muted;
                g.globalAlpha = 0.25;
                g.lineWidth = 1;
                g.stroke();
                const ch = text[k];
                if (ch === ' ') continue;
                const cx = x + cellW / 2;
                const cy = y + cellH * 0.53;
                g.strokeStyle = glow;
                g.fillStyle = glow;
                g.lineWidth = cellH * 0.16;
                g.globalAlpha = 0.14;
                g.strokeText(ch, cx, cy);
                g.globalAlpha = 1;
                g.fillText(ch, cx, cy);
            }
            g.globalAlpha = 1;
        });
    },
};
