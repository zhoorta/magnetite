import test from 'node:test';
import assert from 'node:assert/strict';
import { MeterDsp, GONIO_LEN } from '../../resources/js/magnetite/worklet/meter-processor.js';

const BLOCK = 128;

/** Run `seconds` of a two-channel signal through the meters, in worklet-sized blocks. */
function run(fs, seconds, gen, dsp = new MeterDsp(fs)) {
    const total = Math.round(fs * seconds);
    const l = new Float32Array(BLOCK);
    const r = new Float32Array(BLOCK);
    for (let start = 0; start < total; start += BLOCK) {
        for (let i = 0; i < BLOCK; i++) [l[i], r[i]] = gen(start + i);
        dsp.process(l, r);
    }

    return dsp;
}

const dbToLin = (db) => 10 ** (db / 20);
const sine = (fs, f, amp, phase = 0) => (n) => amp * Math.sin((2 * Math.PI * f * n) / fs + phase);

for (const fs of [44100, 48000]) {
    test(`stereo 1 kHz sine at -20 dBFS reads -20 LUFS (${fs} Hz)`, () => {
        const s = sine(fs, 1000, dbToLin(-20));
        const snap = run(fs, 8, (n) => [s(n), s(n)]).snapshot();

        assert.ok(Math.abs(snap.lufsM + 20) < 0.1, `momentary ${snap.lufsM}`);
        assert.ok(Math.abs(snap.lufsS + 20) < 0.1, `short-term ${snap.lufsS}`);
        assert.ok(Math.abs(snap.lufsI + 20) < 0.1, `integrated ${snap.lufsI}`);
    });

    test(`one channel only is 3 LU lower (${fs} Hz)`, () => {
        const s = sine(fs, 1000, dbToLin(-20));
        const snap = run(fs, 5, (n) => [s(n), 0]).snapshot();

        assert.ok(Math.abs(snap.lufsI + 23.01) < 0.1, `integrated ${snap.lufsI}`);
    });

    test(`a quiet tail below the gates does not pull the integrated value down (${fs} Hz)`, () => {
        const loud = sine(fs, 1000, dbToLin(-20));
        const quiet = sine(fs, 1000, dbToLin(-45));
        const dsp = run(fs, 10, (n) => [loud(n), loud(n)]);
        run(fs, 10, (n) => [quiet(n), quiet(n)], dsp);
        const silent = run(fs, 5, () => [0, 0], dsp).snapshot();

        assert.ok(Math.abs(silent.lufsI + 20) < 0.1, `integrated ${silent.lufsI}`);
        assert.ok(silent.lufsM < -70, 'momentary follows the silence');
    });

    test(`true peak finds the peak between samples (${fs} Hz)`, () => {
        // A tone at fs/4 with a 45 degree phase only ever lands on +-0.707, but its waveform reaches 1.0.
        const s = sine(fs, fs / 4, 1, Math.PI / 4);
        const snap = run(fs, 1, (n) => [s(n), s(n)]).snapshot();

        assert.ok(snap.tpL > 0.98 && snap.tpL < 1.02, `true peak ${snap.tpL}`);
    });

    test(`full-scale sine reads about 0 dBTP, not above (${fs} Hz)`, () => {
        const s = sine(fs, 997, 1);
        const snap = run(fs, 1, (n) => [s(n), s(n)]).snapshot();

        assert.ok(snap.tpL > 0.995 && snap.tpL < 1.01, `true peak ${snap.tpL}`);
    });
}

test('phase correlation: mono +1, inverted -1, independent about 0', () => {
    const fs = 48000;
    const s = sine(fs, 440, 0.5);

    assert.ok(run(fs, 3, (n) => [s(n), s(n)]).snapshot().corr > 0.99);
    assert.ok(run(fs, 3, (n) => [s(n), -s(n)]).snapshot().corr < -0.99);

    let seed = 1;
    const noise = () => ((seed = (seed * 16807) % 2147483647) / 2147483647) * 2 - 1;
    assert.ok(Math.abs(run(fs, 5, () => [noise(), noise()]).snapshot().corr) < 0.1);
});

test('silence reads as silence and does not produce NaN', () => {
    const snap = run(48000, 1, () => [0, 0]).snapshot();

    assert.equal(snap.lufsM, -Infinity);
    assert.equal(snap.corr, 0);
    assert.equal(snap.tpL, 0);
});

test('goniometer buffer is in time order, newest last, and reset clears the readings', () => {
    const dsp = new MeterDsp(48000);
    const n = GONIO_LEN + 300;
    const l = Float32Array.from({ length: n }, (_, i) => i / n);
    const r = Float32Array.from({ length: n }, (_, i) => -i / n);
    dsp.process(l, r);

    const { gonio } = dsp.snapshot();
    assert.equal(gonio.length, GONIO_LEN * 2);
    assert.equal(gonio[gonio.length - 2], l[n - 1]);
    assert.equal(gonio[gonio.length - 1], r[n - 1]);
    assert.equal(gonio[0], l[n - GONIO_LEN]);

    dsp.reset();
    assert.equal(dsp.snapshot().tpL, 0);
    assert.equal(dsp.snapshot().lufsI, -Infinity);
});
