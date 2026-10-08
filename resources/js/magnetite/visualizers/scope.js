export default {
    id: 'scope',
    needs: ['wave'],
    label: 'Scope',
    params: { thickness: 2.5 },

    draw(g, { wave }, { w, h, colors, params }) {
        const { thickness } = { ...this.params, ...params };

        g.lineWidth = thickness;
        g.strokeStyle = colors.accent;
        g.shadowColor = colors.accent;
        g.shadowBlur = 12;
        g.beginPath();

        const step = w / (wave.length - 1);
        for (let i = 0; i < wave.length; i++) {
            const y = (wave[i] / 255) * h;
            i === 0 ? g.moveTo(0, y) : g.lineTo(i * step, y);
        }

        g.stroke();
        g.shadowBlur = 0;
    },
};
