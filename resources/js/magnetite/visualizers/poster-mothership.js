import { loadFont } from '../font-load.js';
import { clamp01 } from './meter.js';
import { stateOf, Bars, begin, follow, palette, posterText } from './poster.js';

// An early-1980s home-computer title screen in one ink on one ground, on a 240 x 320 pixel grid (each pixel 2.5 poster pixels, drawn crisp): a blocky
// saucer over a starfield, tall condensed letters, a credit line. The idea of a black-and-white loading screen with its own drawing and layout. The
// credits are set in Press Start 2P (the same bundled bitmap font as Prompt, asked for through `loadFont`); the title is built from the 5 x 7 glyphs below.
const PIXEL = "'Press Start 2P'";
const FONT = `${PIXEL}, ui-monospace, Menlo, monospace`;
const LW = 240;
const LH = 320;
const P = 2.5;
const BANDS = 30;
const STARS = 64;
const ZONE_BOTTOM = 290; // the title's lowest row
const ZONE_H = 96;

/** A small repeatable random source, so the stars are where they were. */
function random(seed) {
    let s = seed;

    return () => (s = (s * 16807) % 2147483647) / 2147483647;
}

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

/** Text in the characters the pixel font has: capitals without accents. */
const plain = (t) => String(t ?? '').toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ß/g, 'SS');

const mean = (v, from, to) => {
    let t = 0;
    for (let i = from; i < to; i++) t += v[i];

    return t / (to - from);
};

// The title's glyphs: 5 x 7, rows separated by "/".
const FONT_SRC = {
    A: '.###./#...#/#...#/#####/#...#/#...#/#...#', B: '####./#...#/#...#/####./#...#/#...#/####.', C: '.###./#...#/#..../#..../#..../#...#/.###.',
    D: '####./#...#/#...#/#...#/#...#/#...#/####.', E: '#####/#..../#..../####./#..../#..../#####', F: '#####/#..../#..../####./#..../#..../#....',
    G: '.###./#...#/#..../#.###/#...#/#...#/.###.', H: '#...#/#...#/#...#/#####/#...#/#...#/#...#', I: '.###./..#../..#../..#../..#../..#../.###.',
    J: '..###/...#./...#./...#./...#./#..#./.##..', K: '#...#/#..#./#.#../##.../#.#../#..#./#...#', L: '#..../#..../#..../#..../#..../#..../#####',
    M: '#...#/##.##/#.#.#/#.#.#/#...#/#...#/#...#', N: '#...#/##..#/#.#.#/#..##/#...#/#...#/#...#', O: '.###./#...#/#...#/#...#/#...#/#...#/.###.',
    P: '####./#...#/#...#/####./#..../#..../#....', Q: '.###./#...#/#...#/#...#/#.#.#/#..#./.##.#', R: '####./#...#/#...#/####./#.#../#..#./#...#',
    S: '.####/#..../#..../.###./....#/....#/####.', T: '#####/..#../..#../..#../..#../..#../..#..', U: '#...#/#...#/#...#/#...#/#...#/#...#/.###.',
    V: '#...#/#...#/#...#/#...#/#...#/.#.#./..#..', W: '#...#/#...#/#...#/#.#.#/#.#.#/##.##/#...#', X: '#...#/#...#/.#.#./..#../.#.#./#...#/#...#',
    Y: '#...#/#...#/.#.#./..#../..#../..#../..#..', Z: '#####/....#/...#./..#../.#.../#..../#####',
    0: '.###./#...#/#..##/#.#.#/##..#/#...#/.###.', 1: '..#../.##../..#../..#../..#../..#../.###.', 2: '.###./#...#/....#/...#./..#../.#.../#####',
    3: '.###./#...#/....#/..##./....#/#...#/.###.', 4: '...#./..##./.#.#./#..#./#####/...#./...#.', 5: '#####/#..../####./....#/....#/#...#/.###.',
    6: '.###./#..../#..../####./#...#/#...#/.###.', 7: '#####/....#/...#./..#../.#.../.#.../.#...', 8: '.###./#...#/#...#/.###./#...#/#...#/.###.',
    9: '.###./#...#/#...#/.####/....#/....#/.###.',
    '-': '...../...../...../#####/...../...../.....', '.': '...../...../...../...../...../.##../.##..', ',': '...../...../...../...../.##../..#../.#...',
    ':': '...../.##../.##../...../.##../.##../.....', '!': '..#../..#../..#../..#../..#../...../..#..', '?': '.###./#...#/....#/...#./..#../...../..#..',
    "'": '..#../..#../...../...../...../...../.....', '/': '....#/...#./...#./..#../.#.../.#.../#....', '&': '.##../#..#./#.#../.#.../#.#.#/#..#./.##.#',
    '(': '...#./..#../.#.../.#.../.#.../..#../...#.', ')': '.#.../..#../...#./...#./...#./..#../.#...', '+': '...../..#../..#../#####/..#../..#../.....',
    ' ': '...../...../...../...../...../...../.....',
};
const GLYPH = {};
for (const [key, src] of Object.entries(FONT_SRC)) {
    GLYPH[key] = [];
    src.split('/').forEach((row, y) => [...row].forEach((c, x) => c === '#' && GLYPH[key].push([x, y])));
}

// Accent marks as rectangles in glyph units [x, y, w, h]: a glyph is 7 units wide (6 plus a gap) and 12 tall, y 0 its top.
const MARKS = {
    '́': [[3, -3, 2, 1], [2, -2, 2, 1]],
    '̀': [[1, -3, 2, 1], [2, -2, 2, 1]],
    '̂': [[2, -3, 2, 1], [1, -2, 1, 1], [4, -2, 1, 1]],
    '̃': [[1, -3, 4, 1]],
    '̈': [[1, -3, 1, 2], [4, -3, 1, 2]],
    '̧': [[2, 12, 2, 1]],
};

/** The characters of `text` as `{ ch, marks }`: capitals, with the accents kept as marks (or dropped), anything the font lacks as a space. */
function chars(text, keepMarks) {
    const out = [];
    for (const c of String(text ?? '').normalize('NFD').toUpperCase()) {
        if (MARKS[c]) {
            if (out.length && keepMarks) out[out.length - 1].marks.push(...MARKS[c]);
            continue;
        }
        out.push({ ch: GLYPH[c] ? c : ' ', marks: [] });
    }

    return out;
}

// The title is the 5 x 7 glyphs stretched on a unit grid: verticals two units thick, horizontals one (columns 2,1,1,1,2 wide, rows 1,2,3,1,2,2,1 tall),
// so a letter is 7 units wide and 12 tall. The largest unit that fits wins (1 to 5 pixels), in one to three lines. It does not follow the music.
const COLX = [0, 2, 3, 4, 5];
const COLW = [2, 1, 1, 1, 2];
const ROWY = [0, 1, 3, 6, 7, 9, 11];
const ROWH = [1, 2, 3, 1, 2, 2, 1];
const layouts = new Map();

function layout(title) {
    if (layouts.has(title)) return layouts.get(title);
    const cs = chars(title, true);
    const words = [];
    let cur = [];
    cs.forEach((c) => {
        if (c.ch === ' ') {
            if (cur.length) words.push(cur);
            cur = [];
        } else cur.push(c);
    });
    if (cur.length) words.push(cur);
    let best = null;
    for (const u of [5, 4, 3, 2, 1]) {
        const limit = Math.floor(224 / (8 * u));
        const lines = [];
        let line = [];
        let ok = true;
        for (const word of words) {
            if (word.length > limit) {
                ok = false;
                break;
            }
            if (line.length && line.length + 1 + word.length > limit) {
                lines.push(line);
                line = [];
            }
            line = line.concat(line.length ? [{ ch: ' ', marks: [] }] : [], word);
        }
        if (!ok) continue;
        lines.push(line);
        if (lines.length > 3 || lines.length * 16 * u > ZONE_H + 4) continue;
        best = { u, lines };
        break;
    }
    best ??= { u: 1, lines: [cs.slice(0, 28)] };
    if (layouts.size > 64) layouts.clear();
    layouts.set(title, best);

    return best;
}

/** Dither levels 0 (none) to 4 (solid) as rectangles added to the current path. */
function dither(g, x0, y0, w, h, level) {
    if (level <= 0) return;
    for (let y = y0; y < y0 + h; y++) {
        for (let x = x0; x < x0 + w; x++) {
            const on = level >= 4 || (level === 3 && !(x % 2 && y % 2)) || (level === 2 && (x + y) % 2 === 0) || (level === 1 && x % 2 === 0 && y % 2 === 0);
            if (on) g.rect(x, y, 1, 1);
        }
    }
}

const levelOf = (v) => (v < 0.12 ? 0 : v < 0.35 ? 1 : v < 0.6 ? 2 : v < 0.82 ? 3 : 4);

const make = () => {
    const rnd = random(5);

    return {
        bars: new Bars(BANDS),
        avg: 0.3,
        clock: 0,
        last: -9,
        beam: -9,
        beats: 0,
        stars: new Float32Array(STARS),
        pos: Array.from({ length: STARS }, () => [3 + Math.floor(rnd() * 234), 12 + Math.floor(rnd() * 284)]),
        streaks: [],
    };
};

/**
 * A one-colour title screen as a meter. The 64 stars are the spectrum (two per band: a dot, a small cross, a bigger cross as its band rises). The
 * saucer's three dithered tiers fill with the bass (widest), the mids and the highs (the top cap); its four portholes blink with the highs, its legs
 * lengthen and the whole saucer bobs with the bass, and it drifts from left to right as the track plays (its place is the progress). A strong bass
 * onset fires a dither tractor beam down towards the title and streaks a few stars. The title is static, in tall condensed letters; the credits are
 * in the bitmap font. With text off the picture has the stars and the saucer only.
 */
export default {
    id: 'poster-mothership',
    needs: ['bands'],
    label: 'Poster: Mothership',
    params: {
        range: 40,
        attack: 0.07,
        release: 0.4,
        text: 1,
        theme: 1,
        paper: '#000000',
        ink: '#d8d8d8',
    },

    draw(g, { bands }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const st = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink']);
        const view = begin(g, w, h, look.paper, { p });
        loadFont(PIXEL);

        const v = st.bars.update(bands, dt, p);
        st.clock += dt;
        const t = st.clock;
        const bass = mean(st.bars.raw, 0, 4);
        const rise = mean(v, 0, 4);
        const mid = mean(v, 4, 16);
        const high = mean(v, 16, BANDS);
        const level = mean(v, 0, BANDS);
        st.avg = follow(st.avg, bass, dt, 3.3);
        if (bass > st.avg * 1.22 + 0.04 && t - st.last > 0.42) {
            st.last = t;
            st.beats++;
            if (bass > 0.55) {
                st.beam = t;
                const rnd = random(st.beats * 13 + 1);
                st.streaks = Array.from({ length: 5 }, () => ({ i: Math.floor(rnd() * STARS), t0: t }));
            }
        }

        const ink = look.ink;
        const bg = look.paper;
        g.save();
        g.scale(P, P);
        g.fillStyle = bg;
        g.fillRect(0, 0, LW, LH);
        g.fillStyle = ink;

        // The stars: two per band; a dot, then a small cross, then a bigger one.
        g.beginPath();
        st.pos.forEach(([x, y], i) => {
            const target = clamp01(v[i % BANDS] * 1.8);
            st.stars[i] = follow(st.stars[i], target, dt, target > st.stars[i] ? 0.05 : 0.45);
            const b = st.stars[i];
            if (i % 2 === 0 || b > 0.22) g.rect(x, y, 1, 1);
            if (b > 0.55) {
                g.rect(x - 1, y, 1, 1);
                g.rect(x + 1, y, 1, 1);
                g.rect(x, y - 1, 1, 1);
                g.rect(x, y + 1, 1, 1);
            }
            if (b > 0.85) {
                g.rect(x - 2, y, 1, 1);
                g.rect(x + 2, y, 1, 1);
                g.rect(x, y - 2, 1, 1);
                g.rect(x, y + 2, 1, 1);
            }
        });
        st.streaks = st.streaks.filter((s) => t - s.t0 < 0.3);
        for (const s of st.streaks) {
            const [x, y] = st.pos[s.i];
            const k = (t - s.t0) / 0.3;
            const len = Math.round(6 + k * 12);
            for (let d = 0; d < len; d++) if (d % 2 === 0 || k < 0.5) g.rect(Math.round(x - d - k * 10), y, 1, 1);
        }
        g.fill();

        // The saucer drifts with the time played; it bobs and its legs follow the bass.
        const cx = Math.round(54 + clamp01(progress) * 132);
        const y0 = 44 + Math.round(Math.sin(t * 1.2) * 2 + rise * 4);
        const say = posterText(track);
        const tl = view.text ? layout(say.title || say.name) : null;
        const belly = y0 + 40;
        const titleBand = tl ? ZONE_BOTTOM - tl.lines.length * 16 * tl.u : 200;
        const beamA = clamp01(1 - (t - st.beam) / 0.5);
        if (beamA > 0.05) {
            // The beam above the title: dither, wider with the level.
            const lv = beamA > 0.66 ? 3 : beamA > 0.33 ? 2 : 1;
            g.beginPath();
            for (let y = belly + 6; y < titleBand; y++) {
                const half = Math.round(5 + clamp01((y - belly) / (ZONE_BOTTOM - belly)) * (6 + level * 46));
                for (let x = cx - half; x <= cx + half; x++) {
                    const on = lv === 3 ? (x + (y >> 1)) % 4 !== 0 : lv === 2 ? (x + (y >> 1)) % 2 === 0 : (x + (y >> 1)) % 4 === 0;
                    if (on) g.rect(x, y, 1, 1);
                }
            }
            g.fill();
        }
        const tiers = [[36, y0, high], [60, y0 + 8, mid], [84, y0 + 16, rise]];
        g.fillStyle = bg;
        tiers.forEach(([width, y]) => g.fillRect(cx - width / 2, y, width, 8));
        g.fillRect(cx - 52, y0 + 24, 104, 14);
        g.fillStyle = ink;
        g.beginPath();
        tiers.forEach(([width, y, value]) => {
            g.rect(cx - width / 2, y, width, 1);
            g.rect(cx - width / 2, y + 7, width, 1);
            g.rect(cx - width / 2, y, 1, 8);
            g.rect(cx + width / 2 - 1, y, 1, 8);
            dither(g, cx - width / 2 + 1, y + 1, width - 2, 6, levelOf(clamp01(value * 1.7)));
        });
        g.rect(cx - 52, y0 + 24, 104, 14);
        const leg = 6 + Math.round(clamp01(rise * 1.6) * 12);
        g.rect(cx - 32, y0 + 38, 12, leg);
        g.rect(cx + 20, y0 + 38, 12, leg);
        g.fill();
        g.fillStyle = bg;
        [-36, -12, 12, 36].forEach((dx, i) => {
            if (clamp01(v[16 + i * 3] * 1.9) > 0.4) g.fillRect(cx + dx - 1, y0 + 29, 3, 3);
        });

        if (view.text) {
            // The title: static tall letters, the bottoms of a row on one line.
            const { u, lines } = tl;
            g.beginPath();
            let top = ZONE_BOTTOM - lines.length * 16 * u;
            for (const line of lines) {
                const x0 = Math.round(LW / 2 - (line.length * 8 * u - u) / 2);
                const base = top + 15 * u;
                line.forEach((c, k) => {
                    const x = x0 + k * 8 * u;
                    for (const [gx, gy] of GLYPH[c.ch]) g.rect(x + COLX[gx] * u, base - (12 - ROWY[gy]) * u, COLW[gx] * u, ROWH[gy] * u);
                    for (const [mx, my, mw, mh] of c.marks) g.rect(x + mx * u, base - (12 - my) * u, mw * u, mh * u);
                });
                top += 16 * u;
            }
            g.fillStyle = ink;
            g.fill();

            // The credits and the header, in the bitmap font on its 8 pixel grid.
            g.font = `8px ${FONT}`;
            g.textBaseline = 'alphabetic';
            g.fillStyle = ink;
            const put = (s, x, y, align = 'left') => {
                g.textAlign = align;
                g.fillText(s, x, y + 7);
            };
            const length = track?.duration ?? 0;
            const credit = [plain(say.name), say.album && plain(say.album) + (say.year ? ` (${say.year})` : '')].filter(Boolean).join(' - ');
            put(credit.slice(0, 29), LW / 2, 297, 'center');
            const spec = plain([say.fmt.replace(/ · /g, ' ').replace(/-bit \/ /, '/').replace(/ kHz/, ''), say.dr].filter(Boolean).join(' ')).slice(0, 16);
            put(`${spec}  ${length ? `${mmss(progress * length)} / ${mmss(length)}` : ''}`.trim().slice(0, 29), LW / 2, 309, 'center');
            put('NOW PLAYING', 6, 5);
            if (say.no) put(`TRACK ${say.no}`, LW - 6, 5, 'right');
            g.textAlign = 'left';
        }
        g.restore();
        g.restore();
    },
};
