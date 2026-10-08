import test from 'node:test';
import assert from 'node:assert/strict';
import { TrackAnalysis, findCutoff, findContent } from '../../resources/js/magnetite/analysis/track-analysis.js';
import { FFT } from '../../resources/js/magnetite/analysis/fft.js';

const FS = 44100;

/** Interleaved s32 PCM for `seconds` of a signal; `gen(n)` returns [l, r] as floats, `bits` is the source depth. */
function pcm(seconds, gen, { fs = FS, bits = 16, mono = false } = {}) {
    const n = Math.round(fs * seconds);
    const out = new Int32Array(n * (mono ? 1 : 2));
    const max = 2 ** (bits - 1) - 1;
    const q = (x) => Math.max(-max - 1, Math.min(max, Math.round(x * max))) * 2 ** (32 - bits);
    for (let i = 0; i < n; i++) {
        const [l, r] = gen(i);
        if (mono) out[i] = q(l);
        else {
            out[i * 2] = q(l);
            out[i * 2 + 1] = q(r);
        }
    }

    return Buffer.from(out.buffer);
}

function analyse(buffer, { fs = FS, channels = 2, chunk = 1 << 16 } = {}) {
    const a = new TrackAnalysis({ sampleRate: fs, channels });
    for (let i = 0; i < buffer.length; i += chunk) a.push(buffer.subarray(i, i + chunk));

    return a.finish();
}

const sine = (f, amp, fs = FS) => (n) => amp * Math.sin((2 * Math.PI * f * n) / fs);

/** Noise with a pink-ish slope, random phases, exactly zero above `cutoff`: one long periodic block, so no clicks. */
function bandLimitedNoise(seconds, cutoff, fs = FS) {
    let size = 1;
    while (size < seconds * fs) size <<= 1;
    const re = new Float64Array(size);
    const im = new Float64Array(size);
    let seed = 7;
    const rand = () => ((seed = (seed * 16807) % 2147483647) / 2147483647);
    for (let k = 1; k < size / 2; k++) {
        const f = (k * fs) / size;
        if (f > cutoff) break;
        const amp = 1 / Math.sqrt(f);
        const ph = 2 * Math.PI * rand();
        re[k] = amp * Math.cos(ph);
        im[k] = amp * Math.sin(ph);
        re[size - k] = re[k];
        im[size - k] = -im[k]; // conjugate symmetry: the result is real
    }
    new FFT(size).transform(re, im); // forward transform of a conjugate-symmetric spectrum is the real signal
    const peak = re.reduce((m, v) => Math.max(m, Math.abs(v)), 0);

    return (n) => {
        const v = (0.5 * re[n % size]) / peak;

        return [v, v * 0.9];
    };
}

test('dynamic range: a full-scale sine is DR0', () => {
    const s = sine(997, 0.999);
    const r = analyse(pcm(20, (n) => [s(n), s(n)]));

    assert.ok(Math.abs(r.dr.value) < 0.05, `${r.dr.value}`);
});

test('dynamic range: peaks over a steady level, using the second highest block peak', () => {
    const base = sine(440, 0.1);
    const spikes = new Set([1000, 5 * 3 * FS + 2000]); // blocks 0 and 5 of ten
    const gen = (n) => (spikes.has(n) ? [0.9, 0.9] : [base(n), base(n)]);
    const r = analyse(pcm(30, gen));

    assert.ok(Math.abs(r.dr.value - 20 * Math.log10(0.9 / 0.1)) < 0.1, `${r.dr.value}`);
    assert.equal(r.dr.rounded, 19);

    // One spike only: the second highest block peak is the steady 0.1, so the range collapses to about 0.
    const one = analyse(pcm(30, (n) => (n === 1000 ? [0.9, 0.9] : [base(n), base(n)])));
    assert.ok(Math.abs(one.dr.value) < 0.1, `${one.dr.value}`);
});

test('clipping: a flattened sine is found, a clean one is not', () => {
    const loud = sine(220, 1.5);
    const clipped = analyse(pcm(5, (n) => [loud(n), loud(n)]));
    const clean = analyse(pcm(5, (n) => [sine(220, 0.9)(n), sine(220, 0.9)(n)]));

    assert.ok(clipped.clipping.runs > 400, `${clipped.clipping.runs} runs`);
    assert.ok(clipped.clipping.longest_run >= 3);
    assert.equal(clean.clipping.runs, 0);
});

test('effective bit depth: 16 bits in a 24-bit container read 16, real 24 bits read 24', () => {
    const noise = (n) => [Math.sin(n * 12.9898) * 0.3, Math.sin(n * 78.233) * 0.3];

    assert.equal(analyse(pcm(2, noise, { bits: 16 })).effective_bits, 16);
    assert.equal(analyse(pcm(2, noise, { bits: 24 })).effective_bits, 24);
});

test('lossy cutoff: a 16 kHz low-pass is found near 16 kHz, full-band content has none', () => {
    const lowpassed = analyse(pcm(12, bandLimitedNoise(12, 16000)));
    assert.ok(Math.abs(lowpassed.spectrum.cutoff_hz - 16000) <= 1000, `${lowpassed.spectrum.cutoff_hz}`);
    assert.ok(lowpassed.spectrum.cliff_db >= 30);

    const wide = analyse(pcm(12, bandLimitedNoise(12, 21500)));
    assert.ok(wide.spectrum.cutoff_hz === null || wide.spectrum.cutoff_hz >= 20500, `${wide.spectrum.cutoff_hz}`);

    const full = analyse(pcm(12, bandLimitedNoise(12, 22050)));
    assert.equal(full.spectrum.cutoff_hz, null);
});

test('content ceiling: upsampled 96 kHz audio stops at the original Nyquist, real hi-res goes beyond', () => {
    const fs = 96000;
    const up = analyse(pcm(12, bandLimitedNoise(12, 21000, fs), { fs, bits: 24 }), { fs });
    assert.ok(up.spectrum.content_hz >= 21000 && up.spectrum.content_hz <= 22000, `${up.spectrum.content_hz}`);

    const real = analyse(pcm(12, bandLimitedNoise(12, 38000, fs), { fs, bits: 24 }), { fs });
    assert.ok(real.spectrum.content_hz >= 38000, `${real.spectrum.content_hz}`);

    assert.equal(findContent(Array(40).fill(-130)), null);
});

test('findCutoff ignores a dip that recovers and a smooth roll-off', () => {
    const dip = Array.from({ length: 44 }, (_, i) => (i === 30 || i === 31 ? -120 : -60));
    assert.equal(findCutoff(dip).cutoff_hz, null);

    const slope = Array.from({ length: 44 }, (_, i) => -40 - i * 0.8);
    assert.equal(findCutoff(slope).cutoff_hz, null);
});

test('stereo checks: dual mono, inverted channels, DC offset, mono source', () => {
    const s = sine(300, 0.5);

    assert.equal(analyse(pcm(3, (n) => [s(n), s(n)])).dual_mono, true);
    assert.equal(analyse(pcm(3, (n) => [s(n), s(n) * 0.5])).dual_mono, false);
    assert.equal(analyse(pcm(3, (n) => [s(n), -s(n)])).inverted_channels, true);
    assert.ok(analyse(pcm(3, (n) => [s(n) * 0.2 + 0.05, s(n) * 0.2 + 0.05])).dc_offset > 0.049);

    const mono = analyse(pcm(3, (n) => [s(n), 0], { mono: true }), { channels: 1 });
    assert.equal(mono.dual_mono, false);
    assert.equal(mono.channels, 1);
});

test('digital silence inside the track is counted, silence at the ends is not', () => {
    const s = sine(300, 0.3);
    const gap = (n) => (n >= FS * 2 && n < FS * 2 + 4410 ? [0, 0] : [s(n), s(n)]); // 100 ms hole at 2 s
    const r = analyse(pcm(4, (n) => (n < 2000 || n > FS * 4 - 2000 ? [0, 0] : gap(n))));

    assert.equal(r.gaps.count, 1);
    assert.ok(Math.abs(r.gaps.longest_ms - 100) <= 2, `${r.gaps.longest_ms}`);
});

test('loudness range: 40 s at -20 LUFS then 40 s at -30 is about 10 LU', () => {
    const loud = sine(1000, 0.1);
    const quiet = sine(1000, 0.1 * 10 ** -0.5);
    const r = analyse(pcm(80, (n) => (n < FS * 40 ? [loud(n), loud(n)] : [quiet(n), quiet(n)])));

    assert.ok(Math.abs(r.loudness.range - 10) < 0.7, `LRA ${r.loudness.range}`);
    // Both halves are within 10 LU of the mean, so the relative gate keeps them: 10*log10((0.01 + 0.001) / 2) - 0.691
    assert.ok(Math.abs(r.loudness.integrated - -22.6) < 0.2, `integrated ${r.loudness.integrated}`);
});

test('the result does not depend on how the stream is chunked, and the hash follows the audio', () => {
    const s = sine(500, 0.4);
    const data = pcm(6, (n) => [s(n), s(n) * 0.8]);
    const a = analyse(data, { chunk: 1 << 16 });
    const b = analyse(data, { chunk: 1001 });

    assert.deepEqual(a, b);
    assert.match(a.pcm_sha1, /^[0-9a-f]{40}$/);

    const other = Buffer.from(data);
    other[5000] ^= 1;
    assert.notEqual(analyse(other).pcm_sha1, a.pcm_sha1);
});

test('true peak and sample peak are reported in dB', () => {
    const r = analyse(pcm(3, (n) => [sine(11025, 1, FS)(n + 0.5), 0]));

    assert.ok(r.loudness.true_peak_db >= r.loudness.sample_peak_db - 0.01);
    assert.ok(r.loudness.true_peak_db < 0.5);
});
