// Owns the <audio> element and the Web Audio graph. The graph is created lazily
// on the first user gesture (autoplay policy) and exactly once per element.
export class AudioEngine {
    constructor(el) {
        this.el = el;
        this.ctx = null;
    }

    ensureGraph() {
        if (this.ctx) return;

        this.ctx = new AudioContext();
        const source = this.ctx.createMediaElementSource(this.el);

        this.analyser = this.ctx.createAnalyser();
        this.analyser.fftSize = 2048;
        this.analyser.smoothingTimeConstant = 0.8;

        source.connect(this.analyser).connect(this.ctx.destination);

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

        return { freq: this.freq, wave: this.wave };
    }
}
