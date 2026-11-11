import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Beat, bandPowerDb, norm } from '../../resources/js/magnetite/visualizers/beat.js';

const bands = (db) => new Float32Array(31).fill(db);

test('norm clamps to 0..1', () => {
    assert.equal(norm(-80, -66, -22), 0);
    assert.equal(norm(0, -66, -22), 1);
    assert.equal(norm(-44, -66, -22), 0.5);
});

test('band power of equal bands is that level', () => {
    assert.ok(Math.abs(bandPowerDb(bands(-30), 2, 11) + 30) < 1e-4);
});

test('a bass hit above the average is one onset, silence is none', () => {
    const beat = new Beat();
    const quiet = bands(-40);
    const loud = bands(-40);
    for (let i = 2; i <= 11; i++) loud[i] = -20;

    for (let i = 0; i < 120; i++) assert.equal(beat.update(quiet, 1 / 60), false);
    assert.equal(beat.update(loud, 1 / 60), true);
    assert.equal(beat.pulse, 1);
    assert.equal(beat.update(loud, 1 / 60), false); // refractory
    for (let i = 0; i < 60; i++) beat.update(quiet, 1 / 60);
    assert.ok(beat.pulse < 0.01);

    const silent = new Beat();
    for (let i = 0; i < 300; i++) assert.equal(silent.update(bands(-120), 1 / 60), false);
});

test('a steady loud signal stops triggering as the average catches up', () => {
    const beat = new Beat();
    const loud = bands(-20);
    let hits = 0;
    for (let i = 0; i < 600; i++) if (beat.update(loud, 1 / 60)) hits++;
    assert.ok(hits <= 2);
});
