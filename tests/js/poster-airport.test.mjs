import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fakeContext } from './fake-canvas.mjs';

// The poster uses Path2D and the picture loader in the browser; Node gets stand-ins so the drawing code itself can run.
globalThis.Path2D = class {
    moveTo() {}
    arc() {}
};
const { default: airport, routeFor } = await import('../../resources/js/magnetite/visualizers/poster-airport.js');
const { demoSample, stepDemo } = await import('../../resources/js/magnetite/visualizers/demo-sample.js');

const colors = { bg: '#101010', surface: '#202020', text: '#eeeeee', accent: '#ff0000', accent2: '#00ff00', font: 'sans-serif' };

const track = { artist: 'Ana Duarte', title: 'Mare Cheia', album: 'Ilhas', year: 2024, number: 3, duration: 274, codec: 'flac', truth: { bits: 24, sample_rate: 96000, dr: 12 } };

test('the runway is one of four and stays the same for a track', () => {
    const a = routeFor(track);
    assert.ok([0, 1, 2, 3].includes(a));
    assert.equal(routeFor({ ...track }), a);
    assert.ok(new Set(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'].map((t) => routeFor({ artist: t, title: t }))).size > 1, 'different tracks use different runways');
    assert.equal(routeFor(null), routeFor(undefined));
    for (let i = 0; i < 500; i++) assert.ok([0, 1, 2, 3].includes(routeFor({ artist: `artist ${i}`, title: `title ${i * 7}`, number: i })), 'never a negative or missing runway');
});

test('it draws on the demo signal, with and without text, and prints the track where the airport was named', () => {
    const g = fakeContext();
    const sample = demoSample();
    for (let f = 0; f < 60; f++) {
        stepDemo(sample, f / 30);
        airport.draw(g, sample, { w: 600, h: 800, colors, params: {}, dt: 1 / 30, track, progress: f / 60 });
    }
    assert.ok(g.said.includes('Mare Cheia'), 'the title is printed');
    assert.ok(g.said.includes('LEVELS (DB)'));
    const quiet = fakeContext();
    stepDemo(sample, 3);
    airport.draw(quiet, sample, { w: 600, h: 800, colors, params: { text: 0 }, dt: 1 / 30, track, progress: 0.5 });
    assert.equal(quiet.said.length, 0, 'no text when it is off');
    airport.draw(quiet, sample, { w: 60, h: 80, colors, params: {}, dt: 1 / 30, track: null, progress: 0 });
});

test('it is a poster meter that reads the bands and the levels', () => {
    assert.equal(airport.id, 'poster-airport');
    assert.deepEqual(airport.needs, ['bands', 'levels']);
    assert.ok(airport.label.startsWith('Poster: '));
});
