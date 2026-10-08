// Sample-accurate meters, run on the audio thread so no sample is skipped (an AnalyserNode only gives
// windows at animation-frame rate, which is not enough for gated loudness).
//
//   - LUFS: K-weighting and gating from ITU-R BS.1770-4 / EBU R128 (momentary 400 ms, short-term 3 s, integrated)
//   - true peak: 4x oversampling with a 49-tap windowed-sinc interpolator (BS.1770 Annex 2 uses 48 taps; this
//     filter has the same length and puts phase 0 exactly on the input samples)
//   - phase correlation (0.3 s exponential average) and the last GONIO_LEN samples for the goniometer
//
// Self-contained on purpose: it is loaded with audioWorklet.addModule and also imported by the Node tests.

export const GONIO_LEN = 1024;

const ABS_GATE = -70; // LUFS
const REL_GATE = -10; // LU below the ungated mean
const OFFSET = -0.691;
const CORR_TAU = 0.3; // seconds
const OVERSAMPLE = 4;
const TAPS_PER_PHASE = 12;

const energyToLufs = (e) => (e > 0 ? OFFSET + 10 * Math.log10(e) : -Infinity);
const lufsToEnergy = (l) => 10 ** ((l - OFFSET) / 10);

/** BS.1770 K-weighting: a high shelf (head) followed by the RLB high-pass, for any sample rate. */
function kWeighting(fs) {
    const shelf = (() => {
        const f0 = 1681.974450955533;
        const G = 3.999843853973347;
        const Q = 0.7071752369554196;
        const K = Math.tan((Math.PI * f0) / fs);
        const Vh = 10 ** (G / 20);
        const Vb = Vh ** 0.4996667741545416;
        const a0 = 1 + K / Q + K * K;

        return {
            b: [(Vh + (Vb * K) / Q + K * K) / a0, (2 * (K * K - Vh)) / a0, (Vh - (Vb * K) / Q + K * K) / a0],
            a: [(2 * (K * K - 1)) / a0, (1 - K / Q + K * K) / a0],
        };
    })();

    const highpass = (() => {
        const f0 = 38.13547087602444;
        const Q = 0.5003270373238773;
        const K = Math.tan((Math.PI * f0) / fs);
        const a0 = 1 + K / Q + K * K;

        return { b: [1, -2, 1], a: [(2 * (K * K - 1)) / a0, (1 - K / Q + K * K) / a0] };
    })();

    return [shelf, highpass];
}

/** Transposed direct form II biquad, one per channel and stage. */
class Biquad {
    constructor({ b, a }) {
        this.b = b;
        this.a = a;
        this.z1 = 0;
        this.z2 = 0;
    }

    run(x) {
        const y = this.b[0] * x + this.z1;
        this.z1 = this.b[1] * x - this.a[0] * y + this.z2;
        this.z2 = this.b[2] * x - this.a[1] * y;

        return y;
    }
}

/** Polyphase 4x interpolator coefficients: phase p uses taps[p][k] on the sample k steps back. */
function interpolator() {
    const n = OVERSAMPLE * TAPS_PER_PHASE + 1;
    const mid = (n - 1) / 2;
    const taps = Array.from({ length: OVERSAMPLE }, () => new Float64Array(TAPS_PER_PHASE + 1));

    for (let i = 0; i < n; i++) {
        const x = (i - mid) / OVERSAMPLE;
        const sinc = x === 0 ? 1 : Math.sin(Math.PI * x) / (Math.PI * x);
        const t = (2 * Math.PI * i) / (n - 1);
        const blackman = 0.42 - 0.5 * Math.cos(t) + 0.08 * Math.cos(2 * t);
        taps[i % OVERSAMPLE][Math.floor(i / OVERSAMPLE)] = sinc * blackman;
    }

    // Each phase must pass DC with gain 1, or a steady level would read high or low.
    for (const phase of taps) {
        const sum = phase.reduce((s, v) => s + v, 0);
        for (let i = 0; i < phase.length; i++) phase[i] /= sum;
    }

    return taps;
}

export class MeterDsp {
    constructor(sampleRate) {
        this.fs = sampleRate;
        this.filters = [0, 1].map(() => kWeighting(sampleRate).map((c) => new Biquad(c)));
        this.blockLen = Math.round(sampleRate * 0.1);
        this.taps = interpolator();
        this.corrAlpha = Math.exp(-1 / (sampleRate * CORR_TAU));
        this.gonio = new Float32Array(GONIO_LEN * 2);
        this.history = [0, 1].map(() => new Float64Array(TAPS_PER_PHASE + 1));
        this.reset();
    }

    reset() {
        this.blockSum = 0; // K-weighted energy of the running 100 ms block, both channels
        this.blockCount = 0;
        this.blocks = []; // last 30 block energies (3 s)
        this.gated = []; // 400 ms block energies above the absolute gate, for the integrated value
        this.integrated = -Infinity;
        this.tp = [0, 0]; // true peak since reset, linear
        this.sLL = 0;
        this.sRR = 0;
        this.sLR = 0;
        this.gonioPos = 0;
        this.gonio.fill(0);
        for (const f of this.filters) for (const s of f) s.z1 = s.z2 = 0;
        for (const h of this.history) h.fill(0);
    }

    /** Feed one block of left and right samples. */
    process(left, right) {
        const a = this.corrAlpha;
        const hist = this.history;

        for (let i = 0; i < left.length; i++) {
            const l = left[i];
            const r = right[i];

            // loudness
            const kl = this.filters[0][1].run(this.filters[0][0].run(l));
            const kr = this.filters[1][1].run(this.filters[1][0].run(r));
            this.blockSum += kl * kl + kr * kr;
            if (++this.blockCount === this.blockLen) this.endBlock();

            // true peak
            this.tp[0] = Math.max(this.tp[0], this.truePeak(hist[0], l));
            this.tp[1] = Math.max(this.tp[1], this.truePeak(hist[1], r));

            // correlation and goniometer
            this.sLL = this.sLL * a + l * l * (1 - a);
            this.sRR = this.sRR * a + r * r * (1 - a);
            this.sLR = this.sLR * a + l * r * (1 - a);
            this.gonio[this.gonioPos * 2] = l;
            this.gonio[this.gonioPos * 2 + 1] = r;
            this.gonioPos = (this.gonioPos + 1) % GONIO_LEN;
        }
    }

    /** Peak of the 4 interpolated values around the newest sample, and push the sample into the history. */
    truePeak(h, x) {
        h.copyWithin(1, 0);
        h[0] = x;

        let peak = 0;
        for (const phase of this.taps) {
            let y = 0;
            for (let k = 0; k < phase.length; k++) y += phase[k] * h[k];
            peak = Math.max(peak, Math.abs(y));
        }

        return peak;
    }

    endBlock() {
        const e = this.blockSum / this.blockLen;
        this.blockSum = 0;
        this.blockCount = 0;

        this.blocks.push(e);
        if (this.blocks.length > 30) this.blocks.shift();

        // Gating uses 400 ms blocks every 100 ms (75% overlap).
        if (this.blocks.length >= 4) {
            const e400 = this.mean(this.blocks.slice(-4));
            if (energyToLufs(e400) > ABS_GATE) this.gated.push(e400);
            this.integrated = this.integratedLufs();
        }
    }

    mean(list) {
        let s = 0;
        for (const v of list) s += v;

        return list.length ? s / list.length : 0;
    }

    integratedLufs() {
        if (!this.gated.length) return -Infinity;

        const threshold = lufsToEnergy(energyToLufs(this.mean(this.gated)) + REL_GATE);
        let sum = 0;
        let n = 0;
        for (const e of this.gated) {
            if (e > threshold) {
                sum += e;
                n++;
            }
        }

        return n ? energyToLufs(sum / n) : -Infinity;
    }

    /** Current readings. `gonio` is a fresh interleaved L,R copy in time order, oldest first. */
    snapshot() {
        const gonio = new Float32Array(GONIO_LEN * 2);
        const split = this.gonioPos * 2;
        gonio.set(this.gonio.subarray(split), 0);
        gonio.set(this.gonio.subarray(0, split), this.gonio.length - split);

        const d = Math.sqrt(this.sLL * this.sRR);

        return {
            lufsM: this.blocks.length >= 4 ? energyToLufs(this.mean(this.blocks.slice(-4))) : -Infinity,
            lufsS: this.blocks.length >= 30 ? energyToLufs(this.mean(this.blocks)) : -Infinity,
            lufsI: this.integrated,
            tpL: this.tp[0],
            tpR: this.tp[1],
            corr: d > 1e-9 ? this.sLR / d : 0,
            gonio,
        };
    }
}

// The audio-thread half: only defined where the worklet scope exists.
if (typeof AudioWorkletProcessor !== 'undefined') {
    const SILENCE = new Float32Array(128);

    class MagnetiteMeter extends AudioWorkletProcessor {
        constructor() {
            super();
            this.dsp = new MeterDsp(sampleRate);
            this.every = Math.round(sampleRate / 60);
            this.since = 0;
            this.port.onmessage = (e) => {
                if (e.data === 'reset') this.dsp.reset();
            };
        }

        process(inputs) {
            const input = inputs[0];
            const left = input[0] ?? SILENCE;
            const right = input[1] ?? left; // a mono source feeds both sides

            this.dsp.process(left, right);

            this.since += left.length;
            if (this.since >= this.every) {
                this.since = 0;
                const snap = this.dsp.snapshot();
                this.port.postMessage(snap, [snap.gonio.buffer]);
            }

            return true;
        }
    }

    registerProcessor('magnetite-meter', MagnetiteMeter);
}
