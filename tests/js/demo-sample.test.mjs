import { test } from 'node:test';
import assert from 'node:assert/strict';
import { demoSample, stepDemo } from '../../resources/js/magnetite/visualizers/demo-sample.js';

test('the demo sample has the engine\'s shape and moves with time', () => {
    const s = demoSample();
    assert.equal(s.bands.length, 31);
    stepDemo(s, 0);
    const first = Array.from(s.bands);
    stepDemo(s, 1.7);
    assert.notDeepEqual(Array.from(s.bands), first);
    assert.ok(s.bands.every((db) => db > -90 && db < 0), 'band levels stay in a believable range');
    assert.ok(s.bands[0] > s.bands[30] - 20, 'the low end is not far below the top');
    assert.ok(s.stereo.corr >= -1 && s.stereo.corr <= 1);
});
