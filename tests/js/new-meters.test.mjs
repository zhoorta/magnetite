import { test } from 'node:test';
import assert from 'node:assert/strict';
import kMeter from '../../resources/js/magnetite/visualizers/k-meter.js';
import crest from '../../resources/js/magnetite/visualizers/crest.js';
import lra from '../../resources/js/magnetite/visualizers/lra.js';
import { demoSample, stepDemo } from '../../resources/js/magnetite/visualizers/demo-sample.js';

/** A canvas that remembers what was drawn and never throws. */
function recorder() {
    const log = [];
    return new Proxy({ log }, {
        get: (t, k) => (k in t ? t[k] : k === 'measureText' ? () => ({ width: 40 }) : (...a) => { log.push([k, ...a]); }),
        set: (t, k, v) => { t[k] = v; return true; },
    });
}
const colors = { bg: '#000', surface: '#111', text: '#fff', muted: '#888', accent: '#f00', accent2: '#0f0', font: 'monospace' };
const texts = (g) => g.log.filter((e) => e[0] === 'fillText').map((e) => e[1]);

for (const [name, meter] of [['k-meter', kMeter], ['crest', crest], ['lra', lra]]) {
    test(`${name} draws a demo signal in cells of every shape`, () => {
        const sample = demoSample();
        for (const [w, h] of [[600, 300], [300, 600], [60, 40], [1, 1]]) {
            const g = recorder();
            for (let f = 0; f < 40; f++) {
                stepDemo(sample, f / 30);
                meter.draw(g, sample, { w, h, colors, params: {}, dt: 1 / 30 });
            }
            assert.ok(g.log.length > 0, `${w}x${h}`);
        }
    });

    test(`${name} draws idle silence`, () => {
        const sample = demoSample();
        sample.levels.l = { rms: 0, peak: 0 };
        sample.levels.r = { rms: 0, peak: 0 };
        sample.lufs = { m: -Infinity, s: -Infinity, i: -Infinity, tpL: 0, tpR: 0, lra: 0, lraLo: -Infinity, lraHi: -Infinity, lraShape: new Uint32Array(60) };
        sample.playing = false;
        const g = recorder();
        meter.draw(g, sample, { w: 500, h: 300, colors, params: {}, dt: 1 / 60 });
        assert.ok(g.log.length > 0);
    });
}

test('k-meter reads a full-scale sine as the K scale top (+20 on K-20) and -20 dBFS RMS as zero', () => {
    const sample = demoSample();
    const g = recorder();
    const full = Math.SQRT1_2; // RMS of a full-scale sine
    sample.levels.l = sample.levels.r = { rms: full, peak: 1 };
    for (let f = 0; f < 120; f++) kMeter.draw(g, sample, { w: 600, h: 300, colors, params: {}, dt: 1 / 30 });
    assert.ok(texts(g).includes('+20.0'), texts(g).join(' '));
    const h = recorder();
    sample.levels.l = sample.levels.r = { rms: 0.1 / Math.SQRT2, peak: 0.1 }; // -20 dBFS in AES17 terms
    for (let f = 0; f < 120; f++) kMeter.draw(h, sample, { w: 600, h: 300, colors, params: {}, dt: 1 / 30 });
    assert.ok(texts(h).some((t) => t === '0.0'), texts(h).join(' '));
});

test('crest factor of a sine is 3 dB and a spiky signal reads higher', () => {
    const run = (peak, rms) => {
        const sample = demoSample();
        sample.levels.l = sample.levels.r = { rms, peak };
        const g = recorder();
        for (let f = 0; f < 150; f++) crest.draw(g, sample, { w: 600, h: 300, colors, params: {}, dt: 1 / 30 });
        return texts(g).filter((t) => /^\d+\.\d$/.test(t)).at(-1);
    };
    assert.equal(run(Math.SQRT2 * 0.1, 0.1), '3.0');
    assert.equal(run(0.4, 0.05), '18.1');
});

test('lra shows the number once the worklet has one and dashes before', () => {
    const sample = demoSample();
    const g = recorder();
    lra.draw(g, sample, { w: 500, h: 300, colors, params: {} });
    assert.ok(texts(g).includes('8.2'));
    const h = recorder();
    sample.lufs = { ...sample.lufs, lra: 0, lraLo: -Infinity, lraHi: -Infinity };
    lra.draw(h, sample, { w: 500, h: 300, colors, params: {} });
    assert.ok(texts(h).includes('−−.−'));
});
