import test from 'node:test';
import assert from 'node:assert/strict';
import { folderTiles, folderStats, durationText, summaryLine, moveTile } from '../../resources/js/magnetite/dense/folder.js';

const folders = [
    { id: 1, parent_id: null, name: 'Rock', depth: 1 },
    { id: 2, parent_id: 1, name: 'New Wave', depth: 2 },
    { id: 3, parent_id: null, name: 'Jazz', depth: 1 },
];
const track = (duration, level) => ({ duration, truth: { flags: level ? [{ level }] : [] } });
const albums = [
    { id: 10, title: 'Remain in Light', year: 1980, folder_id: 2, dr: 12, tracks: [track(300), track(200, 'problem')] },
    { id: 11, title: 'Fear of Music', year: 1979, folder_id: 2, dr: 10, tracks: [track(250)] },
    { id: 12, title: 'Pornography', year: 1982, folder_id: 1, dr: null, tracks: [track(100, 'warn')] },
    { id: 13, title: 'Kind of Blue', year: 1959, folder_id: 3, dr: 15, tracks: [track(3000)] },
];

test('a folder shows its subfolders first, then its own albums', () => {
    const tiles = folderTiles(folders, albums, 1);
    assert.deepEqual(tiles.map((t) => t.key), ['f2', 'a12']);
    assert.equal(tiles[0].count, 2);
});

test('a folder with a parent starts with a back tile to that parent', () => {
    const tiles = folderTiles(folders, albums, 2);
    assert.equal(tiles[0].kind, 'up');
    assert.equal(tiles[0].folder.id, 1);
    assert.equal(folderTiles(folders, albums, 1)[0].kind, 'folder');
});

test('the albums follow the chosen order', () => {
    assert.deepEqual(folderTiles(folders, albums, 2, 'year').map((t) => t.key), ['f1', 'a11', 'a10']);
    assert.deepEqual(folderTiles(folders, albums, 2, 'dr').map((t) => t.key), ['f1', 'a11', 'a10']);
    assert.deepEqual(folderTiles(folders, albums, 2, 'title').map((t) => t.key), ['f1', 'a11', 'a10']);
    assert.deepEqual(folderTiles(folders, [], 3), []);
});

test('totals cover the folder and everything below it', () => {
    const s = folderStats(folders, albums, 1);
    assert.deepEqual(s, { albums: 3, tracks: 4, seconds: 850, dr: 11, problems: 2 });
    assert.deepEqual(folderStats(folders, albums, 3), { albums: 1, tracks: 1, seconds: 3000, dr: 15, problems: 0 });
    assert.equal(folderStats(folders, [], 3).dr, null);
});

test('durations in minutes, or hours and minutes', () => {
    assert.equal(durationText(0), '0 min');
    assert.equal(durationText(2700), '45 min');
    assert.equal(durationText(3600 * 9 + 20 * 60), '9 h 20 min');
    assert.equal(durationText(3605), '1 h 00 min');
});

test('the summary line grows with the info level', () => {
    const s = folderStats(folders, albums, 1);
    assert.equal(summaryLine(s, 0), '3 albums · 4 tracks · 14 min');
    assert.equal(summaryLine(s, 1), '3 albums · 4 tracks · 14 min · mean DR11');
    assert.equal(summaryLine(s, 2), '3 albums · 4 tracks · 14 min · mean DR11 · 2 tracks with findings');
    assert.equal(summaryLine({ albums: 1, tracks: 1, seconds: 60, dr: null, problems: 1 }, 2), '1 album · 1 track · 1 min · 1 track with findings');
});

test('moving in the grid stays inside it', () => {
    assert.equal(moveTile(0, -1, 5), 0);
    assert.equal(moveTile(4, 1, 5), 4);
    assert.equal(moveTile(1, 3, 5), 4);
    assert.equal(moveTile(0, 4, 0), 0);
});
