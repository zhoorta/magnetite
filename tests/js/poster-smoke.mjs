// What every poster meter has to do, run on a stand-in canvas (fake-canvas.mjs) and the demo signal. A poster's test file calls `posterTests` with the
// poster, what it needs, and the text it must print for the sample track. Not a test file itself (no `.test.`).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fakeContext } from './fake-canvas.mjs';
import { demoSample, stepDemo } from '../../resources/js/magnetite/visualizers/demo-sample.js';

export const colors = { bg: '#101010', surface: '#202020', text: '#eeeeee', accent: '#ff0000', accent2: '#00ff00', font: 'sans-serif' };
export const track = { artist: 'Ana Duarte', title: 'Mare Cheia', album: 'Ilhas', year: 2024, number: 3, duration: 274, codec: 'flac', truth: { bits: 24, sample_rate: 96000, dr: 12 } };

const view = (extra = {}) => ({ w: 600, h: 800, colors, params: {}, dt: 1 / 30, track, progress: 0.4, ...extra });

/**
 * `shapesAreText`: the poster's shapes are themselves characters (the block of text of Prompt), so with text off it still draws some; the check is
 * then that none of the track's words is among them.
 */
export function posterTests(poster, { id, needs, prints, shapesAreText = false }) {
    test(`${id}: it is a poster meter that reads ${needs.join(' and ')}`, () => {
        assert.equal(poster.id, id);
        assert.deepEqual(poster.needs, needs);
        assert.ok(poster.label.startsWith('Poster: '));
        assert.ok(poster.params.text >= 1 && poster.params.theme === 1, 'text on and theme level 1 by default');
    });

    test(`${id}: it draws on the demo signal and prints the track`, () => {
        const g = fakeContext();
        const sample = demoSample();
        for (let f = 0; f < 150; f++) {
            stepDemo(sample, f / 30);
            poster.draw(g, sample, view({ progress: f / 150 }));
        }
        for (const text of prints) assert.ok(g.said.some((t) => (text instanceof RegExp ? text.test(t) : t === text)), `prints ${text}`);
    });

    test(`${id}: with no artist, with no track, in a cell too small to read, and with the theme supplying every colour`, () => {
        const sample = demoSample();
        stepDemo(sample, 1);
        const bare = fakeContext();
        poster.draw(bare, sample, view({ track: { title: 'Only a title' } }));
        assert.ok(['ONLY', 'TITLE'].every((word) => bare.said.some((t) => t.replace(/\s/g, '').toUpperCase().includes(word))), 'the title is the name when there is no artist');
        poster.draw(fakeContext(), sample, view({ track: null, progress: 0 }));
        poster.draw(fakeContext(), sample, view({ params: { theme: 2 } }));
        const quiet = fakeContext();
        poster.draw(quiet, sample, view({ params: { text: 0 } }));
        poster.draw(quiet, sample, view({ w: 60, h: 80 }));
        if (shapesAreText) assert.ok(quiet.said.every((t) => !/mare|duarte|ilhas|only/i.test(t)), 'none of the track is printed when text is off');
        else assert.equal(quiet.said.length, 0, 'no text when it is off, or when the cell is too small');
    });

    test(`${id}: two canvases keep their own state`, () => {
        const sample = demoSample();
        const a = fakeContext();
        for (let f = 0; f < 30; f++) {
            stepDemo(sample, f / 30);
            poster.draw(a, sample, view({ progress: 0.1 }));
        }
        const b = fakeContext();
        poster.draw(b, sample, view({ w: 96, h: 128, params: { text: 0 }, track: null, progress: 0 }));
        if (!shapesAreText) assert.equal(b.said.length, 0);
    });
}
