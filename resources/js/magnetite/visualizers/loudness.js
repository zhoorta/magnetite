// Loudness readout in LUFS (EBU R128): momentary, short-term and integrated, plus true peak.
// The numbers come from the meter worklet; this only draws them.

import { toDb } from './meter';

const ROWS = [
    ['MOMENTARY', 'm'],
    ['SHORT-TERM', 's'],
    ['INTEGRATED', 'i'],
];
const TICKS = [-35, -23, -14, -5];

const fmt = (v) => (Number.isFinite(v) ? `${v < 0 ? '−' : '+'}${Math.abs(v).toFixed(1)}` : '−−.-');

export default {
    id: 'loudness',
    needs: ['lufs'],
    label: 'Loudness (LUFS)',
    params: {
        min: -41,
        max: -5,
        target: -23, // EBU R128 programme loudness
        ceiling: -1, // dBTP, EBU R128 true-peak limit
        warn: '#ff4d3d',
    },

    draw(g, { lufs }, { w, h, colors, params }) {
        const p = { ...this.params, ...params };
        const margin = Math.min(w, h) * 0.07;
        const rowH = Math.min((h - margin * 2) / 4.4, w * 0.12);
        const block = rowH * 4 + rowH * 0.4;
        const top = (h - block) / 2;
        const label = Math.max(9, Math.min(rowH * 0.22, w * 0.02));
        const big = Math.max(14, Math.min(rowH * 0.5, w * 0.07));
        const numW = big * 3.4;
        const barX = margin;
        const barW = w - margin * 2 - numW;
        const at = (v) => Math.max(0, Math.min(1, (v - p.min) / (p.max - p.min)));

        ROWS.forEach(([name, key], i) => {
            const y = top + i * rowH;
            const v = lufs[key];
            const barH = Math.max(4, rowH * 0.16);
            const barY = y + rowH * 0.62;

            g.textBaseline = 'alphabetic';
            g.textAlign = 'left';
            g.fillStyle = colors.muted;
            g.font = `${Math.round(label)}px ${colors.font}`;
            g.fillText(name, barX, y + label * 1.1);

            g.fillStyle = colors.muted;
            g.globalAlpha = 0.22;
            g.fillRect(barX, barY, barW, barH);
            g.globalAlpha = 1;
            g.fillStyle = key === 'i' ? colors.accent2 : colors.accent;
            g.fillRect(barX, barY, barW * at(v), barH);

            // target tick
            g.fillStyle = colors.text;
            g.fillRect(barX + barW * at(p.target) - 0.5, barY - barH * 0.35, 1.5, barH * 1.7);

            g.textAlign = 'right';
            g.fillStyle = colors.text;
            g.font = `${Math.round(big)}px ${colors.font}`;
            g.fillText(fmt(v), w - margin - label * 3.2, y + rowH * 0.55);
            g.fillStyle = colors.muted;
            g.font = `${Math.round(label)}px ${colors.font}`;
            g.fillText('LUFS', w - margin, y + rowH * 0.55);
        });

        // scale under the three bars
        const scaleY = top + rowH * 3 - label * 0.15;
        g.fillStyle = colors.muted;
        g.textBaseline = 'alphabetic';
        g.font = `${Math.round(label)}px ${colors.font}`;
        g.textAlign = 'center';
        for (const t of TICKS) g.fillText(String(t), barX + barW * at(t), scaleY + label * 1.2);

        // true peak, one number per channel; red above the ceiling
        const y = top + rowH * 3.4;
        g.textAlign = 'left';
        g.fillStyle = colors.muted;
        g.font = `${Math.round(label)}px ${colors.font}`;
        g.fillText('TRUE PEAK', barX, y + label * 1.1);
        [['L', lufs.tpL], ['R', lufs.tpR]].forEach(([name, lin], i) => {
            const db = lin > 0 ? toDb(lin) : -Infinity;
            const x = barX + barW * 0.45 + i * barW * 0.28;
            g.textAlign = 'left';
            g.fillStyle = colors.muted;
            g.font = `${Math.round(label)}px ${colors.font}`;
            g.fillText(name, x, y + rowH * 0.5);
            g.fillStyle = db > p.ceiling ? p.warn : colors.text;
            g.font = `${Math.round(big * 0.8)}px ${colors.font}`;
            g.fillText(fmt(db), x + label * 1.6, y + rowH * 0.5);
        });
        g.textAlign = 'right';
        g.fillStyle = colors.muted;
        g.font = `${Math.round(label)}px ${colors.font}`;
        g.fillText('dBTP', w - margin, y + rowH * 0.5);
    },
};
