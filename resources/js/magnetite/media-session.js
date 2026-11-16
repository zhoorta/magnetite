/**
 * What the Media Session API (lock screen, headset buttons, notification, desktop media keys) is told about the player.
 * Pure: Node tests it (tests/js/media-session.test.mjs); the wiring is `syncMedia()` and `mountMediaSession()` in boot.js.
 */

export const SEEK_STEP = 10; // seconds, for seekbackward / seekforward without an offset

/** Title, artist, album and the cover (made absolute: the lock screen fetches it outside the page). Null without a track. */
export function metadataFor(track, base = 'http://localhost/') {
    if (!track) return null;

    let artwork = [];
    if (track.cover) {
        try {
            artwork = [{ src: new URL(track.cover, base).href }]; // no sizes or type: the browser works them out from the image
        } catch {
            artwork = [];
        }
    }

    return {
        title: track.title ?? '',
        artist: track.artist ?? '',
        album: [track.album, track.year].filter(Boolean).join(' · '),
        artwork,
    };
}

/** setPositionState throws on an unknown or infinite duration, or a position past it; null means "do not call it". */
export function positionState({ duration, time, rate = 1 }) {
    if (!Number.isFinite(duration) || duration <= 0) return null;

    return { duration, position: Math.min(Math.max(0, time || 0), duration), playbackRate: rate > 0 ? rate : 1 };
}

/** The seek target for a seekto / seekbackward / seekforward action, kept inside the track; null when it makes no sense. */
export function seekTarget(action, { time, duration }) {
    if (!Number.isFinite(duration) || duration <= 0) return null;

    const by = Number.isFinite(action.seekOffset) ? action.seekOffset : SEEK_STEP;
    let to;
    if (action.action === 'seekto') to = action.seekTime;
    else if (action.action === 'seekbackward') to = (time || 0) - by;
    else if (action.action === 'seekforward') to = (time || 0) + by;
    if (!Number.isFinite(to)) return null;

    return Math.min(Math.max(0, to), duration);
}
