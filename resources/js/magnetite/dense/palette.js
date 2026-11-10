/**
 * Two vivid colours from a cover, to tint the meters with (the same method as the terminal client's `cover::palette`).
 * Pure: it takes the pixels of a small copy of the cover, so Node can test it.
 */

const BINS = 36;

/** [r, g, b] 0-255 -> [hue 0-360, saturation 0-1, value 0-1]. */
export function hsv([r, g, b]) {
    r /= 255; g /= 255; b /= 255;
    const mx = Math.max(r, g, b);
    const d = mx - Math.min(r, g, b);
    let h = 0;
    if (d !== 0) {
        if (mx === r) h = 60 * (((g - b) / d) % 6);
        else if (mx === g) h = 60 * ((b - r) / d + 2);
        else h = 60 * ((r - g) / d + 4);
    }

    return [((h % 360) + 360) % 360, mx === 0 ? 0 : d / mx, mx];
}

/** [hue, saturation, value] -> [r, g, b] 0-255. */
export function rgb(h, s, v) {
    h = ((h % 360) + 360) % 360;
    const c = v * s;
    const hp = h / 60;
    const x = c * (1 - Math.abs((hp % 2) - 1));
    const [r, g, b] = [[c, x, 0], [x, c, 0], [0, c, x], [0, x, c], [x, 0, c], [c, 0, x]][Math.min(5, Math.floor(hp))];
    const m = v - c;

    return [r, g, b].map((n) => Math.round((n + m) * 255));
}

export const toHex = ([r, g, b]) => `#${[r, g, b].map((n) => n.toString(16).padStart(2, '0')).join('')}`;

/** "#rrggbb" -> [r, g, b], or null for anything else. */
export function fromHex(hex) {
    const m = /^#([0-9a-f]{6})$/i.exec(String(hex).trim());

    return m ? [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16)) : null;
}

/**
 * @param {ArrayLike<number>} rgba pixels, 4 bytes each (a 32 x 32 copy of the cover)
 * @param {boolean} light the theme has a light background, so colours are made darker to read on it
 * @returns {?{ accent: number[], accent2: number[] }} two [r, g, b], or null when the cover has no colour
 */
export function palette(rgba, light = false) {
    const weight = new Array(BINS).fill(0);
    const sum = Array.from({ length: BINS }, () => [0, 0, 0]); // per hue bin: weighted s, v, h
    for (let i = 0; i + 3 < rgba.length; i += 4) {
        if (rgba[i + 3] < 128) continue; // transparent
        const [h, s, v] = hsv([rgba[i], rgba[i + 1], rgba[i + 2]]);
        const w = s < 0.25 || v < 0.2 ? 0 : s * v; // grey, black and white do not count
        const b = Math.floor(h / 10) % BINS;
        weight[b] += w;
        sum[b][0] += w * s;
        sum[b][1] += w * v;
        sum[b][2] += w * h;
    }
    const total = weight.reduce((a, b) => a + b, 0);
    if (total < 1) return null;

    // smooth over neighbouring bins so a hue near a bin edge is not split
    const smooth = weight.map((w, i) => w + 0.5 * (weight[(i + 1) % BINS] + weight[(i + BINS - 1) % BINS]));
    const best = (candidates) => candidates.reduce((a, b) => (smooth[b] > smooth[a] ? b : a), candidates[0]);
    const top = best([...Array(BINS).keys()]);

    const hueOf = (b) => {
        const centre = b * 10 + 5;
        let hw = 0;
        let ww = 0;
        for (const k of [(b + BINS - 1) % BINS, b, (b + 1) % BINS]) {
            if (weight[k] > 0) {
                const mean = sum[k][2] / weight[k];
                const d = ((((mean - centre + 540) % 360) + 360) % 360) - 180; // unwrap around the bin's centre
                hw += weight[k] * (centre + d);
                ww += weight[k];
            }
        }

        return ww > 0 ? hw / ww : centre;
    };
    const svOf = (b) => {
        const w = Math.max(weight[b], 1e-6);

        return [sum[b][0] / w, sum[b][1] / w];
    };
    const dist = (a, b) => {
        const d = Math.abs(a - b);

        return Math.min(d, BINS - d);
    };
    const others = [...Array(BINS).keys()].filter((b) => dist(b, top) >= 4 && smooth[b] >= total * 0.12);
    const second = others.length ? best(others) : null;

    const [vLo, vHi] = light ? [0.4, 0.7] : [0.78, 1.0];
    const clamp = (x, lo, hi) => Math.max(lo, Math.min(hi, x));
    const fit = (h, s, v) => rgb(h, clamp(s, 0.55, 1), clamp(v, vLo, vHi));
    const [s1, v1] = svOf(top);
    const accent = fit(hueOf(top), s1, v1);
    let accent2;
    if (second !== null) {
        const [s2, v2] = svOf(second);
        accent2 = fit(hueOf(second), s2, v2);
    } else {
        accent2 = fit(hueOf(top) + 30, Math.max(s1 * 0.8, 0.55), light ? vLo : vHi);
    }

    return { accent, accent2 };
}

/** Is a "#rrggbb" background light? (Rec. 709 luma.) */
export function isLight(hex) {
    const c = fromHex(hex);

    return !!c && 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2] > 140;
}

/** A step of easing from `cur` toward `target` (both [r, g, b]); `k` 0-1 is how much of the way to go. */
export function ease(cur, target, k) {
    return cur.map((c, i) => c + (target[i] - c) * k);
}
