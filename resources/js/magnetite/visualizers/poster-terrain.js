import { clamp01, toDb } from './meter.js';
import { W, H, stateOf, Bars, Reference, begin, follow, isDark, palette, posterText, readableOn } from './poster.js';

const COLS = 8;
const ROWS = 6;
const STRIP = 31;
const SHADES = 8;

// The city's ground: tile (c, r) in the poster's 600 x 800 space; c runs right and down, r left and down, h lifts a point.
const OX = 172;
const OY = 322;
const TX = 20;
const TY = 10;
const TALL = 150;
const at = (c, r, h = 0) => [OX + (c - r) * TX, OY + (c + r) * TY - h];

const RANGES = ['LOW', 'LOW MID', 'HIGH MID', 'HIGH'];

const make = () => ({
    bars: new Bars(COLS),
    strip: new Bars(STRIP),
    ref: new Reference(),
    city: Array.from({ length: ROWS }, () => new Float32Array(COLS)),
    st: { t: 0, level: 0, l: 0, r: 0, corr: 0, clip: 0, m: 0, s: 0, i: 0, tp: 0, shadeKey: '', shades: [], layer: null, lg: null },
});

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
const num = (v, digits = 1) => (Number.isFinite(v) && v > -90 ? v.toFixed(digits) : '–');

function rgb(hex) {
    const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex).trim());
    if (!m) return null;
    const h = m[1].length === 3 ? [...m[1]].map((c) => c + c).join('') : m[1];

    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
}

/** `SHADES + 1` colours from `a` to `b` (just the two ends when one of them is not a hex colour). */
function ramp(a, b) {
    const x = rgb(a);
    const y = rgb(b);

    return Array.from({ length: SHADES + 1 }, (_, i) => {
        const t = i / SHADES;
        if (!x || !y) return t < 0.5 ? a : b;

        return `rgb(${x.map((v, k) => Math.round(v + (y[k] - v) * t)).join(',')})`;
    });
}

function line(g, ...pts) {
    g.beginPath();
    g.moveTo(pts[0], pts[1]);
    for (let i = 2; i < pts.length; i += 2) g.lineTo(pts[i], pts[i + 1]);
    g.stroke();
}

function poly(g, pts) {
    g.beginPath();
    pts.forEach(([x, y], i) => (i ? g.lineTo(x, y) : g.moveTo(x, y)));
    g.closePath();
}

function label(g, text, x, y, size, font, align = 'left', weight = 500) {
    g.font = `${weight} ${size}px ${font}`;
    g.textAlign = align;
    g.fillText(text, x, y);
}

/** Sets the font at the largest size from `size` down at which `text` fits `maxW`, and returns that size. */
function fit(g, text, maxW, size, weight, font, style = '') {
    g.font = `${style} ${weight} ${size}px ${font}`;
    while (size > 8 && g.measureText(text).width > maxW) {
        size -= 1;
        g.font = `${style} ${weight} ${size}px ${font}`;
    }

    return size;
}

/** The whole screen, in the poster's space. Drawn once on the canvas and, when it glows, once more on the small layer that becomes the halo. */
function shapes(g, d) {
    const { look, st, city, b, strip, shades, text, font, say, track, progress, sample, lw } = d;
    const shade = (v) => shades[Math.round(clamp01(v) * SHADES)];
    g.lineWidth = lw;
    g.lineJoin = 'round';
    g.lineCap = 'round';
    g.textBaseline = 'alphabetic';
    g.strokeStyle = look.a;
    g.fillStyle = look.a;
    const length = track?.duration ?? 0;

    // Header boxes: the artist.
    g.strokeRect(22, 22, 150, 24);
    g.strokeRect(172, 22, 180, 24);
    if (text) {
        label(g, 'NOW PLAYING:', 32, 38, 10, font);
        fit(g, say.name.toUpperCase(), 164, 10, 500, font);
        g.fillText(say.name.toUpperCase(), 180, 38);
    }

    // Spectrum strip: one outlined bar per third-octave band, the tall ones in the hot colour.
    g.strokeRect(22, 52, 330, 58);
    for (let i = 0; i < STRIP; i++) {
        const bh = 3 + strip[i] * 48;
        g.strokeStyle = shade(strip[i] * 1.3 - 0.3);
        g.strokeRect(28 + i * 10.4, 106 - bh, 6.4, bh);
    }
    g.strokeStyle = look.a;

    // Ruler: the track's length; the pointer is the time played.
    line(g, 22, 134, 22, 140, 352, 140, 352, 134);
    for (let i = 1; i < 30; i++) line(g, 22 + i * 11, 140, 22 + i * 11, i % 5 ? 137 : 134);
    if (text && length) for (let i = 0; i <= 6; i++) label(g, mmss((i / 6) * length), 22 + i * 55, 128, 8, font, i === 0 ? 'left' : i === 6 ? 'right' : 'center');
    const px = 22 + clamp01(progress) * 330;
    g.strokeStyle = look.b;
    poly(g, [[px, 146], [px - 7, 154], [px + 7, 154]]);
    g.stroke();
    g.strokeStyle = look.a;

    // Four small squares: left level, right level, clip lamp, stereo width.
    [[366, 22], [397, 22], [366, 53], [397, 53]].forEach(([x, y]) => g.strokeRect(x, y, 25, 25));
    [st.l, st.r].forEach((v, i) => {
        g.fillStyle = shade(v * 1.4 - 0.4);
        g.fillRect(374 + i * 31, 43 - v * 17, 9, v * 17);
    });
    g.fillStyle = look.a;
    g.beginPath();
    g.arc(378.5, 65.5, 6, 0, 7);
    g.stroke();
    if (st.clip > 0.02) {
        g.globalAlpha = Math.min(1, st.clip);
        g.fillStyle = look.b;
        g.fill();
        g.fillStyle = look.a;
        g.globalAlpha = 1;
    }
    const apart = 1 + 2 * (1 - st.corr);
    [-apart, apart].forEach((dx) => {
        g.beginPath();
        g.arc(409.5 + dx, 65.5, 5, 0, 7);
        g.stroke();
    });

    // Stereo field: a small goniometer (mid up, side across).
    g.strokeRect(432, 22, 146, 24);
    g.strokeRect(432, 46, 146, 78);
    if (text) label(g, 'STEREO FIELD', 505, 38, 10, font, 'center');
    g.save();
    g.beginPath();
    g.rect(433, 47, 144, 76);
    g.clip();
    g.globalAlpha = 0.5;
    line(g, 471, 51, 539, 119);
    line(g, 539, 51, 471, 119);
    g.globalAlpha = 1;
    const pts = sample.stereo.gonio;
    g.strokeStyle = look.b;
    g.beginPath();
    for (let i = 0; i + 1 < pts.length; i += 2) {
        const x = 505 + ((pts[i + 1] - pts[i]) / Math.SQRT2) * 46;
        const y = 85 - ((pts[i] + pts[i + 1]) / Math.SQRT2) * 46;
        i === 0 ? g.moveTo(x, y) : g.lineTo(x, y);
    }
    g.stroke();
    g.restore();
    g.strokeStyle = look.a;

    // The file's lines under it.
    if (text) {
        [['FORMAT:', say.fmt], ['DYNAMIC RANGE:', say.dr], ['TRACK:', say.no ? `#${say.no}` : '']].forEach(([key, value], i) => {
            label(g, key, 432, 142 + i * 14, 9, font);
            fit(g, value || '–', i === 0 ? 96 : 60, 9, 500, font);
            g.textAlign = 'right';
            g.fillText(value || '–', 578, 142 + i * 14);
        });
    }

    // The city: the ground grid, then the towers from the back to the front, each hiding what stands behind it.
    for (let c = 0; c <= COLS; c++) line(g, ...at(c, 0), ...at(c, ROWS));
    for (let r = 0; r <= ROWS; r++) line(g, ...at(0, r), ...at(COLS, r));
    g.fillStyle = look.paper;
    for (let depth = 0; depth <= COLS + ROWS - 2; depth++) {
        for (let r = 0; r < ROWS; r++) {
            const c = depth - r;
            if (c < 0 || c >= COLS) continue;
            const v = city[r][c];
            const h = v * TALL;
            const seed = Math.abs(Math.sin(c * 12.9898 + r * 78.233) * 43758.5453) % 1; // each plot has its own width
            const m = 0.12 + 0.14 * seed;
            g.strokeStyle = shade(v * 1.5 - 0.25);
            if (h < 2) {
                poly(g, [at(c + m, r + m), at(c + 1 - m, r + m), at(c + 1 - m, r + 1 - m), at(c + m, r + 1 - m)]);
                g.stroke();
                continue;
            }
            const f0 = at(c + m, r + 1 - m);
            const e0 = at(c + 1 - m, r + 1 - m);
            const b0 = at(c + 1 - m, r + m);
            const f1 = at(c + m, r + 1 - m, h);
            const e1 = at(c + 1 - m, r + 1 - m, h);
            const b1 = at(c + 1 - m, r + m, h);
            [[f0, e0, e1, f1], [e0, b0, b1, e1], [at(c + m, r + m, h), b1, e1, f1]].forEach((face) => {
                poly(g, face);
                g.fill();
                g.stroke();
            });
            if (seed > 0.6 && h > 30) {
                for (let k = 1; k < 4; k++) {
                    const x = f0[0] + ((e0[0] - f0[0]) * k) / 4;
                    const y = f0[1] + ((e0[1] - f0[1]) * k) / 4;
                    line(g, x, y, x, y - h);
                }
            }
        }
    }
    g.strokeStyle = look.a;
    g.fillStyle = look.a;
    line(g, 30, 196, 62, 196);
    line(g, 46, 180, 46, 212);
    line(g, 36, 206, 56, 186);
    if (text) {
        const album = say.album.toUpperCase().replace(/[^A-Z0-9]+/g, '_').slice(0, 20);
        label(g, [say.no, album].filter(Boolean).join('_'), 372, 178, 8, font, 'right');
        label(g, 'READING_SPECTRUM', 372, 190, 8, font, 'right');
        label(g, 'ROWS/NOW_TO_SLOW', 22, 506, 8, font);
        label(g, 'COLS/40HZ_16KHZ', 40, 518, 8, font);
        label(g, `LEVEL/${st.level.toFixed(2)}`, 58, 530, 8, font);
    }

    // The list: four ranges, high at the top, each with a wave (faster for a higher range, taller when louder) and a level line.
    line(g, 472, 196, 482, 182, 578, 182, 578, 196);
    for (let k = 0; k < 3; k++) {
        for (let j = 0; j < 3; j++) {
            const x = 500 + k * 26 + j * 5;
            line(g, x + 4, 185, x, 189, x + 4, 193);
        }
    }
    g.strokeRect(392, 196, 186, 208);
    RANGES.forEach((name, i) => {
        const v = (b[i * 2] + b[i * 2 + 1]) / 2;
        const y = 204 + (RANGES.length - 1 - i) * 49; // high at the top
        g.strokeRect(400, y, 36, 42);
        g.strokeRect(442, y, 128, 42);
        g.beginPath();
        for (let n = 0; n <= 28; n++) {
            const yy = y + 21 - Math.sin((n / 28) * 2 * Math.PI * (1 + i * 1.5)) * (3 + 11 * v);
            n ? g.lineTo(404 + n, yy) : g.moveTo(404, yy);
        }
        g.stroke();
        line(g, 442, y + 21, 450 + v * 50, y + 21);
        if (text) label(g, name, 562, y + 25, 10, font, 'right');
    });

    // The logo: artist in spaced capitals, the title, the year's last two digits.
    if (text) {
        g.fillStyle = look.b;
        const title = (say.title || say.name).toUpperCase();
        const year = say.year.slice(-2);
        g.font = `italic 900 40px ${font}`;
        const yw = year ? g.measureText(year).width + 8 : 0;
        const size = fit(g, title, 330 - yw, 34, 900, font);
        g.textAlign = 'right';
        g.fillText(title, 578 - yw, 556);
        const tw = g.measureText(title).width;
        if (year) {
            g.font = `italic 900 40px ${font}`;
            g.fillText(year, 578, 556);
        }
        if (say.title) {
            if ('letterSpacing' in g) g.letterSpacing = '4px';
            fit(g, say.name.toUpperCase(), 330, 12, 700, font);
            g.textAlign = 'left';
            g.fillText(say.name.toUpperCase(), Math.max(248, 578 - yw - tw), 552 - size);
            if ('letterSpacing' in g) g.letterSpacing = '0px';
        }
        g.fillStyle = look.a;
    }

    g.strokeRect(22, 574, 556, 6);

    // Folders: momentary, short-term and integrated loudness, true peak; each fills with its value.
    [['M', st.m, sample.lufs.m], ['S', st.s, sample.lufs.s], ['I', st.i, sample.lufs.i], ['P', st.tp, d.tpDb]].forEach(([name, v, db], i) => {
        const x = 22 + i * 82;
        const top = 658 - v * 44;
        g.globalAlpha = 0.3;
        g.fillRect(x, top, 64, v * 44);
        g.globalAlpha = 1;
        g.strokeStyle = look.b;
        line(g, x, top, x + 64, top);
        g.strokeStyle = look.a;
        poly(g, [[x, 658], [x, 614], [x + 6, 606], [x + 26, 606], [x + 32, 614], [x + 64, 614], [x + 64, 658]]);
        g.stroke();
        for (let k = 0; k <= Math.round(v * 3); k++) {
            g.beginPath();
            g.arc(x + 62 - k * 6, 690, 1.6, 0, 7);
            g.fill();
        }
        line(g, x + 8, 702, x + 8, 712, x + 30, 712);
        g.strokeRect(x + 30, 702, 34, 20);
        if (text) {
            label(g, name, x, 696, 24, font);
            label(g, num(db, db <= -10 ? 0 : 1), x + 47, 716, 9, font, 'center', 700);
        }
    });

    // The window that prints the numbers.
    g.strokeRect(352, 606, 162, 116);
    line(g, 352, 622, 514, 622);
    for (let k = 0; k < 3; k++) {
        g.beginPath();
        g.arc(504 - k * 11, 614, 3, 0, 7);
        g.stroke();
    }
    if (text) {
        const corr = sample.stereo.corr;
        const code = [
            `var t = play("${(say.title || say.name).toLowerCase().slice(0, 14)}");`,
            '',
            `lufs.m = ${num(sample.lufs.m)};`,
            `lufs.s = ${num(sample.lufs.s)};`,
            `peak.tp = ${num(d.tpDb)};`,
            `corr = ${corr >= 0 ? '+' : ''}${corr.toFixed(2)};`,
        ];
        code.forEach((ln, i) => {
            fit(g, ln, 148, 8.5, 500, font);
            g.textAlign = 'left';
            g.fillText(ln, 360, 638 + i * 13);
        });
    }

    // Time played and time left.
    g.strokeRect(524, 606, 54, 44);
    g.strokeRect(524, 678, 54, 44);
    if (text) {
        if (length) {
            g.fillStyle = look.b;
            fit(g, mmss(progress * length), 46, 14, 700, font);
            g.textAlign = 'center';
            g.fillText(mmss(progress * length), 551, 633);
            fit(g, `-${mmss((1 - progress) * length)}`, 46, 14, 700, font);
            g.fillText(`-${mmss((1 - progress) * length)}`, 551, 705);
            g.fillStyle = look.a;
        }
        label(g, 'PLAYED', 551, 664, 9, font, 'center');
        label(g, 'LEFT', 551, 736, 9, font, 'center');
        label(g, 'MAGNETITE', 352, 742, 8, font);
        line(g, 408, 739, 440, 739);
        label(g, 'POSTER TERRAIN', 448, 742, 8, font);
    }
}

/**
 * The screen of an old vector workstation, two colours of line on a dark ground, where every panel is a reading. The centre is a wireframe city
 * on a grid: eight columns are eight frequency ranges; the front row follows the sound calmly and each row behind it more slowly still, so the
 * city keeps a memory of the last seconds and does not jump. Around it: a spectrum strip, a ruler with the time played, the left and right
 * level, a clip lamp, the stereo width, a small goniometer, four ranges with their level, four folders that fill with the momentary, short-term
 * and integrated loudness and the true peak, a window that prints the numbers, and the time played and left. The title is the logo.
 * `city` is the seconds the front row takes to follow; `glow` is the halo (0 is off; it needs a dark ground and a browser with canvas filters).
 */
export default {
    id: 'poster-terrain',
    needs: ['bands', 'levels', 'lufs', 'stereo'],
    label: 'Poster: Terrain',
    params: {
        range: 40,
        attack: 0.08,
        release: 0.5,
        city: 1.2,
        glow: 0.35,
        text: 1,
        theme: 1,
        paper: '#0a0605',
        a: '#f2381f',
        b: '#ffb526',
    },

    draw(g, sample, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const { bars, strip, ref, city, st } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'a', 'b']);
        // On a light paper the amber would be lost, so the highlights take the theme's text colour when that reads better.
        if (!isDark(look.paper)) look.b = readableOn(look.paper, [look.b, colors.text]);
        const view = begin(g, w, h, look.paper, { p });
        const { bands, levels, lufs, stereo } = sample;

        // The readings.
        st.t += dt;
        const b = bars.update(bands, dt, p);
        strip.update(bands, dt, p);
        let mean = 0;
        for (let c = 0; c < COLS; c++) mean += b[c] / COLS;
        st.level = mean;
        for (let r = 0; r < ROWS; r++) for (let c = 0; c < COLS; c++) city[r][c] = follow(city[r][c], b[c], dt, p.city * 1.9 ** (ROWS - 1 - r));
        const l = toDb(levels.l.rms);
        const r = toDb(levels.r.rms);
        ref.update(Math.max(l, r), dt);
        st.l = follow(st.l, ref.norm(l, 30), dt, p.attack * 2);
        st.r = follow(st.r, ref.norm(r, 30), dt, p.attack * 2);
        st.clip = toDb(Math.max(levels.l.peak, levels.r.peak)) >= -0.5 ? 1.5 : Math.max(0, st.clip - dt * 2.5);
        st.corr = follow(st.corr, Math.max(-1, Math.min(1, stereo.corr)), dt, 0.4);
        const loud = (v) => (Number.isFinite(v) ? clamp01((v + 36) / 30) : 0);
        const tpDb = toDb(Math.max(lufs.tpL ?? 0, lufs.tpR ?? 0));
        st.m = follow(st.m, loud(lufs.m), dt, 0.3);
        st.s = follow(st.s, loud(lufs.s), dt, 0.3);
        st.i = follow(st.i, loud(lufs.i), dt, 0.3);
        st.tp = Math.max(clamp01((tpDb + 24) / 24), st.tp - dt * 0.25);

        const shadeKey = `${look.a} ${look.b}`;
        if (st.shadeKey !== shadeKey) Object.assign(st, { shadeKey, shades: ramp(look.a, look.b) });

        const d = {
            look, st, city, b, strip: strip.v, shades: st.shades, text: view.text, font: colors.font, say: posterText(track), track, progress, sample, tpDb,
            lw: Math.max(1.4, 0.6 / view.scale), // lines stay visible in a small cell (the menu thumbnail)
        };
        shapes(g, d);

        // The halo: the same drawing at half size on a layer of its own, laid over the poster blurred and added. Only where it can work: a dark
        // ground (adding light to a light paper washes it out), a cell big enough to read, and a browser whose canvas has filters.
        const m = g.getTransform();
        if (p.glow > 0.01 && view.text && isDark(look.paper) && typeof g.filter === 'string' && typeof document !== 'undefined' && m.a > 0) {
            const lw = Math.max(1, Math.ceil((W * m.a) / 2));
            const lh = Math.max(1, Math.ceil((H * m.d) / 2));
            if (!st.layer) {
                st.layer = document.createElement('canvas');
                st.lg = st.layer.getContext('2d');
            }
            if (st.layer.width !== lw || st.layer.height !== lh) {
                st.layer.width = lw;
                st.layer.height = lh;
            }
            const lg = st.lg;
            lg.setTransform(lw / W, 0, 0, lh / H, 0, 0);
            lg.fillStyle = look.paper;
            lg.fillRect(0, 0, W, H);
            shapes(lg, d);
            g.save();
            g.globalCompositeOperation = 'lighter';
            g.globalAlpha = Math.min(1, p.glow * 2);
            g.filter = `blur(${Math.max(1, 7 * m.a)}px)`;
            g.drawImage(st.layer, 0, 0, W, H);
            g.restore();
        }
        g.restore();
    },
};
