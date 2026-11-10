import { findings, hasProblem } from './info.js';

/**
 * The truth view: every file of the library in one flat list, worst first, like the terminal client's table. No DOM; Node tests it.
 * A row is { track, album } (album is null for tracks without one).
 */

export const TRUTH_SORTS = ['findings', 'dr', 'loudness', 'peak', 'title'];

export const TRUTH_SORT_NAMES = {
    findings: 'findings, the worst first',
    dr: 'dynamic range, the most compressed first',
    loudness: 'loudness, the loudest first',
    peak: 'true peak, the highest first',
    title: 'title',
};

export const nextTruthSort = (sort) => TRUTH_SORTS[(TRUTH_SORTS.indexOf(sort) + 1) % TRUTH_SORTS.length];

/** How bad a track looks: a problem counts 100, a warning 10. Not measured is 0 (it has no findings to show). */
export function severity(track) {
    return (track?.truth?.flags ?? []).reduce((sum, f) => sum + (f.level === 'problem' ? 100 : f.level === 'warn' ? 10 : 0), 0);
}

const dr = (t) => (t.truth?.dr === null || t.truth?.dr === undefined ? null : t.truth.dr);

/** Ascending or descending on a number, the unmeasured always last. */
const byNumber = (get, dir) => (a, b) => {
    const x = get(a.track);
    const y = get(b.track);
    if (x === null || x === undefined) return y === null || y === undefined ? 0 : 1;
    if (y === null || y === undefined) return -1;

    return dir * (x - y);
};

export function truthRows(albums, loose, { sort = 'findings', only = false } = {}) {
    const rows = [
        ...albums.flatMap((a) => a.tracks.map((track) => ({ track, album: { id: a.id, title: a.title, artist: a.artist } }))),
        ...loose.map((track) => ({ track, album: null })),
    ].filter((r) => !only || hasProblem(r.track));

    const title = (a, b) => String(a.track.title).localeCompare(String(b.track.title));
    const compare = {
        findings: (a, b) => severity(b.track) - severity(a.track) || findings(b.track, 9).length - findings(a.track, 9).length || byNumber(dr, 1)(a, b) || title(a, b),
        dr: (a, b) => byNumber(dr, 1)(a, b) || title(a, b),
        loudness: (a, b) => byNumber((t) => t.truth?.lufs, -1)(a, b) || title(a, b),
        peak: (a, b) => byNumber((t) => t.truth?.true_peak, -1)(a, b) || title(a, b),
        title,
    }[sort] ?? title;

    return rows.sort(compare);
}
