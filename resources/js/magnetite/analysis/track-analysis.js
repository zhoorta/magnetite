// Offline measurements for one track, used by `magnetite:analyze` (through bin/measure.mjs). Feed it the
// decoded audio as interleaved 32-bit integers and read `finish()`. The loudness and true-peak maths is the
// same `MeterDsp` the live meters use, so the two cannot disagree.
//
// What it measures and why it matters for "is this file what it says it is":
//   loudness (integrated LUFS, loudness range), true peak          how loud, how much headroom
//   dynamic range (DR, the TT / Pleasurize Music Foundation method) how squashed the mastering is
//   clipping (runs of identical samples at the ceiling)             damage that cannot be undone
//   spectrum, with the cutoff frequency of a lossy encoder          a "lossless" file made from an MP3
//   effective bit depth                                             a "24-bit" file that only holds 16 bits
//   digital-silence gaps, DC offset, dual mono, inverted channels   bad rips and bad conversions
//   SHA-1 of the decoded audio                                      exact duplicates, whatever the tags say

import { createHash } from 'node:crypto';
import { MeterDsp } from '../worklet/meter-processor.js';
import { FFT } from './fft.js';

export const ANALYSIS_VERSION = 1;

const FULL_SCALE = 2 ** 31;
const DR_BLOCK_SECONDS = 3;
const SPECTRUM_FFT = 4096;
const BAND_HZ = 500;
const CLIP_FLOOR_FULL = 0.9999; // at the very top of the range three equal samples in a row are already a clip
const CLIP_FLOOR_SOFT = 0.97; // below that a clipper or limiter ceiling needs a longer flat top to be told from music
const GAP_MIN_SECONDS = 0.02;
const GAP_MAX_SECONDS = 5; // longer digital silence is an intentional pause, not damage

const toDb = (x) => (x > 0 ? 20 * Math.log10(x) : -Infinity);
const round = (x, d = 2) => (Number.isFinite(x) ? Math.round(x * 10 ** d) / 10 ** d : null);

/**
 * Dynamic range as defined by the DR meter (Pleasurize Music Foundation, "DR14"): per channel, split the
 * track into 3 s blocks, take the RMS of the loudest 20% of blocks (RMS counted with a 3 dB boost so a
 * full-scale sine reads 0 dB) and compare it with the second highest block peak. Both channels are averaged.
 * A partial last block counts if it is at least half a block long.
 */
class DynamicRange {
    constructor(sampleRate, channels) {
        this.blockLen = Math.round(sampleRate * DR_BLOCK_SECONDS);
        this.ch = Array.from({ length: channels }, () => ({ sum: 0, peak: 0, n: 0, rms: [], peaks: [] }));
    }

    add(c, x) {
        const s = this.ch[c];
        s.sum += x * x;
        const a = x < 0 ? -x : x;
        if (a > s.peak) s.peak = a;
        if (++s.n === this.blockLen) this.close(s);
    }

    close(s) {
        s.rms.push(Math.sqrt((2 * s.sum) / s.n));
        s.peaks.push(s.peak);
        s.sum = s.peak = s.n = 0;
    }

    finish() {
        const channels = this.ch.map((s) => {
            if (s.n >= this.blockLen / 2) this.close(s);
            if (!s.rms.length) return null;

            const count = s.rms.length;
            const top = Math.max(1, Math.floor(count * 0.2));
            const loudest = [...s.rms].sort((a, b) => b - a).slice(0, top);
            const rms = Math.sqrt(loudest.reduce((t, v) => t + v * v, 0) / top);
            const peaks = [...s.peaks].sort((a, b) => b - a);
            const peak = peaks[Math.min(1, peaks.length - 1)];

            return { dr: rms > 0 && peak > 0 ? toDb(peak / rms) : null, peak, rms };
        });

        if (channels.some((c) => c === null || c.dr === null)) return null;

        const dr = channels.reduce((t, c) => t + c.dr, 0) / channels.length;

        return {
            value: round(dr),
            rounded: Math.round(dr),
            per_channel: channels.map((c) => round(c.dr)),
            peak_db: round(Math.max(...channels.map((c) => toDb(c.peak)))),
            rms_db: round(Math.max(...channels.map((c) => toDb(c.rms)))),
        };
    }
}

/** Runs of exactly equal samples at the ceiling: what hard clipping and clippers leave behind. */
class ClipDetector {
    constructor(channels) {
        this.runs = 0;
        this.samples = 0;
        this.longest = 0;
        this.state = Array.from({ length: channels }, () => ({ value: 0, len: 0 }));
    }

    add(c, x) {
        const s = this.state[c];
        if (s.len > 0 && x === s.value) {
            s.len++;

            return;
        }

        this.flush(s);
        const a = x < 0 ? -x : x;
        if (a >= CLIP_FLOOR_SOFT) {
            s.value = x;
            s.len = 1;
        } else {
            s.len = 0;
        }
    }

    flush(s) {
        const need = Math.abs(s.value) >= CLIP_FLOOR_FULL ? 3 : 6;
        if (s.len >= need) {
            this.runs++;
            this.samples += s.len;
            this.longest = Math.max(this.longest, s.len);
        }
        s.len = 0;
    }

    finish() {
        for (const s of this.state) this.flush(s);

        return { runs: this.runs, samples: this.samples, longest_run: this.longest };
    }
}

/**
 * Average spectrum of the track in bands of BAND_HZ, and where a lossy encoder cut it off. Lossy codecs
 * low-pass the audio (MP3 at 128 kbit/s about 16 kHz, at 320 kbit/s about 20 kHz); the result is a sharp cliff
 * in the average spectrum with nothing but the noise floor above it, which a CD-quality master does not have.
 */
class Spectrum {
    constructor(sampleRate) {
        this.fs = sampleRate;
        this.fft = new FFT(SPECTRUM_FFT);
        this.window = Float64Array.from({ length: SPECTRUM_FFT }, (_, i) => 0.5 - 0.5 * Math.cos((2 * Math.PI * i) / SPECTRUM_FFT));
        this.windowSum = this.window.reduce((a, b) => a + b, 0);
        this.re = new Float64Array(SPECTRUM_FFT);
        this.im = new Float64Array(SPECTRUM_FFT);
        this.power = new Float64Array(SPECTRUM_FFT / 2);
        this.fill = 0;
        this.frames = 0;
    }

    add(mono) {
        this.re[this.fill++] = mono;
        if (this.fill === SPECTRUM_FFT) {
            this.frame();
            this.fill = 0;
        }
    }

    frame() {
        for (let i = 0; i < SPECTRUM_FFT; i++) this.re[i] *= this.window[i];
        this.im.fill(0);
        this.fft.transform(this.re, this.im);
        for (let k = 0; k < SPECTRUM_FFT / 2; k++) this.power[k] += this.re[k] * this.re[k] + this.im[k] * this.im[k];
        this.frames++;
    }

    /** dB per band from 0 Hz up to the last band that fits under the Nyquist frequency. */
    bands() {
        if (!this.frames) return [];

        const binHz = this.fs / SPECTRUM_FFT;
        const n = Math.floor(this.fs / 2 / BAND_HZ);
        const norm = (this.windowSum / 2) ** 2 * this.frames;
        const out = [];
        for (let b = 0; b < n; b++) {
            const lo = Math.ceil((b * BAND_HZ) / binHz);
            const hi = Math.min(SPECTRUM_FFT / 2, Math.ceil(((b + 1) * BAND_HZ) / binHz));
            let sum = 0;
            for (let k = lo; k < hi; k++) sum += this.power[k];
            out.push(round(10 * Math.log10(Math.max(sum / (hi - lo) / norm, 1e-20)), 1));
        }

        return out;
    }

    finish() {
        const db = this.bands();

        return { step_hz: BAND_HZ, db, ...findCutoff(db), content_hz: findContent(db) };
    }
}

/**
 * The highest steep drop in the average spectrum (30 dB over about 500 Hz) after which everything stays low.
 * Looks from 8 kHz up. Returns the frequency where the drop starts, or null when the spectrum fades out
 * smoothly or reaches the Nyquist frequency, as that of a normal recording does.
 */
export function findCutoff(db, { fromHz = 8000, dropDb = 30 } = {}) {
    const first = Math.ceil(fromHz / BAND_HZ) + 2;
    for (let j = db.length - 1; j >= first; j--) {
        const before = (db[j - 2] + db[j - 1]) / 2;
        const after = j + 1 < db.length ? (db[j] + db[j + 1]) / 2 : db[j];
        if (before - after < dropDb) continue;

        // Everything above the drop must stay low, or it was a dip rather than a cutoff.
        let stays = true;
        for (let k = j; k < db.length; k++) {
            if (db[k] > before - dropDb * 0.66) {
                stays = false;
                break;
            }
        }
        if (stays) return { cutoff_hz: j * BAND_HZ, cliff_db: round(before - after, 1) };
    }

    return { cutoff_hz: null, cliff_db: null };
}

/**
 * Top of the real content: the upper edge of the highest band above -95 dB (an amplitude-1 sine reads 0 dB, white
 * noise at -98 dBFS reads about -129 dB per bin). The threshold sits between the shelf of resampler noise a CD
 * master leaves above 22 kHz when it is upsampled (about -98 to -113 dB) and the content of a real 96 kHz
 * recording (-70 dB and up at 24 kHz), so a "96 kHz" file whose content stops near 22 kHz was upsampled.
 */
export function findContent(db, { thresholdDb = -95 } = {}) {
    for (let j = db.length - 1; j >= 0; j--) {
        if (db[j] >= thresholdDb) return (j + 1) * BAND_HZ;
    }

    return null;
}

export class TrackAnalysis {
    /**
     * @param {{sampleRate: number, channels: number}} format  channels is 1 or 2 (downmix anything else first)
     */
    constructor({ sampleRate, channels }) {
        this.fs = sampleRate;
        this.channels = channels;
        this.dsp = new MeterDsp(sampleRate, { live: false, lra: true });
        this.dr = new DynamicRange(sampleRate, channels);
        this.clip = new ClipDetector(channels);
        this.spectrum = new Spectrum(sampleRate);
        this.hash = createHash('sha1');
        this.frames = 0;
        this.bitsOr = 0;
        this.peak = 0;
        this.sum = [0, 0];
        this.maxDiff = 0;
        this.maxSum = 0;
        this.gaps = { count: 0, longest: 0, run: 0, seenSound: false };
        this.leftover = Buffer.alloc(0);
        this.chunk = { l: new Float32Array(0), r: new Float32Array(0) };
    }

    /** Feed raw little-endian signed 32-bit interleaved PCM, in chunks of any size. */
    push(buffer) {
        this.hash.update(buffer);

        const frameBytes = this.channels * 4;
        const data = this.leftover.length ? Buffer.concat([this.leftover, buffer]) : buffer;
        const usable = data.length - (data.length % frameBytes);
        this.leftover = data.subarray(usable);

        // Copy so the Int32Array is aligned whatever the Buffer's offset is.
        const ints = new Int32Array(usable / 4);
        Buffer.from(ints.buffer).set(data.subarray(0, usable));

        const n = usable / frameBytes;
        if (this.chunk.l.length < n) this.chunk = { l: new Float32Array(n), r: new Float32Array(n) };
        const { l, r } = this.chunk;
        const stereo = this.channels === 2;

        for (let i = 0; i < n; i++) {
            const a = ints[i * this.channels];
            const b = stereo ? ints[i * 2 + 1] : a;
            this.bitsOr |= a | b;
            l[i] = a / FULL_SCALE;
            r[i] = b / FULL_SCALE;
            this.sample(l[i], r[i], stereo);
        }

        this.dsp.process(l.subarray(0, n), r.subarray(0, n));
        this.frames += n;
    }

    sample(l, r, stereo) {
        this.dr.add(0, l);
        this.clip.add(0, l);
        if (stereo) {
            this.dr.add(1, r);
            this.clip.add(1, r);
        }

        const al = l < 0 ? -l : l;
        const ar = r < 0 ? -r : r;
        if (al > this.peak) this.peak = al;
        if (ar > this.peak) this.peak = ar;
        this.sum[0] += l;
        this.sum[1] += r;
        const d = l - r < 0 ? r - l : l - r;
        const s = l + r < 0 ? -(l + r) : l + r;
        if (d > this.maxDiff) this.maxDiff = d;
        if (s > this.maxSum) this.maxSum = s;

        this.spectrum.add((l + r) / 2);

        // Digital silence inside the programme (a gap after sound and before more sound).
        const g = this.gaps;
        if (l === 0 && r === 0) {
            g.run++;
        } else {
            if (g.seenSound && g.run >= this.fs * GAP_MIN_SECONDS && g.run <= this.fs * GAP_MAX_SECONDS) {
                g.count++;
                g.longest = Math.max(g.longest, g.run);
            }
            g.run = 0;
            g.seenSound = true;
        }
    }

    finish() {
        const n = this.frames;
        const dsp = this.dsp.snapshot();
        const tp = Math.max(dsp.tpL, dsp.tpR);
        const dc = n ? Math.max(Math.abs(this.sum[0]), Math.abs(this.sum[1])) / n : 0;
        const trailingZeros = this.bitsOr === 0 ? 32 : Math.clz32(this.bitsOr & -this.bitsOr) ^ 31;

        return {
            version: ANALYSIS_VERSION,
            sample_rate: this.fs,
            channels: this.channels,
            duration: round(n / this.fs, 2),
            loudness: {
                integrated: round(this.dsp.integratedLufs(), 1),
                range: round(this.dsp.loudnessRange(), 1),
                true_peak_db: round(toDb(tp)),
                sample_peak_db: round(toDb(this.peak)),
            },
            dr: this.dr.finish(),
            clipping: this.clip.finish(),
            gaps: { count: this.gaps.count, longest_ms: Math.round((this.gaps.longest / this.fs) * 1000) },
            dc_offset: round(dc, 5),
            dual_mono: this.channels === 2 && n > 0 && this.maxDiff === 0,
            inverted_channels: this.channels === 2 && n > 0 && this.maxSum < 1e-6 && this.maxDiff > 0,
            effective_bits: this.bitsOr === 0 ? 0 : 32 - trailingZeros,
            spectrum: this.spectrum.finish(),
            pcm_sha1: this.hash.digest('hex'),
        };
    }
}
