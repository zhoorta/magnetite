import assert from 'node:assert/strict';
import { test } from 'node:test';
import { analyze } from '../../resources/js/magnetite/visualizers/scene-auto.js';

const W = 64;
const H = 64;
const grid = (fill = 0) => new Float32Array(W * H).fill(fill);
const rect = (a, x0, y0, x1, y1, v = 1) => {
    for (let y = y0; y < y1; y++) for (let x = x0; x < x1; x++) a[y * W + x] = v;
};

test('the biggest bright shape is the kick, the others are bands from left to right', () => {
    const a = grid(0.05);
    rect(a, 4, 4, 24, 24); // biggest
    rect(a, 40, 10, 50, 20);
    rect(a, 28, 40, 36, 48);
    rect(a, 52, 50, 60, 58);
    rect(a, 4, 40, 12, 48);
    rect(a, 56, 4, 62, 12);
    const r = analyze(a, W, H);

    assert.ok(Math.abs(r.kick.cx - 14 / W) < 0.03);
    assert.equal(r.bands.length, 5);
    assert.ok(r.bands.every((b) => !b.strip));
    for (let i = 1; i < r.bands.length; i++) assert.ok(r.bands[i].cx > r.bands[i - 1].cx); // left to right
});

test('a frame around the picture is not a light', () => {
    const a = grid(0.05);
    rect(a, 0, 0, W, 3, 0.95);
    rect(a, 0, H - 3, W, H, 0.95);
    rect(a, 0, 0, 3, H, 0.95);
    rect(a, W - 3, 0, W, H, 0.95);
    rect(a, 20, 20, 36, 36);
    const r = analyze(a, W, H);

    assert.ok(Math.abs(r.kick.cx - 28 / W) < 0.03);
});

test('one big shape is cut into strips, left to right, so the bands have something', () => {
    const a = grid(0.05);
    rect(a, 10, 10, 50, 50);
    const r = analyze(a, W, H);

    assert.ok(r.kick);
    assert.ok(r.bands.length >= 4);
    for (let i = 1; i < r.bands.length; i++) assert.ok(r.bands[i].cx > r.bands[i - 1].cx);
    assert.ok(r.bands.every((b) => b.strip));
});

test('a bright picture lets its dark shapes carry the music', () => {
    const a = grid(0.97);
    rect(a, 10, 10, 20, 40, 0.02);
    rect(a, 30, 20, 38, 30, 0.02);
    const r = analyze(a, W, H);

    assert.equal(r.inverted, true);
    assert.ok(r.kick);
});

test('with too few blobs the colours take over, one band per hue', () => {
    const a = grid(0.5);
    const hue = new Float32Array(W * H);
    const sat = new Float32Array(W * H).fill(0.8);
    for (let i = 0; i < W * H; i++) hue[i] = ((i % W) / W) * 0.999; // a rainbow across
    const r = analyze(a, W, H, { hue, sat });

    assert.ok(r.bands.length >= 3);
    assert.ok(r.bands.every((b) => b.hue));
});

import { dominantHue, hueColor, measure, suggest } from '../../resources/js/magnetite/visualizers/scene-auto.js';

test('measure: brightness, contrast and colour of a whole picture', () => {
    const flat = new Float32Array(100).fill(0.5);
    const m = measure(flat, new Float32Array(100).fill(0.2));
    assert.equal(m.mean, 0.5);
    assert.equal(m.contrast, 0);
    assert.ok(Math.abs(m.sat - 0.2) < 1e-6);

    const split = Float32Array.from({ length: 100 }, (_, i) => (i < 50 ? 0 : 1));
    assert.ok(Math.abs(measure(split, flat).contrast - 0.5) < 1e-6);
});

test('suggest: a bright picture gets no bloom, a flat or empty one gets more, many shapes are calmer', () => {
    assert.deepEqual(suggest({ mean: 0.8, contrast: 0.3, sat: 0 }, { kick: {}, bands: [{}, {}], inverted: true }), { bloom: 0 });

    const none = suggest({ mean: 0.4, contrast: 0.1, sat: 0.2 }, { kick: null, bands: [] });
    assert.equal(none.kickGain, 0.6);
    assert.ok(none.bloom >= 0.16);
    assert.equal(none.vignette, undefined); // edges and drift are the user's choice
    assert.equal(none.drift, undefined);

    assert.deepEqual(suggest({ mean: 0.1, contrast: 0.2, sat: 0.5 }, { kick: {}, bands: [{}, {}, {}] }), {});

    const many = suggest({ mean: 0.4, contrast: 0.3, sat: 0.5 }, { kick: {}, bands: Array.from({ length: 8 }, () => ({})) });
    assert.equal(many.bandGain, 0.8);
});

test('dominantHue: the colour of the bright, saturated parts; none for a grey picture', () => {
    const n = 400;
    const luma = new Float32Array(n).fill(0.7);
    const sat = new Float32Array(n).fill(0.8);
    const hue = new Float32Array(n);
    for (let i = 0; i < n; i++) hue[i] = i < 300 ? 0.33 : 0.0; // mostly green, some red
    const d = dominantHue(hue, sat, luma);
    assert.ok(Math.abs(d.hue - 0.33) < 0.03);

    // a dim wall of one colour does not beat a small bright sign of another
    const l2 = Float32Array.from({ length: n }, (_, i) => (i < 300 ? 0.25 : 0.95));
    assert.ok(Math.abs(dominantHue(hue, sat, l2).hue - 0.0) < 0.03 || dominantHue(hue, sat, l2).hue > 0.97);

    assert.equal(dominantHue(hue, new Float32Array(n).fill(0.05), luma), null);
    assert.equal(hueColor(0.5), 'hsl(180, 90%, 60%)');
});

test('suggest: a picture with a colour glows in it', () => {
    assert.equal(suggest({ mean: 0.4, contrast: 0.3, sat: 0.5 }, { kick: {}, bands: [{}] }, { color: 'hsl(120, 90%, 60%)' }).glowColor, 'picture');
    assert.equal(suggest({ mean: 0.4, contrast: 0.3, sat: 0.5 }, { kick: {}, bands: [{}] }).glowColor, undefined);
});
