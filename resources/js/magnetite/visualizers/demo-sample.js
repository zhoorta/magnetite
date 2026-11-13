// A made-up but music-like sample, for pictures of a meter that has no audio behind it (the thumbnails in the meter menu). No DOM.

/** A fresh sample object in the shape the engine hands to meters. */
export function demoSample() {
    return {
        freq: new Uint8Array(512),
        wave: new Uint8Array(1024).fill(128),
        levels: { l: { rms: 0.1, peak: 0.3 }, r: { rms: 0.1, peak: 0.3 } },
        lufs: { m: -14, s: -15, i: -16, tpL: 0.6, tpR: 0.55 },
        stereo: { corr: 0.4, gonio: new Float32Array(2) },
        bands: new Float32Array(31),
        spec: new Float32Array(1024).fill(-120),
        playing: true,
        sampleRate: 44100,
    };
}

/** Moves `sample` to time `t` seconds: low bands loudest, every band slowly moving, the channels a little apart. */
export function stepDemo(sample, t) {
    for (let i = 0; i < sample.bands.length; i++) {
        const swing = 0.5 + 0.5 * Math.sin(t * (0.9 + i * 0.13) + i * 1.7);
        sample.bands[i] = -80 + 46 * swing * (1 - i / 62) + (i < 5 && Math.sin(t * 5) > 0.6 ? 6 : 0);
    }
    sample.levels.l.rms = 0.07 + 0.05 * Math.sin(t * 1.3);
    sample.levels.r.rms = 0.07 + 0.05 * Math.sin(t * 1.1 + 1);
    sample.levels.l.peak = sample.levels.l.rms * 3;
    sample.levels.r.peak = sample.levels.r.rms * 3;
    sample.stereo.corr = 0.3 + 0.5 * Math.sin(t * 0.6);

    return sample;
}
