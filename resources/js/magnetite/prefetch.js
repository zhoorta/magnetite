/** Seconds before the end, or the share of the track played, at which the next track is fetched (whichever comes first). */
export const PREFETCH_SECONDS_LEFT = 45;
export const PREFETCH_SHARE = 0.7;

/** Whether it is time to ask the server to fetch the next track. Once per track, only while playing. */
export function shouldPrefetch({ playing, time, duration, next, already }) {
    if (!playing || !next || already === next.id) return false;
    if (!Number.isInteger(next.id)) return false; // the start page's demo tracks are not in the library
    if (!Number.isFinite(duration) || duration <= 0) return false;

    return time / duration >= PREFETCH_SHARE || duration - time <= PREFETCH_SECONDS_LEFT;
}

/** The prefetch address for a track, taken from its stream address (the saved queue only has that). */
export function prefetchUrl(track) {
    return track.url.replace(/\/stream\/(\d+)(\?.*)?$/, '/prefetch/$1');
}
