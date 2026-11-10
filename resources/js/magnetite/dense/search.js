/**
 * The library's search results, as the server sends them: `{ albums, tracks, page, more }`. No DOM; Node tests it.
 */

export const emptySearch = () => ({ q: '', albums: [], tracks: [], page: 0, more: { albums: false, tracks: false }, loading: false, error: '' });

/** One flat list for the pane: the albums first, then the tracks. */
export function searchItems(s) {
    return [...s.albums.map((album) => ({ kind: 'album', key: `a${album.id}`, album })), ...s.tracks.map((track) => ({ kind: 'track', key: `t${track.id}`, track }))];
}

/** Add the next page of results to what is shown, without a hit twice. */
export function appendPage(s, data) {
    const have = (list, id) => list.some((x) => x.id === id);
    const albums = [...s.albums, ...data.albums.filter((a) => !have(s.albums, a.id))];
    const tracks = [...s.tracks, ...data.tracks.filter((t) => !have(s.tracks, t.id))];

    return { ...s, albums, tracks, page: data.page, more: data.more };
}

/** Is there another page worth asking for when the cursor is `at` of `total` items? */
export function wantsMore(s, at, total, margin = 5) {
    return !s.loading && (s.more.albums || s.more.tracks) && total - at <= margin;
}

/** The queue for playing from a track hit: the track hits only, from that one on. Returns [tracks, index]. */
export function queueFrom(s, trackId) {
    const index = s.tracks.findIndex((t) => t.id === trackId);

    return [s.tracks, Math.max(0, index)];
}
