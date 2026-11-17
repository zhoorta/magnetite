// A made-up but music-like sample, for pictures of a meter that has no audio behind it (the thumbnails in the meter menu). No DOM.

/** A fresh sample object in the shape the engine hands to meters. */
export function demoSample() {
    return {
        freq: new Uint8Array(512),
        wave: new Uint8Array(1024).fill(128),
        levels: { l: { rms: 0.1, peak: 0.3 }, r: { rms: 0.1, peak: 0.3 } },
        lufs: { m: -14, s: -15, i: -16, tpL: 0.6, tpR: 0.55, lra: 8.2, lraLo: -21.5, lraHi: -13.3, lraShape: Uint32Array.from({ length: 60 }, (_, i) => Math.round(220 * Math.exp(-(((i - 41.5) / 4.5) ** 2)) + 60 * Math.exp(-(((i - 34) / 3) ** 2)))) },
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
    const crest = 2.6 + 0.9 * Math.sin(t * 0.9); // peak over RMS, moving so the crest factor line has something to draw
    sample.levels.l.peak = sample.levels.l.rms * crest;
    sample.levels.r.peak = sample.levels.r.rms * crest;
    sample.stereo.corr = 0.3 + 0.5 * Math.sin(t * 0.6);

    // The raw spectrum (dB per bin, a falling slope with a few moving peaks) and a two-tone wave, for the spectrogram and the scope.
    for (let i = 0; i < sample.spec.length; i++) {
        const x = i / sample.spec.length;
        const peaks = 14 * Math.max(0, Math.sin(t * 1.4 + x * 38)) * (1 - x);
        sample.spec[i] = -28 - 62 * Math.sqrt(x) + peaks + 5 * Math.sin(t * 3 + i * 0.7);
    }
    for (let i = 0; i < sample.freq.length; i++) sample.freq[i] = Math.max(0, Math.min(255, 255 * (1 - i / sample.freq.length) ** 1.5 * (0.6 + 0.3 * Math.sin(t * 2 + i * 0.2))));
    for (let i = 0; i < sample.wave.length; i++) {
        const k = i / sample.wave.length;
        sample.wave[i] = 128 + 70 * Math.sin(2 * Math.PI * (3 * k + t * 2)) + 30 * Math.sin(2 * Math.PI * (7 * k + t * 3));
    }

    return sample;
}
