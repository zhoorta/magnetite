const SMALL = new Set(['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'vs', 'via']);

/** "the book i read" -> "The Book I Read": the first and last word always get a capital; small words in between stay small. */
export function titleCase(text) {
    const words = String(text ?? '').split(/(\s+)/);
    const real = words.map((w, i) => [w, i]).filter(([w]) => w.trim() !== '');
    const first = real[0]?.[1];
    const last = real.at(-1)?.[1];

    return words.map((w, i) => {
        if (w.trim() === '') return w;
        const bare = w.toLowerCase();
        if (i !== first && i !== last && SMALL.has(bare)) return bare;
        // keep what is already shouted (an acronym) or mixed (McCartney, iPhone): only plain lower case is capitalised
        if (w !== bare) return w;

        return bare.replace(/(^|[-(/])(\p{L})/gu, (m, pre, ch) => pre + ch.toUpperCase());
    }).join('');
}
