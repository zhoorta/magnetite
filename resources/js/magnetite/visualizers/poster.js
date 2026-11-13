// Shared pieces of the poster meters (docs/posters.md): the poster's own 600 x 800 space, band groups with ballistics, the colours (the poster's own, or
// the theme's), the track's text. Imports carry their file extension so Node can test this file.

import { toDb, clamp01 } from './meter.js';
import { formatText, drText } from '../dense/info.js';

export const W = 600;
export const H = 800;

/** Below this scale (poster pixels per cell pixel) the text would be unreadable, so only the shapes are drawn. */
const TEXT_MIN_SCALE = 0.3;

/** `n` groups of the 31 third-octave bands (in dB), each the loudest band it covers, as 0..1 between `min` and `max` dB. */
export function groupBands(bands, n, min, max, out = new Float32Array(n)) {
    const count = bands.length;
    for (let i = 0; i < n; i++) {
        const from = Math.floor((i * count) / n);
        const to = Math.max(from + 1, Math.floor(((i + 1) * count) / n));
        let loudest = -Infinity;
        for (let k = from; k < to && k < count; k++) if (bands[k] > loudest) loudest = bands[k];
        out[i] = clamp01((loudest - min) / (max - min));
    }

    return out;
}

/** One step towards `target`: about 99% of the way after `seconds`. */
export function follow(value, target, dt, seconds) {
    return value + (target - value) * (1 - Math.exp(-dt / (Math.max(seconds, 0.001) / 4.6)));
}

/** A linear RMS as 0..1 between `min` and `max` dBFS. */
export function level01(rms, min, max) {
    return clamp01((toDb(rms) - min) / (max - min));
}

/** Band groups that rise and fall like a meter: `v` holds the smoothed 0..1 values. */
export class Bars {
    constructor(n) {
        this.n = n;
        this.raw = new Float32Array(n);
        this.v = new Float32Array(n);
    }

    update(bands, dt, { min, max, attack, release }) {
        groupBands(bands, this.n, min, max, this.raw);
        for (let i = 0; i < this.n; i++) this.v[i] = follow(this.v[i], this.raw[i], dt, this.raw[i] > this.v[i] ? attack : release);

        return this.v;
    }
}

/** What each colour of a poster becomes when it follows the theme. */
const THEMED = {
    paper: (c) => c.bg,
    bg: (c) => c.bg,
    ink: (c) => c.text,
    dark: (c) => c.surface,
    a: (c) => c.accent,
    b: (c) => c.accent2,
    c: (c) => c.text,
};

/** The colours a theme level 1 takes from the theme: the paper, the ink and the dark panel; the accents stay the poster's own. */
const NEUTRAL = new Set(['paper', 'ink', 'dark']);

/**
 * The colours for `keys`. `params.theme`: 0 the poster's own colours, 1 paper, ink and dark panel from the theme (accents stay), 2 everything from
 * the theme. A colour left null follows the theme at any level.
 */
export function palette(params, colors, keys) {
    const level = Number(params.theme) || 0;
    const out = {};
    for (const key of keys) out[key] = level >= 2 || !params[key] || (level >= 1 && NEUTRAL.has(key)) ? THEMED[key](colors) : params[key];

    return out;
}

/** Is this #rgb or #rrggbb colour dark? Anything else counts as light. */
export function isDark(hex) {
    const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex).trim());
    if (!m) return false;
    const h = m[1].length === 3 ? [...m[1]].map((c) => c + c).join('') : m[1];
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));

    return 0.2126 * r + 0.7152 * g + 0.0722 * b < 128;
}

/** Relative luminance, 0 (black) to 1 (white), of a #rgb or #rrggbb colour; anything else counts as mid grey. */
export function luminance(hex) {
    const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex).trim());
    if (!m) return 0.5;
    const h = m[1].length === 3 ? [...m[1]].map((c) => c + c).join('') : m[1];
    const [r, g, b] = [0, 2, 4].map((i) => {
        const v = parseInt(h.slice(i, i + 2), 16) / 255;

        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Of `candidates`, the colour that reads best on `bg` (the highest contrast ratio). */
export function readableOn(bg, candidates) {
    const lb = luminance(bg);
    const ratio = (c) => (Math.max(lb, luminance(c)) + 0.05) / (Math.min(lb, luminance(c)) + 0.05);

    return candidates.reduce((best, c) => (ratio(c) > ratio(best) ? c : best));
}

/** The darkest of the colours. */
export const darkest = (...colors) => colors.reduce((best, c) => (luminance(c) < luminance(best) ? c : best));

/** How shapes on this paper add up where they overlap: darker on light paper (like ink), lighter on dark paper. */
export const overlap = (paper) => (isDark(paper) ? 'screen' : 'multiply');

/** "m:ss", or an empty text for an unknown length. */
const clock = (seconds) => (seconds ? `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}` : '');

/** The words a poster may print for a track (empty where unknown); with no artist the title becomes the name. */
export function posterText(track) {
    const t = track ?? {};
    const name = t.artist || t.title || 'magnetite';

    return {
        name,
        title: t.artist ? (t.title ?? '') : '',
        album: t.album ?? '',
        year: t.year ? String(t.year) : '',
        no: t.number ? String(t.number).padStart(2, '0') : '',
        dur: clock(t.duration),
        fmt: formatText(t),
        dr: (drText(t.truth) ?? '').split(' ')[0],
    };
}

/** The small three-column strip: year and number, album, format and DR; empty lines are left out. */
export function metaColumns(say) {
    const cols = [[say.year, [say.no, say.dur].filter(Boolean).join(' · ')], [say.album], [say.fmt, say.dr]];

    return cols.map((lines) => lines.filter(Boolean));
}

/**
 * Fills the cell with the paper colour and opens the poster's own 600 x 800 space, as large as fits and centred; returns the scale.
 * The caller closes it with `g.restore()`.
 */
export function begin(g, w, h, paper) {
    const scale = Math.min(w / W, h / H);
    g.fillStyle = paper;
    g.fillRect(0, 0, w, h);
    g.save();
    g.translate((w - W * scale) / 2, (h - H * scale) / 2);
    g.scale(scale, scale);
    g.beginPath();
    g.rect(0, 0, W, H);
    g.clip();

    return scale;
}

/** Text is on when the poster asks for it and the cell is big enough to read it. */
export const showText = (params, scale) => params.text >= 1 && scale >= TEXT_MIN_SCALE;

const spaced = (g, px) => {
    if ('letterSpacing' in g) g.letterSpacing = `${px}px`;
};

/** The largest size from `size` down to `min` at which `text` fits `maxW`; leaves the font set. */
export function fitSize(g, text, weight, font, maxW, size, min = 10) {
    g.font = `${weight} ${size}px ${font}`;
    while (size > min && g.measureText(text).width > maxW) {
        size -= 2;
        g.font = `${weight} ${size}px ${font}`;
    }

    return size;
}

/** One line of big text, shrunk to fit. */
export function drawName(g, text, x, y, { font, maxW, size, color, weight = 700, caps = false, spacing = 0, align = 'left' }) {
    if (!text) return 0;
    spaced(g, spacing);
    const t = caps ? text.toUpperCase() : text;
    const fs = fitSize(g, t, weight, font, maxW, size);
    g.fillStyle = color;
    g.textAlign = align;
    g.textBaseline = 'alphabetic';
    g.fillText(t, x, y);
    spaced(g, 0);
    g.textAlign = 'left';

    return fs;
}

/** Small columns at `xs`, each line cut with an ellipsis before it reaches the next column. */
export function drawColumns(g, cols, y, { font, color, size = 14, xs = [28, W * 0.34, W * 0.7] }) {
    g.font = `500 ${size}px ${font}`;
    g.fillStyle = color;
    g.textAlign = 'left';
    g.textBaseline = 'alphabetic';
    cols.forEach((lines, i) => lines.forEach((line, k) => {
        const maxW = (xs[i + 1] ?? W - 20) - xs[i] - 12;
        let text = line;
        while (text.length > 3 && g.measureText(text).width > maxW) text = text.slice(0, -2);
        g.fillText(text === line ? text : `${text.trimEnd()}…`, xs[i], y + k * (size + 4));
    }));
}
