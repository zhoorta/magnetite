// Shared round-dial drawing for the needle meters (cream VU, backlit receiver). Styles differ only by params.

const SWEEP = (96 * Math.PI) / 180; // total needle travel, centred on straight up
export const MAJOR_DB = [-30, -20, -10, -6, -3, 0];

/**
 * Draw one dial in the box (x, y, w, h). `p` carries the scale (min, max, redFrom) and the look:
 * face, ink, zone (red-zone colour), and optionally backlight (lit-from-behind face), bezel (metal frame) and glare.
 */
export function drawDial(g, x, y, w, h, name, meter, p, colors) {
    const range = p.max - p.min;
    const r = Math.min(w * 0.6, h * 0.85);
    const cx = x + w / 2;
    const cy = y + h * 0.93;
    const angleOf = (db) => -SWEEP / 2 + SWEEP * ((db - p.min) / range);
    const pt = (db, len) => [cx + Math.sin(angleOf(db)) * len, cy - Math.cos(angleOf(db)) * len];
    const radius = Math.min(w, h) * 0.06;

    g.save();
    g.beginPath();
    g.roundRect(x, y, w, h, radius);
    g.fillStyle = p.backlight ?? p.face;
    g.fill();
    g.clip();

    if (p.backlight) {
        // Lit from behind: bright in the middle, falling off toward the edges, with a soft bloom.
        const vignette = g.createRadialGradient(cx, y + h * 0.62, 0, cx, y + h * 0.62, w * 0.62);
        vignette.addColorStop(0, 'rgba(0,0,0,0)');
        vignette.addColorStop(0.55, 'rgba(0,0,0,0.14)');
        vignette.addColorStop(1, 'rgba(0,0,0,0.72)');
        g.fillStyle = vignette;
        g.fillRect(x, y, w, h);

        const bloom = g.createRadialGradient(cx, y + h * 0.55, 0, cx, y + h * 0.55, w * 0.45);
        bloom.addColorStop(0, 'rgba(255,240,200,0.28)');
        bloom.addColorStop(1, 'rgba(255,240,200,0)');
        g.globalCompositeOperation = 'lighter';
        g.fillStyle = bloom;
        g.fillRect(x, y, w, h);
        g.globalCompositeOperation = 'source-over';
    }

    // red zone
    g.lineWidth = r * 0.05;
    g.strokeStyle = p.zone;
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
        const major = MAJOR_DB.includes(db);
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
    g.fillStyle = p.zone;
    g.beginPath();
    g.arc(x + w * 0.9, y + h * 0.15, r * 0.045, 0, Math.PI * 2);
    g.fill();
    g.globalAlpha = 1;

    // needle (meter.value is already a 0..1 position on the scale)
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

    if (p.glare) {
        const glare = g.createLinearGradient(x, y, x + w * 0.45, y + h * 0.45);
        glare.addColorStop(0, 'rgba(255,255,255,0.16)');
        glare.addColorStop(1, 'rgba(255,255,255,0)');
        g.fillStyle = glare;
        g.beginPath();
        g.moveTo(x, y);
        g.lineTo(x + w * 0.72, y);
        g.lineTo(x, y + h * 0.78);
        g.closePath();
        g.fill();
    }

    g.restore();

    if (p.bezel) drawBezel(g, x, y, w, h, radius);
}

/** Brushed-metal frame around a meter face, with four slotted screws. */
export function drawBezel(g, x, y, w, h, radius) {
    const bw = Math.min(w, h) * 0.045;
    const metal = g.createLinearGradient(x, y, x + w, y + h);
    metal.addColorStop(0, '#e6e6e6');
    metal.addColorStop(0.25, '#8d8d8d');
    metal.addColorStop(0.5, '#d9d9d9');
    metal.addColorStop(0.75, '#7a7a7a');
    metal.addColorStop(1, '#bdbdbd');

    g.save();
    g.lineWidth = bw;
    g.strokeStyle = metal;
    g.beginPath();
    g.roundRect(x - bw / 2, y - bw / 2, w + bw, h + bw, radius + bw / 2);
    g.stroke();

    // screws
    const sr = bw * 0.55;
    const inset = bw * 1.1;
    for (const [sx, sy, rot] of [[x - inset, y - inset, 0.5], [x + w + inset, y - inset, 2.1], [x - inset, y + h + inset, 1.3], [x + w + inset, y + h + inset, 0.2]]) {
        g.fillStyle = metal;
        g.beginPath();
        g.arc(sx, sy, sr, 0, Math.PI * 2);
        g.fill();
        g.strokeStyle = 'rgba(0,0,0,0.55)';
        g.lineWidth = sr * 0.25;
        g.beginPath();
        g.moveTo(sx - Math.cos(rot) * sr * 0.7, sy - Math.sin(rot) * sr * 0.7);
        g.lineTo(sx + Math.cos(rot) * sr * 0.7, sy + Math.sin(rot) * sr * 0.7);
        g.stroke();
    }
    g.restore();
}
