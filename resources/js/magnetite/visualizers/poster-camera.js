import { clamp01, toDb } from './meter.js';
import { stateOf, Bars, Reference, begin, drawName, follow, mixHex, palette, posterText } from './poster.js';

const BANDS = 30;
const RIDGES = 60;
const CX = 300;
const STOPS = ['1.8', '2.8', '4', '5.6', '8', '11', '16', '22'];
const FAN = ['4', '5.6', '8', '11', '16', '22'];
const DIST = ['∞', '7', '3', '2', '1', '0,7'];

// One outline, given down the left side and mirrored for the right: the mount's plate and flange, a rounded shoulder, the rings, a step out
// under the engraving and a long curve in to a rounded end ('L' x y, 'C' x1 y1 x2 y2 x y).
const LEFT = [
    ['L', 107, 72], ['L', 100, 80], ['L', 100, 96], ['L', 94, 98], ['L', 94, 118], ['L', 70, 118],
    ['C', 34, 118, 14, 136, 14, 170], ['L', 9, 172], ['L', 9, 227], ['L', 14, 229], ['L', 14, 318], ['L', 15, 320], ['L', 15, 378],
    ['L', 26, 380], ['L', 26, 386], ['L', 19, 388], ['L', 19, 499], ['L', 26, 501], ['L', 26, 507], ['L', 24, 509], ['L', 24, 558],
    ['L', 12, 562], ['L', 8, 575], ['C', -6, 640, 2, 720, 58, 758], ['C', 96, 782, 140, 790, 190, 790],
];
// Where one ring meets the next: [y, x of the left end].
const SEAMS = [[98, 107], [116, 94], [151, 9], [227, 14], [318, 15], [378, 26], [386, 19], [440, 22], [445, 22], [499, 26], [507, 24], [558, 24], [572, 7], [580, 6]];
const mir = (x) => 600 - x;

const make = () => ({ bars: new Bars(BANDS), ref: new Reference(), pk: new Float32Array(BANDS), st: { l: 0, r: 0, lv: 0, peak: 0, avg: 0, cool: 0, on: 0 } });

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

/** The value of the 30 groups at a fractional position. */
function at(v, x) {
    const i = Math.max(0, Math.min(BANDS - 1, Math.floor(x)));
    const f = Math.max(0, Math.min(1, x - i));

    return v[i] * (1 - f) + v[Math.min(BANDS - 1, i + 1)] * f;
}

function line(g, x0, y0, x1, y1) {
    g.beginPath();
    g.moveTo(x0, y0);
    g.lineTo(x1, y1);
    g.stroke();
}

function circle(g, x, y, r) {
    g.beginPath();
    g.arc(x, y, r, 0, 7);
}

/** Text at a point; `flip` turns it upside down about its own middle, as the engraving on the lens it follows is. */
function label(g, text, x, y, { size, font, color, weight = 500, align = 'center', spacing = 0, flip = false }) {
    g.save();
    if (flip) {
        g.translate(x, y - size * 0.36);
        g.rotate(Math.PI);
    } else {
        g.translate(x, y);
    }
    g.font = `${weight} ${size}px ${font}`;
    if ('letterSpacing' in g) g.letterSpacing = `${spacing}px`;
    g.textAlign = flip ? (align === 'left' ? 'right' : align === 'right' ? 'left' : 'center') : align;
    g.textBaseline = 'alphabetic';
    g.fillStyle = color;
    g.fillText(text, 0, flip ? size * 0.36 : 0);
    g.restore();
}

/**
 * A manual camera lens drawn flat, as a black silhouette with white engraved scales. The two knurled grip rings are the spectrum, 60 ridges each, one
 * band per ridge (bass on the left), the upper ring weighted by the left channel and the lower one by the right. The aperture stops light from the open
 * end towards the closed end with the loudness, and an index follows. The depth-of-field fan lights from the middle outwards with the left and right
 * level. The focus index slides along the distance scale from infinity to the near end with the time played. One round button lights on bass onsets,
 * the other with the peak, a lamp on the mount with the loudness. The scales are engraved upside down, like the drawing it follows; the front ring
 * carries the format and the flared front the title.
 */
export default {
    id: 'poster-camera',
    needs: ['bands', 'levels'],
    label: 'Poster: Camera',
    params: {
        range: 40,
        attack: 0.1,
        release: 0.4,
        text: 1,
        theme: 1,
        paper: '#ffffff',
        ink: '#ffffff',
        dark: '#0b0b0b',
        a: '#ff3b1f',
    },

    draw(g, { bands, levels }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const { bars, ref, pk, st } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink', 'dark', 'a']);
        const view = begin(g, w, h, look.paper, { p });
        const dim = mixHex(look.dark, look.ink, 0.3);

        const v = bars.update(bands, dt, p);
        let mean = 0;
        let top = 0;
        for (let i = 0; i < BANDS; i++) {
            pk[i] = Math.max(v[i], pk[i] - dt * 0.2);
            mean += v[i] / BANDS;
            top = Math.max(top, pk[i]);
        }
        const l = toDb(levels.l.rms);
        const r = toDb(levels.r.rms);
        ref.update(Math.max(l, r), dt);
        const nl = ref.norm(l, 30);
        const nr = ref.norm(r, 30);
        st.l = follow(st.l, nl, dt, nl > st.l ? p.attack : p.release);
        st.r = follow(st.r, nr, dt, nr > st.r ? p.attack : p.release);
        st.lv = follow(st.lv, mean, dt, 0.7);
        st.peak = follow(st.peak, top, dt, 0.5);
        const bass = (bars.raw[0] + bars.raw[1] + bars.raw[2]) / 3;
        st.avg = follow(st.avg, bass, dt, 2.7);
        st.cool -= dt;
        st.on = Math.max(0, st.on - dt * 3);
        if (st.cool <= 0 && bass > st.avg * 1.3 + 0.04) {
            st.on = 1;
            st.cool = 0.2;
        }
        const lv = clamp01(st.lv * 1.5);
        const peak = clamp01(st.peak * 1.25);

        // The engraved numbers and letters are text: left out when text is off or the cell is too small to read them.
        const mark = (...args) => {
            if (view.text) label(g, ...args);
        };
        g.lineJoin = 'round';
        g.lineCap = 'round';
        g.lineWidth = 1.2;
        g.strokeStyle = look.ink;
        g.fillStyle = look.dark;

        // The barrel: one outline down the left side and mirrored on the right, with hairlines where one ring meets the next.
        g.beginPath();
        g.moveTo(107, 60);
        for (const [t, ...c] of LEFT) {
            if (t === 'L') g.lineTo(c[0], c[1]);
            else g.bezierCurveTo(c[0], c[1], c[2], c[3], c[4], c[5]);
        }
        g.lineTo(mir(190), 790);
        for (let i = LEFT.length - 1; i >= 0; i--) {
            const [t, ...c] = LEFT[i];
            const prev = i ? LEFT[i - 1] : ['L', 107, 60];
            const px = prev[0] === 'L' ? prev[1] : prev[5];
            const py = prev[0] === 'L' ? prev[2] : prev[6];
            if (t === 'L') g.lineTo(mir(px), py);
            else g.bezierCurveTo(mir(c[2]), c[3], mir(c[0]), c[1], mir(px), py);
        }
        g.closePath();
        g.fill();
        g.stroke();
        for (const [y, x] of SEAMS) line(g, x, y, mir(x), y);
        line(g, 107, 72, mir(107), 72);

        // The mount: a button and a lamp that lights with the loudness.
        circle(g, 287, 86, 9);
        g.stroke();
        circle(g, 310, 86, 7);
        g.fillStyle = lv > 0.2 ? look.a : dim;
        g.globalAlpha = 0.4 + lv * 0.6;
        g.fill();
        g.globalAlpha = 1;

        // The aperture ring: knurled end strips, the stops lit from the open end by the loudness, an index below.
        g.strokeStyle = look.ink;
        for (const [x, width] of [[12, 30], [455, 135]]) {
            const n = Math.floor(width / 3.2);
            g.globalAlpha = 0.55;
            for (let i = 0; i < n; i++) line(g, x + 2 + i * 3.2, 156, x + 2 + i * 3.2, 222);
            g.globalAlpha = 1;
        }
        const x0 = 60;
        const x1 = 366;
        const font = colors.font;
        mark('A', 402, 197, { size: 15, font, color: look.ink, weight: 600, flip: true });
        STOPS.forEach((t, i) => mark(t, x0 + (i * (x1 - x0)) / 7, 196, { size: 17, font, color: i / 7 <= lv * 1.1 ? look.ink : dim, weight: 600, flip: true }));
        const ix = x0 + clamp01(lv * 1.1) * (x1 - x0);
        g.fillStyle = look.a;
        g.beginPath();
        g.moveTo(ix, 207);
        g.lineTo(ix - 5, 217);
        g.lineTo(ix + 5, 217);
        g.closePath();
        g.fill();
        circle(g, 433, 184, 14);
        g.stroke();
        circle(g, 433, 184, 8);
        g.fillStyle = st.on > 0.2 ? look.a : dim;
        g.fill();

        // The barrel with the depth-of-field fan: left and right from the middle outwards.
        g.strokeStyle = look.ink;
        line(g, 299, 230, 299, 316);
        for (const [side, level] of [[-1, st.l], [1, st.r]]) {
            FAN.forEach((t, i) => {
                const xn = 299 + side * (28 + i * 34);
                const xl = 299 + side * (8 + i * 9);
                const on = clamp01(level * 1.15) > (i + 0.5) / 6;
                g.strokeStyle = on ? look.ink : dim;
                g.lineWidth = on ? 1.6 : 1.1;
                g.beginPath();
                g.moveTo(xn, 262);
                g.lineTo(xl, 292);
                g.lineTo(xl, 312);
                g.stroke();
                mark(t, xn, 256, { size: 13, font, color: on ? look.ink : dim, flip: true });
            });
        }
        g.strokeStyle = look.ink;
        g.lineWidth = 1.2;
        circle(g, 88, 272, 21);
        g.stroke();
        circle(g, 88, 272, 13);
        g.fillStyle = look.a;
        g.globalAlpha = 0.15 + peak * 0.85;
        g.fill();
        g.globalAlpha = 1;

        // The distance window: the focus index slides from infinity to the near end with the time played.
        line(g, 154, 322, 154, 376);
        line(g, 431, 322, 431, 376);
        const d0 = 182;
        const d1 = 398;
        const played = clamp01(progress);
        DIST.forEach((t, i) => mark(t, d0 + (i * (d1 - d0)) / 5, 356, { size: 20, font, color: Math.abs((1 - played) * 5 - i) < 0.5 ? look.ink : mixHex(look.dark, look.ink, 0.75), flip: true }));
        const fx = d1 - played * (d1 - d0);
        g.fillStyle = look.a;
        g.fillRect(fx - 1.2, 326, 2.4, 14);
        g.beginPath();
        g.moveTo(fx, 344);
        g.lineTo(fx - 5, 337);
        g.lineTo(fx + 5, 337);
        g.closePath();
        g.fill();
        mark('M', 509, 364, { size: 14, font, color: look.ink, weight: 600, flip: true });
        mark('FT', 509, 346, { size: 12, font, color: look.ink, weight: 600, flip: true });

        // The two knurled rings: 60 ridges each, one band per ridge.
        for (const [y0, y1, side] of [[386, 440, st.l], [445, 499, st.r]]) {
            g.lineWidth = 2.2;
            for (let i = 0; i < RIDGES; i++) {
                const level = clamp01(at(v, (i / (RIDGES - 1)) * (BANDS - 1)) * 1.25 * (0.55 + 0.75 * side));
                g.strokeStyle = level > 0.12 ? look.ink : dim;
                g.globalAlpha = 0.25 + 0.75 * level;
                line(g, 28 + (i * 544) / (RIDGES - 1), y0 + 5, 28 + (i * 544) / (RIDGES - 1), y1 - 5);
            }
            g.globalAlpha = 1;
        }

        if (view.text) {
            const say = posterText(track);
            const length = track?.duration ?? 0;
            label(g, say.fmt.toUpperCase(), CX, 544, { size: 26, font, color: look.ink, weight: 600, spacing: 2 });
            drawName(g, say.title || say.name, CX, 650, { font, maxW: 440, size: 40, color: look.ink, weight: 600, caps: true, spacing: 2, align: 'center' });
            const by = [say.title ? say.name : '', say.album, say.year].filter(Boolean).join('  ·  ').toUpperCase();
            drawName(g, by, CX, 680, { font, maxW: 440, size: 15, color: look.ink, weight: 500, spacing: 2, align: 'center' });
            label(g, [length ? `${mmss(progress * length)} / ${mmss(length)}` : '', say.dr, say.no].filter(Boolean).join('   ·   '), CX, 716, { size: 13, font, color: look.ink, spacing: 2 });
        }
        g.restore();
    },
};
