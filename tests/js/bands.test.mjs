import test from 'node:test';
import assert from 'node:assert/strict';
import { bandsFromSpectrum, BAND_LABELS, BAND_FLOOR } from '../../resources/js/magnetite/bands.js';

const FS = 48000;
const FFT = 16384;
const bins = FFT / 2;

/** A spectrum that is silent except for the given bin -> dB entries. */
const spectrum = (entries = {}, fill = -200) => {
    const db = new Float32Array(bins).fill(fill);
    for (const [k, v] of Object.entries(entries)) db[k] = v;

    return db;
};
const bandOf = (hz) => {
    const out = bandsFromSpectrum(spectrum({ [Math.round((hz * FFT) / FS)]: -11.18 }), FS, FFT, new Float32Array(31));

    return out.findIndex((v) => v > -100);
};

test('there are 31 labelled bands, 20 Hz to 20 kHz', () => {
    assert.equal(BAND_LABELS.length, 31);
    assert.equal(BAND_LABELS[0], 20);
    assert.equal(BAND_LABELS[17], 1000);
    assert.equal(BAND_LABELS[30], 20000);
});

test('a bin lands in the band whose centre is nearest, from the lowest to the highest', () => {
    assert.equal(bandOf(20), 0);
    assert.equal(bandOf(100), 7);
    assert.equal(bandOf(1000), 17);
    assert.equal(bandOf(4000), 23);
    assert.equal(bandOf(16000), 29);
    assert.equal(bandOf(19500), 30);
});

test('powers add inside a band: two equal bins are 3 dB above one', () => {
    const k = Math.round((1000 * FFT) / FS);
    const one = bandsFromSpectrum(spectrum({ [k]: -30 }), FS, FFT, new Float32Array(31))[17];
    const two = bandsFromSpectrum(spectrum({ [k]: -30, [k + 1]: -30 }), FS, FFT, new Float32Array(31))[17];

    assert.ok(Math.abs(two - one - 3.01) < 0.01, `${two - one}`);
});

test('a full-scale sine spread the way the analyser spreads it reads 0 dB', () => {
    // Blackman window: the main lobe holds the sine's power across about 5 bins; their powers must sum to -11.18 dB.
    const k = Math.round((1000 * FFT) / FS);
    const shape = [0.0006, 0.1034, 0.7466, 0.1034, 0.0006].map((x) => x / 0.9546); // power shares, summing to 1
    const db = spectrum();
    shape.forEach((share, i) => (db[k - 2 + i] = -11.18 + 10 * Math.log10(share)));

    const out = bandsFromSpectrum(db, FS, FFT, new Float32Array(31));
    assert.ok(Math.abs(out[17]) < 0.05, `${out[17]}`);
});

test('silence reads the floor, and bands above Nyquist stay at the floor', () => {
    const out = bandsFromSpectrum(spectrum(), 32000, FFT, new Float32Array(31));

    assert.ok(out.every((v) => v === BAND_FLOOR));
});

test('every band has at least one bin at 44.1 kHz and 96 kHz', () => {
    for (const fs of [44100, 96000]) {
        const flat = new Float32Array(bins).fill(-60);
        const out = bandsFromSpectrum(flat, fs, FFT, new Float32Array(31));
        const usable = out.filter((v, i) => BAND_LABELS[i] < fs / 2.3);
        assert.ok(usable.every((v) => v > -60), `fs ${fs}`);
    }
});
