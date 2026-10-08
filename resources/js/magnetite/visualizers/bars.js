export default {
    id: 'bars',
    needs: ['freq'],
    label: 'Bars',
    params: { count: 56, gap: 3 },

    draw(g, { freq }, { w, h, colors, params }) {
        const { count, gap } = { ...this.params, ...params };
        const usable = Math.floor(freq.length * 0.7); // top bins are mostly empty
        const barW = (w - gap * (count - 1)) / count;
        const grad = g.createLinearGradient(0, h, 0, 0);
        grad.addColorStop(0, colors.accent);
        grad.addColorStop(1, colors.accent2);
        g.fillStyle = grad;

        for (let i = 0; i < count; i++) {
            // log-ish mapping so the bass gets more than one bar
            const bin = Math.floor(Math.pow(i / count, 1.6) * usable);
            // Music falls off roughly 4.5 dB/octave toward the treble, so tilt the highs up to keep
            // the right half of the display alive instead of a flat plateau.
            const tilt = 0.55 + 1.3 * (i / count);
            const v = Math.min(1, (freq[bin] / 255) * tilt);
            const bh = Math.max(2, v * h * 0.95);
            g.fillRect(i * (barW + gap), h - bh, barW, bh);
        }
    },
};
