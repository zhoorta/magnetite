export default {
    id: 'radial',
    needs: ['freq'],
    label: 'Radial',
    params: { spokes: 96, inner: 0.22 },

    draw(g, { freq }, { w, h, colors, params }) {
        const { spokes, inner } = { ...this.params, ...params };
        const cx = w / 2;
        const cy = h / 2;
        const r0 = Math.min(w, h) * inner;
        const span = Math.min(w, h) * 0.5 - r0;
        const usable = Math.floor(freq.length * 0.6);

        g.lineWidth = Math.max(2, Math.min(w, h) / 160);
        g.lineCap = 'round';

        for (let i = 0; i < spokes; i++) {
            const mirrored = i < spokes / 2 ? i : spokes - 1 - i; // symmetric ring
            const v = freq[Math.floor((mirrored / (spokes / 2)) * usable)] / 255;
            const a = (i / spokes) * Math.PI * 2 - Math.PI / 2;
            const r1 = r0 + Math.max(2, v * span);

            g.strokeStyle = i % 2 ? colors.accent : colors.accent2;
            g.beginPath();
            g.moveTo(cx + Math.cos(a) * r0, cy + Math.sin(a) * r0);
            g.lineTo(cx + Math.cos(a) * r1, cy + Math.sin(a) * r1);
            g.stroke();
        }
    },
};
