// Shared maths for the level-meter visualizers: dB conversion, ballistics and peak hold.

export const clamp01 = (x) => (x < 0 ? 0 : x > 1 ? 1 : x);

/** Linear amplitude (1 = full scale) to dBFS, floored so silence does not become -Infinity. */
export const toDb = (x) => 20 * Math.log10(Math.max(x, 1e-6));

/**
 * One channel of a meter. `value` and `peak` are positions on the scale (0..1 between `min` and `max` dB).
 * The reading moves like a real meter: it takes `attack` / `release` seconds to follow the signal,
 * the peak marker holds for `hold` seconds and then falls at `fall` scale-lengths per second.
 */
export class Meter {
    constructor() {
        this.value = 0;
        this.peak = 0;
        this.holdLeft = 0;
        this.clipLeft = 0;
        this.db = -Infinity;
    }

    update({ rms, peak }, dt, { min, max, attack, release, hold, fall, clipDb = -0.5 }) {
        const at = (lin) => clamp01((toDb(lin) - min) / (max - min));

        const target = at(rms);
        const tau = (target > this.value ? attack : release) / 4.6; // 4.6 time constants ~ 99% of the way
        this.value += (target - this.value) * (1 - Math.exp(-dt / tau));
        this.db = toDb(rms);

        const p = at(peak);
        if (p >= this.peak) {
            this.peak = p;
            this.holdLeft = hold;
        } else if (this.holdLeft > 0) {
            this.holdLeft -= dt;
        } else {
            this.peak = Math.max(p, this.peak - fall * dt);
        }

        this.clipLeft = toDb(peak) >= clipDb ? 0.6 : Math.max(0, this.clipLeft - dt);
    }
}

/** Pair of meters, created lazily so each visualizer module keeps its own state. */
export const stereoMeters = () => ({ l: new Meter(), r: new Meter() });
