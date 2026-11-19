import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fakeContext } from './fake-canvas.mjs';
import { demoSample, stepDemo } from '../../resources/js/magnetite/visualizers/demo-sample.js';
import { colors, track, posterTests } from './poster-smoke.mjs';

// The routes are drawn as Path2D shapes in the browser; Node gets a stand-in so the drawing code can run.
globalThis.Path2D = class {};
const { default: network, lineFor } = await import('../../resources/js/magnetite/visualizers/poster-network.js');

posterTests(network, { id: 'poster-network', needs: ['bands'], prints: ['Mare Cheia', /^Map: Arbalete, CC BY-SA 4.0/, 'U9', 'U5'] });

test('the train runs on one of the nine lines, the same line for a track, and different tracks use different lines', () => {
    const lines = ['U1', 'U2', 'U3', 'U4', 'U5', 'U6', 'U7', 'U8', 'U9'];
    assert.ok(lines.includes(lineFor(track)));
    assert.equal(lineFor({ ...track }), lineFor(track));
    assert.equal(lineFor(null), lineFor(undefined));
    const used = new Set();
    for (let i = 0; i < 500; i++) {
        const line = lineFor({ artist: `artist ${i}`, title: `title ${i * 7}`, number: i });
        assert.ok(lines.includes(line), 'never a missing line');
        used.add(line);
    }
    assert.equal(used.size, 9, 'every line gets used');
});

test('the credit for the map is printed whenever the poster prints text, and is not part of the shapes', () => {
    const sample = demoSample();
    stepDemo(sample, 1);
    const g = fakeContext();
    network.draw(g, sample, { w: 600, h: 800, colors, params: {}, dt: 1 / 30, track, progress: 0.5 });
    assert.ok(g.said.some((t) => t.includes('CC BY-SA 4.0')));
    const quiet = fakeContext();
    network.draw(quiet, sample, { w: 600, h: 800, colors, params: { text: 0 }, dt: 1 / 30, track, progress: 0.5 });
    assert.equal(quiet.said.length, 0);
});
