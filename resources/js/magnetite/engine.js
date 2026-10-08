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
    }

    async play(url) {
        this.ensureGraph();
        await this.ctx.resume();

        if (url) {
            this.el.src = url;
        }

        return this.el.play();
    }

    pause() {
        this.el.pause();
    }

    sample() {
        if (!this.analyser) return null;

        this.analyser.getByteFrequencyData(this.freq);
        this.analyser.getByteTimeDomainData(this.wave);

        const [l, r] = this.channels.map((c) => this.level(c));

        return { freq: this.freq, wave: this.wave, levels: { l, r } };
    }

    /** Linear RMS and peak (0..1, full scale = 1) of the latest window of one channel. */
    level({ node, buf }) {
        node.getFloatTimeDomainData(buf);

        let sum = 0;
        let peak = 0;
        for (let i = 0; i < buf.length; i++) {
            const v = buf[i];
            sum += v * v;
            const a = v < 0 ? -v : v;
            if (a > peak) peak = a;
        }

        return { rms: Math.sqrt(sum / buf.length), peak };
    }
}
