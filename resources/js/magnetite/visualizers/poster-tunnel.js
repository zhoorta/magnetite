import { W, stateOf, Bars, begin, palette, posterText } from './poster.js';

const RINGS = 7;
const SPECTRUM = 22;

const make = () => ({ rings: new Bars(RINGS), small: new Bars(SPECTRUM), st: { lvl: 0, spin: 0 } });

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

/** Outline lettering (a stroke and no fill), shrunk to fit `maxW`. */
function outline(g, text, x, y, size, maxW, font) {
    if (!text) return;
    g.font = `800 ${size}px ${font}`;
    while (size > 9 && g.measureText(text).width > maxW) {
        size -= 1;
        g.font = `800 ${size}px ${font}`;
    }
    g.lineWidth = Math.max(1.4, size / 13);
    g.textAlign = 'left';
    g.textBaseline = 'alphabetic';
    g.strokeText(text, x, y);
}

/** Small plain text in the line colour, cut to fit. */
function plain(g, text, x, y, size, maxW, font) {
    if (!text) return;
    g.font = `700 ${size}px ${font}`;
    while (size > 8 && g.measureText(text).width > maxW) {
        size -= 1;
        g.font = `700 ${size}px ${font}`;
    }
    g.textAlign = 'left';
    g.textBaseline = 'alphabetic';
    g.fillText(text, x, y);
}

/**
 * One colour of line on a flat ground, like an exhibition poster: ruled boxes across the top and seven circles that hang from one shared top
 * edge. The gap between two neighbouring circles is a frequency range and widens with its energy, so the tunnel breathes; the innermost never
 * closes. Boxes hold the track's text, a small spectrum, a globe that turns faster with the level, and the time played.
 */
export default {
    id: 'poster-tunnel',
    needs: ['bands'],
    label: 'Poster: Tunnel',
    params: {
        range: 40,
        attack: 0.08,
        release: 0.5,
        text: 1,
        theme: 1,
        paper: '#0f0d0d',
        a: '#ff4a35',
    },

    draw(g, { bands }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const { rings, small, st } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'a']);
        const view = begin(g, w, h, look.paper, { p });

        const e = rings.update(bands, dt, p);
        const s = small.update(bands, dt, p);
        st.lvl = e.reduce((sum, v) => sum + v, 0) / RINGS;
        st.spin += dt * (0.25 + 2.2 * st.lvl);

        g.strokeStyle = look.a;
        g.fillStyle = look.a;
        g.lineJoin = 'round';
        g.lineCap = 'round';

        // The tunnel: the gaps are the energy of seven band groups (outer gaps are wider by nature), the innermost circle keeps a fixed share.
        const top = 236;
        const maxD = 566;
        const first = 0.2;
        const weight = Array.from(e, (v, j) => (0.55 + 1.1 * Math.min(1, v * 1.5)) * (1 + j * 0.28));
        const total = weight.reduce((sum, v) => sum + v, 0);
        let acc = 0;
        for (let j = 0; j < RINGS; j++) {
            acc += weight[j];
            const d = maxD * (first + (1 - first) * (acc / total));
            g.lineWidth = 2 + 2.4 * Math.min(1, e[j] * 1.4);
            g.beginPath();
            g.arc(W / 2, top + d / 2, d / 2, 0, 7);
            g.stroke();
        }

        // The boxes.
        const m = 10;
        g.lineWidth = 2;
        [[m, m, 288, 78], [298, m, 146, 78], [454, m, 136, 146], [m, 98, 434, 34], [m, 140, 434, 26], [298, 140, 146, 26], [454, 166, 136, 70], [454, 244, 136, 30], [508, 756, 82, 34]]
            .forEach(([x, y, bw, bh]) => g.strokeRect(x, y, bw, bh));

        // Spectrum: bars standing on a middle line, taller to the right.
        const mid = 83;
        for (let i = 0; i < SPECTRUM; i++) {
            const bh = 6 + s[i] * 120 * (0.35 + 0.65 * (i / SPECTRUM));
            const x = 466 + (i / (SPECTRUM - 1)) * 112;
            g.beginPath();
            g.moveTo(x, mid - bh / 2);
            g.lineTo(x, mid + bh / 2);
            g.stroke();
        }

        // Globe: rim, three latitudes and four meridians that turn.
        const gx = 490;
        const gy = 201;
        const gr = 24;
        g.lineWidth = 1.6;
        g.beginPath();
        g.arc(gx, gy, gr, 0, 7);
        g.stroke();
        for (let i = 0; i < 4; i++) {
            g.beginPath();
            g.ellipse(gx, gy, Math.max(0.5, Math.abs(Math.cos(st.spin + (i * Math.PI) / 4)) * gr), gr, 0, 0, 7);
            g.stroke();
        }
        [-0.5, 0, 0.5].forEach((f) => {
            const half = Math.sqrt(1 - f * f) * gr;
            g.beginPath();
            g.moveTo(gx - half, gy + f * gr);
            g.lineTo(gx + half, gy + f * gr);
            g.stroke();
        });

        if (view.text) {
            const say = posterText(track);
            const font = colors.font;
            const [codec, ...rest] = say.fmt.split(' ');
            const words = say.name.toUpperCase().split(' ');
            if (words.length > 1) {
                outline(g, words[0], 20, 44, 30, 266, font);
                outline(g, words.slice(1).join(' '), 20, 78, 30, 266, font);
            } else {
                outline(g, words[0], 20, 60, 40, 266, font);
            }
            outline(g, codec ?? '', 308, 44, 28, 126, font);
            outline(g, rest.join(' ').replace(/^[·\s]+/, ''), 308, 76, 28, 126, font);
            outline(g, (say.title || say.album).toUpperCase(), 20, 123, 24, 414, font);
            plain(g, say.title ? say.album.toUpperCase() : '', 20, 158, 15, 270, font);
            plain(g, say.dr || say.year, 308, 159, 16, 126, font);
            const length = track?.duration ?? 0;
            if (length) plain(g, `${mmss(progress * length)} / ${mmss(length)}`, 464, 264, 15, 118, font);
            plain(g, say.year, 520, 779, 16, 60, font);
        }
        g.restore();
    },
};
