// Third-octave band levels (ISO 266 preferred centres, 20 Hz to 20 kHz) from a Web Audio spectrum.

/** Nominal centre frequencies, as printed on a real-time analyser. */
export const BAND_LABELS = [
    20, 25, 31.5, 40, 50, 63, 80, 100, 125, 160, 200, 250, 315, 400, 500, 630, 800, 1000, 1250, 1600, 2000, 2500, 3150,
    4000, 5000, 6300, 8000, 10000, 12500, 16000, 20000,
];

/** Exact base-2 centres: 1 kHz and every third of an octave around it. */
const CENTERS = BAND_LABELS.map((_, i) => 1000 * 2 ** ((i - 17) / 3));
const HALF_BAND = 2 ** (1 / 6);

/**
 * What a full-scale sine reads when the powers of all the bins around it are added: the analyser's Blackman
 * window and 1/N scaling spread it as A^2/4 * mean(w^2) = -11.2 dB. Adding this makes a full-scale sine read 0 dB.
 */
const SINE_CALIBRATION = 11.18;

export const BAND_FLOOR = -120;

const DB_TO_NEPER = Math.LN10 / 10; // exp(db * this) = 10 ** (db / 10), a little faster in the hot loop

const cache = new Map();

/** First and last bin of every band, for one sample rate and FFT size. */
function binRanges(fs, fftSize) {
    const key = `${fs}/${fftSize}`;
    if (cache.has(key)) return cache.get(key);

    const bins = fftSize / 2;
    const width = fs / fftSize;
    const ranges = CENTERS.map((c) => {
        let lo = Math.ceil((c / HALF_BAND) / width);
        let hi = Math.ceil((c * HALF_BAND) / width) - 1; // upper edge belongs to the next band
        if (hi < lo) lo = hi = Math.min(bins - 1, Math.round(c / width)); // band narrower than a bin
        return [Math.min(lo, bins - 1), Math.min(hi, bins - 1)];
    });
    cache.set(key, ranges);

    return ranges;
}

/**
 * Fill `out` (31 values, dB, a full-scale sine = 0) from `db`, the result of getFloatFrequencyData.
 * Bands above the Nyquist frequency read BAND_FLOOR.
 */
export function bandsFromSpectrum(db, fs, fftSize, out) {
    const ranges = binRanges(fs, fftSize);

    for (let b = 0; b < ranges.length; b++) {
        if (CENTERS[b] / HALF_BAND >= fs / 2) {
            out[b] = BAND_FLOOR;
            continue;
        }

        let power = 0;
        for (let k = ranges[b][0]; k <= ranges[b][1]; k++) power += Math.exp(db[k] * DB_TO_NEPER);
        out[b] = power > 0 ? Math.max(BAND_FLOOR, 10 * Math.log10(power) + SINE_CALIBRATION) : BAND_FLOOR;
    }

    return out;
}
