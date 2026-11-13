import { W, H, Bars, begin, drawColumns, drawName, metaColumns, palette, posterText, readableOn, showText } from './poster.js';

const COLS = 16;
const ROWS = 13;
const bars = new Bars(ROWS);
const history = Array.from({ length: COLS }, () => new Float32Array(ROWS));
const st = { acc: 0 };

/**
 * A grid of triangles that scrolls like a coarse spectrogram: time runs to the left, low frequencies are at the bottom, and every cell
 * is one of three states (paper, yellow, a full yellow square). It only scrolls while the music plays.
 */
export default {
    id: 'poster-facets',
    needs: ['bands'],
    label: 'Poster: Facets',
    params: {
        min: -75,
        max: -25,
        speed: 0.14,
        text: 1,
        theme: 1,
        bg: '#f2500f',
        paper: '#dfe0d6',
        a: '#ffd21a',
        ink: '#1a1a1a',
    },

    draw(g, { bands, playing }, { w, h, colors, params, dt, track }) {
        const p = { ...this.params, ...params };
        const look = palette(p, colors, ['bg', 'paper', 'a', 'ink']);
        const s = begin(g, w, h, look.bg);

        const v = bars.update(bands, dt, { min: p.min, max: p.max, attack: 0.04, release: 0.2 });
        if (playing) {
            st.acc += dt;
            if (st.acc >= p.speed) {
                st.acc = 0;
                history.push(history.shift());
                history[COLS - 1].set(v);
            }
        }

        const ink = readableOn(look.bg, [look.ink, '#1a1a1a', '#f6efdc']);
        const txt = showText(p, s);
        const cell = W / COLS;
        const art = txt ? ROWS * cell : H;
        const rows = txt ? ROWS : Math.floor(H / cell);
        const shift = txt ? 0 : (H - rows * cell) / 2;
        for (let c = 0; c < COLS; c++) {
            for (let r = 0; r < rows; r++) {
                const value = history[c][Math.min(ROWS - 1, Math.floor((r / rows) * ROWS))];
                const x = c * cell;
                const y = shift + (rows - 1 - r) * cell;
                if (value > 0.82) {
                    g.fillStyle = look.a;
                    g.fillRect(x, y, cell, cell);
                    continue;
                }
                g.fillStyle = value < 0.4 ? look.paper : look.a;
                g.beginPath();
                g.moveTo(x, y);
                g.lineTo(x + cell, y);
                g.lineTo(x, y + cell);
                g.closePath();
                g.fill();
                if (value > 0.6) {
                    g.fillStyle = look.a;
                    g.beginPath();
                    g.moveTo(x + cell, y);
                    g.lineTo(x + cell, y + cell);
                    g.lineTo(x, y + cell);
                    g.closePath();
                    g.fill();
                }
            }
        }

        if (txt) {
            const say = posterText(track);
            drawName(g, say.name, 28, art + 84, { font: colors.font, maxW: W - 56, size: 70, color: ink });
            drawName(g, say.title, 28, art + 118, { font: colors.font, maxW: W - 56, size: 20, color: ink, weight: 500 });
            drawColumns(g, metaColumns(say), H - 40, { font: colors.font, color: ink, size: 13 });
        }
        g.restore();
    },
};
