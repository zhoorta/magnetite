import test from 'node:test';
import assert from 'node:assert/strict';
import { buildRows, folderDr, sortAlbums, nextSort, parentIndex, openAction, closeAction, folderChoices, targetAlbums, folderAndBelow, ancestorIds, albumCount, rowOfAlbum } from '../../resources/js/magnetite/dense/rows.js';

const folders = [
    { id: 1, parent_id: null, name: 'Rock', depth: 1 },
    { id: 2, parent_id: 1, name: 'New Wave', depth: 2 },
    { id: 3, parent_id: null, name: 'Jazz', depth: 1 },
];
const albums = [
    { id: 10, title: 'Remain in Light', year: 1980, folder_id: 2 },
    { id: 11, title: 'Fear of Music', year: 1979, folder_id: 2 },
    { id: 12, title: 'Pornography', year: 1982, folder_id: 1 },
    { id: 13, title: 'Kind of Blue', year: 1959, folder_id: 3 },
    { id: 14, title: 'Demo', year: null, folder_id: null },
];
const lib = { folders, albums, loose: [{ id: 99 }] };
const keys = (rows) => rows.map((r) => r.key);

test('closed folders show only themselves, then the top-level albums and the loose row', () => {
    assert.deepEqual(keys(buildRows(lib, new Set())), ['f1', 'f3', 'a14', 'loose']);
});

test('an open folder shows its subfolders first, then its albums, one level deeper', () => {
    const rows = buildRows(lib, new Set([1, 2]));
    assert.deepEqual(keys(rows), ['f1', 'f2', 'a10', 'a11', 'a12', 'f3', 'a14', 'loose']);
    assert.deepEqual(rows.map((r) => r.depth), [0, 1, 2, 2, 1, 0, 0, 0]);
});

test('a folder counts the albums below it, subfolders included', () => {
    const rows = buildRows(lib, new Set());
    assert.equal(rows[0].count, 3);
    assert.equal(rows[1].count, 1);
    assert.equal(albumCount(folders, albums, 2), 2);
});

test('no loose tracks, no loose row; an empty library has no rows', () => {
    assert.deepEqual(keys(buildRows({ ...lib, loose: [] }, new Set())), ['f1', 'f3', 'a14']);
    assert.deepEqual(buildRows({ folders: [], albums: [] }, new Set()), []);
});

test('sorting keeps each album in its own folder and leaves the server order alone by default', () => {
    const rows = buildRows(lib, new Set([2, 1]), 'year');
    assert.deepEqual(keys(rows).filter((k) => k.startsWith('a')), ['a11', 'a10', 'a12', 'a14']);
    assert.deepEqual(sortAlbums(albums, 'library'), albums);
    assert.deepEqual(sortAlbums(albums, 'title').map((a) => a.id), [14, 11, 13, 12, 10]);
    assert.deepEqual(sortAlbums(albums, 'year').map((a) => a.id), [13, 11, 10, 12, 14], 'no year goes last');
});

test('the sort cycles and wraps, and can leave the DR order out', () => {
    assert.equal(nextSort('library'), 'title');
    assert.equal(nextSort('title'), 'year');
    assert.equal(nextSort('year'), 'dr');
    assert.equal(nextSort('dr'), 'library');
    assert.equal(nextSort('year', { withDr: false }), 'library');
});

test('the DR order puts the most compressed first and the unmeasured last', () => {
    const list = [{ id: 1, dr: 12 }, { id: 2, dr: null }, { id: 3, dr: 6 }, { id: 4, dr: 9 }];
    assert.deepEqual(sortAlbums(list, 'dr').map((a) => a.id), [3, 4, 1, 2]);
});

test('a folder shows the mean DR of the measured albums below it', () => {
    const measured = [{ id: 10, dr: 8, folder_id: 2 }, { id: 11, dr: 11, folder_id: 2 }, { id: 12, dr: null, folder_id: 1 }, { id: 13, dr: 14, folder_id: 3 }];
    assert.equal(folderDr(folders, measured, 2), 10); // 9.5 rounds up
    assert.equal(folderDr(folders, measured, 1), 10); // New Wave counts for Rock; the unmeasured one does not
    assert.equal(folderDr(folders, [{ id: 1, dr: null, folder_id: 3 }], 3), null);
    const rows = buildRows({ folders, albums: measured }, new Set());
    assert.equal(rows[0].dr, 10);
});

test('the filter keeps the albums it accepts and only the folders that hold some', () => {
    const rows = buildRows(lib, new Set([1, 2]), 'library', (a) => a.id === 10);
    assert.deepEqual(keys(rows), ['f1', 'f2', 'a10', 'loose']);
    assert.deepEqual(keys(buildRows({ ...lib, loose: [] }, new Set([1, 2, 3]), 'library', (a) => a.id === 13)), ['f3', 'a13']);
    assert.deepEqual(keys(buildRows({ ...lib, loose: [] }, new Set(), 'library', () => false)), []);
});

test('parentIndex finds the folder row above, or -1 at the top', () => {
    const rows = buildRows(lib, new Set([1, 2]));
    assert.equal(parentIndex(rows, 2), 1); // Remain in Light -> New Wave
    assert.equal(parentIndex(rows, 1), 0); // New Wave -> Rock
    assert.equal(parentIndex(rows, 0), -1);
    assert.equal(parentIndex(rows, 6), -1); // top-level album
    assert.equal(parentIndex(rows, 7), -1); // loose row
});

test('l opens a closed folder, else goes to the tracks; h closes an open folder, else goes up', () => {
    const open = new Set([1, 2]);
    const rows = buildRows(lib, open);
    assert.equal(openAction(rows, 5, open), 'open-folder'); // Jazz, closed
    assert.equal(openAction(rows, 0, open), 'none'); // Rock, already open
    assert.equal(openAction(rows, 2, open), 'tracks');
    assert.equal(closeAction(rows, 0, open), 'close-folder');
    assert.equal(closeAction(rows, 2, open), 'parent');
    assert.equal(closeAction(rows, 6, open), 'none');
    assert.equal(closeAction(rows, 5, open), 'none'); // closed top-level folder
});

test('a folder cannot be moved into itself or below: the choices leave those out', () => {
    const exclude = folderAndBelow(folders, 1);
    assert.deepEqual(exclude, [1, 2]);
    assert.deepEqual(folderChoices(folders, exclude).map((c) => c.name), ['(top level)', 'Jazz']);
    assert.equal(folderChoices(folders)[0].id, null);
});

test('ancestors run from the folder outwards', () => {
    assert.deepEqual(ancestorIds(folders, 2), [2, 1]);
    assert.deepEqual(ancestorIds(folders, 9), []);
});

test('an action works on the marked albums, else the selected album, else every album of the selected folder', () => {
    const open = new Set([1, 2]);
    const rows = buildRows(lib, open);
    assert.deepEqual(targetAlbums(rows, 2, new Set([13, 14]), folders, albums).map((a) => a.id), [13, 14]);
    assert.deepEqual(targetAlbums(rows, 2, new Set(), folders, albums).map((a) => a.id), [10]);
    assert.deepEqual(targetAlbums(rows, 0, new Set(), folders, albums).map((a) => a.id), [10, 11, 12]);
    assert.deepEqual(targetAlbums(rows, 7, new Set(), folders, albums), []);
});

test('rowOfAlbum finds an album row, or -1 while its folder is closed', () => {
    assert.equal(rowOfAlbum(buildRows(lib, new Set([1, 2])), 10), 2);
    assert.equal(rowOfAlbum(buildRows(lib, new Set()), 10), -1);
});

test('flat: every album in one list, no folder rows, each with the path of its folder', () => {
    const rows = buildRows(lib, new Set(), 'title', null, true);
    assert.deepEqual(keys(rows), ['a14', 'a11', 'a13', 'a12', 'a10', 'loose']);
    assert.equal(rows.find((r) => r.id === 10).path, 'Rock / New Wave');
    assert.equal(rows.find((r) => r.id === 14).path, '');
    assert.ok(rows.every((r) => r.kind !== 'folder' && r.depth === 0));
});

test('flat follows the sort and the filter', () => {
    assert.deepEqual(keys(buildRows(lib, new Set(), 'year', null, true)).slice(0, 3), ['a13', 'a11', 'a10']);
    assert.deepEqual(keys(buildRows(lib, new Set(), 'library', (a) => a.year && a.year < 1981, true)), ['a10', 'a11', 'a13', 'loose']);
});

test('flat: every album in one list, no folder rows, each with the path of its folder', () => {
    const rows = buildRows(lib, new Set(), 'title', null, true);
    assert.deepEqual(keys(rows), ['a14', 'a11', 'a13', 'a12', 'a10', 'loose']);
    assert.equal(rows.find((r) => r.id === 10).path, 'Rock / New Wave');
    assert.equal(rows.find((r) => r.id === 14).path, '');
    assert.ok(rows.every((r) => r.kind !== 'folder' && r.depth === 0));
});

test('flat follows the sort and the filter', () => {
    assert.deepEqual(keys(buildRows(lib, new Set(), 'year', null, true)).slice(0, 3), ['a13', 'a11', 'a10']);
    assert.deepEqual(keys(buildRows(lib, new Set(), 'library', (a) => a.year && a.year < 1981, true)), ['a10', 'a11', 'a13', 'loose']);
});
