// Splits the one player canvas into cells, one per visible meter.

const TARGET_ASPECT = 1.6; // meters read best a little wider than tall
const RAGGED_PENALTY = 0.4;
const MAX_PANELS = 4;

/**
 * Cells for `n` panels on a `w` x `h` canvas, in rows. Picks the column count that makes the cells
 * closest to TARGET_ASPECT, so a wide dock puts meters side by side and a tall fullscreen stacks them.
 * A short last row stretches its cells to fill the width.
 */
export function layout(n, w, h) {
    let best = { cols: 1, score: Infinity };
    for (let cols = 1; cols <= n; cols++) {
        const rows = Math.ceil(n / cols);
        const ragged = n % cols === 0 ? 0 : RAGGED_PENALTY; // prefer even rows (2x2 over 3+1)
        const score = Math.abs(Math.log(w / cols / (h / rows) / TARGET_ASPECT)) + ragged;
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

export { MAX_PANELS };
