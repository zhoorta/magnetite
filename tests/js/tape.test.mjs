import test from 'node:test';
import assert from 'node:assert/strict';
import { hubSpeed, packRadii } from '../../resources/js/magnetite/visualizers/tape.js';

test('at the start the left pack is full and the right is empty, at the end the other way round', () => {
    assert.deepEqual(packRadii(0, 6, 15), [15, 6]);
    assert.deepEqual(packRadii(1, 6, 15), [6, 15]);
    assert.deepEqual(packRadii(-1, 6, 15), packRadii(0, 6, 15));
    assert.deepEqual(packRadii(2, 6, 15), packRadii(1, 6, 15));
});

test('the tape is conserved: the sum of the pack areas does not change', () => {
    const area = ([a, b]) => a * a + b * b;
    for (const p of [0, 0.25, 0.5, 0.9, 1]) assert.ok(Math.abs(area(packRadii(p, 6, 15)) - area(packRadii(0, 6, 15))) < 1e-9);
});

test('a small pack turns faster than a full one', () => {
    assert.equal(hubSpeed(15, 15, 2), 2);
    assert.ok(hubSpeed(6, 15, 2) > hubSpeed(15, 15, 2));
});
