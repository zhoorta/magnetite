// Loudness range (EBU Tech 3342) live: a histogram of the short-term loudness since the track began, with a bracket from
// the 10th to the 95th percentile (the range in LU) and the number. The worklet keeps the histogram (`lraShape`, 1 LU
// bins from -60 to 0 LUFS, plus `lra`, `lraLo`, `lraHi`), so this only draws. It needs 3 s of programme to start.

const FIRST = -60; // the LUFS of the first bin

export default {
    id: 'lra',
    needs: ['lufs'],
    label: 'Loudness range',
    params: {},

    draw(g, { lufs }, { w, h, colors }) {
        const shape = lufs.lraShape ?? [];
        const bins = shape.length || 60;
        const margin = Math.min(w, h) * 0.07;
        const label = Math.max(9, Math.min(h * 0.06, w * 0.04));
        const big = Math.max(16, Math.min(h * 0.2, w * 0.14));
        const ready = Number.isFinite(lufs.lraLo) && lufs.lra > 0;

        g.textBaseline = 'alphabetic';
        g.textAlign = 'left';
        g.fillStyle = colors.muted;
        g.font = `${Math.round(label)}px ${colors.font}`;
        g.fillText('LOUDNESS RANGE', margin, margin + label);
        g.textAlign = 'right';
        g.fillStyle = colors.accent2;
        g.font = `${Math.round(big)}px ${colors.font}`;
        const num = ready ? lufs.lra.toFixed(1) : '−−.−';
        g.fillText(num, w - margin - label * 2.2, margin + label * 0.6 + big * 0.7);
        g.fillStyle = colors.muted;
        g.font = `${Math.round(label)}px ${colors.font}`;
        g.textAlign = 'left';
        g.fillText('LU', w - margin - label * 2.0, margin + label * 0.6 + big * 0.7);

        const x0 = margin;
        const x1 = w - margin;
        const y0 = margin + label * 1.6 + big;
        const y1 = h - margin - label * 3;
        if (y1 - y0 < label * 2) return;
        const bw = (x1 - x0) / bins;
        const max = Math.max(1, ...shape);
        const xOfLufs = (l) => x0 + (x1 - x0) * ((l - FIRST) / bins);

        for (let i = 0; i < bins; i++) {
            const n = shape[i] ?? 0;
            if (!n) continue;
            const mid = FIRST + i + 0.5;
            const inside = ready && mid >= lufs.lraLo - 0.5 && mid <= lufs.lraHi + 0.5;
            const bh = Math.max(1, ((y1 - y0) * n) / max);
            g.fillStyle = inside ? colors.accent : colors.muted;
            g.globalAlpha = inside ? 1 : 0.4;
            g.fillRect(x0 + i * bw + 0.5, y1 - bh, Math.max(1, bw - 1), bh);
        }
        g.globalAlpha = 1;

        if (ready) {
            const a = xOfLufs(lufs.lraLo);
            const b = xOfLufs(lufs.lraHi);
            const yy = y1 + label * 0.8;
            g.strokeStyle = colors.accent2;
            g.lineWidth = Math.max(1.5, h * 0.008);
            g.beginPath();
            g.moveTo(a, yy - label * 0.4);
            g.lineTo(a, yy);
            g.lineTo(b, yy);
            g.lineTo(b, yy - label * 0.4);
            g.stroke();
        }

        g.fillStyle = colors.muted;
        g.font = `${Math.round(label * 0.9)}px ${colors.font}`;
        g.textAlign = 'center';
        for (const l of [-50, -40, -30, -20, -10]) g.fillText(String(l), xOfLufs(l), y1 + label * 2.4);
    },
};
