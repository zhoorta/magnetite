/**
 * The library's sources: your own library, what everybody shares, and playlists. The component shows each one with the same tree,
 * tiles and track list; only the data and what you may do with it differ. No DOM; Node tests it.
 */

export const SOURCES = ['library', 'shared', 'playlists'];

export const SOURCE_LABELS = { library: 'Library', shared: 'Shared', playlists: 'Playlists' };

/** The source `delta` steps from `current`, wrapping round. */
export function stepSource(current, delta) {
    const at = SOURCES.indexOf(current);

    return SOURCES[(Math.max(0, at) + delta + SOURCES.length) % SOURCES.length];
}

/** Actions that change your own library: in the other sources they are refused (the ones that make sense there are handled apart). */
export const LIBRARY_ONLY = new Set(['mark', 'move', 'edit', 'details', 'cover', 'analyse', 'undo', 'mark-track']);

/**
 * What to hand to the player when track `i` of a list is played: only the tracks this person may play (`open !== false`), and the
 * position of the wanted one among them. A track that is not shared with you is skipped; `null` when nothing in the list can be played.
 * Asking for a track that is not playable starts at the first one that is (what Enter on a playlist does).
 */
export function playQueue(tracks, i) {
    const queue = tracks.filter((t) => t.open !== false);
    if (!queue.length) return null;
    const want = tracks[i];
    const at = want && want.open !== false ? queue.findIndex((t) => t.id === want.id) : 0;

    return { queue, index: Math.max(0, at) };
}

/** A copy of `list` with the item at `from` moved to `to` (both clamped); the same list when nothing moves. */
export function moveItem(list, from, to) {
    if (from < 0 || from >= list.length) return list;
    const target = Math.max(0, Math.min(list.length - 1, to));
    if (target === from) return list;
    const next = [...list];
    const [item] = next.splice(from, 1);
    next.splice(target, 0, item);

    return next;
}
