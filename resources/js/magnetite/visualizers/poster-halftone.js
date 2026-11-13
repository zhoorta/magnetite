import { W, H, Bars, begin, drawColumns, drawName, follow, level01, palette, posterText, showText } from './poster.js';

const COLS = 16;
const ROWS = 13;
const bars = new Bars(ROWS);
const history = Array.from({ length: COLS }, () => new Float32Array(ROWS));
const st = { acc: 0, l: 0, r: 0 };
const GRID = 34;
const BLOCK_ROWS = 6;

/**
 * A halftone print: a grid of dots, light and dark blocks side by side, where each dot's size is a spectrogram cell (time to the left, low
 * frequencies at the bottom; it scrolls only while the music plays). Two flat blocks sit on top, one per channel, and grow with its level.
 */
export default {
    id: 'poster-halftone',
    needs: ['bands', 'levels'],
    label: 'Poster: Halftone',
    params: {
        min: -75,
        max: -25,
        speed: 0.14,
        text: 1,
        theme: 1,
        paper: '#f1ead8',
        ink: '#0e0e0e',
        a: '#f2512b',
        b: '#18b84a',
    },

    draw(g, { bands, levels, playing }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const look = palette(p, colors, ['paper', 'ink', 'a', 'b']);
        const s = begin(g, w, h, look.paper);

        const v = bars.update(bands, dt, { min: p.min, max: p.max, attack: 0.04, release: 0.2 });
        if (playing) {
            st.acc += dt;
            if (st.acc >= p.speed) {
                st.acc = 0;
                history.push(history.shift());
                history[COLS - 1].set(v);
            }
        }
        st.l = follow(st.l, level01(levels.l.rms, -50, -6), dt, 0.25);
        st.r = follow(st.r, level01(levels.r.rms, -50, -6), dt, 0.25);

        let top = 0;
        if (showText(p, s)) {
            const say = posterText(track);
            drawName(g, say.name, 28, 70, { font: colors.font, maxW: W - 56, size: 52, color: look.ink, weight: 800 });
            drawName(g, say.title, 28, 106, { font: colors.font, maxW: W - 56, size: 22, color: look.a, weight: 500 });
            drawColumns(g, [[[say.album, say.year].filter(Boolean).join(' · ')]], 134, { font: colors.font, color: look.ink, size: 14, xs: [28] });
            top = 156;
        }

        const height = H - top;
        const cell = W / GRID;
        const rows = Math.floor(height / cell);
        const half = GRID / 2;
        const dark = (c, r) => (Math.floor(c / half) + Math.floor(r / BLOCK_ROWS)) % 2 === 1;

        for (let r = 0; r < rows; r += BLOCK_ROWS) {
            for (let c = 0; c < GRID; c += half) {
                g.fillStyle = dark(c, r) ? look.ink : look.paper;
                g.fillRect(c * cell, top + r * cell, half * cell, Math.min(BLOCK_ROWS, rows - r) * cell);
            }
        }
        for (const onDark of [false, true]) {
            g.fillStyle = onDark ? look.paper : look.ink;
            g.beginPath();
            for (let r = 0; r < rows; r++) {
                for (let c = 0; c < GRID; c++) {
                    if (dark(c, r) !== onDark) continue;
                    const value = history[Math.floor((c / GRID) * COLS)][Math.floor(((rows - 1 - r) / rows) * ROWS)];
                    const radius = cell * 0.5 * (0.12 + 0.88 * value);
                    const x = c * cell + cell / 2;
                    const y = top + r * cell + cell / 2;
                    g.moveTo(x + radius, y);
                    g.arc(x, y, radius, 0, 7);
                }
            }
            g.fill();
        }

        const hl = height * (0.12 + 0.14 * st.l);
        const hr = height * (0.12 + 0.14 * st.r);
        g.fillStyle = look.a;
        g.fillRect(W * 0.25, top + height * 0.22 - hl / 2, W * 0.5, hl);
        g.fillStyle = look.b;
        g.fillRect(W * 0.25, top + height * 0.7 - hr / 2, W * 0.5, hr);
        g.restore();
    },
};
