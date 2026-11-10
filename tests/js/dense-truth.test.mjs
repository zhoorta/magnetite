import test from 'node:test';
import assert from 'node:assert/strict';
import { truthRows, severity, nextTruthSort } from '../../resources/js/magnetite/dense/truth.js';
import { searchItems, appendPage, wantsMore, queueFrom, emptySearch } from '../../resources/js/magnetite/dense/search.js';

const track = (id, title, truth = null) => ({ id, title, truth: truth ?? { analyzed: false, flags: [] } });
const measured = (dr, lufs, tp, flags = []) => ({ analyzed: true, dr, lufs, true_peak: tp, flags: flags.map((level, i) => ({ code: level + i, level, label: level })) });
const albums = [
    { id: 1, title: 'A', artist: 'X', tracks: [track(1, 'Quiet', measured(14, -18, -3)), track(2, 'Squashed', measured(5, -7, 1.2, ['problem', 'warn'])), track(3, 'Unmeasured')] },
    { id: 2, title: 'B', artist: 'Y', tracks: [track(4, 'Hot', measured(8, -8, 0.4, ['warn'])), track(5, 'Clipped', measured(9, -10, 0.1, ['problem']))] },
];
const loose = [track(6, 'Loose', measured(11, -12, -1))];
const ids = (rows) => rows.map((r) => r.track.id);

test('severity: a problem counts more than a warning, notes and unmeasured files count nothing', () => {
    assert.equal(severity(albums[0].tracks[1].truth ? albums[0].tracks[1] : null), 110);
    assert.equal(severity(albums[0].tracks[2]), 0);
    assert.equal(severity({ truth: { flags: [{ level: 'note' }] } }), 0);
    assert.equal(severity(null), 0);
});

test('by findings: problems first, then warnings, then the rest by the lowest DR, the unmeasured last of those', () => {
    assert.deepEqual(ids(truthRows(albums, loose)), [2, 5, 4, 6, 1, 3]);
});

test('by DR, the most compressed first and the unmeasured last', () => {
    assert.deepEqual(ids(truthRows(albums, loose, { sort: 'dr' })), [2, 4, 5, 6, 1, 3]);
});

test('by loudness and by true peak, the highest first, the unmeasured last', () => {
    assert.deepEqual(ids(truthRows(albums, loose, { sort: 'loudness' })), [2, 4, 5, 6, 1, 3]);
    assert.deepEqual(ids(truthRows(albums, loose, { sort: 'peak' })), [2, 4, 5, 6, 1, 3]);
});

test('by title, and each row knows its album (loose ones have none)', () => {
    const rows = truthRows(albums, loose, { sort: 'title' });
    assert.deepEqual(rows.map((r) => r.track.title), ['Clipped', 'Hot', 'Loose', 'Quiet', 'Squashed', 'Unmeasured']);
    assert.equal(rows.find((r) => r.track.id === 6).album, null);
    assert.deepEqual(rows.find((r) => r.track.id === 4).album, { id: 2, title: 'B', artist: 'Y' });
});

test('only the files with a problem or a warning', () => {
    assert.deepEqual(ids(truthRows(albums, loose, { only: true })), [2, 5, 4]);
});

test('the sort cycles and wraps', () => {
    assert.equal(nextTruthSort('findings'), 'dr');
    assert.equal(nextTruthSort('title'), 'findings');
});

const hits = { albums: [{ id: 1 }, { id: 2 }], tracks: [{ id: 10 }, { id: 11 }], page: 1, more: { albums: false, tracks: true } };

test('search results are one list: the albums, then the tracks', () => {
    assert.deepEqual(searchItems(hits).map((i) => i.key), ['a1', 'a2', 't10', 't11']);
    assert.deepEqual(searchItems(emptySearch()), []);
});

test('the next page is added without a hit twice', () => {
    const next = appendPage({ ...emptySearch(), ...hits }, { albums: [{ id: 2 }, { id: 3 }], tracks: [{ id: 12 }], page: 2, more: { albums: false, tracks: false } });
    assert.deepEqual(searchItems(next).map((i) => i.key), ['a1', 'a2', 'a3', 't10', 't11', 't12']);
    assert.equal(next.page, 2);
    assert.equal(next.more.tracks, false);
});

test('another page is wanted near the end of the list, when there is one and none is being fetched', () => {
    const s = { ...emptySearch(), ...hits };
    assert.equal(wantsMore(s, 0, 40), false);
    assert.equal(wantsMore(s, 36, 40), true);
    assert.equal(wantsMore({ ...s, loading: true }, 39, 40), false);
    assert.equal(wantsMore({ ...s, more: { albums: false, tracks: false } }, 39, 40), false);
});

test('playing a track hit queues the track hits from that one', () => {
    const [queue, index] = queueFrom({ ...emptySearch(), ...hits }, 11);
    assert.equal(queue.length, 2);
    assert.equal(index, 1);
    assert.equal(queueFrom({ ...emptySearch(), ...hits }, 999)[1], 0);
});
