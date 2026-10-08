// Owns the <audio> element and the Web Audio graph. The graph is created lazily
// on the first user gesture (autoplay policy) and exactly once per element.

const FFT = 2048;
const LEVEL_WINDOW = 1024; // ~23 ms at 44.1 kHz: long enough for a stable RMS, short enough to track peaks

/** What visualizers get before anything has played, so they can draw an idle state instead of nothing. */
export const SILENCE = {
    freq: new Uint8Array(FFT / 2),
    wave: new Uint8Array(FFT).fill(128),
    levels: { l: { rms: 0, peak: 0 }, r: { rms: 0, peak: 0 } },
};

export class AudioEngine {
    constructor(el) {
        this.el = el;
        this.ctx = null;
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

        this.freq = new Uint8Array(this.analyser.frequencyBinCount);
        this.wave = new Uint8Array(this.analyser.fftSize);
        this.shared = { freq: this.freq, wave: this.wave, levels: { l: { rms: 0, peak: 0 }, r: { rms: 0, peak: 0 } } };
    }

    async play(url) {
        this.ensureGraph();

        // Not awaited: without a user gesture the browser leaves this pending until one happens, and the
        // track must still start so the user sees it playing and any later click can unlock the sound.
        this.unlock();

        if (url) {
            this.el.src = url;
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
     * the returned object keeps its previous value. The object and its arrays are reused every call.
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
