import { clamp01, toDb } from './meter.js';
import { W, H, stateOf, Bars, Reference, begin, follow, isDark, palette, posterText } from './poster.js';

// The chart is an FAA airport diagram (a US government chart, public domain) baked into a mask of its ink (airport-chart.png, 1044 x 1601, alpha =
// how black a pixel was). The airport's names and its data blocks are erased from the picture itself; the poster prints the track in their place.
// The overlays are placed in the picture's own pixel space (130 dpi of the page, 700 x 1073) and mapped to the poster.
const PAGE_W = 700;
const PAGE_H = 1073;
const SC = H / PAGE_H;
const OX = (W - PAGE_W * SC) / 2;
const X = (x) => OX + x * SC;
const Y = (y) => y * SC;

const RWL = { x: 248.5, n: 292, s: 809 }; // the long runway on the left
const RWR = { x: 508.5, n: 308, s: 824 }; // the long runway on the right
const RWS = { x: 217.5, n: 528, s: 687 }; // the short one
const DG = { x0: 90, y0: 317, x1: 355, y1: 583 }; // the diagonal one
const BANDS = 30;

const make = () => ({
    bars: new Bars(BANDS),
    ref: new Reference(),
    pk: new Float32Array(BANDS),
    tints: new Map(),
    st: { l: 0, r: 0, lv: 0, peak: 0, avg: 0, cool: 0, on: 0, ping: 9 },
});

// The picture is a shared asset (not drawing state), so it is loaded once, when the module is first used. A picture that cannot be loaded leaves
// the overlays and the text without it; it never stops the meter.
let chart = null;
function load() {
    if (chart !== null || typeof Image === 'undefined') return;
    try {
        chart = new Image();
        chart.decoding = 'async';
        chart.src = new URL('./airport-chart.png', import.meta.url).href;
    } catch {
        chart = false;
    }
}
load();

function chartReady() {
    load();

    return chart && chart.complete && chart.naturalWidth > 0 ? chart : null;
}

/** The chart's ink in one colour (made once per colour and canvas). */
function tinted(cache, color) {
    if (!cache.has(color)) {
        const c = document.createElement('canvas');
        c.width = chart.naturalWidth;
        c.height = chart.naturalHeight;
        const t = c.getContext('2d');
        t.fillStyle = color;
        t.fillRect(0, 0, c.width, c.height);
        t.globalCompositeOperation = 'destination-in';
        t.drawImage(chart, 0, 0);
        cache.set(color, c);
    }

    return cache.get(color);
}

/** Which runway the plane uses: one of four, fixed for a track (so a different track often rolls down a different runway). */
export function routeFor(track) {
    const key = `${track?.artist ?? ''}${track?.title ?? ''}${track?.number ?? ''}`;
    let h = 7;
    for (let i = 0; i < key.length; i++) h = (h * 31 + key.charCodeAt(i)) >>> 0;
    h = Math.imul(h ^ (h >>> 15), 2246822519) >>> 0; // mix the bits: the low two alone would barely change with the text

    return ((h ^ (h >>> 13)) >>> 0) % 4;
}

const ROUTES = [
    [[DG.x0, DG.y0], [DG.x1, DG.y1]],
    [[RWL.x, RWL.s], [RWL.x, RWL.n]],
    [[RWR.x, RWR.s], [RWR.x, RWR.n]],
    [[RWS.x, RWS.s], [RWS.x, RWS.n]],
];

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
const dbText = (lin) => toDb(lin).toFixed(1);

/** A runway's lights: a lit centreline and two rows of edge lights, filled to `frac` from the start (a and b are poster points). */
function strip(g, a, b, frac, look, glow) {
    const len = Math.hypot(b[0] - a[0], b[1] - a[1]);
    const ux = (b[0] - a[0]) / len;
    const uy = (b[1] - a[1]) / len;
    const n = Math.floor(len / 6.6);
    g.lineCap = 'butt';
    g.strokeStyle = look.a;
    g.lineWidth = 2.4;
    g.setLineDash([5, 4]);
    g.beginPath();
    g.moveTo(a[0], a[1]);
    g.lineTo(a[0] + ux * len * frac, a[1] + uy * len * frac);
    g.stroke();
    g.setLineDash([]);
    const on = new Path2D();
    const off = new Path2D();
    for (let i = 0; i < n; i++) {
        const t = (i + 0.5) / n;
        for (const side of [-1, 1]) {
            const x = a[0] + ux * len * t - uy * side * 7.2;
            const y = a[1] + uy * len * t + ux * side * 7.2;
            if (t < frac) {
                on.moveTo(x + 1.7, y);
                on.arc(x, y, 1.7, 0, 7);
            } else {
                off.moveTo(x + 1.1, y);
                off.arc(x, y, 1.1, 0, 7);
            }
        }
    }
    g.globalAlpha = 0.3;
    g.fillStyle = look.ink;
    g.fill(off);
    g.globalAlpha = 1;
    g.fillStyle = look.a;
    g.shadowColor = look.a;
    g.shadowBlur = glow ? 6 : 0;
    g.fill(on);
    g.shadowBlur = 0;
}

function plane(g, x, y, angle, look) {
    g.save();
    g.translate(x, y);
    g.rotate(angle);
    g.scale(1.25, 1.25);
    g.beginPath();
    [[13, 0], [3, -2.2], [-2, -13], [-5.5, -13], [-3.6, -2.2], [-9, -1.4], [-10.5, -5], [-13, -5], [-12, 0], [-13, 5], [-10.5, 5], [-9, 1.4], [-3.6, 2.2], [-5.5, 13], [-2, 13], [3, 2.2]]
        .forEach(([px, py], i) => (i ? g.lineTo(px, py) : g.moveTo(px, py)));
    g.closePath();
    g.fillStyle = look.a;
    g.lineWidth = 1;
    g.strokeStyle = look.paper;
    g.fill();
    g.stroke();
    g.restore();
}

/** Text at a point, `spacing` px between letters, in the poster's colour. */
function put(g, text, x, y, { size, font, color, weight = 500, align = 'left', spacing = 0 }) {
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

/** The largest size from `size` down at which `text` fits `maxW`. */
function fit(g, text, weight, font, maxW, size, min = 9) {
    g.font = `${weight} ${size}px ${font}`;
    while (size > min && g.measureText(text).width > maxW) {
        size -= 1;
        g.font = `${weight} ${size}px ${font}`;
    }

    return size;
}

/**
 * An airport diagram as a meter (an FAA chart, in the public domain, with the airport's names taken out): a plane rolls down a runway as the track
 * plays, a different runway for each track, its centreline lit behind it. The two long parallel runways are the left and right level (their lights
 * fill from the south end), the short one the peak and the diagonal one the loudness. The 30 bars east of the right-hand runway are the bands
 * (bass at the south end) with a dot at each held peak; the terminal flashes and the tower pings on bass onsets, its gate lights follow band
 * groups. The track is printed where the chart's names were, and the levels in dB where its pavement data were.
 */
export default {
    id: 'poster-airport',
    needs: ['bands', 'levels'],
    label: 'Poster: Airport',
    params: {
        range: 40,
        attack: 0.08,
        release: 0.45,
        text: 1,
        theme: 1,
        paper: '#f3f1e8',
        ink: '#14130f',
        a: '#c4247a',
    },

    draw(g, { bands, levels }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const { bars, ref, pk, tints, st } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink', 'a']);
        const view = begin(g, w, h, look.paper, { p });
        const glow = isDark(look.paper);

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
        st.lv = follow(st.lv, mean, dt, 0.4);
        st.peak = follow(st.peak, top, dt, 0.3);
        const bass = (bars.raw[0] + bars.raw[1] + bars.raw[2]) / 3;
        st.avg = follow(st.avg, bass, dt, 2.7);
        st.cool -= dt;
        st.on = Math.max(0, st.on - dt * 3);
        st.ping += dt;
        if (st.cool <= 0 && bass > st.avg * 1.3 + 0.04) {
            st.on = 1;
            st.cool = 0.2;
            st.ping = 0;
        }

        // The chart, in the ink colour.
        if (chartReady()) g.drawImage(tinted(tints, look.ink), OX, 0, PAGE_W * SC, PAGE_H * SC);

        g.lineJoin = 'round';
        g.lineCap = 'round';
        const pt = ([x, y]) => [X(x), Y(y)];
        // The runways: left and right level, the peak, the loudness (the diagonal).
        strip(g, pt([RWL.x, RWL.s]), pt([RWL.x, RWL.n]), clamp01(st.l * 1.1), look, glow);
        strip(g, pt([RWR.x, RWR.s]), pt([RWR.x, RWR.n]), clamp01(st.r * 1.1), look, glow);
        strip(g, pt([RWS.x, RWS.s]), pt([RWS.x, RWS.n]), clamp01(st.peak * 1.25), look, glow);
        strip(g, pt([DG.x0, DG.y0]), pt([DG.x1, DG.y1]), clamp01(st.lv * 1.6), look, glow);

        // The plane rolls down this track's runway as the track plays.
        const [ra, rb] = ROUTES[routeFor(track)].map(pt);
        const tp = 0.03 + 0.94 * clamp01(progress);
        const px = ra[0] + (rb[0] - ra[0]) * tp;
        const py = ra[1] + (rb[1] - ra[1]) * tp;
        g.strokeStyle = look.a;
        g.lineWidth = 3;
        g.shadowColor = look.a;
        g.shadowBlur = glow ? 6 : 0;
        g.beginPath();
        g.moveTo(ra[0], ra[1]);
        g.lineTo(px, py);
        g.stroke();
        g.shadowBlur = 0;
        plane(g, px, py, Math.atan2(rb[1] - ra[1], rb[0] - ra[0]), look);

        // The 30 bands east of the right-hand runway, bass at the south end, a dot at each held peak.
        g.lineCap = 'butt';
        for (let i = 0; i < BANDS; i++) {
            const y = Y(818 - i * (500 / 29));
            const x0 = X(520);
            g.globalAlpha = 0.28;
            g.strokeStyle = look.ink;
            g.lineWidth = 1;
            g.beginPath();
            g.moveTo(x0, y);
            g.lineTo(x0 + 26, y);
            g.stroke();
            g.globalAlpha = 1;
            g.strokeStyle = look.a;
            g.lineWidth = 2.6;
            g.beginPath();
            g.moveTo(x0, y);
            g.lineTo(x0 + clamp01(v[i] * 1.25) * 26, y);
            g.stroke();
            g.fillStyle = look.a;
            g.beginPath();
            g.arc(x0 + clamp01(pk[i] * 1.25) * 26, y, 1.8, 0, 7);
            g.fill();
        }

        // The terminal flashes, its gate lights follow band groups, the tower pings.
        g.fillStyle = look.a;
        g.globalAlpha = st.on * 0.5;
        g.beginPath();
        g.ellipse(X(410), Y(492), 58 * SC, 14 * SC, 0, 0, 7);
        g.fill();
        g.globalAlpha = 1;
        for (let i = 0; i < 12; i++) {
            const k = Math.floor((i * BANDS) / 12);
            const on = (v[k] + v[Math.min(BANDS - 1, k + 1)]) * 0.7 > 0.35;
            g.globalAlpha = on ? 1 : 0.3;
            g.fillStyle = on ? look.a : look.ink;
            g.beginPath();
            g.arc(X(360 + i * 9.4), Y(510), 1.7, 0, 7);
            g.fill();
        }
        g.globalAlpha = 1;
        if (st.ping < 0.6) {
            g.globalAlpha = 1 - st.ping / 0.6;
            g.strokeStyle = look.a;
            g.lineWidth = 1.6;
            g.beginPath();
            g.arc(X(334), Y(437), 3 + st.ping * 38, 0, 7);
            g.stroke();
            g.globalAlpha = 1;
        }

        if (view.text) {
            const say = posterText(track);
            const font = colors.font;
            const ink = { font, color: look.ink };
            const main = say.title || say.name;
            const who = [say.title ? say.name : '', say.album].filter(Boolean).join(', ').toUpperCase();
            const size = fit(g, main, 700, font, 178, 17, 11);
            const length = track?.duration ?? 0;
            // The headings, top and bottom, where the chart named the airport.
            put(g, main, X(668), Y(58), { ...ink, size, weight: 700, align: 'right' });
            put(g, who, X(668), Y(76), { ...ink, size: 9.5, align: 'right' });
            put(g, who, X(668), Y(1004), { ...ink, size: 9.5, align: 'right' });
            put(g, main, X(668), Y(1023), { ...ink, size, weight: 700, align: 'right' });
            // The track's facts, where the frequencies were.
            [['FORMAT', true], [say.fmt, false], ['DYNAMIC RANGE', true], [say.dr, false], ['LENGTH', true], [say.dur, false], ['TRACK', true], [say.no, false]]
                .forEach(([text, head], i) => put(g, text, X(36), Y(100 + i * 14), { ...ink, size: 8.2, weight: head ? 700 : 500 }));
            // And what the overlays mean, where the notes were.
            put(g, [say.album, say.year].filter(Boolean).join(' ').toUpperCase(), X(134), Y(102), { ...ink, size: 8.2 });
            ['RWY 17R-35L LEFT   RWY 17L-35R RIGHT', 'RWY 13-31 LOUDNESS   RWY 18-36 PEAK', 'BARS EAST OF 17L-35R: 30 BANDS']
                .forEach((t, i) => put(g, t, X(134), Y(124 + i * 14), { ...ink, size: 7.4 }));
            // The levels in dB, where the pavement data were.
            put(g, 'LEVELS (DB)', X(44), Y(878), { ...ink, size: 8.2, weight: 700 });
            [['RWY 17R-35L', dbText(levels.l.rms)], ['RWY 17L-35R', dbText(levels.r.rms)], ['RWY 13-31', dbText((levels.l.rms + levels.r.rms) / 2)], ['RWY 18-36', dbText(Math.max(levels.l.peak, levels.r.peak))]]
                .forEach(([name, value], i) => {
                    put(g, name, X(44), Y(896 + i * 15), { ...ink, size: 8.2 });
                    put(g, value, X(230), Y(896 + i * 15), { ...ink, size: 8.2, align: 'right' });
                });
            // The side notes.
            g.save();
            g.translate(X(18), Y(540));
            g.rotate(-Math.PI / 2);
            put(g, [say.fmt, say.dr, say.dur].filter(Boolean).join(' · '), 0, 3, { ...ink, size: 8, align: 'center', spacing: 0.8 });
            g.restore();
            g.save();
            g.translate(X(686), Y(540));
            g.rotate(Math.PI / 2);
            put(g, [say.no ? `TRACK ${say.no}` : '', say.year, length ? mmss(progress * length) : ''].filter(Boolean).join(' · '), 0, 3, { ...ink, size: 8, align: 'center', spacing: 0.8 });
            g.restore();
        }
        g.restore();
    },
};
