import { albumCount, folderAndBelow, sortAlbums } from './rows.js';
import { hasProblem } from './info.js';

/**
 * What the right pane shows for a selected folder: its subfolders and albums as tiles, and totals for everything below it.
 * No DOM; Node tests it.
 */

/** Tiles of a folder: the back tile (to the parent folder), the subfolders (as the tree lists them), then its own albums in the chosen order. */
export function folderTiles(folders, albums, folderId, sort = 'library') {
    const subfolders = folders.filter((f) => f.parent_id === folderId).map((folder) => ({ kind: 'folder', key: `f${folder.id}`, folder, count: albumCount(folders, albums, folder.id) }));
    const own = sortAlbums(albums.filter((a) => a.folder_id === folderId), sort).map((album) => ({ kind: 'album', key: `a${album.id}`, album }));

    // A "back" tile first when the folder has a parent: it opens that parent.
    const parent = folders.find((f) => f.id === folderId)?.parent_id;
    const up = parent != null && folders.some((f) => f.id === parent) ? [{ kind: 'up', key: `f${parent}`, folder: folders.find((f) => f.id === parent) }] : [];

    return [...up, ...subfolders, ...own];
}

/** Totals for a folder and everything below it: albums, tracks, seconds, the mean DR of the measured albums, tracks with a finding. */
export function folderStats(folders, albums, folderId) {
    const ids = folderAndBelow(folders, folderId);
    const below = albums.filter((a) => ids.includes(a.folder_id));
    const drs = below.map((a) => a.dr).filter((d) => d !== null && d !== undefined);

    return {
        albums: below.length,
        tracks: below.reduce((n, a) => n + a.tracks.length, 0),
        seconds: below.reduce((n, a) => n + a.tracks.reduce((s, t) => s + (t.duration || 0), 0), 0),
        dr: drs.length ? Math.round(drs.reduce((s, d) => s + d, 0) / drs.length) : null,
        problems: below.reduce((n, a) => n + a.tracks.filter(hasProblem).length, 0),
    };
}

/** "9 h 20 min", "48 min", "0 min". */
export function durationText(seconds) {
    const min = Math.round((seconds || 0) / 60);

    return min >= 60 ? `${Math.floor(min / 60)} h ${String(min % 60).padStart(2, '0')} min` : `${min} min`;
}

/** The summary line: "12 albums · 134 tracks · 9 h 20 min", with the DR and the findings at the info levels that show them. */
export function summaryLine(stats, info) {
    const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
    const parts = [plural(stats.albums, 'album', 'albums'), plural(stats.tracks, 'track', 'tracks'), durationText(stats.seconds)];
    if (info > 0 && stats.dr !== null) parts.push(`mean DR${stats.dr}`);
    if (info > 1 && stats.problems > 0) parts.push(plural(stats.problems, 'track with findings', 'tracks with findings'));

    return parts.join(' · ');
}

/** The tile after moving `delta` in a grid with `cols` columns (clamped); left and right wrap onto the next row like reading. */
export function moveTile(at, delta, count) {
    return count === 0 ? 0 : Math.max(0, Math.min(count - 1, at + delta));
}
