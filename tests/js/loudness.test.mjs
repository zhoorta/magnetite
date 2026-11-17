import { test } from 'node:test';
import assert from 'node:assert/strict';
import loudness, { nixieText } from '../../resources/js/magnetite/visualizers/loudness.js';

test('the nixie text is always five cells', () => {
    assert.equal(nixieText(-23.44), '−23.4');
    assert.equal(nixieText(-9.96), '−10.0');
    assert.equal(nixieText(0.04), '  0.0');
    assert.equal(nixieText(NaN), ' −−.−');
    assert.equal(nixieText(-Infinity), ' −−.−');
});

/** A canvas that only remembers what was drawn. */
function recorder() {
    const log = [];
    return new Proxy({ log }, {
        get: (t, k) => (k in t ? t[k] : (...a) => { log.push([k, ...a]); }),
        set: (t, k, v) => { t[k] = v; return true; },
    });
}
const colors = { bg: '#000', surface: '#111', text: '#fff', muted: '#888', accent: '#f00', accent2: '#0f0', font: 'monospace' };

test('draws four rows of tubes and the true peak goes red over the ceiling', () => {
    const lufs = { m: -20.1, s: -22.2, i: -23.3, tpL: 1.2, tpR: 0.3 }; // +1.6 dBTP: over the -1 ceiling
    const g = recorder();
    loudness.draw(g, { lufs }, { w: 600, h: 400, colors, params: {} });
    const cells = g.log.filter((e) => e[0] === 'roundRect').length;
    assert.equal(cells, 20); // 4 rows x 5 cells
    const digits = g.log.filter((e) => e[0] === 'fillText' && /^[0-9−.]$/.test(e[1])).map((e) => e[1]);
    assert.ok(digits.includes('2') && digits.includes('.'));
    assert.equal(g.fillStyle, '#ff4d3d'); // the last row drawn is the true peak, over the ceiling
});

test('draws without values before the first play', () => {
    const g = recorder();
    loudness.draw(g, { lufs: { m: -Infinity, s: -Infinity, i: -Infinity, tpL: 0, tpR: 0 } }, { w: 300, h: 200, colors, params: {} });
    assert.equal(g.log.filter((e) => e[0] === 'roundRect').length, 20);
});
