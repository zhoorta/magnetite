// Owns the <audio> element and the Web Audio graph. The graph is created lazily
// on the first user gesture (autoplay policy) and exactly once per element.

import meterWorklet from './worklet/meter-processor.js?worker&url';

const FFT = 2048;
const LEVEL_WINDOW = 1024; // ~23 ms at 44.1 kHz: long enough for a stable RMS, short enough to track peaks

/** What visualizers get before anything has played, so they can draw an idle state instead of nothing. */
export const SILENCE = {
    freq: new Uint8Array(FFT / 2),
    wave: new Uint8Array(FFT).fill(128),
    levels: { l: { rms: 0, peak: 0 }, r: { rms: 0, peak: 0 } },
    lufs: { m: -Infinity, s: -Infinity, i: -Infinity, tpL: 0, tpR: 0 },
    stereo: { corr: 0, gonio: new Float32Array(2) },
};

const idleLufs = () => ({ ...SILENCE.lufs });
const idleStereo = () => ({ corr: 0, gonio: new Float32Array(2) });

export class AudioEngine {
    constructor(el) {
        this.el = el;
        this.ctx = null;
        // Fetched now, before any audio is streamed: a single-threaded dev server (`php artisan serve`) keeps
        // answering the audio request and would leave a worklet request queued behind it for the whole track.
        this.workletUrl = fetch(meterWorklet)
            .then((r) => r.text())
            .then((code) => URL.createObjectURL(new Blob([code], { type: 'text/javascript' })))
            .catch(() => meterWorklet);
        // Filled by the meter worklet about 60 times a second; stay at their idle values until it is running.
        this.lufs = idleLufs();
        this.stereo = idleStereo();
    }

    ensureGraph() {
        if (this.ctx) return;

        this.ctx = new AudioContext();
        // Surface the state ('running', 'suspended', 'interrupted') so the UI can say when sound is blocked.
        this.ctx.onstatechange = () => this.onState?.(this.ctx.state);
        const source = this.ctx.createMediaElementSource(this.el);

        // Mixed analyser for the spectrum and waveform visualizers.
        this.analyser = this.ctx.createAnalyser();
        this.analyser.fftSize = FFT;
        this.analyser.smoothingTimeConstant = 0.8;
        source.connect(this.analyser).connect(this.ctx.destination);

        // One analyser per channel for the level meters. They only listen: nothing is routed on
        // from them, so the audio the user hears is untouched.
        const split = this.ctx.createChannelSplitter(2);
        source.connect(split);
        this.channels = [0, 1].map((i) => {
            const a = this.ctx.createAnalyser();
            a.fftSize = LEVEL_WINDOW;
            split.connect(a, i);

            return { node: a, buf: new Float32Array(LEVEL_WINDOW) };
        });

        this.startMeterWorklet(source);

        this.freq = new Uint8Array(this.analyser.frequencyBinCount);
        this.wave = new Uint8Array(this.analyser.fftSize);
        this.shared = {
            freq: this.freq,
            wave: this.wave,
            levels: { l: { rms: 0, peak: 0 }, r: { rms: 0, peak: 0 } },
            lufs: this.lufs,
            stereo: this.stereo,
        };
    }

    /**
     * The sample-accurate meters (loudness, true peak, correlation, goniometer) run in an AudioWorklet.
     * It listens like the analysers do: its output is silent, so the heard signal is untouched. If the
     * browser cannot load it the meters that need it simply stay idle.
     */
    startMeterWorklet(source) {
        this.workletUrl
            .then((url) => this.ctx.audioWorklet.addModule(url))
            .then(() => {
                const node = new AudioWorkletNode(this.ctx, 'magnetite-meter', {
                    numberOfInputs: 1,
                    numberOfOutputs: 1,
                    channelCount: 2,
                    channelCountMode: 'explicit',
                    outputChannelCount: [2],
                });
                node.port.onmessage = ({ data }) => {
                    this.lufs.m = data.lufsM;
                    this.lufs.s = data.lufsS;
                    this.lufs.i = data.lufsI;
                    this.lufs.tpL = data.tpL;
                    this.lufs.tpR = data.tpR;
                    this.stereo.corr = data.corr;
                    this.stereo.gonio = data.gonio;
                };
                source.connect(node).connect(this.ctx.destination);
                this.meterNode = node;
            })
            .catch((e) => {
                this.meterError = e;
                console.warn('Meter worklet unavailable:', e);
            });
    }

    async play(url) {
        this.ensureGraph();

        // Not awaited: without a user gesture the browser leaves this pending until one happens, and the
        // track must still start so the user sees it playing and any later click can unlock the sound.
        this.unlock();

        if (url) {
            this.el.src = url;
            // Loudness is per track: start the integrated value and the true-peak hold from zero.
            this.meterNode?.port.postMessage('reset');
        }

        return this.el.play();
    }

    /** Ask the browser to start the audio graph; resolves to whether sound can come out now. */
    async unlock() {
        if (!this.ctx) return false;

        if (this.ctx.state !== 'running') {
            try {
                await this.ctx.resume();
            } catch {
                // stays suspended; the UI shows it
            }
        }

        this.onState?.(this.ctx.state);

        return this.ctx.state === 'running';
    }

    pause() {
        this.el.pause();
    }

    /**
     * Latest measurements, computed only for the inputs in `needs` ('freq', 'wave', 'levels'); the rest of
     * the returned object keeps its previous value. 'lufs' and 'stereo' come from the worklet and are always
     * current. The object and its arrays are reused every call.
     */
    sample(needs) {
        if (!this.analyser) return null;

        const s = this.shared;
        if (needs.includes('freq')) this.analyser.getByteFrequencyData(this.freq);
        if (needs.includes('wave')) this.analyser.getByteTimeDomainData(this.wave);
        if (needs.includes('levels')) {
            this.level(this.channels[0], s.levels.l);
            this.level(this.channels[1], s.levels.r);
        }

        return s;
    }

    /** Linear RMS and peak (0..1, full scale = 1) of the latest window of one channel, written into `out`. */
    level({ node, buf }, out) {
        node.getFloatTimeDomainData(buf);

        let sum = 0;
        let peak = 0;
        for (let i = 0; i < buf.length; i++) {
            const v = buf[i];
            sum += v * v;
            const a = v < 0 ? -v : v;
            if (a > peak) peak = a;
        }

        out.rms = Math.sqrt(sum / buf.length);
        out.peak = peak;
    }
}
