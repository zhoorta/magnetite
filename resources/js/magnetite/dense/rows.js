/**
 * The dense library's row model: no DOM, no Alpine, so Node can test it. The tree on the left is one flat list of rows:
 * folders and albums in the order they are shown, children under their open folder, then "Without album tag".
 *
 * Folders come as the server's flat list ({ id, parent_id, name, depth }, in tree order); albums carry `folder_id`.
 */

export const SORTS = ['library', 'title', 'year', 'dr'];

const norm = (s) => String(s ?? '').toLocaleLowerCase();

/** Albums of one level in the chosen order. 'library' keeps the server's order (artist, year, title); the others are stable. */
export function sortAlbums(albums, sort = 'library') {
    if (sort === 'title') return [...albums].sort((a, b) => norm(a.title).localeCompare(norm(b.title)));
    if (sort === 'year') return [...albums].sort((a, b) => (a.year ?? 9999) - (b.year ?? 9999));
    // the most compressed first: the albums worth a look; not measured ones go last
    if (sort === 'dr') return [...albums].sort((a, b) => (a.dr ?? 999) - (b.dr ?? 999));

    return albums;
}

/** The sort after `sort`, wrapping round; `withDr: false` skips the DR order (when DR is not shown there is nothing to see in it). */
export function nextSort(sort, { withDr = true } = {}) {
    const list = withDr ? SORTS : SORTS.filter((x) => x !== 'dr');

    return list[(list.indexOf(sort) + 1) % list.length];
}

/** Ids of a folder and everything below it. */
export function folderAndBelow(folders, id) {
    const ids = [id];
    for (const f of folders) if (ids.includes(f.parent_id)) ids.push(f.id);

    return ids;
}

/** The folder and the folders around it, from the nearest outwards (to open the way to an album). */
export function ancestorIds(folders, id) {
    const ids = [];
    for (let f = folders.find((x) => x.id === id); f; f = folders.find((x) => x.id === f.parent_id)) ids.push(f.id);

    return ids;
}

/** "Jazz / ECM": the names of a folder and the ones around it, outermost first; empty at the top level. */
export function folderPath(folders, id) {
    return ancestorIds(folders, id).reverse().map((i) => folders.find((f) => f.id === i).name).join(' / ');
}

/** The mean DR of the measured albums in a folder and below, rounded; null when none is measured. */
export function folderDr(folders, albums, id) {
    const ids = folderAndBelow(folders, id);
    const drs = albums.filter((a) => ids.includes(a.folder_id) && a.dr !== null && a.dr !== undefined).map((a) => a.dr);

    return drs.length ? Math.round(drs.reduce((sum, v) => sum + v, 0) / drs.length) : null;
}

/** Albums in a folder and in the folders below it. */
export function albumCount(folders, albums, id) {
    const ids = folderAndBelow(folders, id);

    return albums.filter((a) => ids.includes(a.folder_id)).length;
}

/**
 * @param {{ folders: object[], albums: object[], loose?: object[] }} lib
 * @param {Set<number>} open ids of the open folders
 * @param {string} sort one of SORTS
 * @param {?function(object): boolean} only keep only the albums this accepts (and the folders that hold some)
 * @param {boolean} flat no folders: every album in one list, in the chosen order (`path` says which folder it is in)
 * @returns {object[]} rows: { kind: 'folder' | 'album' | 'loose', key, id, depth, folder?, album?, count? }
 */
export function buildRows({ folders, albums: all, loose = [] }, open, sort = 'library', only = null, flat = false) {
    const albums = only ? all.filter(only) : all;
    if (flat) {
        const rows = sortAlbums(albums, sort).map((a) => ({ kind: 'album', key: `a${a.id}`, id: a.id, depth: 0, album: a, path: folderPath(folders, a.folder_id) }));
        if (loose.length) rows.push({ kind: 'loose', key: 'loose', id: null, depth: 0, count: loose.length });

        return rows;
    }
    const childFolders = new Map();
    for (const f of folders) childFolders.set(f.parent_id ?? 0, [...(childFolders.get(f.parent_id ?? 0) ?? []), f]);
    const albumsIn = new Map();
    for (const a of albums) albumsIn.set(a.folder_id ?? 0, [...(albumsIn.get(a.folder_id ?? 0) ?? []), a]);

    const rows = [];
    const walk = (parent, depth) => {
        for (const f of childFolders.get(parent) ?? []) {
            const count = albumCount(folders, albums, f.id);
            if (only && count === 0) continue;
            rows.push({ kind: 'folder', key: `f${f.id}`, id: f.id, depth, folder: f, count, dr: folderDr(folders, albums, f.id) });
            if (open.has(f.id)) walk(f.id, depth + 1);
        }
        for (const a of sortAlbums(albumsIn.get(parent) ?? [], sort)) rows.push({ kind: 'album', key: `a${a.id}`, id: a.id, depth, album: a });
    };
    walk(0, 0);
    if (loose.length) rows.push({ kind: 'loose', key: 'loose', id: null, depth: 0, count: loose.length });

    return rows;
}

export const clamp = (rows, i) => Math.max(0, Math.min(rows.length - 1, i));

/** Index of the folder row that holds row `i` (-1 at the top level). */
export function parentIndex(rows, i) {
    const row = rows[i];
    if (!row || row.kind === 'loose') return -1;
    const parent = row.kind === 'folder' ? row.folder.parent_id : row.album.folder_id;
    if (parent === null || parent === undefined) return -1;

    return rows.findIndex((r) => r.kind === 'folder' && r.id === parent);
}

export const rowOfAlbum = (rows, albumId) => rows.findIndex((r) => r.kind === 'album' && r.id === albumId);

/** What l (or →) does: open a closed folder, otherwise go on to the tracks. */
export function openAction(rows, i, open) {
    const row = rows[i];
    if (row?.kind === 'folder' && !open.has(row.id)) return 'open-folder';

    return row && row.kind !== 'folder' ? 'tracks' : 'none';
}

/** What h (or ←) does: close an open folder, otherwise go to the folder above. */
export function closeAction(rows, i, open) {
    const row = rows[i];
    if (row?.kind === 'folder' && open.has(row.id)) return 'close-folder';

    return parentIndex(rows, i) >= 0 ? 'parent' : 'none';
}

/** The places an album (or a folder) can be moved to: the top level, then every folder in tree order, minus `exclude`. */
export function folderChoices(folders, exclude = []) {
    return [{ id: null, name: '(top level)', depth: 0 }, ...folders.filter((f) => !exclude.includes(f.id)).map((f) => ({ id: f.id, name: f.name, depth: f.depth }))];
}

/** The albums an action works on: the marked ones, else the selected row (a folder means every album in it). */
export function targetAlbums(rows, cur, marks, folders, albums) {
    const marked = albums.filter((a) => marks.has(a.id));
    if (marked.length) return marked;
    const row = rows[cur];
    if (row?.kind === 'album') return [row.album];
    if (row?.kind === 'folder') {
        const ids = folderAndBelow(folders, row.id);

        return albums.filter((a) => ids.includes(a.folder_id));
    }

    return [];
}
