import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fakeContext } from './fake-canvas.mjs';
import pylon from '../../resources/js/magnetite/visualizers/poster-pylon.js';
import { demoSample, stepDemo } from '../../resources/js/magnetite/visualizers/demo-sample.js';

const colors = { bg: '#101010', surface: '#202020', text: '#eeeeee', accent: '#ff0000', accent2: '#00ff00', font: 'sans-serif' };
const track = { artist: 'Ana Duarte', title: 'Mare Cheia', album: 'Ilhas', year: 2024, number: 3, duration: 274, codec: 'flac', truth: { bits: 24, sample_rate: 96000, dr: 12 } };

test('it is a poster meter that reads the bands and the levels', () => {
    assert.equal(pylon.id, 'poster-pylon');
    assert.deepEqual(pylon.needs, ['bands', 'levels']);
    assert.ok(pylon.label.startsWith('Poster: '));
});

test('it draws on the demo signal and prints the track', () => {
    const g = fakeContext();
    const sample = demoSample();
    for (let f = 0; f < 120; f++) {
        stepDemo(sample, f / 30);
        pylon.draw(g, sample, { w: 600, h: 800, colors, params: {}, dt: 1 / 30, track, progress: f / 120 });
    }
    assert.ok(g.said.includes('MARE CHEIA'), 'the title is printed');
    assert.ok(g.said.some((t) => t.includes('DYNAMIC RANGE')), 'the small print is there');
    assert.ok(g.said.some((t) => t.includes('DB LEFT')), 'the paragraph carries the levels');
});

test('with no artist the title is the only name, with no track it still draws, and text can be turned off', () => {
    const sample = demoSample();
    stepDemo(sample, 1);
    const bare = fakeContext();
    pylon.draw(bare, sample, { w: 600, h: 800, colors, params: {}, dt: 1 / 30, track: { title: 'Only a title' }, progress: 0.2 });
    assert.ok(bare.said.includes('ONLY A TITLE'));
    const none = fakeContext();
    pylon.draw(none, sample, { w: 600, h: 800, colors, params: {}, dt: 1 / 30, track: null, progress: 0 });
    const quiet = fakeContext();
    pylon.draw(quiet, sample, { w: 600, h: 800, colors, params: { text: 0 }, dt: 1 / 30, track, progress: 0.5 });
    assert.equal(quiet.said.length, 0);
    pylon.draw(quiet, sample, { w: 60, h: 80, colors, params: {}, dt: 1 / 30, track, progress: 0.5 });
    assert.equal(quiet.said.length, 0, 'no text in a cell too small to read it');
});

test('two canvases keep their own state', () => {
    const sample = demoSample();
    const a = fakeContext();
    const b = fakeContext();
    for (let f = 0; f < 30; f++) {
        stepDemo(sample, f / 30);
        pylon.draw(a, sample, { w: 600, h: 800, colors, params: {}, dt: 1 / 30, track, progress: 0.1 });
    }
    pylon.draw(b, sample, { w: 96, h: 128, colors, params: { text: 0 }, dt: 1 / 30, track: null, progress: 0 });
    assert.equal(b.said.length, 0);
});
