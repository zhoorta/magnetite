// Splits the one player canvas into cells, one per visible meter.

const TARGET_ASPECT = 1.6; // meters read best a little wider than tall
export const POSTER_ASPECT = 0.75; // a poster is 3:4, so posters read best side by side, not stacked
const RAGGED_PENALTY = 0.4;
const MAX_PANELS = 4;

/**
 * Cells for `n` panels on a `w` x `h` canvas, in rows. Picks the column count that makes the cells
 * closest to `aspect` (width over height; TARGET_ASPECT unless the panels are posters, see `aspectOf`), so a wide dock puts meters
 * side by side and a tall fullscreen stacks them. A short last row stretches its cells to fill the width.
 */
export function layout(n, w, h, aspect = TARGET_ASPECT) {
    let best = { cols: 1, score: Infinity };
    for (let cols = 1; cols <= n; cols++) {
        const rows = Math.ceil(n / cols);
        const ragged = n % cols === 0 ? 0 : RAGGED_PENALTY; // prefer even rows (2x2 over 3+1)
        const score = Math.abs(Math.log(w / cols / (h / rows) / aspect)) + ragged;
        if (score < best.score) best = { cols, score };
    }

    const { cols } = best;
    const rows = Math.ceil(n / cols);
    const cells = [];
    for (let r = 0; r < rows; r++) {
        const inRow = Math.min(cols, n - r * cols);
        for (let c = 0; c < inRow; c++) {
            cells.push({
                x: Math.round((c * w) / inRow),
                y: Math.round((r * h) / rows),
                w: Math.round(((c + 1) * w) / inRow) - Math.round((c * w) / inRow),
                h: Math.round(((r + 1) * h) / rows) - Math.round((r * h) / rows),
            });
        }
    }

    return cells;
}

/** The aspect the cells should have for these meter ids: the geometric mean of what each kind reads best at. */
export function aspectOf(ids) {
    if (!ids.length) return TARGET_ASPECT;
    const logs = ids.map((id) => Math.log(String(id).startsWith('poster-') ? POSTER_ASPECT : TARGET_ASPECT));

    return Math.exp(logs.reduce((a, b) => a + b, 0) / logs.length);
}

export { MAX_PANELS };
