import { loadFont } from '../font-load.js';
import { clamp01, toDb } from './meter.js';
import { stateOf, Bars, Reference, begin, follow, palette, posterText } from './poster.js';

// An 8-bit text-mode screen: 40 columns of an 8 x 8 bitmap font (bundled: Press Start 2P), one colour on a ground, the title in inverse video. The font
// is the design, so it is the poster's own and not the theme's; a canvas does not start a font download by itself, so it is asked for.
const PIXEL = "'Press Start 2P'";
const FONT = `${PIXEL}, ui-monospace, Menlo, monospace`;
const COLS = 40;
const CELL_W = 13;
const ROW_H = 15;
const LEFT = 40;
const TOP = 20;
const BLOCK_ROWS = 18; // the block of scrambled characters
const BLOCK_AT = 3; // its first text row
const GLYPHS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789#@%&$?!*+=/<>';
const BANDS = 30;

/** A small repeatable random source (a linear congruential generator), so a picture is the same every time it is drawn from the same start. */
function random(seed) {
    let s = seed;

    return () => (s = (s * 16807) % 2147483647) / 2147483647;
}

const make = () => {
    const rnd = random(11);
    const glyph = () => (rnd() < 0.14 ? ' ' : GLYPHS[Math.floor(rnd() * GLYPHS.length)]);

    return {
        bars: new Bars(BANDS),
        ref: new Reference(),
        rnd,
        glyph,
        grid: Array.from({ length: BLOCK_ROWS }, () => Array.from({ length: COLS }, glyph)),
        st: { l: 0, r: 0, avg: 0, cool: 0, on: 0, clock: 0 },
    };
};

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

/** Text in the characters the pixel font has: capitals without accents. */
const plain = (t) => String(t ?? '').toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ß/g, 'SS');

/** The value of the 30 groups at a fractional position. */
function at(v, x) {
    const i = Math.max(0, Math.min(BANDS - 1, Math.floor(x)));
    const f = Math.max(0, Math.min(1, x - i));

    return v[i] * (1 - f) + v[Math.min(BANDS - 1, i + 1)] * f;
}

/**
 * An 8-bit text-mode menu screen as a meter. The block of scrambled characters on top is fixed text: each column is a band, and the louder a band, the
 * more often its characters change (a bass onset rewrites a few on the left). Under it the title in inverse video, then a menu in German like the
 * screens it follows: the track's format, rate, range and length, the left and right level as text bars, a peak row that goes inverse on bass onsets,
 * a prompt with a blinking cursor, the time played as a text progress bar and a footer bar with the track number.
 */
export default {
    id: 'poster-prompt',
    needs: ['bands', 'levels'],
    label: 'Poster: Prompt',
    params: {
        range: 40,
        attack: 0.07,
        release: 0.4,
        text: 1,
        theme: 1,
        paper: '#000000',
        ink: '#8bc34a',
    },

    draw(g, { bands, levels }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const { bars, ref, rnd, glyph, grid, st } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink']);
        const view = begin(g, w, h, look.paper, { p });
        loadFont(PIXEL);

        const v = bars.update(bands, dt, p);
        const l = toDb(levels.l.rms);
        const r = toDb(levels.r.rms);
        ref.update(Math.max(l, r), dt);
        const nl = ref.norm(l, 30);
        const nr = ref.norm(r, 30);
        st.l = follow(st.l, nl, dt, nl > st.l ? p.attack : p.release);
        st.r = follow(st.r, nr, dt, nr > st.r ? p.attack : p.release);
        const bass = (bars.raw[0] + bars.raw[1] + bars.raw[2]) / 3;
        st.avg = follow(st.avg, bass, dt, 2.7);
        st.cool -= dt;
        st.on = Math.max(0, st.on - dt * 3);
        st.clock += dt;
        if (st.cool <= 0 && bass > st.avg * 1.3 + 0.04) {
            st.on = 1;
            st.cool = 0.16;
        }
        // The block: a character changes at a rate that follows its column's band; an onset rewrites a few on the left.
        for (let c = 0; c < COLS; c++) {
            const level = clamp01(at(v, (c / (COLS - 1)) * (BANDS - 1)) * 1.25);
            const chance = (level * level * 0.7 + (st.on > 0.9 && c < 12 ? 6 : 0) * 0.04) * dt;
            for (let row = 0; row < BLOCK_ROWS; row++) if (rnd() < chance) grid[row][c] = glyph();
        }

        g.font = `${CELL_W}px ${FONT}`;
        g.textAlign = 'left';
        g.textBaseline = 'alphabetic';
        g.fillStyle = look.ink;
        const baseline = (row) => TOP + row * ROW_H + 12;
        const put = (row, col, text) => g.fillText(text, LEFT + col * CELL_W, baseline(row));
        const middle = (row, text) => put(row, Math.floor((COLS - text.length) / 2), text);
        const inverse = (row, col, width, text) => {
            g.fillRect(LEFT + col * CELL_W, TOP + row * ROW_H - 1, width * CELL_W, ROW_H + 1);
            g.fillStyle = look.paper;
            put(row, col + Math.floor((width - text.length) / 2), text);
            g.fillStyle = look.ink;
        };

        for (let row = 0; row < BLOCK_ROWS; row++) g.fillText(grid[row].join(''), LEFT, baseline(BLOCK_AT + row));

        // The time played as a progress bar of characters (a part of the picture, like the block).
        const cells = 34;
        const done = Math.round(clamp01(progress) * cells);
        middle(45, `[${'#'.repeat(done)}${'.'.repeat(cells - done)}]`);

        if (view.text) {
            const say = posterText(track);
            const [codec = '', depth = ''] = say.fmt.split(' · ');
            const length = track?.duration ?? 0;
            const name = plain(say.name);
            if (say.album) middle(1, plain(`${say.album}${say.year ? ` (${say.year})` : ''}`).slice(0, COLS));
            // The title bar: letter-spaced when it fits, a marquee when it does not.
            const title = plain(say.title || say.name);
            const spaced = title.split(' ').map((word) => word.split('').join(' ')).join('   ');
            let bar;
            if (spaced.length <= 32) {
                bar = spaced;
            } else if (title.length <= 32) {
                bar = title;
            } else {
                const loop = `${title}   ${title}`;
                const off = Math.floor(st.clock * 4) % (title.length + 3);
                bar = loop.slice(off, off + 32);
            }
            inverse(22, 3, 34, bar);
            if (say.title) middle(24, name.slice(0, COLS));
            middle(27, 'WIEDERGABE');
            const row = (y, label, value) => put(y, 6, label.padEnd(28 - value.length) + value);
            row(29, 'FORMAT', `<${plain(codec) || '--'}>`);
            row(30, 'ABTASTRATE', `<${plain(depth).replace(/ /g, '') || '--'}>`);
            row(31, 'DYNAMIK', `<${plain(say.dr) || '--'}>`);
            row(32, 'DAUER', `<${length ? mmss(length) : '--'}>`);
            const level = (x) => `<${'#'.repeat(Math.round(clamp01(x) * 10)).padEnd(10, ' ')}>`;
            row(34, 'PEGEL LINKS', level(st.l));
            row(35, 'PEGEL RECHTS', level(st.r));
            const peak = `<${toDb(Math.max(levels.l.peak, levels.r.peak)).toFixed(1)}DB>`;
            const peakRow = 'SPITZE'.padEnd(28 - peak.length) + peak;
            if (st.on > 0.4) {
                g.fillRect(LEFT + 5 * CELL_W, TOP + 36 * ROW_H - 1, 30 * CELL_W, ROW_H + 1);
                g.fillStyle = look.paper;
                put(36, 6, peakRow);
                g.fillStyle = look.ink;
            } else {
                put(36, 6, peakRow);
            }
            row(38, 'NAECHSTER TITEL', '<N>');
            row(39, 'PROGRAMM-ENDE', '<E>');
            put(42, 14, 'IHRE WAHL ?');
            if (Math.floor(st.clock * 2) % 2) g.fillRect(LEFT + 25 * CELL_W + 2, TOP + 42 * ROW_H + 1, CELL_W - 3, ROW_H - 3);
            if (length) middle(46, `${mmss(progress * length)} / ${mmss(length)}`);
            inverse(50, 8, 24, `TITEL ${say.no || '--'}  MAGNETITE`);
        }
        g.restore();
    },
};
