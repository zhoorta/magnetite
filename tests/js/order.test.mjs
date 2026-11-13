import test from 'node:test';
import assert from 'node:assert/strict';
import { makeOrder, advance, previous, upcoming, cycle, SHUFFLES, REPEATS } from '../../resources/js/magnetite/order.js';

// A queue of two albums (A: 0-2, B: 3-4) and a loose track.
const queue = [0, 1, 2].map((i) => ({ id: i, album_id: 1 })).concat([3, 4].map((i) => ({ id: i, album_id: 2 })), [{ id: 5, album_id: null }]);
const seeded = (seed = 1) => () => ((seed = (seed * 16807) % 2147483647) / 2147483647);
const sorted = (a) => [...a].sort((x, y) => x - y);

test('off is the queue as it is', () => {
    assert.deepEqual(makeOrder(queue, 'off', 3), [0, 1, 2, 3, 4, 5]);
});

test('tracks: every track once, the playing one first', () => {
    for (let s = 1; s < 20; s++) {
        const o = makeOrder(queue, 'tracks', 3, seeded(s));
        assert.equal(o[0], 3);
        assert.deepEqual(sorted(o), [0, 1, 2, 3, 4, 5]);
    }
});

test('albums: each album stays together and in order, the playing album carries on first', () => {
    for (let s = 1; s < 30; s++) {
        const o = makeOrder(queue, 'albums', 1, seeded(s));
        assert.deepEqual(sorted(o), [0, 1, 2, 3, 4, 5]);
        assert.deepEqual(o.slice(0, 2), [1, 2]);
        assert.equal(o.at(-1), 0); // what came before the playing track in its album is last
        const rest = o.slice(2, -1);
        assert.ok(rest.indexOf(3) + 1 === rest.indexOf(4)); // album B in order, together
    }
});

test('albums: a new round starts anywhere and keeps albums whole', () => {
    const o = makeOrder(queue, 'albums', null, seeded(7));
    assert.deepEqual(sorted(o), [0, 1, 2, 3, 4, 5]);
    assert.ok(o.indexOf(0) < o.indexOf(1) && o.indexOf(1) < o.indexOf(2));
});

test('advance goes along the order and stops at the end without repeat', () => {
    const order = [2, 0, 1];
    const q = queue.slice(0, 3);
    assert.equal(advance({ queue: q, order, index: 2, shuffle: 'tracks', repeat: 'off' }).index, 0);
    assert.equal(advance({ queue: q, order, index: 1, shuffle: 'tracks', repeat: 'off' }), null);
});

test('repeat all wraps; a shuffled round never starts with the track that just ended', () => {
    const q = queue.slice(0, 3);
    for (let s = 1; s < 40; s++) {
        const r = advance({ queue: q, order: [0, 1, 2], index: 2, shuffle: 'tracks', repeat: 'all', rand: seeded(s) });
        assert.notEqual(r.index, 2);
        assert.deepEqual(sorted(r.order), [0, 1, 2]);
    }
    assert.equal(advance({ queue: q, order: [0, 1, 2], index: 2, shuffle: 'off', repeat: 'all' }).index, 0);
});

test('repeat all with album shuffle does not start the same album again when there is another', () => {
    for (let s = 1; s < 40; s++) {
        const r = advance({ queue, order: [3, 4, 5, 0, 1, 2], index: 2, shuffle: 'albums', repeat: 'all', rand: seeded(s) });
        assert.notEqual(queue[r.index].album_id, 1);
        assert.deepEqual(sorted(r.order), [0, 1, 2, 3, 4, 5]);
    }
});

test('a one-track queue repeats itself', () => {
    assert.equal(advance({ queue: [queue[0]], order: [0], index: 0, shuffle: 'tracks', repeat: 'all' }).index, 0);
});

test('previous, upcoming and the cycles', () => {
    assert.equal(previous([2, 0, 1], 0), 2);
    assert.equal(previous([2, 0, 1], 2), null);
    assert.equal(upcoming([2, 0, 1], 0), 1);
    assert.equal(upcoming([2, 0, 1], 1), null);
    assert.deepEqual([cycle(SHUFFLES, 'off'), cycle(SHUFFLES, 'tracks'), cycle(SHUFFLES, 'albums')], ['tracks', 'albums', 'off']);
    assert.deepEqual([cycle(REPEATS, 'off'), cycle(REPEATS, 'all'), cycle(REPEATS, 'one')], ['all', 'one', 'off']);
});
