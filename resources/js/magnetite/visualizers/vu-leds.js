import { stereoMeters } from './meter';

const meters = stereoMeters();
const TICKS = [-48, -36, -24, -12, -6, 0];

export default {
    id: 'vu-leds',
    label: 'LED meters',
    params: {
        min: -48,
        max: 0,
        segments: 40,
        attack: 0.04, // fast to catch transients, like a peak meter
        release: 0.35,
        hold: 1.2, // seconds the peak marker stays
        midFrom: -12, // dBFS where the second colour starts
        hotFrom: -3, // dBFS where the third colour starts
    },

    draw(g, { levels }, { w, h, colors, params, dt }) {
        const p = { ...this.params, ...params };
        const cfg = { min: p.min, max: p.max, attack: p.attack, release: p.release, hold: p.hold, fall: 0.4 };

        meters.l.update(levels.l, dt, cfg);
        meters.r.update(levels.r, dt, cfg);

        const margin = Math.min(w, h) * 0.08;
        const labelW = margin * 1.2;
        const barX = margin + labelW;
        const barW = w - barX - margin;
        // Bars stay a sensible height on a tall canvas; the block (bars + scale) is centred vertically.
        const barH = Math.min((h - margin * 2) * 0.28, barW * 0.1);
        const scaleFont = Math.max(10, Math.min(barH * 0.42, w * 0.022));
        const blockH = barH * 2.1 + scaleFont * 2;
        const topY = (h - blockH) / 2;
        const at = (db) => (db - p.min) / (p.max - p.min);

        g.font = `${Math.max(11, Math.round(barH * 0.55))}px ${colors.font}`;
        g.textBaseline = 'middle';

        [['L', meters.l, topY], ['R', meters.r, topY + barH * 1.1]].forEach(([name, m, y]) => {
            g.fillStyle = colors.text;
            g.textAlign = 'left';
            g.fillText(name, margin, y + barH / 2);

            const slot = barW / p.segments;
            const lit = Math.round(m.value * p.segments);
            const peakSeg = Math.min(p.segments - 1, Math.floor(m.peak * p.segments));

            for (let i = 0; i < p.segments; i++) {
                const pos = (i + 0.5) / p.segments;
                const color = pos >= at(p.hotFrom) ? colors.text : pos >= at(p.midFrom) ? colors.accent2 : colors.accent;
                const on = i < lit || (i === peakSeg && m.peak > 0.02);

                g.globalAlpha = on ? 1 : 0.14;
                g.fillStyle = color;
                g.fillRect(barX + i * slot, y, slot * 0.78, barH);
            }
            g.globalAlpha = 1;
        });

        // dB scale under the bars; the last label is right-aligned so it stays inside the bar
        g.fillStyle = colors.muted;
        g.font = `${Math.round(scaleFont)}px ${colors.font}`;
        const scaleY = topY + barH * 2.1 + scaleFont * 1.1;
        for (const db of TICKS) {
            g.textAlign = db === p.max ? 'right' : db === p.min ? 'left' : 'center';
            g.fillText(String(db), barX + at(db) * barW, scaleY);
        }
    },
};
