// The meters draw text on a canvas, and a canvas does not start a font download by itself (CSS only does it for text in the
// page). Asking the font loader for the first family of a stack makes a bundled font arrive, so the meters stop falling
// back after a moment; the next frame draws with it.

/** The first family of a font-family stack, without quotes: "'Inter', sans-serif" -> "Inter"; null if there is none. */
export function firstFamily(stack) {
    const m = String(stack ?? '').match(/^\s*(?:'([^']+)'|"([^"]+)"|([\w -]+?))\s*(?:,|$)/);

    return m ? (m[1] ?? m[2] ?? m[3]).trim() : null;
}

const asked = new Set();

/** Start loading the first family of `stack` (once). Safe where there is no document or font loader. */
export function loadFont(stack) {
    const family = firstFamily(stack);
    if (!family || asked.has(family) || typeof document === 'undefined' || !document.fonts?.load) return;

    asked.add(family);
    document.fonts.load(`16px "${family}"`, 'Aa0-+').catch(() => asked.delete(family));
}
