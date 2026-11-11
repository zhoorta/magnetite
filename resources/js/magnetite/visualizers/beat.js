// Beat and level helpers for the scene visualizers. No canvas: Node tests it.

import { BAND_FLOOR } from '../bands.js';

export const norm = (db, min, max) => {
    const x = (db - min) / (max - min);

    return x < 0 ? 0 : x > 1 ? 1 : x;
};

/** Mean power of bands `lo..hi` (inclusive), in dB. */
export function bandPowerDb(bands, lo, hi) {
    let sum = 0;
    for (let i = lo; i <= hi; i++) sum += Math.exp(bands[i] * (Math.LN10 / 10));
    const mean = sum / (hi - lo + 1);

    return mean > 0 ? 10 * Math.log10(mean) : BAND_FLOOR;
}

/**
 * A kick detector on the low bands: an onset is the bass energy rising `threshold` dB above its own slow average
 * (so it follows the loudness of the track), with a refractory time so one hit is one onset. `pulse` jumps to 1
 * on an onset and falls with time constant `decay`; `energy` is the smoothed bass level in dB.
 */
export class Beat {
    constructor() {
        this.avg = BAND_FLOOR;
        this.energy = BAND_FLOOR;
        this.pulse = 0;
        this.cool = 0;
        this.primed = false;
    }

    /** Feed one frame of third-octave `bands`; returns true when this frame is an onset. */
    update(bands, dt, { lo = 2, hi = 11, threshold = 4, cooldown = 0.2, decay = 0.22, floor = -60 } = {}) {
        const e = bandPowerDb(bands, lo, hi);
        this.energy = e;
        if (!this.primed) {
            this.avg = Math.max(e, BAND_FLOOR); // start from what is playing, not from silence
            this.primed = true;
        }
        this.pulse *= Math.exp(-dt / decay);
        this.cool -= dt;

        const hit = this.cool <= 0 && e > floor && e > this.avg + threshold;
        if (hit) {
            this.pulse = 1;
            this.cool = cooldown;
        }
        this.avg += (Math.max(e, BAND_FLOOR) - this.avg) * (1 - Math.exp(-dt / 0.6));

        return hit;
    }
}
