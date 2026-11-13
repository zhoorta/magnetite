import { loadFont } from '../font-load.js';
import { clamp01, toDb } from './meter.js';
import { W, stateOf, Bars, Reference, begin, follow, palette, posterText } from './poster.js';

const STRAPS = 7;
const ROWS = 8;

/** The cover's rounded lowercase lettering is part of the design, so it is the poster's own; the small labels use the theme's meter font. */
const DISPLAY = "'Righteous'";

// The schematic, in the poster's 600 x 800 space.
const strapX = (i) => 46 + i * 38;
const rowY = (r) => 412 + r * 30;
const STRAP_TOP = 394;
const STRAP_BOT = 640;
const AMP_X = 312;
const GATE_X = 430;
const GATE_W = 24;
const GATE_H = 20;
const busX = (k) => 350 + k * 14;
const countX = (k) => 366 + k * 34;
const DOME_Y = 670;
const DOME_R = 13;
const railY = (j) => 694 + j * 12;
const WIDE_X = 536;
const WIDE_W = 52;
const WIDE_H = 64;
const WIDE_Y = [462, 582];

const zeros = (n) => new Float32Array(n);
const make = () => ({
    bars: new Bars(STRAPS),
    ref: new Reference(),
    pk: zeros(STRAPS),
    slow: zeros(STRAPS),
    on: zeros(STRAPS),
    count: zeros(4),
    st: { step: 0, strobe: 0, cool: 0, l: 0, r: 0, key: '', size: 0, lines: [] },
});

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

function line(g, ...pts) {
    g.beginPath();
    g.moveTo(pts[0], pts[1]);
    for (let i = 2; i < pts.length; i += 2) g.lineTo(pts[i], pts[i + 1]);
    g.stroke();
}

function dot(g, x, y) {
    g.beginPath();
    g.arc(x, y, 2.4, 0, 7);
    g.fill();
}

/** Fills the current path as far as it is lit (0..1), then outlines it. */
function lit(g, amount) {
    if (amount > 0.01) {
        g.globalAlpha = clamp01(amount);
        g.fill();
        g.globalAlpha = 1;
    }
    g.stroke();
}

/** A gate: flat on the left, round on the right. */
function gatePath(g, x, y, w, h) {
    g.beginPath();
    g.moveTo(x, y - h / 2);
    g.lineTo(x + w - h / 2, y - h / 2);
    g.arc(x + w - h / 2, y, h / 2, -Math.PI / 2, Math.PI / 2);
    g.lineTo(x, y + h / 2);
    g.closePath();
}

/** A dome with three legs that end on the three rails. */
function dome(g, cx, amount, paper, ink) {
    g.beginPath();
    g.arc(cx, DOME_Y, DOME_R, Math.PI, 0);
    g.closePath();
    lit(g, amount);
    for (let j = 0; j < 3; j++) {
        const x = cx - 7 + j * 7;
        g.beginPath();
        g.arc(x, DOME_Y + 3, 2.6, 0, 7);
        g.fillStyle = paper;
        g.fill();
        g.fillStyle = ink;
        g.stroke();
        line(g, x, DOME_Y + 5.6, x, railY(j));
        dot(g, x, railY(j));
    }
}

/** The largest size at which the title fits in two lines (three when it has to be small). */
function wrap(g, text, maxW, font) {
    const words = text.split(/\s+/);
    for (let size = 62; size >= 22; size -= 2) {
        g.font = `${size}px ${font}`;
        const lines = [];
        let cur = '';
        for (const word of words) {
            const next = cur ? `${cur} ${word}` : word;
            if (!cur || g.measureText(next).width <= maxW) {
                cur = next;
            } else {
                lines.push(cur);
                cur = word;
            }
        }
        lines.push(cur);
        if (lines.length <= (size > 44 ? 2 : 3) && lines.every((ln) => g.measureText(ln).width <= maxW)) return { size, lines };
    }

    return { size: 22, lines: [text] };
}

/** Small text in the middle of a box, shrunk to fit. */
function boxed(g, text, cx, y, maxW, font) {
    let size = 13;
    g.font = `700 ${size}px ${font}`;
    while (size > 8 && g.measureText(text).width > maxW) {
        size -= 1;
        g.font = `700 ${size}px ${font}`;
    }
    g.fillText(text, cx, y);
}

/**
 * A textbook cover: the track in rounded lowercase type, flush right, over a one-colour logic schematic that is the meter. Seven straps crossed by
 * eight word lines are seven frequency ranges, filling cell by cell (a tick in the ink colour holds the peak). The amplifier on a word line lights
 * when any range reaches that row, the gate behind it when two do. The two wide gates fill with the left and the right level. The dome under a
 * strap flashes on that range's onsets, the four domes on the right count the strobe (onsets of the lowest range, which also thicken the middle
 * rail). The boxes at the bottom hold the time played, the length and the format.
 */
export default {
    id: 'poster-circuit',
    needs: ['bands', 'levels'],
    label: 'Poster: Circuit',
    params: {
        range: 40,
        attack: 0.06,
        release: 0.4,
        text: 1,
        theme: 1,
        paper: '#14161b',
        ink: '#ece4cf',
        a: '#e23a2c',
    },

    draw(g, { bands, levels }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const { bars, ref, pk, slow, on, count, st } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink', 'a']);
        const view = begin(g, w, h, look.paper, { p });

        // The measurements: the ranges, their held peaks and onsets, the strobe and its count of four, the two channels.
        const v = bars.update(bands, dt, p);
        for (let i = 0; i < STRAPS; i++) {
            pk[i] = Math.max(v[i], pk[i] - dt * 0.22);
            slow[i] = follow(slow[i], bars.raw[i], dt, 1.2);
            on[i] = Math.max(clamp01((bars.raw[i] - slow[i] - 0.05) * 6), on[i] * Math.exp(-dt / 0.14));
        }
        st.cool -= dt;
        if (on[0] > 0.6 && st.cool <= 0) {
            st.step = (st.step + 1) % 4;
            st.strobe = 1;
            st.cool = 0.28;
        }
        st.strobe *= Math.exp(-dt / 0.2);
        for (let j = 0; j < 4; j++) count[j] = j === st.step ? Math.max(count[j], st.strobe) : count[j] * Math.exp(-dt / 0.25);
        const l = toDb(levels.l.rms);
        const r = toDb(levels.r.rms);
        ref.update(Math.max(l, r), dt);
        st.l = follow(st.l, ref.norm(l, 30), dt, l > st.l ? p.attack : p.release);
        st.r = follow(st.r, ref.norm(r, 30), dt, r > st.r ? p.attack : p.release);

        /** How far range `i` lights row `row` (0 is the top row). */
        const cell = (i, row) => clamp01(v[i] * ROWS - (ROWS - 1 - row));

        g.strokeStyle = look.a;
        g.fillStyle = look.a;
        const lw = Math.max(1.5, 0.6 / view.scale); // lines stay visible in a small cell (the menu thumbnail)
        g.lineWidth = lw;
        g.lineJoin = 'round';
        g.lineCap = 'round';
        g.textBaseline = 'alphabetic';

        // The bracket over the straps (its gap holds a label, as wide as the label is in the theme's font), and the word lines.
        const label = '40 Hz – 16 kHz';
        g.font = `italic 500 11px ${colors.font}`;
        const gap = view.text ? Math.min(120, g.measureText(label).width / 2 + 8) : 0;
        line(g, 22, 372, 22, 366, 156 - gap, 366);
        line(g, 156 + gap, 366, 290, 366, 290, 372);
        for (let row = 0; row < ROWS; row++) line(g, 0, rowY(row), AMP_X, rowY(row));

        // The straps: lit cells, the held peak, the hook to a ground above and the dome below.
        for (let i = 0; i < STRAPS; i++) {
            const x = strapX(i);
            for (let row = 0; row < ROWS; row++) {
                const a = cell(i, row);
                if (a > 0.01) {
                    g.globalAlpha = a;
                    g.fillRect(x - 4, rowY(row) - 14, 8, 28);
                    g.globalAlpha = 1;
                }
            }
            g.strokeRect(x - 4, STRAP_TOP, 8, STRAP_BOT - STRAP_TOP);
            g.fillStyle = look.ink;
            g.fillRect(x - 4, STRAP_BOT - 6 - pk[i] * (STRAP_BOT - STRAP_TOP - 8), 8, 4);
            g.fillStyle = look.a;
            line(g, x, STRAP_TOP, x, 382, x - 14, 382, x - 14, 387);
            line(g, x - 20, 387, x - 8, 387);
            line(g, x - 18, 390, x - 10, 390);
            line(g, x - 16, 393, x - 12, 393);
            line(g, x, STRAP_BOT, x, DOME_Y - DOME_R);
            dome(g, x, on[i], look.paper, look.a);
        }

        // A row's amplifier (any range reaches it) and gate (two ranges do), and the gate's stepped wire to a wide gate.
        for (let row = 0; row < ROWS; row++) {
            let any = 0;
            let two = 0;
            for (let i = 0; i < STRAPS; i++) {
                const a = cell(i, row);
                if (a > any) {
                    two = any;
                    any = a;
                } else if (a > two) {
                    two = a;
                }
            }
            const y = rowY(row);
            const gy = y + 5;
            g.beginPath();
            g.moveTo(AMP_X, y - 10);
            g.lineTo(AMP_X + 20, y);
            g.lineTo(AMP_X, y + 10);
            g.closePath();
            lit(g, any);
            line(g, AMP_X + 20, y, GATE_X, y);
            const bx = busX(row % 4);
            line(g, bx, gy + 5, GATE_X, gy + 5);
            dot(g, bx, gy + 5);
            gatePath(g, GATE_X, gy, GATE_W, GATE_H);
            lit(g, two);
            const j = row % 4;
            const iy = WIDE_Y[row < 4 ? 0 : 1] - 24 + j * 16;
            const vx = 478 + j * 10;
            line(g, GATE_X + GATE_W, gy, vx, gy, vx, iy, WIDE_X, iy);
        }

        // The four buses down to the domes that count the strobe.
        for (let k = 0; k < 4; k++) {
            const jog = 655 - k * 5;
            line(g, busX(k), rowY(k) + 10, busX(k), jog, countX(k), jog, countX(k), DOME_Y - DOME_R);
            dome(g, countX(k), count[k], look.paper, look.a);
        }

        // The wide gates: left and right level.
        [st.l, st.r].forEach((level, n) => {
            const y = WIDE_Y[n];
            g.save();
            gatePath(g, WIDE_X, y, WIDE_W, WIDE_H);
            g.clip();
            g.fillRect(WIDE_X, y - WIDE_H / 2, WIDE_W * clamp01(level), WIDE_H);
            g.restore();
            gatePath(g, WIDE_X, y, WIDE_W, WIDE_H);
            g.stroke();
            line(g, WIDE_X + WIDE_W, y, W, y);
        });

        // The rails; the middle one is the strobe.
        line(g, 0, railY(0), 500, railY(0));
        g.lineWidth = lw + 2.5 * st.strobe;
        line(g, 0, railY(1), view.text ? 530 : W, railY(1));
        g.lineWidth = lw;
        line(g, 0, railY(2), 500, railY(2));

        // The register boxes on a dashed bus: time played, length, codec, bit depth and rate, DR (five empty boxes without text).
        const say = posterText(track);
        const font = colors.font;
        const length = track?.duration ?? 0;
        const [codec, ...rest] = say.fmt.split(' ');
        const cells = view.text
            ? [length ? mmss(progress * length) : '', say.dur, codec ?? '', rest.join(' ').replace(/^[·\s]+/, ''), say.dr].filter(Boolean)
            : ['', '', '', '', ''];
        const bw = 58;
        const step = bw + 8;
        const x0 = 140;
        if (cells.length) {
            g.setLineDash([5, 4]);
            line(g, x0 - 12, railY(2), x0 - 12, 738, x0 + (cells.length - 1) * step + bw / 2, 738);
            cells.forEach((_, n) => line(g, x0 + n * step + bw / 2, 738, x0 + n * step + bw / 2, 752));
            g.setLineDash([]);
            dot(g, x0 - 12, railY(2));
            g.textAlign = 'center';
            cells.forEach((text, n) => {
                g.strokeRect(x0 + n * step, 752, bw, 30);
                if (text) boxed(g, text, x0 + n * step + bw / 2, 772, bw - 8, font);
            });
        }

        if (view.text) {
            loadFont(DISPLAY);
            const display = `${DISPLAY}, ${font}`;

            // Small labels, the way a schematic names its parts.
            g.font = `italic 500 11px ${font}`;
            g.textAlign = 'center';
            g.fillText(label, 156, 370);
            g.textAlign = 'left';
            g.fillText('Strobe', 536, railY(1) + 4);
            g.fillText('L', WIDE_X + 2, WIDE_Y[0] - WIDE_H / 2 - 6);
            g.fillText('R', WIDE_X + 2, WIDE_Y[1] - WIDE_H / 2 - 6);

            // The cover type, flush right: album and year, the title, the artist.
            const right = 562;
            const maxW = 524;
            g.textAlign = 'right';
            g.font = `500 13px ${font}`;
            if ('letterSpacing' in g) g.letterSpacing = '1px';
            g.fillText([say.album, say.year].filter(Boolean).join('  ·  ').toUpperCase(), right, 62);
            if ('letterSpacing' in g) g.letterSpacing = '0px';

            g.fillStyle = look.ink;
            const title = (say.title || say.name).toLowerCase();
            const ready = typeof document === 'undefined' || !document.fonts?.check || document.fonts.check(`40px ${DISPLAY}`);
            const key = `${title}\n${display}\n${ready}`;
            if (st.key !== key) Object.assign(st, wrap(g, title, maxW, display), { key });
            g.font = `${st.size}px ${display}`;
            st.lines.forEach((ln, n) => g.fillText(ln, right, 76 + st.size * 0.86 + n * st.size * 0.98));
            if (say.title) {
                const artist = say.name.toLowerCase();
                let size = 46;
                g.font = `${size}px ${display}`;
                while (size > 18 && g.measureText(artist).width > maxW) {
                    size -= 1;
                    g.font = `${size}px ${display}`;
                }
                g.fillText(artist, right, 318);
            }
            g.textAlign = 'left';
        }
        g.restore();
    },
};
