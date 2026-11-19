import { clamp01, toDb } from './meter.js';
import { W, stateOf, Bars, Reference, begin, follow, palette, posterText } from './poster.js';

const GROUPS = 30;
const RINGS = 44;
const CX = 300;
const TOP = 330; // the top of the pylon's body
const BASE = 690;
const RING_Y = 340;
// The three crossarms: highs on the top one, bass on the lowest (band groups from..to of 30).
const TIERS = [{ y: 380, len: 98, from: 20, to: 30 }, { y: 470, len: 130, from: 8, to: 20 }, { y: 560, len: 162, from: 0, to: 8 }];
const BOLT = [[0.58, 0], [0.04, 0.56], [0.4, 0.56], [0.26, 1], [0.96, 0.38], [0.56, 0.38], [0.8, 0]];
const HISTORY = 12; // columns of the dot field, one every 0.25 s
const ROWS = 9;

/** The half width of the pylon's body at height y. */
const halfWidth = (y) => 12 + (y - TOP) * (80 / (BASE - TOP));

// Scattered dots in the field, fixed so they are the same on every canvas (a small linear congruential generator, not Math.random).
let seed = 7;
const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
const DOTS = Array.from({ length: HISTORY * ROWS }, (_, i) => ({ c: i % HISTORY, r: Math.floor(i / HISTORY), jx: (rnd() - 0.5) * 7, jy: (rnd() - 0.5) * 7 }));

const make = () => ({
    bars: new Bars(GROUPS),
    ref: new Reference(),
    pk: new Float32Array(GROUPS),
    rg: new Float32Array(RINGS),
    hist: Array.from({ length: HISTORY }, () => new Float32Array(ROWS)),
    st: { l: 0, r: 0, lv: 0, avg: 0, cool: 0, on: 0, wave: 9, rot: 0, t: 0, clock: 0 },
});

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
const dbText = (lin) => toDb(lin).toFixed(1);

/** Mean level of band groups `from`..`to`, brought up a little so the top of the scale is reachable. */
const group = (v, from, to) => {
    let t = 0;
    for (let i = from; i < to; i++) t += v[i];

    return clamp01((t / (to - from)) * 1.5);
};

/** The value of the 30 groups at a fractional position. */
function at(v, x) {
    const i = Math.max(0, Math.min(GROUPS - 1, Math.floor(x)));
    const f = Math.max(0, Math.min(1, x - i));

    return v[i] * (1 - f) + v[Math.min(GROUPS - 1, i + 1)] * f;
}

function poly(g, pts) {
    g.beginPath();
    pts.forEach(([x, y], i) => (i ? g.lineTo(x, y) : g.moveTo(x, y)));
}

function line(g, x0, y0, x1, y1) {
    g.beginPath();
    g.moveTo(x0, y0);
    g.lineTo(x1, y1);
    g.stroke();
}

/** A lightning bolt filled from the bottom as far as `v`. */
function bolt(g, look, x, y, w, h, v) {
    const pts = BOLT.map(([u, t]) => [x + u * w, y + t * h]);
    g.save();
    poly(g, pts);
    g.closePath();
    g.fillStyle = look.paper;
    g.fill();
    g.save();
    g.clip();
    g.fillStyle = look.a;
    g.globalAlpha = 0.9;
    g.fillRect(x - 2, y + h * (1 - v), w + 4, h * v + 2);
    g.restore();
    g.lineWidth = 2;
    g.strokeStyle = look.ink;
    poly(g, pts);
    g.closePath();
    g.stroke();
    g.restore();
}

/** A star inside a ring: one spoke per value, as long as the value. */
function star(g, look, cx, cy, r, values, rot = 0) {
    const n = values.length;
    g.save();
    g.lineWidth = 1.4;
    g.strokeStyle = look.ink;
    g.beginPath();
    g.arc(cx, cy, r, 0, 7);
    g.stroke();
    const pts = [];
    for (let i = 0; i < n * 2; i++) {
        const a = rot + (i * Math.PI) / n - Math.PI / 2;
        const l = i % 2 === 0 ? 0.16 + 0.8 * values[i / 2] : 0.1;
        pts.push([cx + Math.cos(a) * r * l * 0.96, cy + Math.sin(a) * r * l * 0.96]);
    }
    poly(g, pts);
    g.closePath();
    g.fillStyle = look.a;
    g.fill();
    g.restore();
}

/** Text with letter spacing, in the poster's colour. */
function put(g, text, x, y, { size, font, color, weight = 700, align = 'left', spacing = 0 }) {
    if (!text) return;
    g.font = `${weight} ${size}px ${font}`;
    if ('letterSpacing' in g) g.letterSpacing = `${spacing}px`;
    g.textAlign = align;
    g.textBaseline = 'alphabetic';
    g.fillStyle = color;
    g.fillText(text, x, y);
    if ('letterSpacing' in g) g.letterSpacing = '0px';
    g.textAlign = 'left';
}

/** The title in as big a size as fits `maxW` in at most three lines: `{ size, lines }`. */
function titleLines(g, text, font, maxW) {
    const words = text.split(/\s+/).filter(Boolean);
    for (let size = 84; size >= 30; size -= 2) {
        g.font = `900 ${size}px ${font}`;
        if ('letterSpacing' in g) g.letterSpacing = `${-size * 0.03}px`;
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
        const ok = lines.length <= 3 && lines.every((l) => g.measureText(l).width <= maxW);
        if ('letterSpacing' in g) g.letterSpacing = '0px';
        if (ok) return { size, lines };
    }

    return { size: 30, lines: [text] };
}

/**
 * A black-and-white information poster as a meter: 44 concentric rings behind a heavy title, a lattice transmission pylon in front of them, three
 * lightning bolts, a field of dots, three stars and a block of small print. The rings are the spectrum (bass in the middle, treble outside, calm and
 * slow); a bass onset sends a faint ripple outwards. The pylon's three crossarms are three tiers (highs on top, bass at the bottom): the arm, its
 * insulators and its wires light and tremble with the tier's level, and sparks jump from the lowest tier on onsets. The bolts are the left channel,
 * the loudness and the right channel, filling from the bottom. The dots are a spectrogram of the last three seconds. The stars are the spectrum in
 * polar form, the held peaks and the onsets. The ring in the corner is the time played.
 */
export default {
    id: 'poster-pylon',
    needs: ['bands', 'levels'],
    label: 'Poster: Pylon',
    params: {
        range: 40,
        attack: 0.07,
        release: 0.5,
        text: 1,
        theme: 1,
        paper: '#0a0a0a',
        ink: '#f2efe6',
        a: '#ff3b1f',
    },

    draw(g, { bands, levels }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const { bars, ref, pk, rg, hist, st } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink', 'a']);
        const view = begin(g, w, h, look.paper, { p });

        // The measurements: the 30 groups and their held peaks, the two channels, the loudness, the bass onsets.
        const v = bars.update(bands, dt, p);
        let mean = 0;
        for (let i = 0; i < GROUPS; i++) {
            pk[i] = Math.max(v[i], pk[i] - dt * 0.2);
            mean += v[i] / GROUPS;
        }
        const l = toDb(levels.l.rms);
        const r = toDb(levels.r.rms);
        ref.update(Math.max(l, r), dt);
        const nl = ref.norm(l, 30);
        const nr = ref.norm(r, 30);
        st.l = follow(st.l, nl, dt, nl > st.l ? p.attack : p.release);
        st.r = follow(st.r, nr, dt, nr > st.r ? p.attack : p.release);
        st.lv = follow(st.lv, mean, dt, 0.7);
        const bass = (bars.raw[0] + bars.raw[1] + bars.raw[2]) / 3;
        st.avg = follow(st.avg, bass, dt, 2.7);
        st.cool -= dt;
        st.on = Math.max(0, st.on - dt * 3);
        st.wave += dt;
        st.clock += dt;
        if (st.cool <= 0 && bass > st.avg * 1.3 + 0.04) {
            st.on = 1;
            st.cool = 0.16;
            st.wave = 0;
            st.rot += 0.5;
        }
        st.t += dt;
        if (st.t > 0.25) {
            st.t = 0;
            const row = hist.shift();
            for (let k = 0; k < ROWS; k++) row[k] = group(v, Math.floor((k * GROUPS) / ROWS), Math.ceil(((k + 1) * GROUPS) / ROWS));
            hist.push(row);
        }

        g.lineJoin = 'round';
        g.lineCap = 'round';
        const front = st.wave * 460;

        // The rings: calm, thin, one colour, a faint ripple on an onset.
        g.strokeStyle = look.ink;
        for (let i = 0; i < RINGS; i++) {
            rg[i] = follow(rg[i], clamp01(at(v, (i / (RINGS - 1)) * (GROUPS - 1)) * 1.25), dt, 3.2);
            const radius = 34 + i * 5.6;
            const ripple = st.wave < 1 ? Math.exp(-((radius - front) ** 2) / 900) * (1 - st.wave) : 0;
            g.globalAlpha = clamp01(0.42 + rg[i] * 0.14 + ripple * 0.06);
            g.lineWidth = 1 + rg[i] * 0.25;
            g.beginPath();
            g.arc(CX, RING_Y, radius, 0, 7);
            g.stroke();
        }
        g.globalAlpha = 1;

        // The pylon: a mask in the paper colour first, so the rings stop behind it, then the lattice.
        g.fillStyle = look.paper;
        poly(g, [[CX - 12, TOP], [CX + 12, TOP], [CX + 92, BASE], [CX - 92, BASE]]);
        g.closePath();
        g.fill();
        for (const t of TIERS) {
            poly(g, [[CX - halfWidth(t.y), t.y], [CX - t.len, t.y + 4], [CX - halfWidth(t.y + 28), t.y + 28], [CX + halfWidth(t.y + 28), t.y + 28], [CX + t.len, t.y + 4], [CX + halfWidth(t.y), t.y]]);
            g.closePath();
            g.fill();
        }
        g.strokeStyle = look.ink;
        g.lineWidth = 2;
        line(g, CX - 12, TOP, CX - 92, BASE);
        line(g, CX + 12, TOP, CX + 92, BASE);
        g.lineWidth = 1.1;
        for (let y = TOP; y < BASE - 1; y += 30) {
            const y1 = Math.min(BASE, y + 30);
            line(g, CX - halfWidth(y), y, CX + halfWidth(y1), y1);
            line(g, CX + halfWidth(y), y, CX - halfWidth(y1), y1);
            line(g, CX - halfWidth(y1), y1, CX + halfWidth(y1), y1);
            line(g, CX - halfWidth(y), y, CX - halfWidth(y1) * 0.9, y1 - 14);
            line(g, CX + halfWidth(y), y, CX + halfWidth(y1) * 0.9, y1 - 14);
        }
        g.lineWidth = 1.6;
        line(g, CX, TOP, CX, TOP - 40);
        g.beginPath();
        g.arc(CX, TOP - 42, 3, 0, 7);
        g.stroke();

        // The three tiers: the arm, its insulators and its wires, lit and trembling with the tier's level.
        TIERS.forEach((t, ti) => {
            const level = group(v, t.from, t.to);
            const on = level > 0.4;
            for (const side of [-1, 1]) {
                const bx = CX + side * halfWidth(t.y);
                const tx = CX + side * t.len;
                g.strokeStyle = on ? look.a : look.ink;
                g.globalAlpha = 0.55 + level * 0.45;
                g.lineWidth = 1.4 + level * 1.8;
                line(g, bx, t.y, tx, t.y + 4);
                line(g, CX + side * halfWidth(t.y + 28), t.y + 28, tx, t.y + 4);
                g.lineWidth = 1;
                for (let j = 1; j < 5; j++) {
                    const u = j / 5;
                    const lowX = CX + side * halfWidth(t.y + 28);
                    line(g, bx + (tx - bx) * u, t.y + 4 * u, lowX + (tx - lowX) * u, t.y + 28 - 24 * u);
                }
                g.globalAlpha = 1;
                for (let j = 0; j < 4; j++) {
                    g.beginPath();
                    g.ellipse(tx, t.y + 12 + j * 6.5, 4.4, 2.2, 0, 0, 7);
                    g.fillStyle = on && j < level * 4.5 ? look.a : look.paper;
                    g.fill();
                    g.lineWidth = 1.1;
                    g.strokeStyle = look.ink;
                    g.stroke();
                }
                // The wire out to the edge, trembling a little with the tier's level.
                const ex = side < 0 ? -8 : 608;
                const ey = t.y + 52 + ti * 6;
                const sag = 26 + level * 3 * Math.sin(st.clock * (6 + ti * 2.3) + side);
                g.strokeStyle = on ? look.a : look.ink;
                g.globalAlpha = 0.75;
                g.lineWidth = 1.2;
                g.beginPath();
                g.moveTo(tx, t.y + 38);
                g.quadraticCurveTo((tx + ex) / 2, (t.y + 38 + ey) / 2 + sag, ex, ey);
                g.stroke();
                g.globalAlpha = 1;
            }
        });
        // Sparks at the lowest tier on a bass onset.
        if (st.on > 0.05) {
            g.strokeStyle = look.a;
            g.globalAlpha = st.on;
            g.lineWidth = 1.5;
            for (const side of [-1, 1]) {
                const x = CX + side * TIERS[2].len;
                const y = TIERS[2].y + 40;
                for (let j = 0; j < 8; j++) {
                    const a = (j * Math.PI) / 4 + st.rot;
                    const len = 6 + (1 - st.on) * 16;
                    line(g, x + Math.cos(a) * 3, y + Math.sin(a) * 3, x + Math.cos(a) * len, y + Math.sin(a) * len);
                }
            }
            g.globalAlpha = 1;
        }

        // The bolts: left, loudness, right.
        bolt(g, look, 40, 570, 46, 120, clamp01(st.l * 1.1));
        bolt(g, look, 88, 546, 58, 144, clamp01(st.lv * 1.5));
        bolt(g, look, 148, 570, 46, 120, clamp01(st.r * 1.1));

        // The dots: the last three seconds, newest on the right.
        for (const d of DOTS) {
            const level = hist[d.c][ROWS - 1 - d.r];
            g.beginPath();
            g.arc(400 + d.c * 14 + d.jx + d.r * 1.5, 540 + d.r * 16 + d.jy, 0.8 + level * 4.2, 0, 7);
            if (level > 0.5) {
                g.fillStyle = look.a;
                g.globalAlpha = 1;
            } else {
                g.fillStyle = look.ink;
                g.globalAlpha = 0.25 + level * 0.7;
            }
            g.fill();
        }
        g.globalAlpha = 1;

        // The stars: the spectrum in polar form, the held peaks, the onsets.
        star(g, look, 52, 764, 24, Array.from({ length: 8 }, (_, i) => group(v, Math.floor((i * GROUPS) / 8), Math.ceil(((i + 1) * GROUPS) / 8))));
        star(g, look, 108, 764, 24, Array.from({ length: 12 }, (_, i) => clamp01(at(pk, (i / 11) * (GROUPS - 1)) * 1.25)));
        star(g, look, 164, 764, 24, [0.35 + st.on * 0.65, 0.2 + st.lv, 0.35 + st.on * 0.65, 0.2 + st.lv].map(clamp01), st.rot);

        // The ring in the corner: the time played.
        g.lineCap = 'butt';
        g.strokeStyle = look.ink;
        g.globalAlpha = 0.35;
        g.lineWidth = 1;
        g.beginPath();
        g.arc(540, 66, 34, 0, 7);
        g.stroke();
        g.globalAlpha = 1;
        g.strokeStyle = look.a;
        g.lineWidth = 4;
        g.beginPath();
        g.arc(540, 66, 34, -Math.PI / 2, -Math.PI / 2 + clamp01(progress) * Math.PI * 2);
        g.stroke();

        if (view.text) {
            const say = posterText(track);
            const font = colors.font;
            const ink = { font, color: look.ink };
            const length = track?.duration ?? 0;
            const time = length ? mmss(progress * length) : '';
            const main = (say.title || say.name).toUpperCase();
            put(g, [say.album, say.year].filter(Boolean).join('  ·  ').toUpperCase(), 36, 30, { ...ink, size: 9, weight: 600, spacing: 2 });
            // The title, heavy, over the rings, with a ground-coloured edge so it stays readable.
            const fit = titleLines(g, main, font, 470);
            fit.lines.forEach((text, i) => {
                const y = 36 + fit.size * 0.92 + i * fit.size * 0.9;
                g.save();
                g.font = `900 ${fit.size}px ${font}`;
                if ('letterSpacing' in g) g.letterSpacing = `${-fit.size * 0.03}px`;
                g.lineJoin = 'round';
                g.strokeStyle = look.paper;
                g.lineWidth = 7;
                g.strokeText(text, 34, y);
                g.fillStyle = look.ink;
                g.fillText(text, 34, y);
                g.restore();
            });
            if (say.title) {
                const y = 36 + fit.size * 0.92 + (fit.lines.length - 1) * fit.size * 0.9 + 26;
                g.font = `700 13px ${font}`;
                if ('letterSpacing' in g) g.letterSpacing = '3px';
                const width = g.measureText(say.name.toUpperCase()).width;
                if ('letterSpacing' in g) g.letterSpacing = '0px';
                g.fillStyle = look.paper;
                g.fillRect(30, y - 14, width + 10, 20);
                put(g, say.name.toUpperCase(), 36, y, { ...ink, size: 13, spacing: 3 });
            }
            put(g, time, 540, 70, { ...ink, size: 11, align: 'center' });
            // The vertical readout: the loudness in dB and the time.
            g.save();
            g.translate(18, 470);
            g.rotate(-Math.PI / 2);
            put(g, [`${dbText((levels.l.rms + levels.r.rms) / 2)} DB`, length ? `${time} / ${mmss(length)}` : ''].filter(Boolean).join('  —  '), 0, 0, { ...ink, size: 10, align: 'center', spacing: 2.5 });
            g.restore();
            g.strokeStyle = look.ink;
            g.lineWidth = 1;
            line(g, 30, 360, 30, 600);
            poly(g, [[26, 592], [30, 600], [34, 592]]);
            g.stroke();
            // The small print and the paragraph.
            [say.fmt ? `FORMAT ${say.fmt.toUpperCase()}` : '', say.dr ? `DYNAMIC RANGE ${say.dr}` : '', say.no ? `TRACK ${say.no}` : '', 'LEFT / LOUDNESS / RIGHT']
                .filter(Boolean).forEach((text, i) => put(g, text, 36, 706 + i * 9.5, { ...ink, size: 7 }));
            const para = [
                `${main} IS TRACK ${say.no || ''}${say.album ? ` ON ${say.album.toUpperCase()}` : ''}${say.year ? ` (${say.year})` : ''}.`,
                say.fmt ? `A ${say.fmt.toUpperCase()} FILE${say.dur ? `, ${say.dur} LONG` : ''}${say.dr ? `, WITH A DYNAMIC RANGE OF ${say.dr}` : ''}.` : '',
                `NOW ${dbText(levels.l.rms)} DB LEFT, ${dbText(levels.r.rms)} DB RIGHT.`,
                'THE RINGS ARE THE SPECTRUM, THE PYLON ITS THREE TIERS.',
            ].filter(Boolean).join(' ');
            g.font = `700 7.6px ${font}`;
            const rows = [];
            let cur = '';
            for (const word of para.split(' ')) {
                const next = cur ? `${cur} ${word}` : word;
                if (!cur || g.measureText(next).width <= 340) {
                    cur = next;
                } else {
                    rows.push(cur);
                    cur = word;
                }
            }
            rows.push(cur);
            rows.slice(0, 7).forEach((text, i) => put(g, text, 232, 710 + i * 10.4, { ...ink, size: 7.6 }));
        }
        g.restore();
    },
};
