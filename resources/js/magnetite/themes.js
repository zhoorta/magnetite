// Themes as one JSON bundle: checking one that someone pasted or loaded, a safe id, the file to export, contrast. No DOM; Node tests it.

export const COLOR_KEYS = ['bg', 'surface', 'text', 'muted', 'accent', 'accent2'];
export const RADII = ['0rem', '0.15rem', '0.5rem', '0.75rem', '1rem'];
export const FONTS = [
    ['Space Grotesk', "'Space Grotesk', ui-sans-serif, system-ui, sans-serif"],
    ['System', 'ui-sans-serif, system-ui, sans-serif'],
    ['Serif', "Georgia, 'Times New Roman', serif"],
    ['Mono', "'Courier New', ui-monospace, monospace"],
];

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

/** The meter looks a theme may carry: ids and plain parameters only. */
function cleanLooks(list) {
    return list
        .filter((v) => v && typeof v.id === 'string' && v.id.length <= 40)
        .map((v) => ({
            id: v.id,
            params: Object.fromEntries(Object.entries(v.params && typeof v.params === 'object' ? v.params : {}).filter(([, x]) => ['number', 'string', 'boolean'].includes(typeof x) || x === null)),
        }));
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
    const font = typeof input.font === 'string' ? input.font.trim() : FONTS[1][1];
    if (!FONT.test(font)) return { error: 'The "font" may only hold letters, digits, spaces, commas, quotes, hyphens and dots.' };

    let id = slug(input.id) || slug(name) || 'theme';
    for (let n = 2; taken.includes(id); n++) id = `${slug(input.id) || slug(name) || 'theme'}-${n}`.slice(0, 44);

    const theme = { id, name, colors, radius, font };
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
    const { id, name, colors, radius, font, visualizer, visualizers } = theme;

    return JSON.stringify({ id, name, colors, radius, font, ...(visualizers ? { visualizers } : visualizer ? { visualizer } : {}) }, null, 4);
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
