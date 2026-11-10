import test from 'node:test';
import assert from 'node:assert/strict';
import { palette, hsv, rgb, fromHex, toHex, isLight, ease } from '../../resources/js/magnetite/dense/palette.js';

const fill = (parts, n = 1024) => {
    // parts: [[r, g, b, share]] over n pixels
    const out = new Uint8ClampedArray(n * 4);
    let at = 0;
    for (const [r, g, b, share] of parts) {
        for (let i = 0; i < Math.round(n * share); i++, at++) out.set([r, g, b, 255], at * 4);
    }

    return out;
};
const hue = (c) => hsv(c)[0];

test('hsv and rgb agree', () => {
    for (const c of [[200, 30, 30], [30, 60, 210], [10, 200, 90], [255, 255, 0]]) {
        const [h, s, v] = hsv(c);
        const back = rgb(h, s, v);
        c.forEach((n, i) => assert.ok(Math.abs(n - back[i]) <= 1, `${c} -> ${back}`));
    }
    assert.deepEqual(hsv([0, 0, 0]), [0, 0, 0]);
});

test('a cover of two colours gives both hues, the larger one first', () => {
    const p = palette(fill([[200, 30, 30, 0.6], [30, 60, 210, 0.4]]));
    assert.ok(Math.abs(hue(p.accent) - hue([200, 30, 30])) < 12, `accent ${p.accent}`);
    assert.ok(Math.abs(hue(p.accent2) - hue([30, 60, 210])) < 12, `accent2 ${p.accent2}`);
});

test('colours are made bright and saturated enough to read on a dark theme, and darker on a light one', () => {
    const dark = palette(fill([[90, 20, 20, 1]]));
    const light = palette(fill([[90, 20, 20, 1]]), true);
    assert.ok(hsv(dark.accent)[2] >= 0.78 && hsv(dark.accent)[1] >= 0.55);
    assert.ok(hsv(light.accent)[2] <= 0.7 && hsv(light.accent)[2] >= 0.4);
});

test('a cover with one hue gets a shifted second colour', () => {
    const p = palette(fill([[200, 30, 30, 1]]));
    assert.notDeepEqual(p.accent, p.accent2);
    assert.ok(Math.abs(hue(p.accent2) - hue(p.accent) - 30) < 8);
});

test('grey, black and white covers have no palette, and neither has transparent nothing', () => {
    assert.equal(palette(fill([[128, 128, 128, 0.5], [0, 0, 0, 0.25], [255, 255, 255, 0.25]])), null);
    assert.equal(palette(new Uint8ClampedArray(1024 * 4)), null);
});

test('a hue next to a bin edge is not split in two', () => {
    // 9 degrees and 11 degrees fall in different bins; together they are one colour
    const a = rgb(9, 0.9, 0.9);
    const b = rgb(11, 0.9, 0.9);
    const p = palette(fill([[...a, 0.5], [...b, 0.5]]));
    assert.ok(Math.abs(hue(p.accent) - 10) < 8);
    assert.ok(Math.abs(hue(p.accent2) - hue(p.accent) - 30) < 8, 'no second hue: a shifted one');
});

test('hex helpers', () => {
    assert.deepEqual(fromHex('#39ff6a'), [0x39, 0xff, 0x6a]);
    assert.equal(fromHex('rgb(1,2,3)'), null);
    assert.equal(toHex([57, 255, 106]), '#39ff6a');
    assert.equal(isLight('#f6f1e7'), true);
    assert.equal(isLight('#000000'), false);
});

test('easing moves part of the way', () => {
    assert.deepEqual(ease([0, 100, 200], [100, 100, 0], 0.5), [50, 100, 100]);
    assert.deepEqual(ease([1, 2, 3], [9, 9, 9], 0), [1, 2, 3]);
});
