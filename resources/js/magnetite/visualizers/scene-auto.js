// Finds the parts of a picture that can carry the music: the bright blobs. Pure, on a grayscale grid, so Node tests it.

/**
 * `luma` is w*h values 0..1; `hue` (0..1) and `sat` (0..1) are optional and only used when the blobs are too few. Returns `{ labels, blobs, kick, bands }`: `labels` gives every pixel a blob number (0 = none),
 * `blobs[i]` has `{ id, area, cx, cy, mean }` (centroid in 0..1), `kick` is the blob that pulses with the bass (the biggest,
 * brightest one) and `bands` are up to `max` more, ordered left to right, so bass is on the left and treble on the right.
 */
export function analyze(luma, w, h, { share = 0.14, max = 8, minArea = 0.002, maxArea = 0.4, hue = null, sat = null, invert = true } = {}) {
    const n = w * h;
    const out = find(luma, w, h, { share, max, minArea, maxArea, hue, sat });

    // a bright picture (white paper, a pale sky) has nothing to light: let its dark shapes carry the music instead
    if (invert) {
        let sum = 0;
        for (let i = 0; i < n; i++) sum += luma[i];
        if (sum / n > 0.6) {
            const dark = new Float32Array(n);
            for (let i = 0; i < n; i++) dark[i] = 1 - luma[i];
            const alt = find(dark, w, h, { share, max, minArea, maxArea, hue, sat });
            alt.inverted = true;

            return alt;
        }
    }

    return out;
}

function find(luma, w, h, { share, max, minArea, maxArea, hue, sat }) {
    const n = w * h;

    // brightest `share` of the pixels, but never below a real highlight
    const hist = new Uint32Array(256);
    for (let i = 0; i < n; i++) hist[Math.min(255, Math.round(luma[i] * 255))]++;
    let acc = 0;
    let t = 255;
    for (; t > 0; t--) {
        acc += hist[t];
        if (acc >= n * share) break;
    }
    const thr = Math.min(Math.max(t / 255, 0.35), 0.92);

    // 3x3 majority, so speckle does not become a hundred blobs
    const raw = new Uint8Array(n);
    for (let i = 0; i < n; i++) raw[i] = luma[i] >= thr ? 1 : 0;
    const mask = new Uint8Array(n);
    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            let c = 0;
            for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) c += raw[(y + dy) * w + x + dx];
            mask[y * w + x] = c >= 5 ? 1 : 0;
        }
    }

    const labels = new Int16Array(n);
    const blobs = [];
    const stack = new Int32Array(n);
    for (let s = 0; s < n; s++) {
        if (!mask[s] || labels[s]) continue;
        const id = blobs.length + 1;
        let sp = 0;
        let area = 0;
        let sx = 0;
        let sy = 0;
        let sl = 0;
        let x0 = w;
        let x1 = 0;
        let y0 = h;
        let y1 = 0;
        stack[sp++] = s;
        labels[s] = id;
        while (sp) {
            const i = stack[--sp];
            const x = i % w;
            const y = (i / w) | 0;
            area++;
            sx += x;
            sy += y;
            sl += luma[i];
            if (x < x0) x0 = x;
            if (x > x1) x1 = x;
            if (y < y0) y0 = y;
            if (y > y1) y1 = y;
            if (x > 0 && mask[i - 1] && !labels[i - 1]) (labels[i - 1] = id), (stack[sp++] = i - 1);
            if (x < w - 1 && mask[i + 1] && !labels[i + 1]) (labels[i + 1] = id), (stack[sp++] = i + 1);
            if (y > 0 && mask[i - w] && !labels[i - w]) (labels[i - w] = id), (stack[sp++] = i - w);
            if (y < h - 1 && mask[i + w] && !labels[i + w]) (labels[i + w] = id), (stack[sp++] = i + w);
        }
        blobs.push({ id, area, cx: sx / area / w, cy: sy / area / h, mean: sl / area, bw: (x1 - x0 + 1) / w, bh: (y1 - y0 + 1) / h, labels });
    }

    // a blob that fills the picture is its background, not a light
    const usable = blobs.filter((b) => b.area >= n * minArea && b.area <= n * maxArea && !(b.bw > 0.85 && b.bh > 0.85)).sort((a, b) => b.area * b.mean - a.area * a.mean);
    const kick = usable[0] ?? null;
    let bands = usable.slice(1, 1 + max).sort((a, b) => a.cx - b.cx);

    // one big shape (a glow, a sky) leaves the bands with nothing: cut the biggest into strips of equal area, left to right
    if (kick && bands.length < 4) {
        const want = max - bands.length;
        const cols = new Float64Array(w);
        for (let i = 0; i < n; i++) if (labels[i] === kick.id) cols[i % w]++;
        const strips = new Int16Array(n);
        let acc = 0;
        for (let x = 0; x < w; x++) {
            acc += cols[x];
            const k = Math.min(want - 1, Math.floor((acc - 1) / (kick.area / want)));
            for (let y = 0; y < h; y++) if (labels[y * w + x] === kick.id) strips[y * w + x] = k + 1;
        }
        const extra = [];
        for (let k = 1; k <= want; k++) {
            let area = 0;
            for (let i = 0; i < n; i++) if (strips[i] === k) area++;
            if (area >= n * minArea) extra.push({ id: k, area, cx: (k - 0.5) / want, cy: kick.cy, mean: kick.mean, labels: strips, strip: true });
        }
        bands = bands.concat(extra).sort((a, b) => a.cx - b.cx);
    }

    // still little to light, but there is colour: one band per hue, red (low) to violet (high)
    const small = bands.reduce((t, b) => t + b.area, 0) < n * 0.02;
    if (small && hue && sat) {
        const groups = Array.from({ length: 6 }, () => ({ area: 0, labels: new Int16Array(n), id: 1 }));
        for (let i = 0; i < n; i++) {
            if (sat[i] < 0.35 || luma[i] < 0.2) continue;
            const gi = Math.min(5, Math.floor(hue[i] * 6));
            groups[gi].labels[i] = 1;
            groups[gi].area++;
        }
        const hues = groups.map((g, i) => ({ ...g, cx: i / 6, cy: 0.5, mean: 0.5, hue: true })).filter((g) => g.area >= n * 0.015);
        if (hues.length >= 3) bands = hues;
    }

    return { labels, blobs, kick, bands, threshold: thr };
}

/** What a picture looks like as a whole: average brightness, contrast (spread of brightness) and colourfulness, all 0..1. */
export function measure(luma, sat) {
    const n = luma.length;
    let sum = 0;
    let ss = 0;
    for (let i = 0; i < n; i++) {
        sum += luma[i];
        ss += sat[i];
    }
    const mean = sum / n;
    let v = 0;
    for (let i = 0; i < n; i++) v += (luma[i] - mean) ** 2;

    return { mean, contrast: Math.sqrt(v / n), sat: ss / n };
}

/**
 * The colour a picture is made of where it is brightest and most saturated: the hue (0..1) weighted by saturation and by brightness
 * squared, so a glowing sign counts for more than a dull wall. Returns `null` for a picture with no colour (grey, black and white).
 * `hue`, `sat` and `luma` are 0..1 per pixel.
 */
export function dominantHue(hue, sat, luma) {
    const n = luma.length;
    const BINS = 36;
    const w = new Float64Array(BINS);
    let total = 0;
    for (let i = 0; i < n; i++) {
        if (sat[i] < 0.3 || luma[i] < 0.2) continue;
        const wt = sat[i] * luma[i] * luma[i];
        w[Math.min(BINS - 1, Math.floor(hue[i] * BINS))] += wt;
        total += wt;
    }
    if (total < n * 0.004) return null;

    // the best bin counting its two neighbours, so a hue that straddles a bin edge is not split
    let best = 0;
    let bestW = -1;
    for (let b = 0; b < BINS; b++) {
        const sum = w[(b + BINS - 1) % BINS] + w[b] + w[(b + 1) % BINS];
        if (sum > bestW) (bestW = sum), (best = b);
    }

    // circular mean over those three bins
    let sx = 0;
    let sy = 0;
    for (const b of [(best + BINS - 1) % BINS, best, (best + 1) % BINS]) {
        const ang = ((b + 0.5) / BINS) * Math.PI * 2;
        sx += Math.cos(ang) * w[b];
        sy += Math.sin(ang) * w[b];
    }
    const h = (Math.atan2(sy, sx) / (Math.PI * 2) + 1) % 1;

    return { hue: h, share: bestW / total };
}

/** A CSS colour for a hue (0..1): bright and saturated, for light added on top of the picture. */
export const hueColor = (h) => `hsl(${Math.round(h * 360)}, 90%, 60%)`;

/**
 * Starting settings for a picture, from what `measure` and `analyze` found. Only the values that differ from the defaults are
 * returned; the user's own settings are applied on top. A guess, not taste: it is a place to start.
 */
export function suggest(m, found, { color = null } = {}) {
    const out = {};
    if (color) out.glowColor = 'picture'; // the picture's own strongest colour, so the added light never clashes with it
    const lights = found.bands.filter((b) => !b.strip && !b.hue).length;

    if (found.inverted) {
        out.bloom = 0; // a bright picture only has its dark shapes to light; nothing to swell
    } else if (m.contrast < 0.15) {
        out.bloom = 0.14; // flat: little else will move
    }

    if (!found.kick && !lights) {
        // nothing bright to light: the picture itself carries it, gently
        out.kickGain = 0.6;
        out.bandGain = 0.6;
        out.bloom = Math.max(out.bloom ?? 0.1, 0.16);
    } else if (lights >= 7) {
        out.bandGain = 0.8; // many small shapes would buzz
        out.release = 0.28;
    }

    return out;
}
