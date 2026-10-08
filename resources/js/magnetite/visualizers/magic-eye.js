import { stereoMeters } from './meter';

const meters = stereoMeters();
const DEG = Math.PI / 180;

/**
 * A "magic eye" tuning indicator (6E5 / EM84 style), one per channel. A phosphor target glows inside a glass
 * tube and a dark shadow wedge opens from its centre: with no signal the wedge is wide, and as the level rises
 * it closes, like the tube it imitates.
 */
export default {
    id: 'magic-eye',
    needs: ['levels'],
    label: 'Magic eyes',
    params: {
        min: -36, // dBFS where the shadow is fully open
        max: 0, // dBFS where it is fully closed
        attack: 0.12,
        release: 0.35,
        openAngle: 78, // half-angle of the shadow with no signal, in degrees
        phosphor: null, // glow colour; defaults to the theme's second accent
        glass: '#050806', // inside of the tube
        rim: null, // metal ring; defaults to the theme's muted colour
    },

    draw(g, { levels }, { w, h, colors, params, dt }) {
        const p = { ...this.params, ...params };
        const cfg = { min: p.min, max: p.max, attack: p.attack, release: p.release, hold: 0.6, fall: 0.3 };

        meters.l.update(levels.l, dt, cfg);
        meters.r.update(levels.r, dt, cfg);

        const phosphor = p.phosphor ?? colors.accent2;
        const rim = p.rim ?? colors.muted;
        const gap = Math.min(w, h) * 0.12;
        const d = Math.max(20, Math.min(h * 0.74, (w - gap * 3) / 2));
        const x0 = (w - (d * 2 + gap)) / 2;
        const cy = h * 0.46;

        [['L', meters.l], ['R', meters.r]].forEach(([name, m], i) => {
            this.eye(g, x0 + d / 2 + i * (d + gap), cy, d / 2, m, { ...p, phosphor, rim }, colors);

            g.fillStyle = colors.muted;
            g.font = `${Math.max(10, Math.round(d * 0.1))}px ${colors.font}`;
            g.textAlign = 'center';
            g.textBaseline = 'middle';
            g.fillText(name, x0 + d / 2 + i * (d + gap), cy + d / 2 + d * 0.16);
        });
    },

    eye(g, cx, cy, r, meter, p, colors) {
        const v = meter.value;
        const half = (p.openAngle * (1 - v)) * DEG; // shadow half-angle: closes as the level rises
        const target = r * 0.84;

        g.save();

        // tube glass and metal ring
        g.fillStyle = p.glass;
        g.beginPath();
        g.arc(cx, cy, r, 0, Math.PI * 2);
        g.fill();
        g.lineWidth = r * 0.05;
        g.strokeStyle = p.rim;
        g.stroke();

        g.beginPath();
        g.arc(cx, cy, r * 0.98, 0, Math.PI * 2);
        g.clip();

        // phosphor target: it brightens as the tube is driven harder
        g.shadowColor = p.phosphor;
        g.shadowBlur = r * (0.12 + 0.3 * v);
        g.globalAlpha = 0.45 + 0.55 * v;
        g.fillStyle = p.phosphor;
        g.beginPath();
        g.arc(cx, cy, target, 0, Math.PI * 2);
        g.fill();
        g.shadowBlur = 0;

        // hot centre
        const hot = g.createRadialGradient(cx, cy, 0, cx, cy, target);
        hot.addColorStop(0, 'rgba(255,255,255,0.55)');
        hot.addColorStop(0.55, 'rgba(255,255,255,0.08)');
        hot.addColorStop(1, 'rgba(255,255,255,0)');
        g.globalAlpha = 0.3 + 0.7 * v;
        g.fillStyle = hot;
        g.beginPath();
        g.arc(cx, cy, target, 0, Math.PI * 2);
        g.fill();
        g.globalAlpha = 1;

        // shadow wedge, opening downward from the centre; the soft edge is a blurred shadow of the same shape
        if (half > 0.002) {
            g.shadowColor = p.glass;
            g.shadowBlur = r * 0.08;
            g.fillStyle = p.glass;
            g.beginPath();
            g.moveTo(cx, cy);
            g.arc(cx, cy, r * 1.2, Math.PI / 2 - half, Math.PI / 2 + half);
            g.closePath();
            g.fill();
            g.shadowBlur = 0;
        }

        // cathode cap in the middle
        g.fillStyle = p.glass;
        g.beginPath();
        g.arc(cx, cy, r * 0.12, 0, Math.PI * 2);
        g.fill();
        g.lineWidth = r * 0.02;
        g.strokeStyle = p.phosphor;
        g.globalAlpha = 0.5 + 0.5 * v;
        g.stroke();
        g.globalAlpha = 1;

        g.restore();

        // glass highlight on top of everything
        g.save();
        g.lineWidth = r * 0.05;
        g.lineCap = 'round';
        g.strokeStyle = 'rgba(255,255,255,0.16)';
        g.beginPath();
        g.arc(cx, cy, r * 0.9, 200 * DEG, 250 * DEG);
        g.stroke();
        g.restore();
    },
};
