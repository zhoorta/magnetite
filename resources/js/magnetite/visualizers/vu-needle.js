import { stereoMeters } from './meter';

const SWEEP = (96 * Math.PI) / 180; // total needle travel, centred on straight up
const ASPECT = 1.55; // dial face width / height
const MAJOR = [-30, -20, -10, -6, -3, 0];
const meters = stereoMeters();

export default {
    id: 'vu-needle',
    label: 'VU needles',
    params: {
        min: -30, // dBFS at the left end of the scale
        max: 3, // dBFS at the right end
        response: 0.3, // seconds for the needle to settle, like a real VU meter
        redFrom: -6, // dBFS where the red zone starts
        face: null, // dial face colour; defaults to the theme surface
        ink: null, // ticks, labels and needle; defaults to the theme text
    },

    draw(g, { levels }, { w, h, colors, params, dt }) {
        const p = { ...this.params, ...params };
        const face = p.face ?? colors.surface;
        const ink = p.ink ?? colors.text;
        const cfg = { min: p.min, max: p.max, attack: p.response, release: p.response, hold: 1, fall: 0.3 };

        meters.l.update(levels.l, dt, cfg);
        meters.r.update(levels.r, dt, cfg);

        // Dials keep a fixed shape, so a tall fullscreen canvas gets bigger dials, not taller faces.
        const gap = Math.min(w, h) * 0.04;
        const boxW = Math.min((w - gap * 3) / 2, (h - gap * 2) * ASPECT);
        const boxH = boxW / ASPECT;
        const x0 = (w - (boxW * 2 + gap)) / 2;
        const y0 = (h - boxH) / 2;

        [['L', meters.l], ['R', meters.r]].forEach(([name, m], i) => {
            this.dial(g, x0 + i * (boxW + gap), y0, boxW, boxH, name, m, { ...p, face, ink }, colors);
        });
    },

    dial(g, x, y, w, h, name, meter, p, colors) {
        const range = p.max - p.min;
        const r = Math.min(w * 0.6, h * 0.85);
        const cx = x + w / 2;
        const cy = y + h * 0.93;
        const angleOf = (db) => -SWEEP / 2 + SWEEP * ((db - p.min) / range);
        const pt = (db, len) => [cx + Math.sin(angleOf(db)) * len, cy - Math.cos(angleOf(db)) * len];

        // face
        g.save();
        g.beginPath();
        g.roundRect(x, y, w, h, Math.min(w, h) * 0.06);
        g.fillStyle = p.face;
        g.fill();
        g.clip();

        // red zone
        g.lineWidth = r * 0.05;
        g.strokeStyle = colors.accent;
        g.beginPath();
        g.arc(cx, cy, r * 0.93, angleOf(p.redFrom) - Math.PI / 2, angleOf(p.max) - Math.PI / 2);
        g.stroke();

        // ticks and labels
        g.strokeStyle = p.ink;
        g.fillStyle = p.ink;
        g.font = `${Math.max(10, Math.round(r * 0.085))}px ${colors.font}`;
        g.textAlign = 'center';
        g.textBaseline = 'middle';
        for (let db = p.min; db <= p.max; db += 2) {
            const major = MAJOR.includes(db);
            g.lineWidth = major ? r * 0.012 : r * 0.006;
            const [x1, y1] = pt(db, r * (major ? 0.8 : 0.85));
            const [x2, y2] = pt(db, r * 0.9);
            g.beginPath();
            g.moveTo(x1, y1);
            g.lineTo(x2, y2);
            g.stroke();
            if (major) {
                const [tx, ty] = pt(db, r * 0.7);
                g.fillText(String(db), tx, ty);
            }
        }

        // captions
        g.font = `${Math.max(12, Math.round(r * 0.14))}px ${colors.font}`;
        g.textAlign = 'left';
        g.fillText(name, x + w * 0.07, y + h * 0.16);
        g.font = `${Math.max(10, Math.round(r * 0.08))}px ${colors.font}`;
        g.textAlign = 'center';
        g.fillText('dB', cx, cy - r * 0.36);

        // clip lamp
        g.globalAlpha = meter.clipLeft > 0 ? 1 : 0.18;
        g.fillStyle = colors.accent;
        g.beginPath();
        g.arc(x + w * 0.9, y + h * 0.15, r * 0.045, 0, Math.PI * 2);
        g.fill();
        g.globalAlpha = 1;

        // needle (value is already on the 0..1 scale, so map it through the sweep directly)
        const a = -SWEEP / 2 + SWEEP * meter.value;
        g.strokeStyle = p.ink;
        g.lineWidth = r * 0.018;
        g.lineCap = 'round';
        g.shadowColor = 'rgba(0,0,0,0.35)';
        g.shadowBlur = r * 0.03;
        g.shadowOffsetX = r * 0.012;
        g.beginPath();
        g.moveTo(cx - Math.sin(a) * r * 0.08, cy + Math.cos(a) * r * 0.08);
        g.lineTo(cx + Math.sin(a) * r * 0.92, cy - Math.cos(a) * r * 0.92);
        g.stroke();
        g.shadowColor = 'transparent';

        // hub
        g.fillStyle = p.ink;
        g.beginPath();
        g.arc(cx, cy, r * 0.045, 0, Math.PI * 2);
        g.fill();

        g.restore();
    },
};
