import test from 'node:test';
import assert from 'node:assert/strict';
import { metadataFor, positionState, seekTarget } from '../../resources/js/magnetite/media-session.js';

test('metadata: no track, no metadata', () => {
    assert.equal(metadataFor(null), null);
});

test('metadata: the cover becomes an absolute url, the album carries the year', () => {
    const m = metadataFor({ title: 'A', artist: 'B', album: 'C', year: 1999, cover: '/cover/3' }, 'https://magnetite.test/library');
    assert.deepEqual(m, { title: 'A', artist: 'B', album: 'C · 1999', artwork: [{ src: 'https://magnetite.test/cover/3' }] });
});

test('metadata: missing fields are empty strings, no cover is no artwork', () => {
    assert.deepEqual(metadataFor({ title: 'A' }), { title: 'A', artist: '', album: '', artwork: [] });
});

test('position state: only for a known duration, the position kept inside it', () => {
    assert.equal(positionState({ duration: NaN, time: 1 }), null);
    assert.equal(positionState({ duration: Infinity, time: 1 }), null);
    assert.equal(positionState({ duration: 0, time: 0 }), null);
    assert.deepEqual(positionState({ duration: 100, time: 120 }), { duration: 100, position: 100, playbackRate: 1 });
    assert.deepEqual(positionState({ duration: 100, time: -3, rate: 0 }), { duration: 100, position: 0, playbackRate: 1 });
});

test('seek: seekto, and back or forward by the offset or 10 s, clamped', () => {
    const at = { time: 50, duration: 100 };
    assert.equal(seekTarget({ action: 'seekto', seekTime: 30 }, at), 30);
    assert.equal(seekTarget({ action: 'seekto', seekTime: 500 }, at), 100);
    assert.equal(seekTarget({ action: 'seekbackward' }, at), 40);
    assert.equal(seekTarget({ action: 'seekbackward', seekOffset: 70 }, at), 0);
    assert.equal(seekTarget({ action: 'seekforward' }, at), 60);
    assert.equal(seekTarget({ action: 'seekforward', seekOffset: 5 }, at), 55);
});

test('seek: nothing without a duration or a target', () => {
    assert.equal(seekTarget({ action: 'seekforward' }, { time: 0, duration: NaN }), null);
    assert.equal(seekTarget({ action: 'seekto' }, { time: 0, duration: 100 }), null);
});
