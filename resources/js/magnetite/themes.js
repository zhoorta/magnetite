import { METER_SCHEMAS } from './meter-schemas.js';

// Themes as one JSON bundle: checking one that someone pasted or loaded, a safe id, the file to export, contrast. No DOM; Node tests it.

export const COLOR_KEYS = ['bg', 'surface', 'text', 'muted', 'accent', 'accent2'];
export const RADII = ['0rem', '0.15rem', '0.5rem', '0.75rem', '1rem'];
/** The font a theme gets when it names none: the system's sans. */
export const DEFAULT_FONT = 'ui-sans-serif, system-ui, sans-serif';

/**
 * The fonts the theme studio offers: [label, font-family stack, group]. Every group but "System" is bundled (declared in
 * `resources/css/fonts.css`, downloaded only when used); "System" is whatever the device has. A theme file stores the
 * stack, so a bundled name in an imported theme works too, and any other name falls back to the next in its stack.
 */
export const FONTS = [
    ['Inter', "'Inter', ui-sans-serif, system-ui, sans-serif", 'Sans'],
    ['Space Grotesk', "'Space Grotesk', ui-sans-serif, system-ui, sans-serif", 'Sans'],
    ['IBM Plex Sans', "'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif", 'Sans'],
    ['Outfit', "'Outfit', ui-sans-serif, system-ui, sans-serif", 'Sans'],
    ['Quicksand', "'Quicksand', ui-rounded, ui-sans-serif, system-ui, sans-serif", 'Rounded and display'],
    ['Righteous', "'Righteous', ui-sans-serif, system-ui, sans-serif", 'Rounded and display'],
    ['Fraunces', "'Fraunces', Georgia, 'Times New Roman', serif", 'Serif'],
    ['Lora', "'Lora', Georgia, 'Times New Roman', serif", 'Serif'],
    ['JetBrains Mono', "'JetBrains Mono', ui-monospace, 'SF Mono', Menlo, monospace", 'Mono'],
    ['IBM Plex Mono', "'IBM Plex Mono', ui-monospace, 'SF Mono', Menlo, monospace", 'Mono'],
    ['Courier Prime', "'Courier Prime', 'Courier New', Courier, monospace", 'Mono'],
    ['Share Tech Mono', "'Share Tech Mono', ui-monospace, 'SF Mono', Menlo, monospace", 'Mono'],
    ['VT323', "'VT323', ui-monospace, 'SF Mono', Menlo, monospace", 'Pixel and terminal'],
    ['Silkscreen', "'Silkscreen', ui-monospace, 'SF Mono', Menlo, monospace", 'Pixel and terminal'],
    ['Press Start 2P', "'Press Start 2P', ui-monospace, 'SF Mono', Menlo, monospace", 'Pixel and terminal'],
    ['Pixelify Sans', "'Pixelify Sans', ui-monospace, 'SF Mono', Menlo, monospace", 'Pixel and terminal'],
    ['System sans', DEFAULT_FONT, 'System'],
    ['Helvetica', "'Helvetica Neue', Helvetica, Arial, sans-serif", 'System'],
    ['System serif', "Georgia, 'Times New Roman', serif", 'System'],
    ['System mono', "ui-monospace, 'SF Mono', Menlo, monospace", 'System'],
    ['Courier', "'Courier New', ui-monospace, monospace", 'System'],
];

/** FONTS as [[group, [[label, stack], ...]], ...], in order, for the pickers. */
export const FONT_GROUPS = FONTS.reduce((groups, [label, stack, group]) => {
    const g = groups.find((x) => x[0] === group);
    g ? g[1].push([label, stack]) : groups.push([group, [[label, stack]]]);

    return groups;
}, []);

const HEX = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;
const RADIUS = /^\d{1,2}(\.\d{1,3})?(rem|px)$/;
const FONT = /^[\w\s,'"\-.]{1,160}$/;

/** "Late-night FM" -> "late-night-fm": lowercase letters, digits and hyphens. */
export function slug(text) {
    return String(text ?? '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
}

/** "#abc" -> "#aabbcc", any case -> lower. */
const fullHex = (c) => {
    const h = c.toLowerCase();

    return h.length === 4 ? `#${h[1]}${h[1]}${h[2]}${h[2]}${h[3]}${h[3]}` : h;
};

/**
 * A meter's params as the schema allows them: only its keys, numbers clamped to the range, colours as #rrggbb or null (follow the
 * theme). A value that does not fit is dropped, so the meter keeps its default.
 */
function cleanParams(id, params) {
    const schema = METER_SCHEMAS[id];
    const plain = Object.entries(params).filter(([, x]) => ['number', 'string', 'boolean'].includes(typeof x) || x === null);
    if (!schema) return Object.fromEntries(plain); // a meter without a schema (the picture scenes): plain values, as before

    const out = {};
    for (const { key, type, min, max } of schema) {
        const x = params[key];
        if (type === 'number' && typeof x === 'number' && Number.isFinite(x)) out[key] = Math.min(max, Math.max(min, x));
        else if (type === 'color' && x === null) out[key] = null;
        else if (type === 'color' && typeof x === 'string' && HEX.test(x)) out[key] = fullHex(x);
    }

    return out;
}

/** The meter looks a theme may carry: ids and checked parameters only. */
function cleanLooks(list) {
    return list
        .filter((v) => v && typeof v.id === 'string' && v.id.length <= 40)
        .map((v) => ({ id: v.id, params: cleanParams(v.id, v.params && typeof v.params === 'object' && !Array.isArray(v.params) ? v.params : {}) }));
}

/**
 * Check a theme object. Returns `{ theme }` (cleaned: only the known keys, colours as lower-case #rrggbb, an id that is not in
 * `taken`) or `{ error }` with a sentence to show. Nothing is trusted: the values end up in CSS variables and in a font-family.
 */
export function validateTheme(input, taken = []) {
    if (!input || typeof input !== 'object' || Array.isArray(input)) return { error: 'A theme is one JSON object.' };
    const name = typeof input.name === 'string' ? input.name.trim().slice(0, 40) : '';
    if (!name) return { error: 'The theme needs a "name".' };
    if (!input.colors || typeof input.colors !== 'object') return { error: 'The theme needs "colors".' };

    const colors = {};
    for (const key of COLOR_KEYS) {
        const c = input.colors[key];
        if (typeof c !== 'string' || !HEX.test(c)) return { error: `Colour "${key}" must be a hex colour like #1a2b3c.` };
        colors[key] = fullHex(c);
    }
    const radius = typeof input.radius === 'string' ? input.radius.trim() : '0.5rem';
    if (!RADIUS.test(radius)) return { error: 'The "radius" must be like 0.5rem or 8px.' };
    const font = typeof input.font === 'string' ? input.font.trim() : DEFAULT_FONT;
    if (!FONT.test(font)) return { error: 'The "font" may only hold letters, digits, spaces, commas, quotes, hyphens and dots.' };

    // The font of the text the meters draw (scales, labels, numbers); left out, they follow `font`.
    const meterFont = typeof input.meterFont === 'string' ? input.meterFont.trim() : '';
    if (meterFont && !FONT.test(meterFont)) return { error: 'The "meterFont" may only hold letters, digits, spaces, commas, quotes, hyphens and dots.' };

    let id = slug(input.id) || slug(name) || 'theme';
    for (let n = 2; taken.includes(id); n++) id = `${slug(input.id) || slug(name) || 'theme'}-${n}`.slice(0, 44);

    const theme = { id, name, colors, radius, font };
    if (meterFont) theme.meterFont = meterFont;
    if (Array.isArray(input.visualizers)) theme.visualizers = cleanLooks(input.visualizers);
    else if (input.visualizer && typeof input.visualizer === 'object') theme.visualizer = cleanLooks([input.visualizer])[0] ?? undefined;
    if (theme.visualizer === undefined) delete theme.visualizer;

    return { theme };
}

/** Text of a theme file (as typed or loaded) to a checked theme: `{ theme }` or `{ error }`. */
export function parseTheme(text, taken = []) {
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        return { error: 'That is not valid JSON.' };
    }

    return validateTheme(data, taken);
}

/** The JSON a theme is shared as: the same keys as themes.json, nothing local (no `custom` flag). */
export function exportTheme(theme) {
    const { id, name, colors, radius, font, meterFont, visualizer, visualizers } = theme;

    return JSON.stringify({ id, name, colors, radius, font, ...(meterFont ? { meterFont } : {}), ...(visualizers ? { visualizers } : visualizer ? { visualizer } : {}) }, null, 4);
}

/** WCAG contrast ratio of two hex colours (1 to 21). */
export function contrast(a, b) {
    const lum = (hex) => {
        const h = fullHex(hex).slice(1);
        const [r, g, bl] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));

        return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
    };
    const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
}
