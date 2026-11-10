/**
 * What the lists and the player say about a file, from the `truth` the server sends with a library track (see Track::toTruth).
 * No DOM: Node tests it. `level` is the user's info setting: 0 nothing, 1 the format and DR line, 2 also the findings.
 */

export const INFO_LEVELS = 3;

const MINUS = '−'; // the typographic minus, as in the terminal client

/** "DR9 (fair)", or null when the file has not been measured. */
export function drText(truth) {
    if (!truth || truth.dr === null || truth.dr === undefined) return null;

    return `DR${truth.dr}${truth.dr_class ? ` (${truth.dr_class})` : ''}`;
}

/** "-10.9 LUFS" with a proper minus sign, or null. */
export function lufsText(truth) {
    const v = truth?.lufs;
    if (v === null || v === undefined) return null;

    return `${v < 0 ? MINUS : ''}${Math.abs(v).toFixed(1)} LUFS`;
}

/** "MP3 · 32-bit / 48 kHz": what is known without measuring is only the codec; the rest comes from the analysis. */
export function formatText(track) {
    const t = track?.truth;
    const bits = t?.declared_bits ?? t?.bits;
    const rate = t?.sample_rate;
    const khz = rate ? `${Number((rate / 1000).toFixed(1))} kHz` : null;
    const depth = bits && khz ? `${bits}-bit / ${khz}` : (khz ?? (bits ? `${bits}-bit` : null));

    return [track?.codec ? String(track.codec).toUpperCase() : null, depth].filter(Boolean).join(' · ');
}

/** The first line under the title: format, dynamic range, loudness. Empty when level 0. */
export function infoLine(track, level) {
    if (level < 1 || !track) return '';

    return [formatText(track), drText(track.truth), lufsText(track.truth)].filter(Boolean).join(' · ');
}

/** Findings worth a mark: problems and warnings, not notes. Worst first, at most `max`. */
export function findings(track, max = 2) {
    const flags = (track?.truth?.flags ?? []).filter((f) => f.level !== 'note');
    const rank = { problem: 0, warn: 1 };

    return [...flags].sort((a, b) => (rank[a.level] ?? 2) - (rank[b.level] ?? 2)).slice(0, max);
}

/** Does the track carry any problem or warning? (The "only problems" filter, and the mark in the lists.) */
export function hasProblem(track) {
    return findings(track, 1).length > 0;
}

/** Problems among an album's tracks. */
export function albumProblems(album) {
    return (album?.tracks ?? []).filter(hasProblem).length;
}

/** What the player shows under the title at this level: { line, findings }. Findings only at level 2. */
export function dockInfo(track, level) {
    return { line: infoLine(track, level), findings: level >= 2 ? findings(track, 2) : [] };
}

/** "track 6 of 11" when the queue is one album, else just the number when there is one. */
export function trackPosition(track, queue, index) {
    if (!track) return '';
    const sameAlbum = track.album_id && queue.length > 0 && queue.every((t) => t.album_id === track.album_id);
    if (sameAlbum) return `track ${index + 1} of ${queue.length}`;

    return track.edit?.track_number ? `track ${track.edit.track_number}` : '';
}
