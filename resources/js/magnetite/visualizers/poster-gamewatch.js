import { clamp01, toDb } from './meter.js';
import { stateOf, Bars, Reference, begin, follow, mixHex, palette, posterText } from './poster.js';

// A 1980s handheld LCD game (wide screen, a parachute-rescue game), drawn from scratch as vector shapes; the badge says PLAY & WATCH / Magnetite and no
// real brand or artwork is used. It keeps its own colours (shell, face, buttons); the poster around it follows the theme.
const BANDS = 30;
const TAU = Math.PI * 2;
const SERIF = '"Times New Roman", Times, serif';
const MONO = "ui-monospace, 'SF Mono', Menlo, Consolas, monospace";

const LANES = [96, 188, 280]; // the three landing lanes, and the boat's stops
const HX = 330; // the helicopter
const HY = 36;
const STEP = 0.26; // seconds of one LCD frame
const FALL = 8; // frames of a fall
const WATER = 206; // top of the water, in the LCD's 412 x 266 space
const LCD_INK = '#1c1c18';
const LCD_1 = '#b4b09c';
const LCD_2 = '#d8d6c4';
const SEA = '#3f8fc0';
const PALM = '#2f8f4a';
const GHOST = 0.07;

const make = () => ({
    bars: new Bars(BANDS),
    ref: new Reference(),
    clock: 0,
    slow: 0.3,
    last: -9,
    n: 0,
    jumps: [],
    boat: 1,
    alarm: -9,
    l: 0,
    r: 0,
});

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

const mean = (v, from, to) => {
    let t = 0;
    for (let i = from; i < to; i++) t += v[i];

    return t / (to - from);
};

function label(g, text, x, y, { size = 10.5, weight = 400, font, color, align = 'left', spacing = 1.3 }) {
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

/** `text` cut with an ellipsis until it fits `max`. */
function cut(g, text, max, { size, weight = 400, font, spacing = 0 }) {
    g.font = `${weight} ${size}px ${font}`;
    if ('letterSpacing' in g) g.letterSpacing = `${spacing}px`;
    let s = text;
    while (s.length > 3 && g.measureText(s).width > max) s = `${s.slice(0, -2).trimEnd()}…`;
    if ('letterSpacing' in g) g.letterSpacing = '0px';

    return s;
}

function round(g, x, y, w, h, r) {
    g.beginPath();
    g.roundRect(x, y, w, h, r);
}

// ---- seven segments
const SEGMENTS = ['abcdef', 'bc', 'abdeg', 'abcdg', 'bcfg', 'acdfg', 'acdefg', 'abc', 'abcdefg', 'abcdfg'];

function digit(g, x, y, h, d, on, off) {
    const w = h * 0.55;
    const t = h * 0.13;
    const hh = h / 2;
    const bars = {
        a: [x + t, y, w - 2 * t, t],
        g: [x + t, y + hh - t / 2, w - 2 * t, t],
        d: [x + t, y + h - t, w - 2 * t, t],
        f: [x, y + t, t, hh - t * 1.3],
        b: [x + w - t, y + t, t, hh - t * 1.3],
        e: [x, y + hh + t * 0.3, t, hh - t * 1.3],
        c: [x + w - t, y + hh + t * 0.3, t, hh - t * 1.3],
    };
    for (const k of 'abcdefg') {
        g.fillStyle = SEGMENTS[d].includes(k) ? on : off;
        g.fillRect(...bars[k]);
    }
}

function clock(g, x, y, h, secs, on, off) {
    const m = Math.floor(secs / 60) % 100;
    const s = Math.floor(secs % 60);
    const w = h * 0.55;
    const gap = h * 0.2;
    [Math.floor(m / 10), m % 10, Math.floor(s / 10), s % 10].forEach((d, i) => digit(g, x + i * (w + gap) + (i > 1 ? gap * 1.4 : 0), y, h, d, on, off));
    const cx = x + 2 * (w + gap);
    g.fillStyle = on;
    g.fillRect(cx - 1.5, y + h * 0.3, 3, 3);
    g.fillRect(cx - 1.5, y + h * 0.65, 3, 3);
}

// ---- the LCD's figures (drawn in its own 412 x 266 space, with the fill and stroke set by the caller)
function parachutist(g, x, y, k, phase) {
    g.save();
    g.translate(x, y);
    if (k > 0) {
        g.beginPath();
        g.ellipse(0, -14, 14, 11, 0, Math.PI, 0);
        g.closePath();
        g.fill();
        g.lineWidth = 1.3;
        g.beginPath();
        g.moveTo(-14, -14);
        g.lineTo(-3, 0);
        g.moveTo(14, -14);
        g.lineTo(3, 0);
        g.moveTo(0, -14);
        g.lineTo(0, 0);
        g.stroke();
    }
    g.lineWidth = 2.6;
    g.beginPath();
    g.arc(0, 4, 3.6, 0, TAU);
    g.fill();
    g.beginPath();
    g.moveTo(0, 8);
    g.lineTo(0, 17);
    if (k > 0) {
        g.moveTo(-6, 11);
        g.lineTo(0, 9);
        g.lineTo(6, 11);
        g.moveTo(0, 17);
        g.lineTo(-4, 23 + phase);
        g.moveTo(0, 17);
        g.lineTo(4, 23 - phase);
    } else {
        g.moveTo(-9, 6);
        g.lineTo(0, 10);
        g.lineTo(9, 6 + phase * 2);
        g.moveTo(0, 17);
        g.lineTo(-7, 22);
        g.moveTo(0, 17);
        g.lineTo(8, 21);
    }
    g.stroke();
    g.restore();
}

function boat(g, x, y, phase) {
    g.beginPath();
    g.moveTo(x - 30, y);
    g.lineTo(x + 30, y);
    g.lineTo(x + 22, y + 11);
    g.lineTo(x - 22, y + 11);
    g.closePath();
    g.fill();
    g.beginPath();
    g.arc(x - 2, y - 14, 3.8, 0, TAU);
    g.fill();
    g.beginPath();
    g.moveTo(x - 2, y - 10);
    g.lineTo(x - 2, y - 2);
    g.moveTo(x - 2, y - 8);
    g.lineTo(x + 14, y - 14 + phase * 3);
    g.moveTo(x - 2, y - 8);
    g.lineTo(x - 14, y - 4 - phase * 3);
    g.stroke();
}

function shark(g, x, y, v) {
    const height = 6 + v * 14;
    g.beginPath();
    g.moveTo(x - 14, y);
    g.quadraticCurveTo(x - 4, y - height * 0.4, x + 2, y - height);
    g.quadraticCurveTo(x + 4, y - height * 0.3, x + 16, y);
    g.closePath();
    g.fill();
}

function helicopter(g, x, y, blade) {
    g.beginPath();
    g.ellipse(x, y, 24, 11, 0, 0, TAU);
    g.fill();
    g.beginPath();
    g.moveTo(x + 18, y - 4);
    g.lineTo(x + 46, y - 12);
    g.lineTo(x + 46, y - 4);
    g.lineTo(x + 20, y + 4);
    g.closePath();
    g.fill();
    g.lineWidth = 2.6;
    g.beginPath();
    g.moveTo(x, y - 11);
    g.lineTo(x, y - 17);
    if (blade) {
        g.moveTo(x - 36, y - 17);
        g.lineTo(x + 36, y - 17);
    } else {
        g.moveTo(x - 24, y - 19);
        g.lineTo(x + 30, y - 15);
    }
    g.moveTo(x - 14, y + 14);
    g.lineTo(x + 16, y + 14);
    g.moveTo(x - 8, y + 10);
    g.lineTo(x - 8, y + 14);
    g.moveTo(x + 8, y + 10);
    g.lineTo(x + 8, y + 14);
    g.stroke();
}

function palm(g, x, y, dir, sway) {
    g.save();
    g.strokeStyle = PALM;
    g.lineWidth = 7;
    g.beginPath();
    g.moveTo(x, 205);
    g.quadraticCurveTo(x + dir * 6, 140, x + dir * 2, y);
    g.stroke();
    g.lineWidth = 4;
    for (const a of [-1.2, -0.6, 0, 0.6, 1.2]) {
        const aa = a + sway * (a < 0 ? -1 : 1);
        g.beginPath();
        g.moveTo(x + dir * 2, y);
        g.quadraticCurveTo(x + dir * 2 + Math.sin(aa) * 26, y - 20 - Math.abs(Math.cos(aa)) * 6, x + dir * 2 + Math.sin(aa) * 40, y + 8 + Math.abs(aa) * 12);
        g.stroke();
    }
    g.restore();
}

/** The LCD itself, in its 412 x 266 space: the printed background, the faint positions, then what is lit. */
function lcd(g, st, v, rise, text, lossless, font, elapsed) {
    const t = st.clock;
    const high = mean(v, 18, BANDS);
    const frame = (j) => Math.floor((t - j.t0) / STEP);
    const where = (lane, k) => [HX + (LANES[lane] - HX) * Math.min(1, k / 3), 46 + k * 20];
    const sway = (rate, shift) => 0.12 * Math.sin(t * rate + shift) * high * 3;

    // What is printed under the glass: water and palms.
    g.save();
    g.globalAlpha = 0.5;
    g.fillStyle = SEA;
    g.fillRect(0, WATER, 412, 266 - WATER);
    g.strokeStyle = SEA;
    g.lineWidth = 2;
    g.globalAlpha = 0.9;
    for (let y = WATER + 6; y < 262; y += 12) {
        g.beginPath();
        for (let x = 0; x <= 412; x += 12) g.lineTo(x, y + ((x / 12) % 2 ? 2 : -2));
        g.stroke();
    }
    g.globalAlpha = 0.65;
    palm(g, 22, 112, 1, sway(7, 0));
    palm(g, 392, 150, -1, sway(6, 1));
    g.restore();

    // Every position that can light, faintly, as on the real thing.
    g.save();
    g.fillStyle = LCD_INK;
    g.strokeStyle = LCD_INK;
    g.lineCap = 'round';
    g.lineJoin = 'round';
    g.globalAlpha = GHOST;
    for (let l = 0; l < 3; l++) {
        for (let k = 0; k < FALL; k++) parachutist(g, ...where(l, k), k, 0);
        boat(g, LANES[l], WATER - 2, 0);
    }
    [90, 200, 310].forEach((x) => shark(g, x, 258, 0.5));
    helicopter(g, HX, HY, 1);
    helicopter(g, HX, HY, 0);
    g.restore();
    clock(g, 44, 8, 30, 0, 'rgba(0,0,0,0)', `rgba(0,0,0,${GHOST})`);

    // What is lit: the score is the time played.
    clock(g, 44, 8, 30, elapsed, LCD_INK, `rgba(0,0,0,${GHOST})`);
    g.fillStyle = LCD_INK;
    g.strokeStyle = LCD_INK;
    g.lineCap = 'round';
    g.lineJoin = 'round';
    g.lineWidth = 2.6;
    helicopter(g, HX, HY + (rise > 0.5 ? 2 : 0), Math.floor(t * 8) % 2);

    // The boat steps to the lane of the first parachutist that is about to land.
    const next = st.jumps.filter((j) => frame(j) >= 3).sort((a, b) => a.t0 - b.t0)[0];
    if (next) st.boat = next.lane;
    boat(g, LANES[st.boat], WATER - 2 + (st.jumps.some((j) => frame(j) >= FALL - 1 && j.lane === st.boat) ? 3 : 0), Math.floor(t * 5) % 2);

    for (const j of st.jumps) {
        const k = frame(j);
        if (k >= FALL - 1) continue;
        parachutist(g, ...where(j.lane, k), k, Math.floor(t * 6) % 2);
    }
    // Three fins in the water: the mids, in three groups.
    [90, 200, 310].forEach((x, i) => {
        const value = mean(v, 4 + i * 5, 9 + i * 5) * 1.3;
        if (value > 0.18) shark(g, x, 260, clamp01(value));
    });

    if (text) {
        label(g, 'AM', 24, 30, { size: 12, weight: 700, font, color: LCD_INK, spacing: 0.3 });
        // Both game names show faintly; the one for this file (A lossless, B lossy) is lit.
        const lit = { size: 11, weight: 700, font, color: LCD_INK, spacing: 0.3 };
        g.globalAlpha = 0.15;
        label(g, 'GAME A', 6, 262, lit);
        label(g, 'GAME B', 406, 262, { ...lit, align: 'right' });
        g.globalAlpha = 1;
        if (lossless) label(g, 'GAME A', 6, 262, lit);
        else label(g, 'GAME B', 406, 262, { ...lit, align: 'right' });
    }
}

/** The whole handheld, in its own 874 x 529 space. */
function device(g, st, v, rise, look, pal, say, text, lossless, font, elapsed) {
    const t = st.clock;
    const ink = pal.label;
    g.fillStyle = pal.shell;
    round(g, 0, 0, 874, 529, 16);
    g.fill();
    g.fillStyle = pal.face;
    round(g, 14, 26, 846, 474, 8);
    g.fill();
    g.fillStyle = pal.bezel;
    round(g, 172, 52, 520, 420, 14);
    g.fill();
    g.strokeStyle = 'rgba(0,0,0,0.18)';
    g.lineWidth = 2;
    g.stroke();
    // The black frame line, open where the two labels sit.
    g.strokeStyle = ink;
    g.lineWidth = 2.5;
    g.beginPath();
    g.moveTo(330, 97);
    g.lineTo(207, 97);
    g.arcTo(195, 97, 195, 109, 12);
    g.lineTo(195, 423);
    g.arcTo(195, 435, 207, 435, 12);
    g.lineTo(350, 435);
    g.moveTo(520, 435);
    g.lineTo(665, 435);
    g.arcTo(677, 435, 677, 423, 12);
    g.lineTo(677, 109);
    g.arcTo(677, 97, 665, 97, 12);
    g.lineTo(500, 97);
    g.stroke();
    if (text) {
        const top = cut(g, (say.title || say.name).toUpperCase(), 150, { size: 17, weight: 700, font, spacing: 1 });
        label(g, top, 436, 104, { size: 17, weight: 700, font, color: ink, align: 'center', spacing: 1 });
        const bottom = cut(g, (say.album || say.fmt).toUpperCase(), 160, { size: 17, weight: 700, font, spacing: 1 });
        label(g, bottom, 436, 442, { size: 17, weight: 700, font, color: ink, align: 'center', spacing: 1 });
    }

    // The screen's rim and the LCD.
    g.fillStyle = pal.shell;
    round(g, 210, 113, 452, 305, 16);
    g.fill();
    g.fillStyle = '#0d1b3a';
    round(g, 225, 127, 422, 276, 8);
    g.fill();
    g.save();
    g.translate(230, 132);
    round(g, 0, 0, 412, 266, 6);
    g.clip();
    g.fillStyle = LCD_2;
    g.fillRect(0, 0, 412, 266);
    g.fillStyle = LCD_1;
    g.globalAlpha = 0.45;
    g.fillRect(0, 0, 412, 70);
    g.fillRect(0, 205, 412, 61);
    g.globalAlpha = 1;
    lcd(g, st, v, rise, text, lossless, font, elapsed);
    g.fillStyle = 'rgba(255,255,255,0.12)';
    g.beginPath();
    g.moveTo(0, 0);
    g.lineTo(240, 0);
    g.lineTo(0, 156);
    g.closePath();
    g.fill();
    g.restore();

    // The badge.
    g.fillStyle = pal.badge;
    round(g, 38, 84, 106, 62, 6);
    g.fill();
    g.strokeStyle = '#1a1a1a';
    g.lineWidth = 1.5;
    g.stroke();
    if (text) {
        label(g, 'PLAY', 91, 113, { size: 26, weight: 800, font, color: '#1a1a1a', align: 'center', spacing: -1 });
        label(g, '& WATCH', 91, 138, { size: 22, weight: 800, font, color: '#1a1a1a', align: 'center', spacing: -0.5 });
        label(g, 'Magnetite', 91, 172, { size: 17, weight: 700, font: SERIF, color: '#1a1a1a', align: 'center', spacing: 0.3 });
    }

    // The three small buttons, and the lamps.
    [['GAME A', 72], ['GAME B', 132], ['TIME', 193]].forEach(([name, y]) => {
        g.fillStyle = pal.shell;
        round(g, 733, y - 20, 54, 38, 19);
        g.fill();
        g.fillStyle = pal.btn;
        round(g, 739, y - 14, 42, 26, 13);
        g.fill();
        if (text) label(g, name, 759, y + 38, { size: 13, weight: 600, font, color: ink, align: 'center', spacing: 0.4 });
    });
    [['ALARM', 102, t - st.alarm < 0.22], ['ACL', 162, lossless]].forEach(([name, y, on]) => {
        g.fillStyle = on ? pal.red : '#7d7d78';
        g.beginPath();
        g.arc(819, y, 8, 0, TAU);
        g.fill();
        g.strokeStyle = '#3a3a38';
        g.lineWidth = 2;
        g.stroke();
        if (text) label(g, name, 819, y + 24, { size: 10.5, weight: 600, font, color: ink, align: 'center', spacing: 0.4 });
    });

    // The two big buttons press with the left and right channel.
    [[98, st.l, '◄ LEFT', 100], [773, st.r, 'RIGHT ►', 773]].forEach(([cx, level, name, lx]) => {
        const press = level > 0.5 ? (level - 0.5) * 2 : 0;
        g.fillStyle = pal.shell;
        g.beginPath();
        g.arc(cx, 372, 41, 0, TAU);
        g.fill();
        g.fillStyle = 'rgba(0,0,0,0.25)';
        g.beginPath();
        g.arc(cx, 374, 32, 0, TAU);
        g.fill();
        g.fillStyle = pal.red;
        g.beginPath();
        g.arc(cx, 372 + press * 3, 31 - press * 1.5, 0, TAU);
        g.fill();
        g.fillStyle = 'rgba(255,255,255,0.28)';
        g.beginPath();
        g.ellipse(cx - 7, 362 + press * 3, 13, 8, -0.5, 0, TAU);
        g.fill();
        if (text) label(g, name, lx, 444, { size: 15, weight: 800, font, color: ink, align: 'center', spacing: 0.6 });
    });
}

/**
 * A 1980s handheld LCD game as a meter. On every bass onset the helicopter drops a parachutist who comes down in one of three lanes in eight steps,
 * like an LCD (every position shows faintly, as on the real thing), and the boat steps to the lane of the next one to land; the three shark fins are
 * three mid band groups, the palms shake with the highs, the helicopter bobs with the bass, the two red buttons press with the left and right channel,
 * ALARM lights on a strong onset, ACL for a lossless file (GAME A, the same), and the score in seven segments is the time played. The device keeps its
 * own colours; the track is printed in the two label gaps and around it.
 */
export default {
    id: 'poster-gamewatch',
    needs: ['bands', 'levels'],
    label: 'Poster: Play & Watch',
    params: {
        range: 40,
        attack: 0.07,
        release: 0.4,
        text: 1,
        theme: 1,
        paper: '#17120f',
        ink: '#ece3d0',
        shell: '#5a3320',
        face: '#c9a96a',
        bezel: '#d8bd85',
        btn: '#8a6b52',
        red: '#d8222a',
        label: '#1a1410',
        badge: '#dfe4e8',
    },

    draw(g, { bands, levels }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const st = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink']);
        const view = begin(g, w, h, look.paper, { p });
        const font = colors.font;
        const themed = Number(p.theme) >= 2;
        const pal = { shell: themed ? colors.accent2 : p.shell, face: p.face, bezel: p.bezel, btn: p.btn, red: themed ? colors.accent : p.red, label: p.label, badge: p.badge };

        const v = st.bars.update(bands, dt, p);
        const dl = toDb(levels.l.rms);
        const dr = toDb(levels.r.rms);
        st.ref.update(Math.max(dl, dr), dt);
        const nl = st.ref.norm(dl, 30);
        const nr = st.ref.norm(dr, 30);
        st.l = follow(st.l, nl, dt, nl > st.l ? p.attack : p.release);
        st.r = follow(st.r, nr, dt, nr > st.r ? p.attack : p.release);

        st.clock += dt;
        const t = st.clock;
        const bass = mean(st.bars.raw, 0, 4);
        const rise = mean(v, 0, 4);
        st.slow = follow(st.slow, bass, dt, 3.3);
        const onset = bass > st.slow * 1.22 + 0.04 && t - st.last > 0.42 && st.jumps.length < 6;
        // The game goes on in a quiet passage too, but not in silence.
        if (onset || (t - st.last > 2.2 && st.jumps.length < 2 && mean(v, 0, BANDS) > 0.05)) {
            st.last = t;
            st.n++;
            st.jumps.push({ t0: t, lane: (st.n * 5 + (st.n >> 1)) % 3 });
            if (onset && bass > 0.55) st.alarm = t;
        }
        st.jumps = st.jumps.filter((j) => t - j.t0 < STEP * FALL);

        const say = posterText(track);
        const length = track?.duration ?? 0;
        const elapsed = length ? clamp01(progress) * length : 0;
        const lossless = /flac|alac|wav|aiff|ape|wv/i.test(track?.codec ?? '');
        const ink = look.ink;
        const quiet = mixHex(look.ink, look.paper, 0.45);

        if (view.text) {
            label(g, 'MAGNETITE  ·  NOW PLAYING', 20, 44, { size: 11, font: MONO, color: quiet, spacing: 2 });
            label(g, say.no, 580, 44, { size: 11, font: MONO, color: quiet, align: 'right', spacing: 2 });
            label(g, cut(g, say.title || say.name, 560, { size: 36, weight: 700, font }), 20, 108, { size: 36, weight: 700, font, color: ink, spacing: -0.5 });
            if (say.title) label(g, say.name, 20, 140, { size: 18, font, color: quiet, spacing: 0.2 });
        }

        g.save();
        g.translate(20, 215);
        g.scale(560 / 874, 560 / 874);
        device(g, st, v, rise, look, pal, say, view.text, lossless, font, elapsed);
        g.restore();

        if (view.text) {
            const y = 622;
            const row = say.album ? `${say.album}${say.year ? ` · ${say.year}` : ''}` : '';
            label(g, cut(g, row, 560, { size: 15, font }), 20, y, { size: 15, font, color: ink, spacing: 0.2 });
            label(g, [say.fmt, say.dr].filter(Boolean).join('  ·  '), 20, y + 26, { size: 12, font: MONO, color: quiet, spacing: 1.5 });
            // An LCD-style progress bar of 40 cells.
            const cells = 40;
            const done = Math.floor(clamp01(progress) * cells);
            for (let i = 0; i < cells; i++) {
                g.fillStyle = i < done ? ink : mixHex(look.paper, look.ink, 0.14);
                g.fillRect(20 + i * 14, y + 52, 10, 12);
            }
            if (length) {
                label(g, mmss(elapsed), 20, y + 88, { size: 12, font: MONO, color: quiet, spacing: 1.5 });
                label(g, mmss(length), 580, y + 88, { size: 12, font: MONO, color: quiet, align: 'right', spacing: 1.5 });
            }
        }
        g.restore();
    },
};
