import { MAJOR_DB } from './dial';
import { stereoMeters } from './meter';

const ASPECT = 4.2; // face width / height: a long, shallow window
const THETA = (14 * Math.PI) / 180; // half of the needle's travel; small, so the needle moves almost sideways
const meters = stereoMeters();

/**
 * A wide horizontal meter, like a cassette deck's: the scale is a long shallow arc and the needle pivots far below
 * the window, so it only shows as a line sweeping sideways. Two of them, stacked or side by side depending on room.
 */
export default {
    id: 'vu-wide',
    needs: ['levels'],
    label: 'Wide meters',
    params: {
        min: -30,
        max: 3,
        response: 0.3,
        redFrom: -6,
        face: null, // defaults to the theme surface
        ink: null, // scale and labels; defaults to the theme text
        needle: null, // defaults to the ink colour
        zone: null, // red zone and clip lamp; defaults to the theme accent
    },

    draw(g, { levels }, { w, h, colors, params, dt }) {
        const p = { ...this.params, ...params };
        const look = { ...p, face: p.face ?? colors.surface, ink: p.ink ?? colors.text, zone: p.zone ?? colors.accent };
        look.needle = p.needle ?? look.ink;
        const cfg = { min: p.min, max: p.max, attack: p.response, release: p.response, hold: 1, fall: 0.3 };

        meters.l.update(levels.l, dt, cfg);
        meters.r.update(levels.r, dt, cfg);

        // Stacked fits tall canvases, side by side fits the short, wide player dock: use whichever gives the bigger meters.
        const gap = Math.min(w, h) * 0.06;
        const side = Math.min((w - gap * 3) / 2, (h - gap * 2) * ASPECT);
        const stacked = Math.min(w - gap * 2, ((h - gap * 3) / 2) * ASPECT);
        const useStack = stacked > side;
        const boxW = useStack ? stacked : side;
        const boxH = boxW / ASPECT;

        const boxes = useStack
            ? [[(w - boxW) / 2, (h - (boxH * 2 + gap)) / 2], [(w - boxW) / 2, (h - (boxH * 2 + gap)) / 2 + boxH + gap]]
            : [[(w - (boxW * 2 + gap)) / 2, (h - boxH) / 2], [(w - (boxW * 2 + gap)) / 2 + boxW + gap, (h - boxH) / 2]];

        [['L', meters.l], ['R', meters.r]].forEach(([name, m], i) => {
            this.meter(g, boxes[i][0], boxes[i][1], boxW, boxH, name, m, look, colors);
        });
    },

    meter(g, x, y, W, H, name, meter, p, colors) {
        const R = (W * 0.43) / Math.sin(THETA); // radius of the scale arc
        const cx = x + W / 2;
        const py = y + H * 0.42 + R; // pivot, far below the window
        const range = p.max - p.min;
        const angleOf = (db) => -THETA + 2 * THETA * ((db - p.min) / range);
        const pt = (db, len) => [cx + Math.sin(angleOf(db)) * len, py - Math.cos(angleOf(db)) * len];

        g.save();
        g.beginPath();
        g.roundRect(x, y, W, H, H * 0.1);
        g.fillStyle = p.face;
        g.fill();
        g.clip();

        // red zone along the scale
        g.lineWidth = H * 0.07;
        g.strokeStyle = p.zone;
        g.beginPath();
        g.arc(cx, py, R, angleOf(p.redFrom) - Math.PI / 2, angleOf(p.max) - Math.PI / 2);
        g.stroke();

        // ticks hang below the arc, labels below the ticks
        g.strokeStyle = p.ink;
        g.fillStyle = p.ink;
        g.font = `${Math.max(9, Math.round(H * 0.17))}px ${colors.font}`;
        g.textAlign = 'center';
        g.textBaseline = 'middle';
        for (let db = p.min; db <= p.max; db += 2) {
            const major = MAJOR_DB.includes(db);
            g.lineWidth = major ? H * 0.02 : H * 0.011;
            const [x1, y1] = pt(db, R - H * 0.05);
            const [x2, y2] = pt(db, R - H * (major ? 0.16 : 0.11));
            g.beginPath();
            g.moveTo(x1, y1);
            g.lineTo(x2, y2);
            g.stroke();
            if (major) {
                const [tx, ty] = pt(db, R - H * 0.27);
                g.fillText(String(db), tx, ty);
            }
        }

        g.textAlign = 'left';
        g.font = `${Math.max(10, Math.round(H * 0.24))}px ${colors.font}`;
        g.fillText(name, x + W * 0.035, y + H * 0.22);

        // clip lamp
        g.globalAlpha = meter.clipLeft > 0 ? 1 : 0.18;
        g.fillStyle = p.zone;
        g.beginPath();
        g.arc(x + W * 0.965, y + H * 0.2, H * 0.05, 0, Math.PI * 2);
        g.fill();
        g.globalAlpha = 1;

        // needle: drawn from far below, so only the part inside the window shows
        const a = -THETA + 2 * THETA * meter.value;
        g.strokeStyle = p.needle;
        g.lineWidth = Math.max(1.5, H * 0.022);
        g.lineCap = 'round';
        g.shadowColor = 'rgba(0,0,0,0.4)';
        g.shadowBlur = H * 0.04;
        g.beginPath();
        g.moveTo(cx + Math.sin(a) * (R - H * 1.3), py - Math.cos(a) * (R - H * 1.3));
        g.lineTo(cx + Math.sin(a) * (R + H * 0.06), py - Math.cos(a) * (R + H * 0.06));
        g.stroke();

        g.restore();
    },
};
