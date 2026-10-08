// Scrolling spectrogram: time runs left to right (newest at the right edge), frequency is logarithmic from
// 20 Hz up, level is colour. The picture lives on a canvas of its own and is shifted left each frame, so
// only the new columns are computed. It only scrolls while the audio is playing.

const state = { c: null, g: null, pw: 0, ph: 0, acc: 0, lut: null, lutKey: '' };
const TICKS = [[100, '100'], [1000, '1k'], [10000, '10k']];
const FMIN = 20;
const FMAX = 20000;

const scratch = document.createElement('canvas').getContext('2d', { willReadFrequently: true });

function rgb(color) {
    scratch.canvas.width = scratch.canvas.height = 1;
    scratch.clearRect(0, 0, 1, 1);
    scratch.fillStyle = color;
    scratch.fillRect(0, 0, 1, 1);
    const [r, g, b] = scratch.getImageData(0, 0, 1, 1).data;

    return [r, g, b];
}

/** 256 colours from the theme: background, then accent, accent 2 and text for the loudest. */
function palette(colors) {
    const key = [colors.bg, colors.accent, colors.accent2, colors.text].join('|');
    if (state.lutKey === key) return state.lut;

    const stops = [[0, colors.bg], [0.4, colors.accent], [0.75, colors.accent2], [1, colors.text]].map(([at, c]) => [at, rgb(c)]);
    const lut = new Uint8ClampedArray(256 * 4);
    for (let i = 0; i < 256; i++) {
        const t = i / 255;
        let k = 1;
        while (k < stops.length - 1 && t > stops[k][0]) k++;
        const [t0, c0] = stops[k - 1];
        const [t1, c1] = stops[k];
        const f = Math.max(0, Math.min(1, (t - t0) / (t1 - t0)));
        for (let ch = 0; ch < 3; ch++) lut[i * 4 + ch] = c0[ch] + (c1[ch] - c0[ch]) * f;
        lut[i * 4 + 3] = 255;
    }
    state.lut = lut;
    state.lutKey = key;
    state.c = null; // the old picture used the old colours: start a fresh one

    return lut;
}

export default {
    id: 'spectrogram',
    needs: ['spectrum'],
    label: 'Spectrogram',
    params: {
        min: -95, // dB at the darkest colour
        max: -10, // dB at the brightest
        tilt: 2, // dB per octave added toward the treble, so music (about -3 dB/octave) does not fade to black
        span: 14, // seconds from the right edge to the left
    },

    draw(g, { spec, playing, sampleRate }, { w, h, colors, params, dt }) {
        const p = { ...this.params, ...params };
        const font = Math.max(9, Math.min(h * 0.04, w * 0.016));
        const left = Math.round(font * 3);
        const pw = w - left;
        const ph = h;
        if (pw < 20 || ph < 20) return;

        const lut = palette(colors);
        // A fresh picture when there is none (first draw, new theme colours) or the cell was resized.
        if (!state.c || state.pw !== pw || state.ph !== ph) {
            state.c = document.createElement('canvas');
            state.c.width = state.pw = pw;
            state.c.height = state.ph = ph;
            state.g = state.c.getContext('2d');
            state.g.fillStyle = `rgb(${lut[0]},${lut[1]},${lut[2]})`;
            state.g.fillRect(0, 0, pw, ph);
            state.acc = 0;
        }

        if (playing) {
            state.acc += (dt * pw) / p.span;
            const n = Math.min(pw, Math.floor(state.acc));
            if (n >= 1) {
                state.acc -= n;
                state.g.drawImage(state.c, -n, 0);
                this.column(state.g, spec, sampleRate, pw - n, n, ph, lut, p);
            }
        }

        g.drawImage(state.c, left, 0);

        g.fillStyle = colors.muted;
        g.font = `${Math.round(font)}px ${colors.font}`;
        g.textAlign = 'right';
        g.textBaseline = 'middle';
        for (const [f, label] of TICKS) {
            const y = ph * (1 - Math.log(f / FMIN) / Math.log(FMAX / FMIN));
            g.fillText(label, left - font * 0.5, y);
            g.globalAlpha = 0.35;
            g.fillRect(left - font * 0.3, y, font * 0.3, 1);
            g.globalAlpha = 1;
        }
    },

    /** Paint `n` identical columns at x from the current spectrum. */
    column(t, spec, sampleRate, x, n, ph, lut, p) {
        const bins = spec.length;
        const hzPerBin = sampleRate / 2 / bins;
        const img = t.createImageData(n, ph);
        const d = img.data;
        const span = 1 / (p.max - p.min);

        for (let y = 0; y < ph; y++) {
            const f = FMIN * (FMAX / FMIN) ** (1 - (y + 0.5) / ph);
            const pos = Math.min(bins - 2, f / hzPerBin);
            const i = Math.floor(pos);
            const db = spec[i] + (spec[i + 1] - spec[i]) * (pos - i) + p.tilt * Math.log2(f / 1000);
            const v = Math.max(0, Math.min(1, (db - p.min) * span));
            const c = Math.round(v * 255) * 4;
            for (let k = 0; k < n; k++) {
                const o = (y * n + k) * 4;
                d[o] = lut[c];
                d[o + 1] = lut[c + 1];
                d[o + 2] = lut[c + 2];
                d[o + 3] = 255;
            }
        }
        t.putImageData(img, x, 0);
    },
};
