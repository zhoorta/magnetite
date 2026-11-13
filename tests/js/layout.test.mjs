import test from 'node:test';
import assert from 'node:assert/strict';
import { layout, aspectOf } from '../../resources/js/magnetite/layout.js';

const columns = (cells) => new Set(cells.map((c) => c.x)).size;

test('two plain meters keep stacking on a tall canvas', () => {
    assert.equal(columns(layout(2, 1000, 800)), 1);
});

test('two posters go side by side on the same canvas', () => {
    assert.equal(columns(layout(2, 1000, 800, aspectOf(['poster-tunnel', 'poster-rings']))), 2);
});

test('four posters stand in one row on a wide screen', () => {
    assert.equal(columns(layout(4, 2000, 800, aspectOf(Array(4).fill('poster-lens')))), 4);
});

test('the aspect of no meters or of plain meters is the default', () => {
    assert.equal(aspectOf([]), 1.6);
    assert.ok(Math.abs(aspectOf(['vu-wide', 'scope']) - 1.6) < 1e-9);
});
