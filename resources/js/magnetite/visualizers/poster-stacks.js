import { clamp01, toDb } from './meter.js';
import { W, stateOf, Bars, Reference, begin, drawName, follow, mixHex, palette, posterText } from './poster.js';

const BANDS = 30;
const ROWS = 20;
const HISTORY = 30;
const STEP = 33; // the height of a row of the building
const TOP = 140;
const LOW_ROW = 6; // the left part of the building starts at this row; the right part is the whole height
const SLATS = 22; // the lined shaft of the tower

const make = () => ({
    bars: new Bars(BANDS),
    ref: new Reference(),
    pk: new Float32Array(BANDS),
    hist: new Float32Array(HISTORY),
    st: { l: 0, r: 0, lv: 0, peak: 0, avg: 0, cool: 0, on: 0, t: 0 },
});

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

/** The value of the 30 groups at a fractional position. */
function at(v, x) {
    const i = Math.max(0, Math.min(BANDS - 1, Math.floor(x)));
    const f = Math.max(0, Math.min(1, x - i));

    return v[i] * (1 - f) + v[Math.min(BANDS - 1, i + 1)] * f;
}

/**
 * The colours: paper and ink follow the theme at level 1; at level 2 the rest comes from the theme too (the two accents and the text colour);
 * otherwise they are the poster's own.
 */
function colours(p, colors) {
    const base = palette(p, colors, ['paper', 'ink']);
    if (Number(p.theme) < 2) return { ...base, s0: p.s0, s1: p.s1, s2: p.s2, core: p.core, left: p.left, right: p.right, hotLeft: p.hotLeft, hotRight: p.hotRight, pink: p.pink, tower: p.tower };
    const { accent, accent2, text, bg } = colors;

    return {
        ...base,
        s0: mixHex(accent, bg, 0.2),
        s1: accent2,
        s2: accent,
        core: mixHex(accent, text, 0.2),
        left: accent,
        right: accent2,
        hotLeft: mixHex(accent, text, 0.45),
        hotRight: mixHex(accent2, text, 0.45),
        pink: accent,
        tower: accent,
    };
}

/** One building: row `r` is a stripe that is a band, with a curved, fixed left end; its colour moves from its base towards a hotter one with its level. */
function stripe(g, base, hot, xL, xR, y, level) {
    g.strokeStyle = mixHex(base, hot, level * 0.6);
    g.beginPath();
    g.moveTo(xL + STEP / 2, y + 16);
    g.bezierCurveTo(xL + 24 + STEP / 2, y + 16, xL + 38, y, xL + 74, y);
    g.lineTo(xR, y);
    g.stroke();
}

/**
 * A modern travel print: a sun in the corner, one building of curved stripes (a lower part on the left, a taller part on the right) and a TV tower.
 * Each row of the building is a band (the highs at the top, the bass at the bottom); the stripe keeps its shape and its colour drifts towards a hotter
 * one as its band gets louder. The pink lines on the right part are the loudness of the last three seconds, one line a moment. The sun's core
 * follows the loudness and its rings pop on bass onsets. The lined lower shaft of the tower is a thermometer for the left and right level and its
 * tip lights on peaks.
 */
export default {
    id: 'poster-stacks',
    needs: ['bands', 'levels'],
    label: 'Poster: Stacks',
    params: {
        range: 40,
        attack: 0.12,
        release: 0.4,
        text: 1,
        theme: 1,
        paper: '#e8dfd0',
        ink: '#0f1416',
        s0: '#e8531e',
        s1: '#e63a2e',
        s2: '#e8458f',
        core: '#f28200',
        left: '#e8531e',
        right: '#e63a2e',
        hotLeft: '#f6a21a',
        hotRight: '#ee3f9a',
        pink: '#e8458f',
        tower: '#f28200',
    },

    draw(g, { bands, levels }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const { bars, ref, pk, hist, st } = stateOf(g, make);
        const look = colours(p, colors);
        const view = begin(g, w, h, look.paper, { p });

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
        st.on = Math.max(0, st.on - dt * 2.5);
        if (st.cool <= 0 && bass > st.avg * 1.3 + 0.04) {
            st.on = 1;
            st.cool = 0.2;
        }
        st.t += dt;
        if (st.t > 0.1) {
            st.t = 0;
            hist.copyWithin(1, 0, HISTORY - 1);
            hist[0] = clamp01(st.lv * 1.5 + st.on * 0.2);
        }
        const lv = clamp01(st.lv * 1.5);
        const peak = clamp01(st.peak * 1.25);

        // The sun: its rings pop on onsets, its core follows the loudness.
        const circle = (x, y, radius, color) => {
            g.fillStyle = color;
            g.beginPath();
            g.arc(x, y, radius, 0, 7);
            g.fill();
        };
        circle(60, 100, 200 + st.on * 5, look.s0);
        circle(60, 100, 122 + st.on * 10, look.s1);
        circle(60, 100, 95 + st.on * 8, look.s2);
        circle(60, 100, 50 + lv * 26, look.core);

        // The building: one stripe per row, highs at the top; the low left part and the tall right part share their rows.
        g.lineWidth = STEP - 4.5;
        g.lineJoin = 'round';
        g.lineCap = 'round';
        for (let row = 0; row < ROWS; row++) {
            const level = clamp01(at(v, ((ROWS - 1 - row) / (ROWS - 1)) * (BANDS - 1)) * 1.25);
            const y = TOP + row * STEP + STEP / 2;
            if (row >= LOW_ROW) stripe(g, look.left, look.hotLeft, 158, 330, y, level);
            stripe(g, look.right, look.hotRight, 352, 612, y, level);
        }
        // The pink lines on the right part: the loudness of the last three seconds, newest on the right.
        g.fillStyle = look.pink;
        for (let row = 0; row < 4; row++) {
            for (let i = 0; i < HISTORY; i++) {
                g.globalAlpha = 0.12 + 0.88 * hist[HISTORY - 1 - i];
                g.fillRect(442 + i * 4.2, TOP + row * STEP + 2.25, 2.2, 28.5);
            }
        }
        g.globalAlpha = 1;

        // The tower: antenna, saucers, cone and shaft; the lined lower shaft is a thermometer, the tip lights on peaks.
        const tx = 70;
        g.fillStyle = look.tower;
        g.fillRect(tx - 1.5, 352, 3, 80);
        circle(tx, 352, 2.2, look.tower);
        for (const [y, rx] of [[438, 36], [451, 29], [463, 21]]) {
            g.beginPath();
            g.ellipse(tx, y, rx, 5, 0, 0, 7);
            g.fill();
        }
        g.beginPath();
        g.moveTo(tx - 34, 474);
        g.lineTo(tx + 34, 474);
        g.lineTo(tx + 9, 520);
        g.lineTo(tx - 9, 520);
        g.closePath();
        g.fill();
        g.beginPath();
        g.moveTo(tx - 9, 518);
        g.lineTo(tx + 9, 518);
        g.lineTo(tx + 13, 656);
        g.lineTo(tx - 13, 656);
        g.closePath();
        g.fill();
        const slat = (800 - 658) / SLATS;
        const level = clamp01((st.l + st.r) * 0.55);
        for (let i = 0; i < SLATS; i++) {
            g.globalAlpha = i / SLATS < level ? 1 : 0.2;
            g.fillRect(tx - 14, 800 - (i + 1) * slat + 1.2, 28, slat - 2.6);
        }
        g.globalAlpha = 1;
        if (peak > 0.7) {
            g.globalAlpha = (peak - 0.7) * 3;
            circle(tx, 350, 6, look.core);
            g.globalAlpha = 1;
        }

        if (view.text) {
            const say = posterText(track);
            const font = colors.font;
            const length = track?.duration ?? 0;
            drawName(g, say.title || say.name, 580, 56, { font, maxW: 250, size: 40, color: look.ink, weight: 700, spacing: -0.8, align: 'right' });
            const small = (text, y) => {
                if (!text) return;
                g.font = `400 11px ${font}`;
                g.textAlign = 'right';
                g.fillStyle = look.ink;
                g.fillText(text, 580, y);
                g.textAlign = 'left';
            };
            const y = 78;
            small([say.title ? say.name : '', say.album].filter(Boolean).join(' · ').slice(0, 36), y);
            small([say.fmt, say.dr, length ? `${mmss(progress * length)} / ${mmss(length)}` : ''].filter(Boolean).join(' · '), y + 15);
        }
        g.restore();
    },
};
