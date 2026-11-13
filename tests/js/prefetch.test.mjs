import test from 'node:test';
import assert from 'node:assert/strict';
import { shouldPrefetch, prefetchUrl } from '../../resources/js/magnetite/prefetch.js';

const base = { playing: true, time: 10, duration: 300, next: { id: 8 }, already: null };

test('waits while the track is young and long', () => {
    assert.equal(shouldPrefetch(base), false);
});

test('asks at 70% of the track', () => {
    assert.equal(shouldPrefetch({ ...base, time: 209 }), false);
    assert.equal(shouldPrefetch({ ...base, time: 210 }), true);
});

test('asks 45 seconds before the end when that comes first', () => {
    // 20 minute track: 70% is at 14 minutes, but 45 s before the end is later; 70% still wins.
    assert.equal(shouldPrefetch({ ...base, duration: 1200, time: 840 }), true);
    // 60 second track: 45 s left is already at 15 s, long before 70%.
    assert.equal(shouldPrefetch({ ...base, duration: 60, time: 14 }), false);
    assert.equal(shouldPrefetch({ ...base, duration: 60, time: 15 }), true);
});

test('asks once per track', () => {
    assert.equal(shouldPrefetch({ ...base, time: 250, already: 8 }), false);
    assert.equal(shouldPrefetch({ ...base, time: 250, already: 7 }), true);
});

test('does nothing when paused, at the end of the queue or before the duration is known', () => {
    assert.equal(shouldPrefetch({ ...base, time: 250, playing: false }), false);
    assert.equal(shouldPrefetch({ ...base, time: 250, next: undefined }), false);
    assert.equal(shouldPrefetch({ ...base, time: 250, duration: NaN }), false);
    assert.equal(shouldPrefetch({ ...base, time: 250, duration: Infinity }), false);
    assert.equal(shouldPrefetch({ ...base, time: 0, duration: 0 }), false);
});

test('derives the prefetch address from the stream address', () => {
    assert.equal(prefetchUrl({ url: 'http://x.test/stream/42' }), 'http://x.test/prefetch/42');
    assert.equal(prefetchUrl({ url: '/stream/7' }), '/prefetch/7');
});

test('does not prefetch a track that is not in the library (the demo tracks)', () => {
    assert.equal(shouldPrefetch({ ...base, next: { id: 'achilles' }, time: 290 }), false);
});
